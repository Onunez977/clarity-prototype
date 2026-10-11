<?php

declare(strict_types=1);

namespace App\Support\Otto;

/**
 * Hit search only: “latest” with no explicit time range means the last 12 hours
 * (DEV-2698). Scan search does not call this — a bare “latest” there keeps the
 * existing default window.
 */
class LatestHitsWindow
{
    public const HOURS = 12;

    public const PRESET = 'last_12_hours';

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    public static function applyToToolArguments(array $arguments, ?string $prompt): array
    {
        if (! is_string($prompt) || ! self::applies($prompt)) {
            return $arguments;
        }

        unset($arguments['start_date'], $arguments['end_date']);
        $arguments['time_range'] = self::PRESET;

        return $arguments;
    }

    public static function applies(string $prompt): bool
    {
        $normalized = self::normalize($prompt);

        if ($normalized === '' || preg_match('/\blatest\b/u', $normalized) !== 1) {
            return false;
        }

        if (UserRequestedTimeRange::parse($prompt) !== null) {
            return false;
        }

        return ! self::namesExplicitWindow($normalized);
    }

    private static function namesExplicitWindow(string $normalized): bool
    {
        if (preg_match(
            '/\blatest\s+(?:\d+|a|an|one|two|three|four|five|six|seven|eight|nine|ten|eleven|twelve|twenty-four|thirty-six|forty-eight)\s+(?:days?|hours?|hrs?|weeks?|months?|minutes?|mins?)\b/u',
            $normalized,
        ) === 1) {
            return true;
        }

        if (preg_match('/\blatest\s+(?:week|month|night|morning|afternoon|evening)\b/u', $normalized) === 1) {
            return true;
        }

        return preg_match(
            '/\b(?:yesterday|tomorrow|today|tonight|this\s+(?:morning|afternoon|evening|week|month|year)|last\s+night|since|between|\d{4}-\d{2}-\d{2}|\d{1,2}[\/\-]\d{1,2}(?:[\/\-]\d{2,4})?|\d{1,2}(?::\d{2})?\s*(?:a\.?m\.?|p\.?m\.?)|monday|tuesday|wednesday|thursday|friday|saturday|sunday|january|february|march|april|june|july|august|september|october|november|december|jan|feb|mar|apr|jun|jul|aug|sep|sept|oct|nov|dec|may\s+\d{1,2})\b/u',
            $normalized,
        ) === 1;
    }

    private static function normalize(string $prompt): string
    {
        $prompt = mb_strtolower(trim($prompt));
        $prompt = str_replace(["\u{2019}", "\u{2018}", '`'], "'", $prompt);
        $prompt = preg_replace('/\s+/u', ' ', $prompt) ?? $prompt;

        return $prompt;
    }
}
