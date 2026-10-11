<?php

namespace App\Ai\Knowledge;

use App\Ai\Surface;
use Illuminate\Support\Str;

/**
 * A single Otto knowledge-base article (markdown + YAML frontmatter).
 */
final class KnowledgeArticle
{
    /**
     * @param  string[]  $areas
     * @param  Surface[]  $surfaces
     * @param  string[]  $permissions
     * @param  string[]  $features
     * @param  string[]  $keywords
     */
    public function __construct(
        public readonly string $slug,
        public readonly string $title,
        public readonly string $scope,
        public readonly array $areas,
        public readonly array $surfaces,
        public readonly array $permissions,
        public readonly array $features,
        public readonly string $summary,
        public readonly array $keywords,
        public readonly string $body,
    ) {}

    public static function fromMarkdown(string $slug, string $contents): self
    {
        $contents = str_replace("\r\n", "\n", $contents);

        $meta = [];
        $body = trim($contents);

        if (preg_match('/\A---\s*\n(.*?)\n---\s*\n?(.*)\z/s', $contents, $matches) === 1) {
            $meta = self::parseSimpleYaml(trim($matches[1]));
            $body = trim($matches[2]);
        }

        $scope = strtolower((string) ($meta['scope'] ?? 'global'));
        if (! in_array($scope, ['global', 'app'], true)) {
            $scope = 'global';
        }

        $summary = trim((string) ($meta['summary'] ?? ''));
        if ($summary === '') {
            $summary = self::deriveSummary($body);
        }

        return new self(
            slug: $slug,
            title: trim((string) ($meta['title'] ?? Str::headline($slug))),
            scope: $scope,
            areas: self::stringList($meta['areas'] ?? []),
            surfaces: self::surfaceList($meta['surfaces'] ?? []),
            permissions: self::stringList($meta['permissions'] ?? [], lower: false),
            features: self::stringList($meta['features'] ?? [], lower: false),
            summary: $summary,
            keywords: self::stringList($meta['keywords'] ?? []),
            body: $body,
        );
    }

    public function isGlobal(): bool
    {
        return $this->scope === 'global';
    }

    public function availableOnSurface(Surface $surface): bool
    {
        return $this->surfaces === [] || in_array($surface, $this->surfaces, true);
    }

    public function relevance(string $query): int
    {
        $tokens = self::tokenize($query);

        if ($tokens === []) {
            return 0;
        }

        $title = Str::lower($this->title);
        $keywords = Str::lower(implode(' ', $this->keywords));
        $areas = Str::lower(implode(' ', $this->areas));
        $summary = Str::lower($this->summary);
        $body = Str::lower($this->body);

        $score = 0;

        foreach ($tokens as $token) {
            if (str_contains($title, $token)) {
                $score += 8;
            }
            if (str_contains($keywords, $token)) {
                $score += 6;
            }
            if ($areas !== '' && str_contains($areas, $token)) {
                $score += 5;
            }
            if (str_contains($summary, $token)) {
                $score += 3;
            } elseif (str_contains($body, $token)) {
                $score += 1;
            }
        }

        if ($score > 0 && $this->scope === 'app') {
            $score += 2;
        }

        return $score;
    }

    /**
     * Minimal YAML subset for frontmatter (scalars + inline lists).
     *
     * @return array<string, mixed>
     */
    private static function parseSimpleYaml(string $yaml): array
    {
        $meta = [];

        foreach (explode("\n", $yaml) as $line) {
            if (! str_contains($line, ':')) {
                continue;
            }

            [$key, $value] = array_map('trim', explode(':', $line, 2));
            if ($key === '') {
                continue;
            }

            if (str_starts_with($value, '[') && str_ends_with($value, ']')) {
                $inner = trim($value, '[]');
                $meta[$key] = $inner === ''
                    ? []
                    : array_map(fn (string $item): string => trim($item, " \t\"'"), explode(',', $inner));

                continue;
            }

            $meta[$key] = trim($value, " \t\"'");
        }

        return $meta;
    }

    private static function deriveSummary(string $body): string
    {
        foreach (explode("\n", $body) as $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            return Str::limit($line, 180);
        }

        return '';
    }

    /**
     * @return string[]
     */
    private static function tokenize(string $value): array
    {
        $parts = preg_split('/[^a-z0-9]+/', Str::lower($value), flags: PREG_SPLIT_NO_EMPTY) ?: [];

        return array_values(array_unique(array_filter(
            $parts,
            fn (string $part): bool => strlen($part) >= 2,
        )));
    }

    /**
     * @return string[]
     */
    private static function stringList(mixed $value, bool $lower = true): array
    {
        if ($value === null || $value === '') {
            return [];
        }

        $items = is_array($value) ? $value : [$value];

        $items = array_map(
            fn ($item): string => $lower ? Str::lower(trim((string) $item)) : trim((string) $item),
            $items,
        );

        return array_values(array_filter($items, fn (string $item): bool => $item !== ''));
    }

    /**
     * @return Surface[]
     */
    private static function surfaceList(mixed $value): array
    {
        $surfaces = [];

        foreach (self::stringList($value) as $name) {
            $surface = Surface::tryFrom($name);

            if ($surface !== null) {
                $surfaces[] = $surface;
            }
        }

        return array_values(array_unique($surfaces, SORT_REGULAR));
    }
}
