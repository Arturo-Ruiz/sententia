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
        if (empty(trim($query))) return collect();

        $resultados = DB::table('sentence_chunks')->where('content', 'LIKE', '%' . $query . '%')
            ->limit($limit)
            ->get();

        if ($resultados->isNotEmpty()) return $resultados;

        $queryEmbedding = Str::of($query)->toEmbeddings(
            model: 'embed-multilingual-v3.0',
            provider: 'cohere'
        );

        // CORRECCIÓN: $queryEmbedding ya es un array, no necesita toArray()
        $vectorString = '[' . implode(',', $queryEmbedding) . ']';

        return DB::table('sentence_chunks')
            ->select(['id', 'sentence_id', 'content', DB::raw("1 - (embedding <=> '$vectorString') as similarity_score")])
            ->whereRaw("1 - (embedding <=> '$vectorString') > 0.3")
            ->orderByRaw("embedding <=> '$vectorString' ASC")
            ->limit($limit)
            ->get();
    }
}
