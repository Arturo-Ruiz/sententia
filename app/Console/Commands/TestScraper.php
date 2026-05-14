<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('app:test-scraper')]
#[Description('Command description')]
class TestScraper extends Command
{
    /**
     * Execute the console command.
     */
    public function handle()
    {
        $url = 'https://historico.tsj.gob.ve/decisiones/scs1/febrero/352951-026-26226-2026-25-023.HTML';
        $this->info("Test Scrapper with URL: $url");

        $scraper = new \App\Services\TsjScraperService();
        $result = $scraper->scrapeFromUrl($url);

        if ($result instanceof \App\Models\Sentence) {
            $this->info("Success sentence save: " . $result->case_number);
        } else {
            $this->error("Error saving sentence.");
        }
    }
}
