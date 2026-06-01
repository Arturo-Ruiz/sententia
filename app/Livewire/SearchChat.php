<?php

namespace App\Livewire;

use App\Ai\Agents\JudicialAssistant;
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
            $searchService = app(SemanticSearchService::class);
            $results = $searchService->search($currentQuestion, limit: 5);

            if ($results->isEmpty()) {
                $this->messages[] = ['role' => 'assistant', 'content' => 'No se encontraron sentencias relevantes para tu consulta. Intenta describir tu caso con más detalle.'];
                $this->persistMessages();

                return;
            }

            $context = $results->map(function ($r, $index) {
                $meta = json_decode($r->metadata ?? '{}', true) ?? [];
                unset($meta['scraped_at']);

                $partes = $meta['parts'] ?? 'No especificadas';
                $magistrado = $meta['magistrate'] ?? 'No especificado';
                $procedimiento = $meta['procedure'] ?? 'No especificado';

                $relevantText = $r->relevant_chunks ?? '';

                // Enviar contenido completo al LLM (truncado a 30K chars para no exceder context window)
                $fullContent = mb_strlen($r->full_content ?? '') > 30000
                    ? mb_substr($r->full_content, 0, 30000).'... [truncado]'
                    : ($r->full_content ?? '');

                $n = $index + 1;

                return "=== SENTENCIA #{$n} ===\n".
                    "EXPEDIENTE: {$r->case_number}\n".
                    "TRIBUNAL: {$r->court}\n".
                    "URL: {$r->url}\n".
                    "PARTES: {$partes}\n".
                    "MAGISTRADO: {$magistrado}\n".
                    "PROCEDIMIENTO: {$procedimiento}\n\n".
                    "FRAGMENTOS MÁS RELEVANTES:\n{$relevantText}\n\n".
                    "CONTENIDO COMPLETO DE LA SENTENCIA:\n{$fullContent}\n".
                    '=== FIN ===';
            })->implode("\n\n");

            // 3. Instanciar el asistente pasándole el historial conversacional limpio
            $agent = new JudicialAssistant($history);

            // 4. Promptear con el contexto híbrido enriquecido y el Rerank
            $response = $agent->prompt(
                "CONTEXTO:\n\n{$context}\n\nPREGUNTA:\n{$currentQuestion}"
            );

            $this->messages[] = ['role' => 'assistant', 'content' => $response->text];

            // 5. Persistir la conversación en la base de datos
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
