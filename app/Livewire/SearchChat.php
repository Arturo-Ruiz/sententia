<?php

namespace App\Livewire;

use App\Ai\Agents\JudicialAssistant;
use App\Services\SemanticSearchService;
use Illuminate\Support\Facades\Log;
use Livewire\Component;

class SearchChat extends Component
{
    public string $question = '';

    public array $messages = [];

    public function render()
    {
        return view('livewire.search-chat');
    }

    public function ask(): void
    {
        if (empty(trim($this->question))) {
            return;
        }

        $this->messages[] = ['role' => 'user', 'content' => $this->question];
        $currentQuestion = $this->question;
        $this->reset('question');

        try {
            $searchService = app(SemanticSearchService::class);
            $results = $searchService->search($currentQuestion, limit: 5);

            if ($results->isEmpty()) {
                $this->messages[] = ['role' => 'assistant', 'content' => 'No se encontraron sentencias relevantes para tu consulta. Intenta describir tu caso con más detalle.'];

                return;
            }

            $context = $results->map(function ($r, $index) {
                $meta = json_decode($r->metadata ?? '{}', true) ?? [];
                unset($meta['scraped_at']);

                $partes = $meta['parts'] ?? 'No especificadas';
                $magistrado = $meta['magistrate'] ?? 'No especificado';
                $procedimiento = $meta['procedure'] ?? 'No especificado';

                $relevantText = $r->relevant_chunks ?? '';
                
                $fullLen = mb_strlen($r->full_content ?? '');
                $tail = $fullLen > 300 ? mb_substr($r->full_content, -300) : '';

                $n = $index + 1;

                return "=== SENTENCIA #{$n} ===\n".
                    "EXPEDIENTE: {$r->case_number}\n".
                    "TRIBUNAL: {$r->court}\n".
                    "URL: {$r->url}\n".
                    "PARTES: {$partes}\n".
                    "MAGISTRADO: {$magistrado}\n".
                    "PROCEDIMIENTO: {$procedimiento}\n\n".
                    "TEXTO RELEVANTE AL CASO:\n{$relevantText}\n\n".
                    "FINAL DEL DOCUMENTO (para fecha/firmas):\n{$tail}\n".
                    '=== FIN ===';
            })->implode("\n\n");

            $agent = new JudicialAssistant;

            $response = $agent->prompt(
                "CONTEXTO:\n\n{$context}\n\nPREGUNTA:\n{$currentQuestion}"
            );

            $this->messages[] = ['role' => 'assistant', 'content' => $response->text];
        } catch (\Exception $e) {
            Log::error('Error en SearchChat: '.$e->getMessage());
            $this->messages[] = ['role' => 'assistant', 'content' => 'Error al procesar la consulta: '.$e->getMessage()];
        }
    }
}
