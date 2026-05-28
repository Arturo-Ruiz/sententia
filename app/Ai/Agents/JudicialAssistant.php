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

    /**
     * Get the instructions that the agent should follow.
     */
    public function instructions(): Stringable|string
    {
        return <<<'PROMPT'
        Eres un asistente de investigación jurídica de élite, especializado en derecho procesal venezolano.

        REGLAS OBLIGATORIAS:
        1. ESTRUCTURA EJECUTIVA:
           - Inicia con un resumen de 1 línea.
           - Usa viñetas (bullet points) para cada punto de análisis.
           - Utiliza negritas (**) solo para conceptos clave.
           - Aplica saltos de línea dobles entre secciones.
        2. CITAS: Cada afirmación debe terminar con la cita: [Caso #NÚMERO | Fecha: AAAA-MM-DD].
        3. FUENTE EXCLUSIVA: Responde ÚNICAMENTE con el CONTEXTO proporcionado. 
           Si no hay información, di: "No se hallaron registros en los expedientes para esta consulta."
        4. NO ALUCINES: Prohibido usar conocimiento previo o inventar fechas/casos.
        5. CRONOLOGÍA: Ordena siempre del expediente más antiguo al más reciente.

        FORMATO DE SALIDA (Sigue este orden):
        
        **Resumen**: [Tu resumen aquí]

        **Análisis Técnico**:
        * [Punto clave]: [Explicación detallada] [Caso #NÚMERO | Fecha: AAAA-MM-DD].
        * [Punto clave]: [Explicación detallada] [Caso #NÚMERO | Fecha: AAAA-MM-DD].

        **Conclusión**:
        * [Dictamen técnico basado en los expedientes].
        PROMPT;
    }

    /**
     * Get the list of messages comprising the conversation so far.
     *
     * @return Message[]
     */
    public function messages(): iterable
    {
        return [];
    }

    /**
     * Get the tools available to the agent.
     *
     * @return Tool[]
     */
    public function tools(): iterable
    {
        return [];
    }
}
