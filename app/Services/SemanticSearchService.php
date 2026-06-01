<?php

namespace App\Services;

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

        // 2. Búsqueda por similitud vectorial (Dense Retrieval en Postgres con pgvector)
        $queryEmbedding = Str::of($query)->toEmbeddings(model: 'embed-multilingual-v3.0', provider: 'cohere');
        $vectorString = '['.implode(',', $queryEmbedding).']';

        $topChunks = DB::table('sentence_chunks')
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
            ->limit(30)
            ->get();

        // 3. Búsqueda por Texto Completo (Sparse Retrieval en Postgres con GIN Index)
        $ftsResults = DB::table('sentences')
            ->select([
                'id as sentence_id',
                'case_number',
                'url',
                'court',
                'metadata',
            ])
            ->selectRaw("ts_rank(search_vector, plainto_tsquery('spanish', ?)) as rank", [$query])
            ->whereRaw("search_vector @@ plainto_tsquery('spanish', ?)", [$query])
            ->orderBy('rank', 'desc')
            ->limit(30)
            ->get();

        if ($topChunks->isEmpty() && $ftsResults->isEmpty()) {
            return collect();
        }

        // 4. Fusión de Resultados (Reciprocal Rank Fusion - RRF)
        // Agrupar chunks vectoriales por sentencia y conservar el de menor distancia
        $vectorGrouped = $topChunks->groupBy('sentence_id');
        $vectorCandidates = $vectorGrouped->map(function (Collection $chunks) {
            $best = $chunks->sortBy('distance')->first();

            // Coleccionar los top-3 chunks más relevantes
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

        // Mapear resultados vectoriales a su rango
        $vectorRankMap = [];
        foreach ($vectorCandidates as $rank => $candidate) {
            $vectorRankMap[$candidate['sentence_id']] = array_merge($candidate, ['rank' => $rank]);
        }

        // Mapear resultados FTS a su rango
        $ftsRankMap = [];
        foreach ($ftsResults->values() as $rank => $result) {
            $ftsRankMap[$result->sentence_id] = [
                'sentence_id' => $result->sentence_id,
                'case_number' => $result->case_number,
                'url' => $result->url,
                'court' => $result->court,
                'metadata' => $result->metadata,
                'rank' => $rank,
            ];
        }

        // Fusionar rankings calculando el RRF Score
        $rrfCandidates = collect();
        $allIds = array_unique(array_merge(array_keys($vectorRankMap), array_keys($ftsRankMap)));

        foreach ($allIds as $id) {
            $vectorInfo = $vectorRankMap[$id] ?? null;
            $ftsInfo = $ftsRankMap[$id] ?? null;

            $rrfScore = 0.0;
            if ($vectorInfo !== null) {
                $rrfScore += 1.0 / ($vectorInfo['rank'] + 60);
            }
            if ($ftsInfo !== null) {
                $rrfScore += 1.0 / ($ftsInfo['rank'] + 60);
            }

            $info = $vectorInfo ?? $ftsInfo;

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

        // Tomar los top-15 de la fusión para pasárselos al Reranker
        $topRrfCandidates = $rrfCandidates->sortByDesc('rrf_score')->take(15)->values();

        // 5. Cargar chunks para sentencias que solo se encontraron por FTS
        $ftsOnlyIds = $topRrfCandidates->filter(fn ($c) => empty($c->relevant_chunks))->pluck('sentence_id')->all();
        if (! empty($ftsOnlyIds)) {
            $chunks = DB::table('sentence_chunks')
                ->whereIn('sentence_id', $ftsOnlyIds)
                ->orderBy('sentence_id')
                ->orderBy('chunk_index')
                ->get()
                ->groupBy('sentence_id');

            foreach ($topRrfCandidates as $c) {
                if (empty($c->relevant_chunks)) {
                    $sentenceChunks = $chunks[$c->sentence_id] ?? collect();
                    $c->relevant_chunks = $sentenceChunks->take(3)->pluck('content')->implode("\n\n[...]\n\n");
                }
            }
        }

        // 6. Reranking Semántico Avanzado (Cohere Rerank)
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

        // 7. Enriquecer los resultados finales con su contenido original completo
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
