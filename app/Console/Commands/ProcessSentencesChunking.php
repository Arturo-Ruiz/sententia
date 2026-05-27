<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

#[Signature('app:chunk {limit=100 : Cantidad de sentencias a procesar por tanda}')]
#[Description('Pica las sentencias judiciales en bloques y genera sus embeddings con DeepSeek')]
class ProcessSentencesChunking extends Command
{
    /**
     * Execute the console command.
     */
    public function handle()
    {
        $limit = (int) $this->argument('limit');
        
        // 1. Buscamos sentencias que aún no tengan fragmentos procesados
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

        $apiKey = env('DEEPSEEK_API_KEY');
        
        if (empty($apiKey)) {
            $this->error('🚨 Error: No se encontró la variable DEEPSEEK_API_KEY en tu archivo .env');
            return 1;
        }

        $this->info("Procesando {$sentences->count()} sentencias directamente vía HTTP...");

        foreach ($sentences as $sentence) {
            if (empty($sentence->content)) {
                continue;
            }

            // 2. Limpieza de codificación para evitar strings corruptos
            $contentClean = mb_convert_encoding($sentence->content, 'UTF-8', 'UTF-8');
            $contentClean = preg_replace('/[\x00-\x1F\x7F]/', '', $contentClean);

            // 3. Troceado limpio por palabras (Aprox. 250 palabras por fragmento)
            $words = explode(' ', $contentClean);
            $chunks = array_chunk($words, 250);

            foreach ($chunks as $index => $chunkWords) {
                $chunkText = implode(' ', $chunkWords);
                
                if (blank($chunkText)) {
                    continue;
                }

                try {
                    // 4. Petición HTTP a la URL correcta de DeepSeek (/v1/embeddings)
                    $response = Http::withToken($apiKey)
                        ->timeout(15)
                        ->withHeaders([
                            'Content-Type' => 'application/json',
                            'Accept' => 'application/json'
                        ])
                        ->post('https://api.deepseek.com/v1/embeddings', [
                            'model' => 'deepseek-embedding',
                            'input' => $chunkText,
                        ]);

                    if ($response->failed()) {
                        $this->error("🚨 HTTP STATUS: " . $response->status());
                        $this->error("🚨 CUERPO: " . ($response->body() ?: 'Vacio/Null'));
                        throw new \Exception("API Error");
                    }

                    $responseData = $response->json();
                    
                    if (!isset($responseData['data'][0]['embedding'])) {
                        throw new \Exception("Estructura de respuesta inesperada");
                    }

                    $embedding = $responseData['data'][0]['embedding'];
                    
                    // Convertimos el array de números a formato string de pgvector: '[0.12, -0.34, ...]'
                    $vectorString = '[' . implode(',', $embedding) . ']';

                    // 5. Guardamos el fragmento y su vector en la base de datos de Docker
                    DB::table('sentence_chunks')->insert([
                        'sentence_id' => $sentence->id,
                        'content' => $chunkText,
                        'chunk_index' => $index,
                        'embedding' => DB::raw("'$vectorString'"),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);

                    // ⏱️ Pausa de cortesía (300ms) para respetar el rate-limit de la API
                    usleep(300000);

                } catch (\Exception $e) {
                    $this->error("Error en fragmento {$index} de la sentencia ID {$sentence->id}: " . $e->getMessage());
                    continue;
                }
            }
            $this->comment("Sentencia ID {$sentence->id} procesada con éxito.");
        }

        $this->info('¡Tanda completada!');
        return 0;
    }
}