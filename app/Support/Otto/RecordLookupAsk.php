<?php

declare(strict_types=1);

namespace App\Support\Otto;

/**
 * Whether the user asked for live scans / hits / assignments / zones — not product help.
 *
 * KnowledgeBaseSearchTool uses this so a first-turn "today's scans" or
 * "what zones do I have?" ask cannot be answered from documentation
 * (or with "I have no scan search tool" / "I have no zone-search tool").
 */
final class RecordLookupAsk
{
    public static function matches(string $prompt): bool
    {
        $normalized = self::normalize($prompt);

        if ($normalized === '' || self::isHowToQuestion($normalized)) {
            return false;
        }

        if (self::isZoneInventory($prompt)) {
            return true;
        }

        if (! preg_match('/\b(scans?|hits?|alerts?|assignments?|hotlists?|wanted vehicles?)\b/', $normalized)) {
            return false;
        }

        return (bool) preg_match(
            '/\b(show|find|search|look up|lookup|get|pull|list|display|most recent|recent|latest|today|yesterday|this morning|this week|last \d+|last week|past week)\b/',
            $normalized,
        );
    }

    /**
     * Model-facing redirect when the knowledge-base tool is the wrong call.
     */
    public static function knowledgeBaseRedirect(string $prompt): string
    {
        if (self::isZoneInventory($prompt)) {
            return 'The user asked about their Scout patrol zones, not product help. Call ZoneSearch now. Do not say you lack a zone-search tool and do not tell them to open the Zones map to review existing zones.';
        }

        return 'The user asked for live records, not product help. Call ScanSearch, HitSearch, or HotlistSearch with their time range or plate. Do not say you lack a scan search tool and do not tell them to open another page.';
    }

    /**
     * A question about the user's existing zones, including "what zones do I have?".
     * Create / edit requests and product how-tos are not inventory asks.
     */
    public static function isZoneInventory(string $prompt): bool
    {
        $normalized = self::normalize($prompt);

        if ($normalized === '' || self::isHowToQuestion($normalized)) {
            return false;
        }

        return self::normalizedZoneInventory($normalized);
    }

    private static function normalizedZoneInventory(string $normalized): bool
    {
        if (preg_match('/\b(create|draw|add|make|update|edit|change|recolor|delete|remove|rename)\b/', $normalized) === 1) {
            return false;
        }

        return preg_match(
            '/\b(?:what|which|list|show|my|our|any)\b.{0,48}\bzones?\b|\bzones?\b.{0,48}\b(?:do i have|do we have|are there|assigned|no-scan|no scan)\b|\bno-scan zones?\b/',
            $normalized,
        ) === 1;
    }

    private static function isHowToQuestion(string $normalized): bool
    {
        if (! preg_match('/\b(what is|what\'s|whats|how do i|how does|how can|how to|explain|help me understand)\b/', $normalized)) {
            return false;
        }

        return ! preg_match('/\b(today|yesterday|recent|most recent|latest|this morning|this week|last \d+)\b/', $normalized);
    }

    private static function normalize(string $prompt): string
    {
        $prompt = mb_strtolower(trim($prompt));
        $prompt = str_replace(["\u{2019}", "\u{2018}", '`'], "'", $prompt);

        return preg_replace('/\s+/u', ' ', $prompt) ?? $prompt;
    }
}
