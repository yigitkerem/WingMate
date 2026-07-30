<?php

namespace App\Chatbot;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class KnowledgeBaseSearch
{
    /**
     * @var array<int, string>
     */
    private const array STOP_WORDS = [
        'about',
        'airline',
        'all',
        'and',
        'any',
        'are',
        'available',
        'can',
        'could',
        'does',
        'every',
        'flight',
        'flights',
        'for',
        'from',
        'have',
        'how',
        'included',
        'into',
        'is',
        'may',
        'more',
        'must',
        'offer',
        'offers',
        'on',
        'our',
        'please',
        'rule',
        'rules',
        'should',
        'tell',
        'that',
        'the',
        'their',
        'there',
        'this',
        'thy',
        'turkish',
        'what',
        'when',
        'where',
        'which',
        'with',
        'would',
        'you',
        'your',
        'me',
        'my',
        'bir',
        'bu',
        'icin',
        'ile',
        'mi',
        'nedir',
        'nasil',
        'olan',
        'olarak',
        've',
    ];

    /**
     * @var array<string, string>
     */
    private const array TOKEN_SYNONYMS = [
        'bag' => 'baggage',
        'bags' => 'baggage',
        'bagaj' => 'baggage',
        'luggage' => 'baggage',
        'cancel' => 'cancellation',
        'canceled' => 'cancellation',
        'cancelled' => 'cancellation',
        'cancelling' => 'cancellation',
        'cancellations' => 'cancellation',
        'compensate' => 'compensation',
        'compensated' => 'compensation',
        'delayed' => 'delay',
        'delays' => 'delay',
        'food' => 'catering',
        'meal' => 'catering',
        'meals' => 'catering',
        'refundable' => 'refund',
        'refunds' => 'refund',
        'reimbursement' => 'refund',
        'rights' => 'right',
    ];

    /**
     * @return array{query: string, hits: array<int, array<string, mixed>>, count: int, store: string}
     */
    public function search(string $query, int $limit = 3): array
    {
        $chunks = $this->chunks();
        $queryTokens = $this->expandedQueryTokens($query);
        $documents = collect($chunks)
            ->map(function (array $chunk): array {
                $content = (string) ($chunk['content'] ?? '');
                $title = (string) ($chunk['title'] ?? '');
                $category = (string) ($chunk['category'] ?? '');

                return [
                    'chunk' => $chunk,
                    'content' => $content,
                    'title_tokens' => $this->tokens($title.' '.$category),
                    'content_tokens' => $this->tokens($content),
                ];
            });
        $documentCount = max($documents->count(), 1);
        $documentFrequencies = $documents
            ->flatMap(fn (array $document): array => array_values(array_unique($document['content_tokens'])))
            ->countBy();

        $hits = $documents
            ->map(function (array $document) use ($documentCount, $documentFrequencies, $queryTokens): array {
                $chunk = $document['chunk'];
                $content = $document['content'];
                $score = $this->score(
                    $queryTokens,
                    $document['title_tokens'],
                    $document['content_tokens'],
                    $documentFrequencies->all(),
                    $documentCount,
                );

                return [
                    'title' => (string) ($chunk['title'] ?? ''),
                    'category' => $chunk['category'] ?? '',
                    'source_url' => $chunk['source_url'] ?? '',
                    'source_file' => $chunk['source_file'] ?? '',
                    'page' => $chunk['page'] ?? 0,
                    'content' => $this->excerpt($content, $queryTokens),
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
            ->replace(['wi-fi', 'wi fi'], 'wifi')
            ->replaceMatches('/[^a-z0-9]+/', ' ')
            ->trim()
            ->value();

        if ($normalized === '') {
            return [];
        }

        return collect(explode(' ', $normalized))
            ->filter(fn (string $token): bool => Str::length($token) >= 3)
            ->reject(fn (string $token): bool => in_array($token, self::STOP_WORDS, true))
            ->map(fn (string $token): string => self::TOKEN_SYNONYMS[$token] ?? $token)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return array<int, string>
     */
    private function expandedQueryTokens(string $query): array
    {
        $normalized = Str::of($query)->lower()->ascii()->toString();
        $tokens = collect($this->tokens($query));

        if (Str::contains($normalized, [
            'cancel',
            'compensation',
            'delay',
            'denied boarding',
            'passenger right',
            'iptal',
            'tazminat',
            'gecikme',
            'yolcu hakk',
        ])) {
            $tokens = $tokens->merge(['passenger', 'right']);
        }

        if (Str::contains($normalized, ['cancel', 'iptal'])) {
            $tokens = $tokens->push('iptal');
        }

        if (Str::contains($normalized, ['compensation', 'tazminat'])) {
            $tokens = $tokens->push('tazminat');
        }

        if (Str::contains($normalized, ['delay', 'gecikme'])) {
            $tokens = $tokens->push('gecikme');
        }

        if (Str::contains($normalized, ['baggage', 'luggage', 'bagaj'])) {
            $tokens = $tokens->push('bagaj');
        }

        if (Str::contains($normalized, ['check-in', 'check in', 'checkin'])) {
            $tokens = $tokens->merge(['kontrol', 'check']);
        }

        return $tokens->unique()->values()->all();
    }

    /**
     * @param  array<int, string>  $queryTokens
     * @param  array<int, string>  $titleTokens
     * @param  array<int, string>  $contentTokens
     * @param  array<string, int>  $documentFrequencies
     */
    private function score(
        array $queryTokens,
        array $titleTokens,
        array $contentTokens,
        array $documentFrequencies,
        int $documentCount,
    ): float {
        if ($queryTokens === [] || $contentTokens === []) {
            return 0.0;
        }

        $contentLookup = array_flip($contentTokens);
        $titleLookup = array_flip($titleTokens);
        $matches = collect($queryTokens)
            ->filter(fn (string $token): bool => isset($contentLookup[$token]) || isset($titleLookup[$token]))
            ->values()
            ->all();
        $minimumMatches = count($queryTokens) <= 2
            ? count($queryTokens)
            : max(2, (int) ceil(count($queryTokens) * 0.4));

        if (count($matches) < $minimumMatches) {
            return 0.0;
        }

        $queryWeight = collect($queryTokens)
            ->sum(fn (string $token): float => $this->inverseDocumentFrequency(
                (int) ($documentFrequencies[$token] ?? 0),
                $documentCount,
            ));
        $matchedWeight = collect($matches)
            ->sum(fn (string $token): float => $this->inverseDocumentFrequency(
                (int) ($documentFrequencies[$token] ?? 0),
                $documentCount,
            ));
        $coverage = $matchedWeight / max($queryWeight, 0.01);
        $titleBoost = collect($matches)
            ->filter(fn (string $token): bool => isset($titleLookup[$token]))
            ->count() * 0.12;
        $density = count($matches) / max(count($contentTokens), 1);

        return $coverage + $titleBoost + min($density * 10, 0.25);
    }

    private function inverseDocumentFrequency(int $documentFrequency, int $documentCount): float
    {
        return log(($documentCount + 1) / ($documentFrequency + 1)) + 1;
    }

    /**
     * @param  array<int, string>  $queryTokens
     */
    private function excerpt(string $content, array $queryTokens): string
    {
        $units = collect(preg_split('/(?:\R{1,}|(?<=[.!?])\s+)/u', $content) ?: [])
            ->map(fn (string $unit): string => Str::squish($unit))
            ->filter(fn (string $unit): bool => $unit !== '')
            ->values();

        if ($units->isEmpty() || $queryTokens === []) {
            return Str::limit(Str::squish($content), 700);
        }

        $bestIndex = $units
            ->keys()
            ->sortByDesc(function (int $index) use ($queryTokens, $units): float {
                $window = $units
                    ->slice(max(0, $index - 1), 4)
                    ->implode(' ');
                $windowTokens = array_flip($this->tokens($window));
                $matches = collect($queryTokens)
                    ->filter(fn (string $token): bool => isset($windowTokens[$token]))
                    ->count();

                return $matches + ($matches / max(count($this->tokens($window)), 1));
            })
            ->first();

        if (! is_int($bestIndex)) {
            return Str::limit(Str::squish($content), 700);
        }

        return Str::limit(
            $units->slice(max(0, $bestIndex - 1), 4)->implode(' '),
            700,
        );
    }
}
