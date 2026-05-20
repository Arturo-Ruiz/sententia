<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Symfony\Component\DomCrawler\Crawler;

use App\Models\Sentence;


class TsjScraperService
{
    private string $baseUrl = 'https://www.tsj.gob.ve/decisiones';

    /**
     * Create a new class instance.
     */
    public function __construct()
    {
        //
    }
    public function fetchSalas(): array
    {
        try {
            $response = Http::withoutVerifying()->timeout(10)->get($this->baseUrl, [
                'p_p_id' => 'senderSentencias_WAR_NoticiasTsjPorlet612',
                'p_p_lifecycle' => '2',
                'p_p_state' => 'normal',
                'p_p_mode' => 'view',
                'p_p_cacheability' => 'cacheLevelPage',
                'server[endpoint]' => '/services/WSDecision.HTTPEndpoint',
                'server[method]' => '/listSala',
            ]);

            if ($response->successful()) {
                $data = $response->json();
                return $data['coleccion']['SALA'] ?? [];
            }
        } catch (\Exception $e) {
            Log::error("Error al obtener salas de la API: " . $e->getMessage());
        }

        return [];
    }

    /**
     * Obtiene los días del año específico donde una sala publicó sentencias
     */
    public function fetchActiveDays(string $salaId, string $year): array
    {
        try {
            $response = Http::withoutVerifying()->timeout(12)->get($this->baseUrl, [
                'p_p_id' => 'displaySentencias_WAR_NoticiasTsjPorlet612',
                'p_p_lifecycle' => '2',
                'p_p_state' => 'normal',
                'p_p_mode' => 'view',
                'p_p_cacheability' => 'cacheLevelPage',
                'server[endpoint]' => '/services/WSDecision.HTTPEndpoint',
                'server[method]' => '/listDayByAnoSala',
                'SALA' => $salaId,
                'ANO' => $year,
            ]);

            if ($response->successful()) {
                $data = $response->json();

                // Accedemos correctamente bajando un nivel en el payload del TSJ
                $diasRaw = $data['coleccion']['DIA'] ?? [];

                if (empty($diasRaw)) return [];

                // Si es un solo día (objeto asociativo directo que contiene la clave 'FECHA')
                // lo metemos dentro de una lista para que el foreach del comando no rompa.
                if (isset($diasRaw['FECHA'])) {
                    return [$diasRaw];
                }

                return $diasRaw;
            }
        } catch (\Exception $e) {
            Log::error("Error consultando días activos de la Sala #{$salaId} para el año {$year}: " . $e->getMessage());
        }

        return [];
    }

    /**
     * Descarga el listado de decisiones publicadas en una fecha exacta y para una sala dada
     */
    public function scrapeActiveDay(string $fecha, string $salaId, string $salaRealName, $output = null): int
    {
        try {
            $response = Http::withoutVerifying()->timeout(15)->get($this->baseUrl, [
                'p_p_id' => 'displayListaDecision_WAR_NoticiasTsjPorlet612',
                'p_p_lifecycle' => '2',
                'p_p_state' => 'normal',
                'p_p_mode' => 'view',
                'p_p_cacheability' => 'cacheLevelPage',
                'server[endpoint]' => '/services/WSDecision.HTTPEndpoint',
                'server[method]' => '/listDecisionByFechaSala',
                'FECHA' => $fecha,
                'SALA' => $salaId,
            ]);

            if (!$response->successful()) return 0;

            $data = $response->json();

            // Accedemos a la colección interna de sentencias devueltas
            $decisionesRaw = $data['coleccion']['DECISION'] ?? [];

            if (empty($decisionesRaw)) return 0;

            // Al igual que con los días, si hay una sola sentencia el TSJ devuelve un array asociativo directo
            if (isset($decisionesRaw['URL']) || isset($decisionesRaw['ID'])) {
                $decisionesRaw = [$decisionesRaw];
            }

            return $this->processJsonResults($decisionesRaw, $salaRealName, $output);
        } catch (\Exception $e) {
            Log::error("Fallo al procesar el día {$fecha} en Sala {$salaRealName}: " . $e->getMessage());
            return 0;
        }
    }

    /**
     * Procesa los resultados estructurados del JSON, extrae metadatos y guarda el contenido extendido
     */
    public function processJsonResults(array $decisiones, string $courtName, $output = null): int
    {
        $scrapedCount = 0;

        foreach ($decisiones as $decision) {
            // Mapeamos la URL de la sentencia (la API suele devolver la clave en mayúsculas 'URL')
            $decisionUrl = $decision['URL'] ?? null;

            if (!$decisionUrl) {
                continue;
            }

            // Control estricto de duplicados en PostgreSQL
            if (Sentence::where('url', $decisionUrl)->exists()) {
                continue;
            }

            // Troceado inteligente de la URL para extraer datos referenciales
            $urlParts = explode('-', basename($decisionUrl));
            $sentenceNumber = $decision['NUMERO'] ?? ($urlParts[1] ?? 'S/N');
            $caseNumber = $decision['EXPEDIENTE'] ?? ($urlParts[2] ?? 'S/E-' . uniqid());

            if ($output) {
                $output->warn("    💾 Descargando -> Exp: $caseNumber | Sentencia N° $sentenceNumber...");
            }

            // Extracción limpia desde las propiedades del objeto de la API del TSJ
            $procedure = $decision['PROCEDIMIENTO'] ?? null;
            $parts = $decision['PARTES'] ?? null;
            $decisionSummary = $decision['DECISION'] ?? null;
            $magistrate = $decision['PONENTE'] ?? null;

            // Crawling del documento HTML extendido (Aquí sí consumimos la web final)
            $fullContent = $this->downloadCleanContent($decisionUrl);

            // Persistencia de Datos
            Sentence::create([
                'url' => $decisionUrl,
                'case_number' => $caseNumber,
                'court' => $courtName,
                'content' => $fullContent,
                'metadata' => [
                    'sentence_number' => $sentenceNumber,
                    'procedure' => $procedure,
                    'parts' => $parts,
                    'decision_summary' => $decisionSummary,
                    'magistrate' => $magistrate,
                    'scraped_at' => now()->toDateTimeString(),
                ]
            ]);

            $scrapedCount++;

            // Delay estratégico anti-baneo de 700 milisegundos
            usleep(700000);
        }

        return $scrapedCount;
    }

    /**
     * Obtiene el texto limpio de la sentencia descartando headers, scripts y estilos
     */
    public function downloadCleanContent(string $url): string
    {
        try {
            $response = Http::withoutVerifying()->timeout(15)->get($url);
            if (!$response->successful()) return 'Error de comunicación con el repositorio central.';

            $html = $response->body();
            if (!mb_check_encoding($html, 'UTF-8')) {
                $html = mb_convert_encoding($html, 'UTF-8', 'ISO-8859-1');
            }

            $crawler = new Crawler($html);
            $crawler->filter('script, style, link, meta, header, footer, nav, table:first-of-type')->each(function (Crawler $node) {
                foreach ($node as $n) {
                    if ($n->parentNode) $n->parentNode->removeChild($n);
                }
            });

            if ($crawler->filter('body')->count() > 0) {
                return trim($crawler->filter('body')->text());
            }

            return trim($crawler->text());
        } catch (\Exception $e) {
            return 'No se pudo recuperar el cuerpo del documento por timeout o congestión de la red.';
        }
    }
}
