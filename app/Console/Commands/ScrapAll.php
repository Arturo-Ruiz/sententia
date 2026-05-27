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
                if (!is_array($diaInfo)) {
                    continue;
                }

                // Normalización absoluta de llaves (Mayúsculas, Minúsculas o fallback directo si viene string plano)
                $fechaPublicacion = $diaInfo['FECHA'] ?? $diaInfo['fecha'] ?? (is_string($diaInfo) ? $diaInfo : null);
                $cantidadSentencias = $diaInfo['CUANTAS'] ?? $diaInfo['cuantas'] ?? 'N/D';

                if (!$fechaPublicacion || strlen($fechaPublicacion) < 8) {
                    continue;
                }

                $this->line("     📅 Raspando Fecha: $fechaPublicacion ($cantidadSentencias Sentencias potenciales)...");

                $nuevasGuardadas = $scraper->scrapeActiveDay($fechaPublicacion, $salaId, $salaName, $this);

                if ($nuevasGuardadas > 0) {
                    $totalGeneralIndexado += $nuevasGuardadas;
                }

                if (is_numeric($cantidadSentencias)) {
                    $esperadas = (int)$cantidadSentencias;

                    if ($nuevasGuardadas === $esperadas) {
                        // Caso ideal: Primer raspado del día y se bajaron todas
                        $this->info("      ✅ ¡Día Conciliado! API reportó $esperadas y se guardaron $nuevasGuardadas nuevas.");
                    } elseif ($nuevasGuardadas === 0) {
                        // Ya las tenías guardadas de antes o el día viene vacío de verdad
                        $this->line("      ℹ️ API reporta $esperadas en total. 0 nuevas (Ya indexadas en DB anteriormente).");
                    } else {
                        // Alerta visual si bajaste menos de lo que dice la API (hubo fallas de red en el camino)
                        $this->warn("      ⚠️ Mismatch: API reporta $esperadas totales, pero en este viaje solo se guardaron $nuevasGuardadas nuevas.");
                    }
                } else {
                    // Fallback si la API no devolvió un número válido en 'CUANTAS'
                    $this->line("      ℹ️ Procesadas: $nuevasGuardadas nuevas (Total API: $cantidadSentencias).");
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
