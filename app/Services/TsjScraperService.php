<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Symfony\Component\DomCrawler\Crawler;

use App\Models\Sentence;

class TsjScraperService
{
    /**
     * Create a new class instance.
     */
    public function __construct()
    {
        //
    }

    public function scrapeFromUrl($url)
    {
        $response = Http::withoutVerifying()->get($url);

        if (!$response->successful()) {
            return "Error al conectar con el TSJ";
        }

        $crawler = new Crawler($response->body());


        $caseNumber = $this->extractByText($crawler, 'N° de Expediente');
        $sentenceNumber = $this->extractByText($crawler, 'N° de Sentencia');
        $magistrate = $this->extractByText($crawler, 'Ponente');

        $content = $crawler->filter('body')->text();

        return Sentence::updateOrCreate(
            ['url' => $url],
            [
                'case_number' => $caseNumber ?? 'Desconocido',
                'court' => 'Sala de Casación Penal',
                'content' => $content,
                'metadata' => [
                    'sentence_number' => $sentenceNumber,
                    'magistrate' => $magistrate,
                    'scraped_at' => now()->toDateTimeString(),
                ]
            ]
        );
    }

    private function extractByText(Crawler $crawler, $text)
    {
        try {
            return trim($crawler->filter("td:contains('$text')")->nextAll()->first()->text());
        } catch (\Exception $e) {
            return null;
        }
    }
}
