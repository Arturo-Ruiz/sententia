<?php

namespace App\Livewire;

use Livewire\Component;
use App\Services\SemanticSearchService;
use App\Ai\Agents\JudicialAssistant;

class SearchChat extends Component
{

    public string $question = '';
    public array $messages = [];

    public function render()
    {
        return view('livewire.search-chat');
    }

    public function ask()
    {
        if (empty(trim($this->question))) return;

        $this->messages[] = ['role' => 'user', 'content' => $this->question];
        $currentQuestion = $this->question;
        $this->reset('question');

        $searchService = app(SemanticSearchService::class);
        $resultados = $searchService->search($currentQuestion, limit: 3);

        // Mapeo correcto a la columna 'content' que vimos en tu base de datos
        $contexto = $resultados->isNotEmpty()
            ? $resultados->map(fn($r) => $r->content)->implode("\n\n")
            : "No se encontró información relevante.";

        $agent = new JudicialAssistant();
        $respuesta = $agent->prompt("Usa este contexto para responder: {$contexto}. Pregunta: {$currentQuestion}");

        $this->messages[] = ['role' => 'assistant', 'content' => $respuesta->text];
    }
}
