<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

use App\Services\TsjScraperService;

#[Signature('app:scrap-all {year : El año que se desea escanear por completo (ej: 2026)}')]
#[Description('Realiza un barrido masivo de las 21 salas y juzgados del TSJ para un año completo')]

class ScrapAll extends Command
{
    /**
     * Execute the console command.
     */
    public function handle()
    {
        $year = $this->argument('year');

        if ($year < 2000 || $year > now()->year) {
            $this->error("Año fuera de los parámetros del repositorio histórico (2000 - " . now()->year . ").");
            return Command::FAILURE;
        }

        $this->info("======================================================================");
        $this->info(" 🚀 MOTOR API DINÁMICO SENTENTIA SUITE - INICIANDO AÑO: $year ");
        $this->info("======================================================================");

        $scraper = new TsjScraperService();

        // 1. Buscamos todas las salas mapeadas dinámicamente por el portal del TSJ
        $this->line("📡 Conectando con la API del TSJ para extraer el catálogo de Salas...");
        $salasRaw = $scraper->fetchSalas();

        if (empty($salasRaw)) {
            $this->error("❌ No se pudo recuperar el catálogo de salas desde el Endpoint primario. Abortando.");
            return Command::FAILURE;
        }

        // Blindaje por si la API del TSJ devuelve una sola sala como array asociativo directo
        if (isset($salasRaw['SSALAID'])) {
            $salasRaw = [$salasRaw];
        }

        $this->info("🏛️ Se detectaron " . count($salasRaw) . " salas y juzgados operativos.");
        $totalGeneralIndexado = 0;

        // BUCLE 1: Recorremos las salas obtenidas del API
        foreach ($salasRaw as $sala) {
            $salaId   = $sala['SSALAID'] ?? null;
            $salaName = $sala['SSALADESCRIPCION'] ?? 'Sala Desconocida';
            $salaDir  = $sala['SSALADIR'] ?? 'unknown';

            if (!$salaId) {
                continue;
            }

            $this->warn("\n----------------------------------------------------------------------");
            $this->warn(" 🏛️  PROCESANDO: $salaName (ID: $salaId | Ruta: /$salaDir)");
            $this->warn("----------------------------------------------------------------------");

            // 2. Preguntamos qué días de ese año tienen sentencias para esta sala
            $diasActivos = $scraper->fetchActiveDays($salaId, $year);

            if (empty($diasActivos)) {
                $this->line("   ℹ️ Sin actividad registrada para esta sala en el año $year.");
                continue;
            }

            $this->info("   🔍 Encontrados " . count($diasActivos) . " días con publicaciones. Iniciando recolección...");

            // BUCLE 2: Atacamos directamente los días hábiles reportados por el endpoint
            foreach ($diasActivos as $diaInfo) {
                $fechaPublicacion = $diaInfo['FECHA'] ?? null; // ej: "05/02/2026"
                $cantidadSentencias = $diaInfo['CUANTAS'] ?? 'N/D';

                if (!$fechaPublicacion) {
                    continue;
                }

                $this->line("     📅 Raspando Fecha: $fechaPublicacion ($cantidadSentencias Sentencias potenciales)...");

                // Ejecutamos la extracción para ese día exacto pasando $this para el output visual
                $nuevasGuardadas = $scraper->scrapeActiveDay($fechaPublicacion, $salaId, $salaName, $this);

                if ($nuevasGuardadas > 0) {
                    $this->info("      ✅ Se guardaron [$nuevasGuardadas] nuevas decisiones.");
                    $totalGeneralIndexado += $nuevasGuardadas;
                }
            }
        }

        $this->info("\n======================================================================");
        $this->info(" 🎉 SINCRONIZACIÓN FINALIZADA EXITOSAMENTE ");
        $this->info(" Total de sentencias indexadas con la API interna: $totalGeneralIndexado");
        $this->info("======================================================================");

        return Command::SUCCESS;
    }
}
