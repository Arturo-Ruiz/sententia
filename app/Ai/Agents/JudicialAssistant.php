<?php

namespace App\Ai\Agents;

use Illuminate\Support\Facades\DB;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Promptable;
use Stringable;

class JudicialAssistant implements Agent, Conversational, HasTools
{
    use Promptable;

    public function __construct(
        protected string $userQuery
    ) {}

    public function instructions(): Stringable|string
    {
        $chunks = DB::table('sentence_chunks')
            ->join('sentences', 'sentence_chunks.sentence_id', '=', 'sentences.id')
            ->select(['sentences.id as sentence_id', 'sentence_chunks.content'])
            ->whereVectorSimilarTo('embedding', $this->userQuery)
            ->limit(4)
            ->get();

        $context = $chunks->map(
            fn($chunk, $index) =>
            "[Document #" . ($index + 1) . " - Sentence ID: {$chunk->sentence_id}]:\n{$chunk->content}"
        )->implode("\n\n");

        return <<<PROMPT
        Eres un asistente legal experto en derecho procesal venezolano.
        Responde la pregunta del usuario basándote UNICAMENTE en el contexto:
        
        Reglas:
        1. Si la respuesta no está en el contexto, di que no tienes información suficiente.
        2. Cita siempre el ID de la sentencia correspondiente.
        3. Sé preciso, profesional y estructurado.

        CONTEXTO JURÍDICO:
        {$context}
        PROMPT;
    }

    public function messages(): iterable
    {
        return [];
    }
    public function tools(): iterable
    {
        return [];
    }
}
