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

        $textResults = DB::table('sentence_chunks')
            ->join('sentences', 'sentence_chunks.sentence_id', '=', 'sentences.id')
            ->select('sentence_chunks.content', 'sentences.case_number', 'sentences.url', 'sentences.court', 'sentences.metadata', 'sentences.id as sentence_id')
            ->where('sentences.case_number', 'LIKE', "%$query%")
            ->orWhere('sentence_chunks.content', 'LIKE', "%$query%")
            ->limit($limit)
            ->get();

        if ($textResults->isNotEmpty()) return $textResults;

        $queryEmbedding = Str::of($query)->toEmbeddings(model: 'embed-multilingual-v3.0', provider: 'cohere');
        $vectorString = '[' . implode(',', $queryEmbedding) . ']';

        return DB::table('sentence_chunks')
            ->join('sentences', 'sentence_chunks.sentence_id', '=', 'sentences.id')
            ->select([
                'sentence_chunks.content',
                'sentences.case_number',
                'sentences.url',
                'sentences.court',
                'sentences.metadata',
                'sentences.id as sentence_id'
            ])
            ->whereRaw("1 - (sentence_chunks.embedding <=> '$vectorString') > 0.3")
            ->orderByRaw("sentence_chunks.embedding <=> '$vectorString' ASC")
            ->limit($limit)
            ->get();
    }
}
