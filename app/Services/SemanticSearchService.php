<?php

namespace App\Services;

use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SemanticSearchService
{
    /**
     * Search for semantically similar sentences using hybrid search and reranking,
     * returning unique cases with their full context for the LLM.
     *
     * @param  string  $query  The natural language query.
     * @param  int  $limit  Max number of unique sentences to return.
     * @param  string|null  $court  Optional court/sala filter (exact match).
     * @param  string|null  $dateFrom  Optional start date filter (YYYY-MM-DD).
     * @param  string|null  $dateTo  Optional end date filter (YYYY-MM-DD).
     * @return Collection<int, object{sentence_id: int, case_number: string, url: string, court: string, metadata: string, relevant_chunks: string, full_content: string}>
     */
    public function search(
        string $query,
        int $limit = 10,
        ?string $court = null,
        ?string $dateFrom = null,
        ?string $dateTo = null,
    ): Collection {
        if (empty(trim($query))) {
            return collect();
        }

        // 1. Fast path — búsqueda exacta por número de expediente
        $caseQuery = DB::table('sentences')
            ->where('case_number', 'LIKE', '%'.str_replace(['%', '_'], ['\%', '\_'], $query).'%')
            ->limit($limit);
        $this->applyFilters($caseQuery, $court, $dateFrom, $dateTo);

        $caseNumberMatch = $caseQuery->get();

        if ($caseNumberMatch->isNotEmpty()) {
            return $this->enrichWithChunks($caseNumberMatch);
        }

        // 2. Dense Retrieval — similitud vectorial con pgvector (HNSW)
        $queryEmbedding = Str::of($query)->toEmbeddings(model: 'embed-multilingual-v3.0', provider: 'cohere');
        $vectorString = '['.implode(',', $queryEmbedding).']';

        $vectorQuery = DB::table('sentence_chunks')
            ->join('sentences', 'sentence_chunks.sentence_id', '=', 'sentences.id')
            ->select([
                'sentences.id as sentence_id',
                'sentences.case_number',
                'sentences.url',
                'sentences.court',
                'sentences.metadata',
                'sentence_chunks.content as chunk_content',
            ])
            ->selectRaw('(sentence_chunks.embedding <=> ?::vector) as distance', [$vectorString])
            ->orderByRaw('sentence_chunks.embedding <=> ?::vector ASC', [$vectorString])
            ->limit(50);
        $this->applyFilters($vectorQuery, $court, $dateFrom, $dateTo, 'sentences');

        $topChunks = $vectorQuery->get();

        // 3. Sparse Retrieval — Full-Text Search con diccionario español y GIN Index
        $ftsQuery = DB::table('sentences')
            ->select(['id as sentence_id', 'case_number', 'url', 'court', 'metadata'])
            ->selectRaw("ts_rank(search_vector, plainto_tsquery('spanish', ?)) as rank", [$query])
            ->whereRaw("search_vector @@ plainto_tsquery('spanish', ?)", [$query])
            ->orderBy('rank', 'desc')
            ->limit(50);
        $this->applyFilters($ftsQuery, $court, $dateFrom, $dateTo);

        $ftsResults = $ftsQuery->get();

        // 4. Exact Match — ILIKE para nombres propios y términos exactos
        $ilikeQuery = DB::table('sentences')
            ->select(['id as sentence_id', 'case_number', 'url', 'court', 'metadata'])
            ->where('content', 'ILIKE', '%'.str_replace(['%', '_'], ['\%', '\_'], $query).'%')
            ->limit(30);
        $this->applyFilters($ilikeQuery, $court, $dateFrom, $dateTo);

        $ilikeResults = $ilikeQuery->get();

        if ($topChunks->isEmpty() && $ftsResults->isEmpty() && $ilikeResults->isEmpty()) {
            return collect();
        }

        // 5. Reciprocal Rank Fusion (RRF) con 3 señales — k=60 (paper original)
        $vectorRankMap = $this->buildVectorRankMap($topChunks);
        $ftsRankMap = $this->buildRankMap($ftsResults);
        $ilikeRankMap = $this->buildRankMap($ilikeResults);

        $allIds = array_unique(array_merge(
            array_keys($vectorRankMap),
            array_keys($ftsRankMap),
            array_keys($ilikeRankMap),
        ));

        $rrfCandidates = collect();

        foreach ($allIds as $id) {
            $vectorInfo = $vectorRankMap[$id] ?? null;
            $ftsInfo = $ftsRankMap[$id] ?? null;
            $ilikeInfo = $ilikeRankMap[$id] ?? null;

            $rrfScore = 0.0;
            if ($vectorInfo !== null) {
                $rrfScore += 1.0 / ($vectorInfo['rank'] + 60);
            }
            if ($ftsInfo !== null) {
                $rrfScore += 1.0 / ($ftsInfo['rank'] + 60);
            }
            if ($ilikeInfo !== null) {
                $rrfScore += 1.0 / ($ilikeInfo['rank'] + 60);
            }

            $info = $vectorInfo ?? $ftsInfo ?? $ilikeInfo;

            $rrfCandidates->push((object) [
                'sentence_id' => $id,
                'case_number' => $info['case_number'],
                'url' => $info['url'],
                'court' => $info['court'],
                'metadata' => $info['metadata'],
                'relevant_chunks' => $vectorInfo['relevant_chunks'] ?? '',
                'rrf_score' => $rrfScore,
            ]);
        }

        // Tomar los top-20 de la fusión para pasárselos al Reranker
        $topRrfCandidates = $rrfCandidates->sortByDesc('rrf_score')->take(20)->values();

        // 6. Cargar chunks relevantes para sentencias que llegaron por FTS/ILIKE (sin chunks vectoriales)
        $noChunkIds = $topRrfCandidates
            ->filter(fn ($c) => empty($c->relevant_chunks))
            ->pluck('sentence_id')
            ->all();

        if (! empty($noChunkIds)) {
            // Seleccionar los chunks más relevantes por similitud vectorial (no por orden de índice)
            $chunks = DB::table('sentence_chunks')
                ->whereIn('sentence_id', $noChunkIds)
                ->select(['sentence_id', 'content'])
                ->selectRaw('(embedding <=> ?::vector) as distance', [$vectorString])
                ->get()
                ->groupBy('sentence_id');

            foreach ($topRrfCandidates as $c) {
                if (empty($c->relevant_chunks)) {
                    $sentenceChunks = $chunks[$c->sentence_id] ?? collect();
                    $c->relevant_chunks = $sentenceChunks
                        ->sortBy('distance')
                        ->take(3)
                        ->pluck('content')
                        ->implode("\n\n[...]\n\n");
                }
            }
        }

        // 7. Reranking Semántico Avanzado (Cohere Rerank v3.5)
        if ($topRrfCandidates->isEmpty()) {
            return collect();
        }

        $reranked = $topRrfCandidates->rerank(
            by: function ($s) {
                return $s->relevant_chunks ?: "Expediente: {$s->case_number}";
            },
            query: $query,
            limit: $limit,
            provider: 'cohere',
            model: 'rerank-v3.5'
        )->values();

        // 8. Enriquecer los resultados finales con contenido original completo
        $sentenceIds = $reranked->pluck('sentence_id')->all();
        $fullContents = DB::table('sentences')
            ->whereIn('id', $sentenceIds)
            ->pluck('content', 'id');

        return $reranked->map(function ($s) use ($fullContents) {
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

    /**
     * Apply optional metadata filters to a query builder.
     */
    private function applyFilters(
        Builder $query,
        ?string $court,
        ?string $dateFrom,
        ?string $dateTo,
        ?string $table = null,
    ): void {
        $col = fn (string $name): string => $table ? "{$table}.{$name}" : $name;

        if ($court !== null) {
            $query->where($col('court'), $court);
        }

        if ($dateFrom !== null) {
            $query->where($col('decision_date'), '>=', $dateFrom);
        }

        if ($dateTo !== null) {
            $query->where($col('decision_date'), '<=', $dateTo);
        }
    }

    /**
     * Build a rank map from vector search results, grouping by sentence_id
     * and keeping the top-3 most relevant chunks per sentence.
     *
     * @return array<int, array{sentence_id: int, case_number: string, url: string, court: string, metadata: string, relevant_chunks: string, rank: int}>
     */
    private function buildVectorRankMap(Collection $topChunks): array
    {
        $grouped = $topChunks->groupBy('sentence_id');

        $candidates = $grouped->map(function (Collection $chunks) {
            $best = $chunks->sortBy('distance')->first();

            $relevantChunks = $chunks->sortBy('distance')
                ->take(3)
                ->pluck('chunk_content')
                ->implode("\n\n[...]\n\n");

            return [
                'sentence_id' => $best->sentence_id,
                'case_number' => $best->case_number,
                'url' => $best->url,
                'court' => $best->court,
                'metadata' => $best->metadata,
                'relevant_chunks' => $relevantChunks,
                'distance' => $best->distance,
            ];
        })->sortBy('distance')->values();

        $rankMap = [];
        foreach ($candidates as $rank => $candidate) {
            $rankMap[$candidate['sentence_id']] = array_merge($candidate, ['rank' => $rank]);
        }

        return $rankMap;
    }

    /**
     * Build a rank map from flat result sets (FTS or ILIKE).
     *
     * @return array<int, array{sentence_id: int, case_number: string, url: string, court: string, metadata: string, rank: int}>
     */
    private function buildRankMap(Collection $results): array
    {
        $rankMap = [];

        foreach ($results->values() as $rank => $result) {
            $rankMap[$result->sentence_id] = [
                'sentence_id' => $result->sentence_id,
                'case_number' => $result->case_number,
                'url' => $result->url,
                'court' => $result->court,
                'metadata' => $result->metadata,
                'rank' => $rank,
            ];
        }

        return $rankMap;
    }
}
