<?php

namespace App\Livewire;

use Livewire\Component;
use App\Services\SemanticSearchService;
use App\Ai\Agents\JudicialAssistant;
use Illuminate\Support\Facades\Log;

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
        if (empty(trim($this->question))) return;

        $this->messages[] = ['role' => 'user', 'content' => $this->question];
        $currentQuestion = $this->question;
        $this->reset('question');

        try {
            $searchService = app(SemanticSearchService::class);
            $results = $searchService->search($currentQuestion, limit: 5);

            $context = $results->isNotEmpty()
                ? $results->map(function ($r) {
                    $meta = json_decode($r->metadata ?? '{}', true);

                    unset($meta['scraped_at']);

                    $partes = $meta['parts'] ?? 'Partes no especificadas';
                    $magistrado = $meta['magistrate'] ?? 'Magistrado no especificado';
                    $procedimiento = $meta['procedure'] ?? 'Procedimiento no especificado';

                    // 3. Convertimos el resto de la metadata (ej. decision_summary) a texto
                    $metaString = collect($meta)->map(fn($v, $k) => strtoupper($k) . ": $v")->implode("\n");

                    // 4. Construimos el bloque de texto MASIVO para la IA
                    return "### EXPEDIENTE: [Caso #{$r->case_number}]\n" .
                        "TRIBUNAL/CORTE: {$r->court}\n" .
                        "URL FUENTE: {$r->url}\n" .
                        "PARTES: {$partes}\n" .
                        "MAGISTRADO PONENTE: {$magistrado}\n" .
                        "PROCEDIMIENTO: {$procedimiento}\n" .
                        "--- DETALLES METADATA ---\n{$metaString}\n" .
                        "--- CONTENIDO DE LA SENTENCIA ---\n{$r->content}";
                })->implode("\n\n==================================\n\n")
                : "No se encontraron registros.";

            $agent = new JudicialAssistant();

            $response = $agent->prompt(
                "CONTEXTO JURÍDICO:\n{$context}\n\nPREGUNTA DEL USUARIO:\n{$currentQuestion}"
            );

            $this->messages[] = ['role' => 'assistant', 'content' => $response->text];
        } catch (\Exception $e) {
            Log::error("Error en SearchChat: " . $e->getMessage());
            $this->messages[] = ['role' => 'assistant', 'content' => "Error al procesar la consulta."];
        }
    }
}
