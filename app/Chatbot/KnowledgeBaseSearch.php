<?php

namespace App\Chatbot;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class KnowledgeBaseSearch
{
    /**
     * @return array{query: string, hits: array<int, array<string, mixed>>, count: int, store: string}
     */
    public function search(string $query, int $limit = 3): array
    {
        $chunks = $this->chunks();
        $tokens = $this->tokens($query);

        $hits = collect($chunks)
            ->map(function (array $chunk) use ($tokens): array {
                $content = (string) ($chunk['content'] ?? '');
                $title = (string) ($chunk['title'] ?? '');
                $haystack = $this->tokens($title.' '.$content);
                $score = $this->score($tokens, $haystack, $content);

                return [
                    'title' => $title,
                    'category' => $chunk['category'] ?? '',
                    'source_url' => $chunk['source_url'] ?? '',
                    'source_file' => $chunk['source_file'] ?? '',
                    'page' => $chunk['page'] ?? 0,
                    'content' => Str::limit($content, 1600),
                    'score' => round($score, 3),
                ];
            })
            ->filter(fn (array $hit): bool => $hit['score'] > 0)
            ->sortByDesc('score')
            ->take(max(1, min($limit, 6)))
            ->values()
            ->all();

        return [
            'query' => $query,
            'hits' => $hits,
            'count' => count($hits),
            'store' => 'Laravel JSON knowledge base',
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function chunks(): array
    {
        $path = base_path('temp/data/thy_knowledge_base.json');

        if (! File::exists($path)) {
            return [];
        }

        $knowledgeBase = json_decode((string) File::get($path), true);

        return is_array($knowledgeBase['chunks'] ?? null) ? $knowledgeBase['chunks'] : [];
    }

    /**
     * @return array<int, string>
     */
    private function tokens(string $text): array
    {
        $normalized = Str::of($text)
            ->lower()
            ->ascii()
            ->replaceMatches('/[^a-z0-9]+/', ' ')
            ->trim()
            ->value();

        if ($normalized === '') {
            return [];
        }

        return collect(explode(' ', $normalized))
            ->filter(fn (string $token): bool => strlen($token) >= 3)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  array<int, string>  $queryTokens
     * @param  array<int, string>  $documentTokens
     */
    private function score(array $queryTokens, array $documentTokens, string $content): float
    {
        if ($queryTokens === [] || $documentTokens === []) {
            return 0.0;
        }

        $documentLookup = array_flip($documentTokens);
        $matches = 0;

        foreach ($queryTokens as $token) {
            if (isset($documentLookup[$token])) {
                $matches++;
            }
        }

        $coverage = $matches / count($queryTokens);
        $density = $matches / max(count($documentTokens), 1);

        return $coverage + min($density * 8, 0.35) + (str_contains(Str::ascii(Str::lower($content)), implode(' ', $queryTokens)) ? 0.15 : 0);
    }
}
