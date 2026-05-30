<?php

namespace App\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SemanticSearchService
{
    /**
     * Search for semantically similar sentences, returning unique cases
     * with their full context for the LLM.
     *
     * @param  string  $query  The natural language query.
     * @param  int  $limit  Max number of unique sentences to return.
     * @return Collection<int, object{sentence_id: int, case_number: string, url: string, court: string, metadata: string, relevant_chunks: string, full_content: string}>
     */
    public function search(string $query, int $limit = 10): Collection
    {
        if (empty(trim($query))) {
            return collect();
        }

        // 1. Exact case number match — fast path
        $caseNumberMatch = DB::table('sentences')
            ->where('case_number', 'LIKE', "%{$query}%")
            ->limit($limit)
            ->get();

        if ($caseNumberMatch->isNotEmpty()) {
            return $this->enrichWithChunks($caseNumberMatch);
        }

        // 2. Vector similarity search — fetch more chunks to guarantee enough unique sentences
        $queryEmbedding = Str::of($query)->toEmbeddings(model: 'embed-multilingual-v3.0', provider: 'cohere');
        $vectorString = '['.implode(',', $queryEmbedding).']';

        // Fetch top 30 chunks to ensure we get at least $limit unique sentences
        $topChunks = DB::table('sentence_chunks')
            ->join('sentences', 'sentence_chunks.sentence_id', '=', 'sentences.id')
            ->select([
                'sentences.id as sentence_id',
                'sentences.case_number',
                'sentences.url',
                'sentences.court',
                'sentences.metadata',
                'sentence_chunks.content as chunk_content',
                DB::raw("(sentence_chunks.embedding <=> '{$vectorString}') as distance"),
            ])
            ->orderByRaw("sentence_chunks.embedding <=> '{$vectorString}' ASC")
            ->limit(30)
            ->get();

        if ($topChunks->isEmpty()) {
            return collect();
        }

        // 3. Group by sentence, keep the best (lowest distance) per sentence, and deduplicate
        $grouped = $topChunks->groupBy('sentence_id');

        $uniqueSentences = $grouped->map(function (Collection $chunks) {
            $best = $chunks->sortBy('distance')->first();

            // Collect the top 3 most relevant chunks for this sentence
            $relevantChunks = $chunks->sortBy('distance')
                ->take(3)
                ->pluck('chunk_content')
                ->implode("\n\n[...]\n\n");

            return (object) [
                'sentence_id' => $best->sentence_id,
                'case_number' => $best->case_number,
                'url' => $best->url,
                'court' => $best->court,
                'metadata' => $best->metadata,
                'distance' => $best->distance,
                'relevant_chunks' => $relevantChunks,
            ];
        })
            ->sortBy('distance')
            ->take($limit)
            ->values();

        // 4. Enrich each unique sentence with its full original content
        $sentenceIds = $uniqueSentences->pluck('sentence_id')->all();
        $fullContents = DB::table('sentences')
            ->whereIn('id', $sentenceIds)
            ->pluck('content', 'id');

        return $uniqueSentences->map(function ($s) use ($fullContents) {
            $s->full_content = $fullContents[$s->sentence_id] ?? '';

            return $s;
        });
    }

    /**
     * Enrich exact-match sentence results with their chunk data.
     */
    private function enrichWithChunks(Collection $sentences): Collection
    {
        $ids = $sentences->pluck('id')->all();

        // Get the first 3 chunks per sentence for relevant excerpts
        $chunks = DB::table('sentence_chunks')
            ->whereIn('sentence_id', $ids)
            ->orderBy('sentence_id')
            ->orderBy('chunk_index')
            ->get()
            ->groupBy('sentence_id');

        return $sentences->map(function ($s) use ($chunks) {
            $sentenceChunks = $chunks[$s->id] ?? collect();

            return (object) [
                'sentence_id' => $s->id,
                'case_number' => $s->case_number,
                'url' => $s->url,
                'court' => $s->court,
                'metadata' => $s->metadata,
                'relevant_chunks' => $sentenceChunks->take(3)->pluck('content')->implode("\n\n[...]\n\n"),
                'full_content' => $s->content,
            ];
        });
    }
}
