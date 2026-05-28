<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

#[Signature('app:chunk {limit=100} {offset=0}')]
#[Description('Index sentences with enriched metadata for chronological retrieval')]
class ProcessSentencesChunking extends Command
{
    public function handle()
    {
        $limit = (int) $this->argument('limit');
        $offset = (int) $this->argument('offset');

        $sentences = DB::table('sentences')
            ->leftJoin('sentence_chunks', 'sentences.id', '=', 'sentence_chunks.sentence_id')
            ->whereNull('sentence_chunks.id')
            ->select('sentences.id', 'sentences.content', 'sentences.case_number', 'sentences.court', 'sentences.metadata')
            ->limit($limit)
            ->offset($offset)
            ->get();

        foreach ($sentences as $sentence) {
            $metadataArray = json_decode($sentence->metadata ?? '{}', true);
            $metaString = collect($metadataArray)->map(fn($v, $k) => "$k: $v")->implode(' | ');

            $enrichedHeader = "CASE: {$sentence->case_number} | COURT: {$sentence->court} | META: {$metaString}\n\nCONTENT: ";

            $chunks = array_chunk(explode(' ', preg_replace('/[\x00-\x1F\x7F]/', '', $sentence->content)), 300);

            DB::beginTransaction();
            foreach ($chunks as $index => $words) {
                $chunkText = $enrichedHeader . trim(implode(' ', $words));

                $embedding = Str::of($chunkText)->toEmbeddings(model: 'embed-multilingual-v3.0', provider: 'cohere');
                $vectorString = '[' . implode(',', $embedding) . ']';

                DB::table('sentence_chunks')->insert([
                    'sentence_id' => $sentence->id,
                    'content'     => $chunkText,
                    'chunk_index' => $index,
                    'embedding'   => DB::raw("'$vectorString'"),
                    'created_at'  => now(),
                ]);
            }
            DB::commit();
            $this->info("Indexed: {$sentence->case_number}");
        }
    }
}
