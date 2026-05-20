<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

use App\Services\TsjScraperService;

#[Signature('app:test-scraper')]
#[Description('Command description')]
class TestScraper extends Command
{
    /**
     * Execute the console command.
     */
    public function handle()
    {
        $filePath = base_path('html_tsj_results.html');

        if (!file_exists($filePath)) {
            $this->error("No encontré el archivo html_tsj_results.html en la raíz del proyecto.");
            return;
        }

        $this->info("Leyendo archivo HTML renderizado...");
        $htmlContent = file_get_contents($filePath);

        $scraper = new TsjScraperService();
        $totalSaved = $scraper->parseResultsPage($htmlContent);

        $this->info("¡Proceso completado! Se mapearon y guardaron [ $totalSaved ] sentencias con sus expedientes correctos en PostgreSQL.");
    }
}
