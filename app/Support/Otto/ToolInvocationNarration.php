<?php

declare(strict_types=1);

namespace App\Support\Otto;

/**
 * Detects and strips model-invented tool-call stand-ins such as
 * `[Calling ScanSearch with preset: "today"]`.
 *
 * Keep the `[Calling …]` pattern in lockstep with `stripToolCalls()` in
 * `resources/js/otto/lib/markdown.js`.
 */
final class ToolInvocationNarration
{
    public static function strip(string $content): string
    {
        return trim((string) preg_replace('/\[Calling\s+[^\]]+\]/i', '', $content));
    }

    public static function isNarrationOnly(string $content): bool
    {
        $stripped = trim((string) preg_replace(
            '/<!--otto_table:[^>]+-->/',
            '',
            self::strip($content),
        ));

        if ($stripped === '') {
            return true;
        }

        if (strlen($stripped) > 240) {
            return false;
        }

        return (bool) preg_match(
            '/^(?:let me |i(?:\'ll| will) )(?:pull up|look up|search|find|get|check)\b/i',
            $stripped,
        );
    }

    public static function withoutNarration(string $content): string
    {
        if (! self::isNarrationOnly($content)) {
            return self::strip($content);
        }

        preg_match_all('/<!--otto_table:[^>]+-->/', $content, $matches);

        return implode("\n\n", $matches[0] ?? []);
    }
}
