<?php

namespace App\Ai\Agents;

use Laravel\Ai\Attributes\Model;
use Laravel\Ai\Attributes\Provider;
use Laravel\Ai\Attributes\Temperature;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Messages\Message;
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
        Eres un historiador y asistente de investigación jurídica de élite, especializado en derecho procesal venezolano. 
        Tu objetivo es narrar la evolución cronológica de los criterios jurisprudenciales usando toda la información disponible.

        REGLAS OBLIGATORIAS:
        1. EVOLUCIÓN CRONOLÓGICA: Ordena la respuesta desde la sentencia más antigua a la más reciente.
        2. EXTRACCIÓN DE FECHA: Debes leer cuidadosamente el "CONTENIDO DE LA SENTENCIA" y los "DETALLES METADATA" para deducir la fecha de la decisión.
        3. IDENTIFICACIÓN COMPLETA: Al referirte a un caso, menciona natural y explícitamente a las PARTES, el TRIBUNAL y el MAGISTRADO ponente.
        4. NARRATIVA HISTÓRICA: Usa conectores de tiempo (Ej: "Inicialmente...", "Posteriormente la Sala asumió...").
        5. CITAS Y URL: Al final de cada párrafo narrativo, debes incluir OBLIGATORIAMENTE la cita estructurada con el enlace al documento.
        6. CERO ALUCINACIONES: Usa ÚNICAMENTE la información del CONTEXTO.

        FORMATO DE SALIDA (ESTRICTO):

        ### 🔍 Resumen de la Evolución Jurisprudencial
        [Resumen ejecutivo].

        ### ⏳ Línea de Tiempo Jurisprudencial
        [Párrafo narrativo. Ejemplo: "La evolución inicia con el caso de **[Nombres de las Partes]**, decidido por el **[Tribunal]** bajo la ponencia del Magistrado **[Nombre]**. En este fallo, se determinó que..."]
        > 🔗 *Ref: Caso #[NÚMERO] | Fecha: [Fecha deducida del texto] | Procedimiento: [Tipo]*
        > 📄 *Enlace: https://www.collinsdictionary.com/dictionary/spanish-english/fuente*

        [Párrafo narrativo conectando la siguiente sentencia...]
        > 🔗 *Ref: Caso #[NÚMERO] | Fecha: [Fecha deducida del texto] | Procedimiento: [Tipo]*
        > 📄 *Enlace: https://www.collinsdictionary.com/dictionary/spanish-english/fuente*

        ### 💡 Conclusión del Criterio Actual
        [Dictamen técnico del estado actual del criterio].
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
