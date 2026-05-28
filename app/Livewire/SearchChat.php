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

        // Historial del usuario
        $this->messages[] = ['role' => 'user', 'content' => $this->question];
        $currentQuestion = $this->question;
        $this->reset('question');

        try {
            $searchService = app(SemanticSearchService::class);
            $results = $searchService->search($currentQuestion, limit: 5);

            $context = $results->isNotEmpty()
                ? $results->map(fn($r) => "SENTENCIA: [Caso #{$r->case_number}]\nCONTENIDO: {$r->content}")
                          ->implode("\n\n---\n\n")
                : "No se encontraron registros en los expedientes.";


            $agent = new JudicialAssistant();
            $response = $agent->prompt(
                "CONTEXTO CRONOLÓGICO:\n{$context}\n\nPREGUNTA: {$currentQuestion}\n\n" .
                "INSTRUCCIÓN: Analiza la evolución de los casos presentados, cita cada uno usando " .
                "el formato [Caso #Número | Fecha: AAAA-MM-DD] y responde como un experto jurídico."
            );

            $this->messages[] = ['role' => 'assistant', 'content' => $response->text];

        } catch (\Exception $e) {
            Log::error("Error en SearchChat: " . $e->getMessage());
            $this->messages[] = ['role' => 'assistant', 'content' => "Error al procesar la consulta."];
        }
    }
}
