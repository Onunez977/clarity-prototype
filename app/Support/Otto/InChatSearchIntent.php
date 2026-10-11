<?php

namespace App\Support\Otto;

use App\Ai\PageContext;
use App\Ai\Tools\ApplyHitSearchFiltersTool;
use App\Ai\Tools\ApplyHotlistSearchFiltersTool;
use App\Ai\Tools\ApplyScanSearchFiltersTool;

/**
 * Deterministic detection of user prompts that must run a Scan/Hit/Hotlist
 * search in chat rather than proposing page filters.
 *
 * Used by OttoConversation::send to strip page context on scan-search / hit-search /
 * hotlist-search pages — the same server-gated escape hatch as "Make Otto
 * search instead", without a model-controlled tool argument.
 *
 * Ordinary asks ("search for plate ABC123") must NOT match.
 */
class InChatSearchIntent
{
    /**
     * @var list<string>
     */
    public const PHRASES = [
        'search yourself',
        'search in chat',
        'show in chat',
        'show the results here',
        'show results here',
        'results here in the chat',
        "don't change the page",
        'do not change the page',
        'dont change the page',
        'run it yourself',
        'run this search yourself',
        'run that search yourself',
        'run the search yourself',
        'instead of changing the page',
    ];

    public static function matches(string $prompt): bool
    {
        $normalized = self::normalize($prompt);

        if ($normalized === '') {
            return false;
        }

        foreach (self::PHRASES as $phrase) {
            if (str_contains($normalized, $phrase)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>|null  $pageContext
     */
    public static function shouldStripPageContext(string $prompt, ?array $pageContext): bool
    {
        if (! self::isDeferringSearchPage($pageContext)) {
            return false;
        }

        return self::matches($prompt);
    }

    /**
     * @param  array<string, mixed>|null  $pageContext
     */
    public static function isDeferringSearchPage(?array $pageContext): bool
    {
        if ($pageContext === null) {
            return false;
        }

        $pageType = PageContext::normalizePageType(
            trim((string) ($pageContext['pageType'] ?? $pageContext['page_type'] ?? '')),
        );

        return in_array($pageType, [
            ApplyScanSearchFiltersTool::PAGE_TYPE,
            ApplyHitSearchFiltersTool::PAGE_TYPE,
            ApplyHotlistSearchFiltersTool::PAGE_TYPE,
        ], true);
    }

    private static function normalize(string $prompt): string
    {
        $prompt = mb_strtolower(trim($prompt));
        $prompt = str_replace(["\u{2019}", "\u{2018}", '`'], "'", $prompt);
        $prompt = preg_replace('/\s+/u', ' ', $prompt) ?? $prompt;

        return $prompt;
    }
}
