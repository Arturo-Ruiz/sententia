<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Laravel\Ai\Embeddings;
use Throwable;

#[Signature('app:chunk {limit=0} {offset=0} {--batch-size=96} {--include-partial}')]
#[Description('Index sentences with enriched metadata for chronological retrieval')]
class ProcessSentencesChunking extends Command
{
    private const MAX_COHERE_BATCH = 96;

    public function handle(): int
    {
        $limit = (int) $this->argument('limit');
        $offset = (int) $this->argument('offset');
        $batchSize = min((int) $this->option('batch-size'), self::MAX_COHERE_BATCH);

        $query = DB::table('sentences')
            ->whereNotExists(function ($q) {
                $q->select(DB::raw(1))
                    ->from('sentence_chunks')
                    ->whereColumn('sentence_chunks.sentence_id', 'sentences.id');
            })
            ->select('id', 'content', 'case_number', 'court', 'metadata')
            ->orderBy('id', 'asc');

        if ($this->option('include-partial')) {
            $query->orWhereExists(function ($q) {
                $q->select(DB::raw(1))
                    ->from('sentence_chunks')
                    ->whereColumn('sentence_chunks.sentence_id', 'sentences.id')
                    ->whereNull('sentence_chunks.embedding');
            });
        }

        if ($limit > 0) {
            $query->limit($limit);
        }
        if ($offset > 0) {
            $query->offset($offset);
        }

        $this->info('Calculando total de sentencias a procesar...');
        $totalSentencesToProcess = $query->count();

        if ($totalSentencesToProcess === 0) {
            $this->info('No hay sentencias pendientes por procesar.');

            return Command::SUCCESS;
        }

        $this->info("Procesando {$totalSentencesToProcess} sentencias...");

        $bar = $this->output->createProgressBar($totalSentencesToProcess);
        $bar->start();

        $apiBatch = [];
        $dbBuffer = [];
        $sentenceChunkCounts = [];
        $totalProcessedSentences = 0;
        $totalInsertedChunks = 0;

        foreach ($query->cursor() as $sentence) {
            // Limpiar chunks parciales existentes para re-procesamiento limpio
            if ($this->option('include-partial')) {
                DB::table('sentence_chunks')
                    ->where('sentence_id', $sentence->id)
                    ->delete();
            }

            $metadataArray = json_decode($sentence->metadata ?? '{}', true) ?? [];
            $metaString = '';
            foreach ($metadataArray as $k => $v) {
                $metaString .= "$k: $v | ";
            }
            $metaString = rtrim($metaString, ' | ');

            $enrichedHeader = "CASE: {$sentence->case_number} | COURT: {$sentence->court} | META: {$metaString}\n\nCONTENT: ";

            $cleanContent = preg_replace('/[\x00-\x1F\x7F]/', '', $sentence->content);
            $words = preg_split('/\s+/', trim($cleanContent));

            if (empty($words[0]) && count($words) === 1) {
                $totalProcessedSentences++;
                $bar->advance();

                continue;
            }

            // Chunking con overlap del 25% (300 palabras de tamaño, 75 de traslape)
            $chunkSize = 300;
            $overlap = 75;
            $wordChunks = [];
            $i = 0;
            $totalWords = count($words);

            while ($i < $totalWords) {
                $chunkWords = array_slice($words, $i, $chunkSize);
                $wordChunks[] = $chunkWords;
                $i += ($chunkSize - $overlap);

                if ($chunkSize <= $overlap) {
                    break;
                }
            }

            $sentenceChunkCounts[$sentence->id] = count($wordChunks);

            foreach ($wordChunks as $index => $chunkWords) {
                $chunkText = $enrichedHeader.implode(' ', $chunkWords);

                $apiBatch[] = [
                    'sentence_id' => $sentence->id,
                    'case_number' => $sentence->case_number,
                    'content' => $chunkText,
                    'chunk_index' => $index,
                ];

                if (count($apiBatch) >= $batchSize) {
                    $this->processApiBatch($apiBatch, $dbBuffer);
                    $apiBatch = [];

                    $totalInsertedChunks += $this->flushCompletedSentences($dbBuffer, $sentenceChunkCounts);
                }
            }

            $totalProcessedSentences++;
            $bar->advance();
        }

        if (count($apiBatch) > 0) {
            $this->processApiBatch($apiBatch, $dbBuffer);
        }

        $totalInsertedChunks += $this->flushCompletedSentences($dbBuffer, $sentenceChunkCounts, true);

        $bar->finish();
        $this->newLine();
        $this->info("✅ Listo. Procesadas {$totalProcessedSentences} sentencias e insertados {$totalInsertedChunks} chunks.");

        return Command::SUCCESS;
    }

    private function processApiBatch(array &$apiBatch, array &$dbBuffer): void
    {
        $texts = array_column($apiBatch, 'content');

        $retries = 3;
        while ($retries > 0) {
            try {
                $response = Embeddings::for($texts)
                    ->generate(provider: 'cohere', model: 'embed-multilingual-v3.0');
                break;
            } catch (Throwable $e) {
                $retries--;
                if ($retries === 0) {
                    $this->error("\nFallo crítico en API de Cohere: ".$e->getMessage());
                    $this->error('La ejecución abortará, pero ninguna sentencia quedará corrupta en la base de datos.');
                    throw $e;
                }
                $this->warn("\nLímite de API o error de red. Reintentando en 2 segundos...");
                sleep(2);
            }
        }

        $now = now();

        foreach ($apiBatch as $i => $chunk) {
            $vectorString = '['.implode(',', $response->embeddings[$i]).']';

            $dbBuffer[$chunk['sentence_id']][] = [
                'sentence_id' => $chunk['sentence_id'],
                'content' => $chunk['content'],
                'chunk_index' => $chunk['chunk_index'],
                'embedding' => DB::raw("'$vectorString'"),
                'created_at' => $now,
            ];
        }
    }

    private function flushCompletedSentences(array &$dbBuffer, array &$sentenceChunkCounts, bool $forceAll = false): int
    {
        $rowsToInsert = [];

        foreach ($dbBuffer as $sentenceId => $rows) {
            $expectedChunks = $sentenceChunkCounts[$sentenceId] ?? 0;

            if ($forceAll || count($rows) === $expectedChunks) {
                $rowsToInsert = array_merge($rowsToInsert, $rows);

                unset($dbBuffer[$sentenceId]);
                unset($sentenceChunkCounts[$sentenceId]);
            }
        }

        if (count($rowsToInsert) > 0) {
            DB::table('sentence_chunks')->insert($rowsToInsert);
        }

        return count($rowsToInsert);
    }
}
