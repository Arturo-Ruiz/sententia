<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SemanticSearchService
{
    /**
     * Create a new class instance.
     */
    public function __construct()
    {
        //
    }

    public function search(string $query, int $limit = 5)
    {
        if (empty(trim($query))) {
            return collect();
        }

        $queryEmbedding = Str::of($query)->toEmbeddings(
            model: 'embed-multilingual-v3.0',
            provider: 'cohere'
        );

        $vectorString = '[' . implode(',', $queryEmbedding) . ']';

        return DB::table('sentence_chunks')
            ->join('sentences', 'sentence_chunks.sentence_id', '=', 'sentences.id')
            ->select([
                'sentences.id as sentence_id',
                'sentence_chunks.content as chunk_content',
                'sentence_chunks.chunk_index',
                DB::raw("1 - (sentence_chunks.embedding <=> '$vectorString') as similarity_score")
            ])
            ->orderByRaw("sentence_chunks.embedding <=> '$vectorString'")
            ->limit($limit)
            ->get();
    }
}
