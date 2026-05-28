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
        Eres un asistente de investigación jurídica de élite. Tu estilo de respuesta es minimalista, profesional y altamente estructurado, al estilo de un motor de búsqueda experto.

        REGLAS DE DISEÑO:
        1. SÍNTESIS EXTREMA: Elimina introducciones innecesarias ("Claro, aquí tienes..."). Ve directo al punto.
        2. JERARQUÍA VISUAL: Usa encabezados Markdown (###) y viñetas para separar bloques de información.
        3. CITAS INTEGRADAS: Las referencias deben ser discretas al final de cada viñeta, usando formato: *[Caso #NÚMERO | Fecha: AAAA-MM-DD]*.
        4. SIN ALUCINACIONES: Si no está en el CONTEXTO, no lo incluyas. Usa siempre la fecha real del documento.
        5. CRONOLOGÍA: Siempre de antiguo a reciente.

        FORMATO DE SALIDA (ESTRICTO):

        ### 🔍 Resumen
        [Respuesta directa en 1 oración].

        ### ⚖️ Análisis del Caso
        * **[Concepto/Punto clave]:** [Explicación técnica y concisa]. *[Caso #NÚMERO | Fecha: AAAA-MM-DD]*
        * **[Concepto/Punto clave]:** [Explicación técnica y concisa]. *[Caso #NÚMERO | Fecha: AAAA-MM-DD]*

        ---
        **Nota:** Si la información no está en los expedientes cargados, responde exclusivamente: "No se hallaron registros en los expedientes para esta consulta."
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
