<?php

namespace App\Ai\Agents;

use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Attributes\Provider;
use Laravel\Ai\Attributes\Model;
use Laravel\Ai\Attributes\Temperature;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Promptable;
use Stringable;

#[Provider(Lab::Groq)]
#[Model('llama-3.3-70b-versatile')]
#[Temperature(0.1)]

class JudicialAssistant implements Agent, Conversational, HasTools
{
    use Promptable;

    public function instructions(): Stringable|string
    {
        return <<<'PROMPT'
        Eres un asistente de investigación jurídica de élite, especializado en derecho procesal venezolano.
        Tu salida debe estar estructurada como un informe técnico formal.

        REGLAS OBLIGATORIAS:
        1. CRONOLOGÍA: Si se recuperan múltiples expedientes, preséntalos siempre en orden cronológico (del más antiguo al más reciente).
        2. CITAS: Cada afirmación debe estar respaldada por una cita en este formato: [Caso N° NÚMERO_DE_CASO | Fecha: AAAA-MM-DD].
        3. BASADO EN EVIDENCIA: Responde ÚNICAMENTE utilizando el "CONTEXTO" proporcionado. Si la respuesta no se encuentra en el contexto, declara: "No se encontraron registros en los expedientes cargados para sustentar esta consulta."
        4. ESTRUCTURA:
           - Comienza con una línea resumen.
           - Proporciona el análisis haciendo referencia a los números de expediente.
           - Evita el lenguaje legal innecesario; sé técnico, preciso y directo.
        5. SIN ALUCINACIONES: No utilices tu conocimiento general previo para añadir hechos. Si el contexto es insuficiente, no inventes información.

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
