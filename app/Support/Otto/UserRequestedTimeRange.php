<?php

declare(strict_types=1);

namespace App\Support\Otto;

use Carbon\Carbon;

/**
 * Parses a relative / "today" time window from the user's prompt and forces tool
 * arguments to honor it exactly — never the model's nearest preset snap
 * (e.g. "last 6 days" must not become time_range=last_7_days).
 *
 * Exact preset matches (today / yesterday / last_7_days / last_30_days /
 * last_90_days, plus "last week"→7 days and "last month"→30 days) keep the
 * preset value; every other relative window becomes a custom start_date+end_date
 * computed from now in the user's zone.
 */
class UserRequestedTimeRange
{
    /**
     * @var array<string, int>
     */
    private const WORD_AMOUNTS = [
        'a' => 1,
        'an' => 1,
        'one' => 1,
        'two' => 2,
        'three' => 3,
        'four' => 4,
        'five' => 5,
        'six' => 6,
        'seven' => 7,
        'eight' => 8,
        'nine' => 9,
        'ten' => 10,
        'eleven' => 11,
        'twelve' => 12,
        'thirteen' => 13,
        'fourteen' => 14,
        'fifteen' => 15,
        'sixteen' => 16,
        'seventeen' => 17,
        'eighteen' => 18,
        'nineteen' => 19,
        'twenty' => 20,
        'twenty-one' => 21,
        'twenty-four' => 24,
        'twenty-eight' => 28,
        'thirty' => 30,
        'thirty-six' => 36,
        'forty' => 40,
        'forty-five' => 45,
        'forty-eight' => 48,
        'sixty' => 60,
        'ninety' => 90,
    ];

    /**
     * Day counts that map onto Otto search presets.
     *
     * @var array<int, string>
     */
    private const PRESET_DAYS = [
        7 => 'last_7_days',
        30 => 'last_30_days',
        90 => 'last_90_days',
    ];

    public function __construct(
        public readonly ?string $preset,
        public readonly ?int $amount,
        public readonly ?string $unit,
    ) {}

    public static function parse(string $prompt): ?self
    {
        $normalized = self::normalize($prompt);

        if ($normalized === '') {
            return null;
        }

        $amountAlternation = self::amountAlternation();

        if (preg_match(
            '/(?:(?:in|over|during|for|within)\s+)?(?:the\s+)?(?:last|past|previous)\s+('.$amountAlternation.')\s+(days?|hours?|hrs?|weeks?|months?)\b/u',
            $normalized,
            $matches,
        ) === 1) {
            $amount = self::parseAmount($matches[1]);
            $unit = self::normalizeUnit($matches[2]);

            if ($amount !== null && $unit !== null) {
                return self::fromAmountAndUnit($amount, $unit);
            }
        }

        if (preg_match(
            '/(?:(?:in|over|during|for|within)\s+)?(?:the\s+)?(?:last|past|previous)\s+(week|month)\b/u',
            $normalized,
            $matches,
        ) === 1) {
            return $matches[1] === 'week'
                ? new self('last_7_days', null, null)
                : new self('last_30_days', null, null);
        }

        if (self::mentionsYesterdayThroughToday($normalized)) {
            return new self(null, null, 'yesterday_through_today');
        }

        if (preg_match('/\b(?:for\s+|from\s+|through\s+|as\s+of\s+)?today\b/u', $normalized) === 1) {
            return new self('today', null, null);
        }

        return null;
    }

    /**
     * Rewrite tool arguments so a parsed user window wins over the model's
     * time_range / start_date / end_date. Unchanged when the prompt has no
     * clear relative/"today" window.
     *
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    public static function applyToToolArguments(array $arguments, ?string $prompt, string $timezone): array
    {
        if (! is_string($prompt) || trim($prompt) === '') {
            return $arguments;
        }

        $requested = self::parse($prompt);
        if ($requested === null) {
            return $arguments;
        }

        if (is_string($requested->preset) && $requested->preset !== '') {
            $arguments['time_range'] = $requested->preset;
            unset($arguments['start_date'], $arguments['end_date']);

            return $arguments;
        }

        [$start, $end] = $requested->bounds($timezone);
        unset($arguments['time_range']);
        $arguments['start_date'] = $start->toIso8601String();
        $arguments['end_date'] = $end->toIso8601String();

        return $arguments;
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    public function bounds(string $timezone): array
    {
        $timezone = $timezone !== '' ? $timezone : 'UTC';
        $end = Carbon::now($timezone);
        $start = $end->copy();

        if ($this->preset === 'today') {
            $start = $end->copy()->startOfDay();

            return [$start, $end];
        }

        if ($this->preset === 'yesterday') {
            $start = $end->copy()->subDay()->startOfDay();
            $end = $end->copy()->subDay()->endOfDay();

            return [$start, $end];
        }

        if ($this->preset !== null) {
            $days = match ($this->preset) {
                'last_7_days' => 7,
                'last_30_days' => 30,
                'last_90_days' => 90,
                default => (int) $this->preset,
            };
            $start = $end->copy()->subDays($days);

            return [$start, $end];
        }

        if ($this->unit === 'yesterday_through_today') {
            $start = $end->copy()->subDay()->startOfDay();

            return [$start, $end];
        }

        $amount = max(1, (int) $this->amount);

        $start = match ($this->unit) {
            'hours' => $end->copy()->subHours($amount),
            'weeks' => $end->copy()->subWeeks($amount),
            'months' => $end->copy()->subMonths($amount),
            default => $end->copy()->subDays($amount),
        };

        return [$start, $end];
    }

    private static function mentionsYesterdayThroughToday(string $normalized): bool
    {
        return preg_match(
            '/\byesterday\b(?:\s*(?:through|to|until|thru|-|–|—|,|and)\s*|\s+)\btoday\b/u',
            $normalized,
        ) === 1
            || preg_match(
                '/\b(?:from|between)\s+yesterday\b.{0,40}\btoday\b/u',
                $normalized,
            ) === 1;
    }

    private static function fromAmountAndUnit(int $amount, string $unit): self
    {
        if ($unit === 'days' && isset(self::PRESET_DAYS[$amount])) {
            return new self(self::PRESET_DAYS[$amount], null, null);
        }

        if ($unit === 'weeks' && $amount === 1) {
            return new self('last_7_days', null, null);
        }

        if ($unit === 'months' && $amount === 1) {
            return new self('last_30_days', null, null);
        }

        return new self(null, $amount, $unit);
    }

    private static function parseAmount(string $token): ?int
    {
        if (ctype_digit($token)) {
            $amount = (int) $token;

            return $amount > 0 ? $amount : null;
        }

        return self::WORD_AMOUNTS[$token] ?? null;
    }

    private static function normalizeUnit(string $unit): ?string
    {
        $unit = rtrim($unit, 's');

        return match ($unit) {
            'day' => 'days',
            'hour', 'hr' => 'hours',
            'week' => 'weeks',
            'month' => 'months',
            default => null,
        };
    }

    private static function amountAlternation(): string
    {
        static $alternation = null;

        if (is_string($alternation)) {
            return $alternation;
        }

        $words = array_keys(self::WORD_AMOUNTS);
        usort($words, fn (string $a, string $b): int => strlen($b) <=> strlen($a));
        $wordPart = implode('|', array_map(
            fn (string $word): string => preg_quote($word, '/'),
            $words,
        ));

        $alternation = '\d+|'.$wordPart;

        return $alternation;
    }

    private static function normalize(string $prompt): string
    {
        $prompt = mb_strtolower(trim($prompt));
        $prompt = str_replace(["\u{2019}", "\u{2018}", '`'], "'", $prompt);
        $prompt = preg_replace('/\s+/u', ' ', $prompt) ?? $prompt;

        return $prompt;
    }
}
