<?php

declare(strict_types=1);

namespace App\Support\Otto;

/**
 * Replaces a streamed reply that contains code before the turn is released.
 *
 * The system prompt forbids scripts, queries, and shell commands. The model
 * can still answer with a fenced program anyway, the same way a zone question
 * can finish without a search. Prompt text does not close that gap, so the
 * chat turn checks the streamed text and overwrites the stored reply with a
 * plain-language refusal. The refusal never names tool classes. Plate numbers,
 * URLs, and ordinary sentences stay.
 */
final class CodeReplyTurn
{
    public const REFUSAL = 'I can’t write code, scripts, queries, or shell commands. Ask me in plain language what you want to find or how something works in Clarity, and I’ll help with that.';

    /**
     * Refusal that should replace a streamed answer containing code.
     * Null leaves the model reply in place.
     */
    public static function replacement(string $reply): ?string
    {
        if (! self::containsCode($reply)) {
            return null;
        }

        return self::REFUSAL;
    }

    public static function containsCode(string $reply): bool
    {
        $reply = str_replace(["\r\n", "\r"], "\n", $reply);

        if (preg_match('/```+|~~~+/', $reply) === 1) {
            return true;
        }

        $prose = (string) preg_replace('~https?://[^\s)>\]}]+~i', ' ', $reply);

        foreach (self::listingPatterns() as $pattern) {
            if (preg_match($pattern, $prose) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private static function listingPatterns(): array
    {
        return [
            '/^[ \t]*(?:async[ \t]+)?def[ \t]+\w+[ \t]*\(/m',
            '/^[ \t]*class[ \t]+\w+[ \t]*[:(]/m',
            '/^[ \t]*elif[ \t]+\S[^\n]*:\s*$/m',
            '/^[ \t]*except(?:[ \t]+\S[^\n]*)?:\s*$/m',
            '/^[ \t]*for[ \t]+\w+[ \t]+in[ \t]+[^\n]+:\s*$/m',
            '/^[ \t]*with[ \t]+(?:open[ \t]*\(|[^\n]+\bas\b)[^\n]*:\s*$/m',
            '/^[ \t]*import[ \t]+[a-zA-Z_][\w.]*(?:[ \t]+as[ \t]+[a-zA-Z_]\w*)?(?:[ \t]*,[ \t]*[a-zA-Z_][\w.]*(?:[ \t]+as[ \t]+[a-zA-Z_]\w*)?)*[ \t]*$/m',
            '/^[ \t]*from[ \t]+[a-zA-Z_][\w.]*[ \t]+import[ \t]+\S/m',
            '/^[ \t]*print[ \t]*\(/m',
            '/\bif\s+__name__\s*==\s*[\'"]__main__[\'"]/',
            '/^[ \t]*(?:export[ \t]+)?(?:async[ \t]+)?function[ \t]+\w+[ \t]*\(/m',
            '/^[ \t]*(?:const|let|var)[ \t]+\w+[ \t]*=[ \t]*\S/m',
            '/=>[ \t]*\{/',
            '/\bconsole\.(?:log|error|warn|debug)[ \t]*\(/',
            '/\brequire[ \t]*\([ \t]*[\'"][^\'"]+[\'"][ \t]*\)/',
            '/\bmodule\.exports\b/',
            '/<\?(?:php\b|=)/',
            '/\$this->[a-zA-Z_]\w*/',
            '/^[ \t]*(?:public|protected|private)[ \t]+function[ \t]+\w+/m',
            '/^[ \t]*namespace[ \t]+[A-Z][A-Za-z0-9_]*(?:\\\\[A-Z][A-Za-z0-9_]*)+;/m',
            '/\b[A-Za-z_]\w*\.[A-Za-z_]\w*[ \t]*\(/',
            '/\bSELECT\s+\*\s+FROM\b/i',
            '/\bSELECT\s+[\w."`]+(?:\s*,\s*[\w."`]+)+\s+FROM\b/i',
            '/\bSELECT\b[^\n]{0,240}\bFROM\b[^\n]{0,120}\bWHERE\s+(?!the\b|a\b|an\b|your\b|our\b)[\w."`]+/i',
            '/\bINSERT\s+INTO\s+[\w."`]+/i',
            '/\bUPDATE\s+[\w."`]+\s+SET\s+[\w."`]+\s*=/i',
            '/\bDELETE\s+FROM\s+[\w."`]+/i',
            '/\b(?:CREATE|DROP|ALTER)\s+TABLE\s+[\w."`]+/i',
            '/\bWITH\s+\w+\s+AS\s*\(\s*SELECT\b/i',
            '/\b(?:COUNT|SUM|AVG|MIN|MAX)\s*\(\s*(?:\*|[\w."`]+)\s*\)/i',
            '/^\s*SELECT\s+(?!(?:the|a|an|your|our|some|any)\b)\S+\s+FROM\s+(?!(?:the|a|an|your|our|yesterday|today|last|this)\b)\S+\s*;?\s*$/im',
            '/^\s*(?:\$\s*)?(?:sudo\s+)?(?:python3?|pip3?|node|npm|npx|php|bash|sh|composer)\s+(?:-\S+|\S+\.(?:py|js|php|sh|sql|csv)|artisan|install|run|require|test|exec)\b/m',
            '/^\s*#!\/(?:usr\/)?bin\/(?:env\s+)?(?:bash|sh|zsh|python|node|php)\b/m',
            '/\bpython3?\s+\S+\.py\b/',
            '/\bpip3?\s+install\b/',
            '/\bnpm\s+(?:install|run)\b/',
            '/\bphp\s+artisan\b/',
            '/\bchmod\s+[0-7+][0-7+x]*/',
        ];
    }
}
