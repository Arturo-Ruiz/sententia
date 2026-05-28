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
                    $meta = json_decode($r->metadata, true);

                    unset($meta['scraped_at']);

                    $metaString = collect($meta)->map(fn($v, $k) => "$k: $v")->implode(' | ');

                    return "### SENTENCIA: [Caso #{$r->case_number} | Fecha: {$r->date}]\nMETADATA: {$metaString}\nCONTENIDO: {$r->content}";
                })->implode("\n\n---\n\n")
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
