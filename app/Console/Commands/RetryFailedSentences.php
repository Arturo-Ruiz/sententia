<?php

namespace App\Console\Commands;

use App\Models\Sentence;
use App\Services\TsjScraperService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('app:retry-failed-sentences')]
#[Description('Re-descarga sentencias cuyo contenido falló por errores de red o encoding')]
class RetryFailedSentences extends Command
{
    private const ERROR_CONTENT = 'Error al procesar contenido (Encoding o Red).';

    protected $scraperService;

    public function __construct(TsjScraperService $scraperService)
    {
        parent::__construct();
        $this->scraperService = $scraperService;
    }

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('🔍 Buscando sentencias con registros de error en la base de datos...');

        // Buscamos solo los registros que se quedaron con el string de error
        $failedSentences = Sentence::where('content', self::ERROR_CONTENT)->get();
        $total = $failedSentences->count();

        if ($total === 0) {
            $this->info('✅ ¡Felicidades! No hay sentencias con errores de contenido en este momento.');

            return Command::SUCCESS;
        }

        $this->warn("⚠️ Se encontraron {$total} sentencias fallidas. Iniciando reparación masiva...");
        $repairedCount = 0;

        foreach ($failedSentences as $sentence) {
            $this->warn("🔄 Re-descargando: Exp {$sentence->case_number} -> {$sentence->url}");

            // Intentamos descargar de nuevo con tu lógica limpia y UTF-8
            $freshContent = $this->scraperService->downloadCleanContent($sentence->url);

            // Validamos que la respuesta no sea otro error ni esté vacía
            if ($freshContent !== self::ERROR_CONTENT && ! empty($freshContent)) {
                $sentence->update([
                    'content' => $freshContent,
                ]);
                $repairedCount++;
            }

        }

        $this->info('======================================================================');
        $this->info("🎉 PROCESO TERMINADO: Se repararon [{$repairedCount} de {$total}] sentencias.");
        $this->info('======================================================================');

        return Command::SUCCESS;
    }
}
