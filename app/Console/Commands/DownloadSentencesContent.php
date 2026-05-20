<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

use App\Models\Sentence;
use App\Services\TsjScraperService;

#[Signature('app:download-sentences-content')]
#[Description('Command description')]
class DownloadSentencesContent extends Command
{
    /**
     * Execute the console command.
     */
    public function handle()
    {
        // 1. Traer de PostgreSQL todas las sentencias que tengan el texto de pendiente
        $pendingSentences = Sentence::where('content', 'like', '%Pendiente%')->get();

        if ($pendingSentences->isEmpty()) {
            $this->info("No hay sentencias pendientes por descargar.");
            return;
        }

        $this->info("Se encontraron [ " . $pendingSentences->count() . " ] sentencias listas para descargar.");
        $scraper = new TsjScraperService();

        // 2. Recorrerlas e ir descargando su texto
        foreach ($pendingSentences as $sentence) {
            $this->info("Descargando contenido para el expediente: {$sentence->case_number}...");

            $success = $scraper->downloadSentenceContent($sentence->id);

            if ($success) {
                $this->info("¡Completado con éxito!");
            } else {
                $this->error("No se pudo descargar este registro.");
            }

            // 3. REGLA DE ORO: Pausa de 2 segundos entre peticiones para que el servidor 
            // del TSJ no bloquee tu dirección IP por comportamiento robótico.
            sleep(2);
        }

        $this->info("¡Proceso de descarga masiva finalizado!");
    }
}
