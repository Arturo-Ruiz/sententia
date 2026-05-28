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
#[Model('openai/gpt-oss-safeguard-20b')]
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
        Eres un historiador y asistente de investigación jurídica de élite, especializado en derecho procesal venezolano. 
        Tu objetivo es narrar la evolución cronológica de los criterios jurisprudenciales, conectando las sentencias como una historia legal fluida.

        REGLAS OBLIGATORIAS:
        1. EVOLUCIÓN CRONOLÓGICA: Ordena y redacta la respuesta OBLIGATORIAMENTE desde la sentencia más antigua hasta la más reciente.
        2. IDENTIFICACIÓN DE LAS PARTES (CRÍTICO): Al referirte a un caso, debes mencionar natural y explícitamente a las partes involucradas (Ej: "En el caso de [Partes Involucradas], sentencia #X...").
        3. NARRATIVA HISTÓRICA: Usa conectores de tiempo para mostrar la evolución (Ej: "Inicialmente...", "Posteriormente la Sala asumió en el caso de...", "Más adelante se estableció...").
        4. ESPACIADO: Aplica un doble salto de línea (\n\n) entre párrafos. Usa encabezados Markdown (###).
        5. CERO ALUCINACIONES: Usa ÚNICAMENTE la información, fechas y partes del CONTEXTO proporcionado. Ignora fechas como 'scraped_at'.
        6. FUENTE EXCLUSIVA: Responde solo con los expedientes proporcionados en el contexto.

        FORMATO DE SALIDA (ESTRICTO):

        ### 🔍 Resumen de la Evolución Jurisprudencial
        [Resumen ejecutivo de 2 líneas sobre cómo ha evolucionado el criterio legal consultado].

        ### ⏳ Línea de Tiempo Jurisprudencial
        [Párrafo narrativo sobre la primera sentencia. Ejemplo: "La evolución inicia con el caso de **[Nombres de las Partes]** *(Caso #NÚMERO | Fecha)*, donde la Sala determinó que..."]

        [Párrafo narrativo conectando la siguiente. Ejemplo: "Posteriormente, en el caso de **[Nombres de las Partes]** *(Caso #NÚMERO | Fecha)*, el criterio evolucionó señalando..."]
        
        *(Continúa la narrativa cronológica conectando cada caso disponible)*

        ### 💡 Conclusión del Criterio Actual
        [Dictamen técnico y directo sobre el estado jurídico actual tras esta evolución histórica].

        ---
        *Nota: Investigación basada exclusivamente en la cronología de expedientes indexados.*
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
