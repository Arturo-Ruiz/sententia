<?php

namespace App\Services;

use App\Models\Sentence;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Symfony\Component\DomCrawler\Crawler;

class TsjScraperService
{
    private string $baseUrl = 'https://www.tsj.gob.ve/decisiones';

    private array $headers;

    public function __construct()
    {
        $this->headers = [
            'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
            'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8',
            'Accept-Language' => 'es-ES,es;q=0.9,en;q=0.8',
            'Connection' => 'keep-alive',
            'Cache-Control' => 'no-cache',
            'Pragma' => 'no-cache',
        ];
    }

    public function fetchSalas(): array
    {
        try {
            $response = Http::withoutVerifying()
                ->withHeaders($this->headers)
                ->timeout(300)
                ->get($this->baseUrl, [
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
            Log::error('Error al obtener salas de la API: '.$e->getMessage());
        }

        return [];
    }

    public function fetchActiveDays(string $salaId, string $year): array
    {
        try {
            $response = Http::withoutVerifying()
                ->withHeaders($this->headers)
                ->timeout(300)
                ->get($this->baseUrl, [
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

                if (! isset($data['coleccion']) || ! is_array($data['coleccion'])) {
                    return [];
                }

                $diasRaw = $data['coleccion']['DIA'] ?? $data['coleccion']['dia'] ?? [];

                if (empty($diasRaw)) {
                    return [];
                }

                if (is_array($diasRaw) && (isset($diasRaw['FECHA']) || isset($diasRaw['fecha']))) {
                    return [$diasRaw];
                }

                return is_array($diasRaw) ? $diasRaw : [];
            }
        } catch (\Exception $e) {
            Log::error("Error consultando días activos de la Sala #{$salaId}: ".$e->getMessage());
        }

        return [];
    }

    public function scrapeActiveDay(string $fecha, string $salaId, string $salaRealName, $output = null): int
    {
        try {
            $response = Http::withoutVerifying()
                ->withHeaders($this->headers)
                ->timeout(300)
                ->get($this->baseUrl, [
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

            if (! $response->successful()) {
                return 0;
            }

            $data = $response->json();

            if (! isset($data['coleccion']) || ! is_array($data['coleccion'])) {
                return 0;
            }

            $decisionesRaw = $data['coleccion']['SENTENCIA']
                ?? $data['coleccion']['sentencia']
                ?? $data['coleccion']['DECISION']
                ?? $data['coleccion']['decision']
                ?? [];

            if (empty($decisionesRaw)) {
                if (count($data['coleccion']) > 0 && ! isset($data['coleccion'][0])) {
                    $decisionesRaw = [$data['coleccion']];
                } else {
                    return 0;
                }
            }

            if (is_array($decisionesRaw) && (isset($decisionesRaw['SSENTID']) || isset($decisionesRaw['SSENTNOMBREDOC']))) {
                $decisionesRaw = [$decisionesRaw];
            }

            return $this->processJsonResults($decisionesRaw, $salaRealName, $output);
        } catch (\Exception $e) {
            Log::error("Fallo al procesar el día {$fecha} en Sala {$salaRealName}: ".$e->getMessage());

            return 0;
        }
    }

    public function processJsonResults(array $decisiones, string $courtName, $output = null): int
    {
        $scrapedCount = 0;
        $skipped = 0;

        $mesesEspanol = [
            '01' => 'enero',
            '02' => 'febrero',
            '03' => 'marzo',
            '04' => 'abril',
            '05' => 'mayo',
            '06' => 'junio',
            '07' => 'julio',
            '08' => 'agosto',
            '09' => 'septiembre',
            '10' => 'octubre',
            '11' => 'noviembre',
            '12' => 'diciembre',
        ];

        foreach ($decisiones as $index => $decision) {
            if (! is_array($decision)) {
                continue;
            }

            // 📌 SANITIZACIÓN ANTE RESIDUOS {"@nil": "true"} DE LA API
            $docName = $decision['SSENTNOMBREDOC'] ?? null;
            if (is_array($docName)) {
                $docName = null;
            }

            $salaDir = $decision['SSALADIR'] ?? null;
            if (is_array($salaDir)) {
                $salaDir = null;
            }

            $fechaRaw = $decision['DSENTFECHA'] ?? '';
            if (is_array($fechaRaw)) {
                $fechaRaw = '';
            }

            if (! $docName || $docName === 'null' || ! $salaDir || empty($fechaRaw)) {
                if ($output) {
                    $output->info('    ⏭️ Saltando registro #'.($index + 1).': Documento o ruta no disponible.');
                }
                $skipped++;

                continue;
            }

            $partsFecha = explode('/', $fechaRaw);
            $mesIndex = $partsFecha[1] ?? '01';
            $mesFolder = $mesesEspanol[$mesIndex] ?? 'enero';
            $decisionUrl = "{$this->baseUrl}/{$salaDir}/{$mesFolder}/{$docName}";

            if (Sentence::where('url', $decisionUrl)->exists()) {
                $skipped++;

                continue;
            }

            // 📌 EVITA EL ERROR "Array to string conversion" EN EL TRATAMIENTO DE STRINGS
            $sentenceNumber = $decision['SSENTNUMERO'] ?? null;
            if (is_array($sentenceNumber) || empty($sentenceNumber)) {
                $sentenceNumber = 'S/N';
            }

            $caseNumber = $decision['SSENTEXPEDIENTE'] ?? null;
            if (is_array($caseNumber) || empty($caseNumber)) {
                $caseNumber = 'S/E-'.uniqid();
            }

            $partsRaw = $decision['SSENTPARTES'] ?? '';
            $partsString = is_array($partsRaw) ? '' : (string) $partsRaw;
            $partsClean = trim(preg_replace('/\s+/', ' ', $partsString));

            $summaryRaw = $decision['SSENTDECISION'] ?? '';
            $summaryString = is_array($summaryRaw) ? '' : (string) $summaryRaw;

            // Saneamiento de campos que van a la metadata estructurada
            $procedure = $decision['SPROCDESCRIPCION'] ?? null;
            if (is_array($procedure)) {
                $procedure = null;
            }

            $magistrate = $decision['SPONENOMBRE'] ?? null;
            if (is_array($magistrate)) {
                $magistrate = null;
            }

            $decisionDate = null;
            if (! empty($fechaRaw) && ! is_array($fechaRaw)) {
                try {
                    $parts = explode('/', $fechaRaw);
                    if (count($parts) === 3) {
                        $decisionDate = "{$parts[2]}-{$parts[1]}-{$parts[0]}";
                    }
                } catch (\Exception $e) {
                    $decisionDate = null;
                }
            }

            if ($output) {
                $output->warn("    💾 Descargando -> Exp: $caseNumber | Sentencia N° $sentenceNumber...");
            }

            $fullContent = $this->downloadCleanContent($decisionUrl);

            Sentence::create([
                'url' => $decisionUrl,
                'case_number' => $caseNumber,
                'court' => $courtName,
                'content' => $fullContent,
                'decision_date' => $decisionDate,
                'metadata' => [
                    'sentence_number' => $sentenceNumber,
                    'procedure' => $procedure,
                    'parts' => $partsClean,
                    'decision_summary' => $summaryString,
                    'magistrate' => $magistrate,
                    'scraped_at' => now()->toDateTimeString(),
                ],
            ]);

            usleep(500000); // Rate limiting: 0.5s entre requests al TSJ

            $scrapedCount++;
        }

        return $scrapedCount;
    }

    public function downloadCleanContent(string $url): string
    {
        for ($attempt = 1; $attempt <= 3; $attempt++) {
            try {
                if (str_contains($url, 'www.tsj.gob.ve')) {
                    $url = str_replace('www.tsj.gob.ve', 'historico.tsj.gob.ve', $url);
                }

                $response = Http::withoutVerifying()
                    ->withHeaders($this->headers)
                    ->timeout(300)
                    ->get($url);

                if ($response->successful()) {
                    $rawBody = $response->body();
                    $encoding = mb_detect_encoding($rawBody, ['UTF-8', 'Windows-1252', 'ISO-8859-1'], true);
                    $html = mb_convert_encoding($rawBody, 'UTF-8', $encoding ?: 'Windows-1252');

                    $crawler = new Crawler($html);

                    // Limpieza de elementos innecesarios
                    $crawler->filter('script, style, link, meta, header, footer, nav, table:first-of-type, .portlet-boundary')->each(function (Crawler $node) {
                        foreach ($node as $n) {
                            if ($n->parentNode) {
                                $n->parentNode->removeChild($n);
                            }
                        }
                    });

                    $extractedText = '';
                    if ($crawler->filter('body')->count() > 0) {
                        $extractedText = trim($crawler->filter('body')->text());
                    } else {
                        $extractedText = trim($crawler->text());
                    }

                    if (! empty($extractedText)) {
                        return $extractedText;
                    }
                }
            } catch (\Exception $e) {
                Log::warning("Intento #{$attempt} fallido para URL: {$url}. Motivo: ".$e->getMessage());

                if ($attempt < 3) {
                    usleep(1500000 * $attempt);
                }
            }
        }

        return 'Error al procesar contenido (Encoding o Red).';
    }
}
