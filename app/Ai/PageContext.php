<?php

namespace App\Ai;

use App\Support\Otto\PageActionCopy;
use Illuminate\Support\Str;

/**
 * Optional page descriptor from the client. A page advertises its identity
 * ({@see $pageType}), a human-readable description, and the current relevant
 * state (active Filament filters). That descriptor rides alongside {@see Surface}:
 * the surface drives chat behaviour; this drives which page-specific tools
 * resolve and how Otto may propose filter changes.
 *
 * Request-scoped only — never persisted. The client supplies it as an untrusted
 * hint; it is NEVER used as an authorization boundary (tools re-check permissions
 * server-side). A missing/garbage payload yields null via {@see fromClient}.
 */
final class PageContext
{
    private const MAX_PAGE_TYPE_LENGTH = 64;

    private const MAX_DESCRIPTION_LENGTH = 500;

    /**
     * When the client sends a path-derived awareness fallback before the Filament
     * adapter has registered the canonical pageType, map that path onto the
     * adapter id so Apply* tools and DefersToPageSearch still resolve.
     *
     * @var array<string, string>
     */
    private const PATH_PAGE_TYPE_ALIASES = [
        '/app/scans' => 'scan-search',
        '/app/hits' => 'hit-search',
        '/app/hotlists' => 'hotlist-search',
        '/app/hotlist' => 'hotlist-search',
        '/scans' => 'scan-search',
        '/hits' => 'hit-search',
        '/hotlists' => 'hotlist-search',
        '/hotlist' => 'hotlist-search',
        '/scout/org-map' => 'scout-map',
    ];

    /**
     * @param  array<string, mixed>  $state
     */
    public function __construct(
        public readonly string $pageType,
        public readonly string $description = '',
        public readonly array $state = [],
    ) {}

    public static function fromClient(mixed $payload): ?self
    {
        if (! is_array($payload) || $payload === []) {
            return null;
        }

        $pageType = trim((string) ($payload['pageType'] ?? $payload['page_type'] ?? ''));

        if ($pageType === '') {
            return null;
        }

        $pageType = self::normalizePageType($pageType);

        $state = $payload['state'] ?? [];
        if (! is_array($state)) {
            $state = [];
        }

        return new self(
            pageType: Str::limit($pageType, self::MAX_PAGE_TYPE_LENGTH, ''),
            description: Str::limit(trim((string) ($payload['description'] ?? '')), self::MAX_DESCRIPTION_LENGTH, ''),
            state: $state,
        );
    }

    /**
     * Canonical pageType for a client-supplied id or known app path.
     */
    public static function normalizePageType(string $pageType): string
    {
        $pageType = rtrim(trim($pageType), '/');

        if ($pageType === '') {
            return '/';
        }

        return self::PATH_PAGE_TYPE_ALIASES[$pageType] ?? $pageType;
    }

    /**
     * A compact, human-readable rendering of the current page state for the system
     * prompt. Scalars and flat lists are shown inline; nested structures fall back
     * to a JSON blob so nothing is silently dropped.
     */
    public function stateSummary(): string
    {
        if ($this->state === []) {
            return '';
        }

        $lines = [];

        foreach ($this->state as $key => $value) {
            if ($value === null || $value === '' || $value === []) {
                continue;
            }

            $label = Str::headline((string) $key);

            if (is_scalar($value)) {
                $lines[] = '- '.$label.': '.$this->humanizeStateScalar((string) $key, (string) $value);

                continue;
            }

            if (is_array($value) && array_is_list($value) && $this->isFlatScalarList($value)) {
                $lines[] = '- '.$label.': '.implode(', ', array_map(fn ($item): string => (string) $item, $value));

                continue;
            }

            $lines[] = '- '.$label.': '.json_encode($value);
        }

        return implode("\n", $lines);
    }

    /**
     * Page-state values in the system prompt must not echo Filament/Otto slugs
     * that Otto could repeat to the user.
     */
    private function humanizeStateScalar(string $key, string $value): string
    {
        if ($key !== 'time_range') {
            return $value;
        }

        return PageActionCopy::timeRangePhrase($value) ?? $value;
    }

    /**
     * @param  array<int, mixed>  $value
     */
    private function isFlatScalarList(array $value): bool
    {
        foreach ($value as $item) {
            if (! is_scalar($item)) {
                return false;
            }
        }

        return true;
    }
}
