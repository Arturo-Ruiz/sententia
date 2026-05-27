<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

#[Signature('app:chunk {limit=100 : Cantidad de sentencias a procesar por tanda}')]
#[Description('Procesa sentencias en alta velocidad usando las macros de embeddings de Laravel 13')]
class ProcessSentencesChunking extends Command
{
    /**
     * Execute the console command.
     */
    public function handle()
    {
        $limit = (int) $this->argument('limit');
        
        // 1. Traemos las sentencias pendientes
        $sentences = DB::table('sentences')
            ->leftJoin('sentence_chunks', 'sentences.id', '=', 'sentence_chunks.sentence_id')
            ->whereNull('sentence_chunks.id')
            ->select('sentences.id', 'sentences.content') 
            ->limit($limit)
            ->get();

        if ($sentences->isEmpty()) {
            $this->info('¡Todas las sentencias ya tienen sus embeddings listos!');
            return 0;
        }

        $this->info("🚀 L13 SDK: Indexando {$sentences->count()} sentencias vía Cohere...");

        foreach ($sentences as $sentence) {
            if (empty($sentence->content)) {
                continue;
            }

            // 2. Limpieza de caracteres y codificación
            $contentClean = mb_convert_encoding($sentence->content, 'UTF-8', 'UTF-8');
            $contentClean = preg_replace('/[\x00-\x1F\x7F]/', '', $contentClean);
            
            // 3. Troceado por palabras (~300 palabras por fragmento)
            $words = explode(' ', $contentClean);
            $rawChunks = array_chunk($words, 300); 

            $textToEmbed = [];
            foreach ($rawChunks as $chunkWords) {
                $text = trim(implode(' ', $chunkWords));
                if (!empty($text)) {
                    $textToEmbed[] = $text;
                }
            }

            if (empty($textToEmbed)) {
                continue;
            }

            $this->comment(" -> Sentencia ID {$sentence->id}: Generando embeddings para " . count($textToEmbed) . " chunks...");

            try {
                // 4. GENERACIÓN MEDIANTE MACRO NATIVA (Laravel AI SDK)
                // Usamos Str::of() sobre cada fragmento de texto para llamar a toEmbeddings()
                $embeddingsResult = [];
                foreach ($textToEmbed as $chunkText) {
                    // Especificamos el modelo multilingual de Cohere configurado en tu config/ai.php
                    $embeddingsResult[] = Str::of($chunkText)->toEmbeddings(
                        model: 'embed-multilingual-v3.0',
                        provider: 'cohere'
                    );
                }

                // 5. Inserción masiva atómica (Todo o nada) en Postgres
                DB::beginTransaction();
                
                foreach ($textToEmbed as $index => $chunkText) {
                    $embedding = $embeddingsResult[$index];
                    // Formateamos el array de floats de Laravel para el tipo pgvector
                    $vectorString = '[' . implode(',', $embedding) . ']';

                    DB::table('sentence_chunks')->insert([
                        'sentence_id' => $sentence->id,
                        'content' => $chunkText,
                        'chunk_index' => $index,
                        'embedding' => DB::raw("'$vectorString'"),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
                
                DB::commit();
                $this->info(" ✅ Sentencia ID {$sentence->id} procesada con éxito.");

            } catch (\Exception $e) {
                DB::rollBack();
                $this->error(" ❌ Error en la sentencia {$sentence->id}: " . $e->getMessage());
                
                // Mitigación rápida de red por ráfagas
                if (str_contains(strtolower($e->getMessage()), '429') || str_contains(strtolower($e->getMessage()), 'limit')) {
                    $this->warn("    Pausa de respiro de 3 segundos...");
                    sleep(3);
                }
                continue;
            }

            // Pausa imperceptible para balancear la carga de Docker
            usleep(10000);
        }

        $this->info('🎯 ¡Tanda masiva completada bajo los estándares de Laravel 13!');
        return 0;
    }
}