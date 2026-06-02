<?php

namespace App\Livewire;

use App\Ai\Agents\JudicialAssistant;
use App\Ai\Agents\QueryReformulator;
use App\Services\SemanticSearchService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Livewire\Component;

class SearchChat extends Component
{
    public string $question = '';

    public array $messages = [];

    public ?string $conversationId = null;

    public function mount(): void
    {
        $this->conversationId = session('sententia_conversation_id');

        if ($this->conversationId) {
            $this->messages = $this->loadMessages($this->conversationId);
        }
    }

    public function render()
    {
        return view('livewire.search-chat');
    }

    public function ask(): void
    {
        if (empty(trim($this->question))) {
            return;
        }

        // 1. Construir el historial limpio (solo preguntas y respuestas, sin contexto RAG)
        $history = $this->messages;

        // 2. Agregar el mensaje del usuario actual
        $this->messages[] = ['role' => 'user', 'content' => $this->question];
        $currentQuestion = $this->question;
        $this->reset('question');

        try {
            // 1. Reformular la pregunta en lenguaje jurídico para mejor retrieval
            $legalQuery = null;
            try {
                $reformulator = new QueryReformulator;
                $reformulation = $reformulator->prompt($currentQuestion);
                $legalQuery = $reformulation->text;
                Log::info('Query reformulada', ['original' => $currentQuestion, 'legal' => $legalQuery]);
            } catch (\Exception $e) {
                Log::warning('Reformulación falló, usando query original: '.$e->getMessage());
            }

            // 2. Buscar con query original + reformulación jurídica
            $searchService = app(SemanticSearchService::class);
            $results = $searchService->search($currentQuestion, limit: 5, legalQuery: $legalQuery);

            if ($results->isEmpty()) {
                $this->messages[] = ['role' => 'assistant', 'content' => 'No se encontraron sentencias relevantes para tu consulta. Intenta describir tu caso con más detalle o usar términos jurídicos específicos.'];
                $this->persistMessages();

                return;
            }

            // 3. Construir contexto estructurado para el LLM (solo chunks relevantes, no full_content)
            $context = $results->map(function ($r, $index) {
                $meta = json_decode($r->metadata ?? '{}', true) ?? [];
                unset($meta['scraped_at']);

                $partes = $meta['parts'] ?? 'No especificadas';
                $magistrado = $meta['magistrate'] ?? 'No especificado';
                $procedimiento = $meta['procedure'] ?? 'No especificado';
                $resumen = $meta['decision_summary'] ?? '';

                $relevantText = $r->relevant_chunks ?? '';
                $n = $index + 1;

                return "=== SENTENCIA #{$n} ===\n".
                    "EXPEDIENTE: {$r->case_number}\n".
                    "TRIBUNAL: {$r->court}\n".
                    "URL: {$r->url}\n".
                    "PARTES: {$partes}\n".
                    "MAGISTRADO: {$magistrado}\n".
                    "PROCEDIMIENTO: {$procedimiento}\n".
                    ($resumen ? "RESUMEN: {$resumen}\n" : '').
                    "\nCONTENIDO RELEVANTE:\n{$relevantText}\n".
                    '=== FIN ===';
            })->implode("\n\n");

            // 4. Instanciar el asistente pasándole el historial conversacional limpio
            $agent = new JudicialAssistant($history);

            // 5. Promptear con el contexto híbrido enriquecido
            $response = $agent->prompt(
                "CONTEXTO:\n\n{$context}\n\nPREGUNTA DEL USUARIO:\n{$currentQuestion}"
            );

            $this->messages[] = ['role' => 'assistant', 'content' => $response->text];

            // 6. Persistir la conversación en la base de datos
            $this->persistMessages();
        } catch (\Exception $e) {
            Log::error('Error en SearchChat: '.$e->getMessage());
            $this->messages[] = ['role' => 'assistant', 'content' => 'Error al procesar la consulta: '.$e->getMessage()];
        }
    }

    /**
     * Start a new conversation, clearing the current history.
     */
    public function newChat(): void
    {
        $this->messages = [];
        $this->conversationId = null;
        session()->forget('sententia_conversation_id');
    }

    /**
     * Persist all messages of the current conversation to the database.
     */
    private function persistMessages(): void
    {
        if (! $this->conversationId) {
            $this->conversationId = (string) Str::uuid();
            session(['sententia_conversation_id' => $this->conversationId]);
        }

        $userId = auth()->id();
        $title = Str::limit($this->messages[0]['content'] ?? 'Nueva consulta', 100);

        DB::table('agent_conversations')->updateOrInsert(
            ['id' => $this->conversationId],
            [
                'user_id' => $userId,
                'title' => $title,
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );

        // Re-insertar mensajes limpios (sin contexto RAG) para mantener integridad
        DB::table('agent_conversation_messages')
            ->where('conversation_id', $this->conversationId)
            ->delete();

        $now = now();

        foreach ($this->messages as $msg) {
            DB::table('agent_conversation_messages')->insert([
                'id' => (string) Str::uuid(),
                'conversation_id' => $this->conversationId,
                'user_id' => $userId,
                'agent' => JudicialAssistant::class,
                'role' => $msg['role'],
                'content' => $msg['content'],
                'attachments' => '[]',
                'tool_calls' => '[]',
                'tool_results' => '[]',
                'usage' => '{}',
                'meta' => '{}',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    /**
     * Load messages from a persisted conversation.
     *
     * @return array<int, array{role: string, content: string}>
     */
    private function loadMessages(string $conversationId): array
    {
        return DB::table('agent_conversation_messages')
            ->where('conversation_id', $conversationId)
            ->orderBy('created_at')
            ->get()
            ->map(fn ($m) => ['role' => $m->role, 'content' => $m->content])
            ->toArray();
    }
}
