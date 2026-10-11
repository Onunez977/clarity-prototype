<?php

declare(strict_types=1);

namespace App\Ai\Tools\Concerns;

use App\Support\Otto\LatestHitsWindow;
use App\Support\Otto\UserRequestedTimeRange;
use Carbon\Carbon;
use Laravel\Ai\Tools\Request;

/**
 * Shared Otto search date windows. Naive start/end values from the model are
 * interpreted in the user timezone (an explicit offset still wins). Date-only
 * values (e.g. "2026-08-25") expand to start/end of that local day. Results
 * are ISO-8601 with offset so ClickHouse/Postgres helpers cannot misread the
 * wall clock as UTC — the same contract Guardian Otto uses.
 */
trait ResolvesOttoDateRange
{
    /**
     * @return array{0: ?string, 1: ?string}
     */
    protected function resolveOttoRange(Request $request, string $tz, bool $defaultLast30Days = true): array
    {
        $tz = $this->ottoTimezone($tz);
        $request = $this->requestHonoringUserTimeRange($request, $tz);
        $range = strtolower(trim((string) ($request['time_range'] ?? '')));
        $now = Carbon::now($tz);

        $preset = match ($range) {
            'today' => [
                $now->copy()->startOfDay(),
                $now->copy()->endOfDay(),
            ],
            'yesterday' => [
                $now->copy()->subDay()->startOfDay(),
                $now->copy()->subDay()->endOfDay(),
            ],
            'last_7_days', '7d' => [
                $now->copy()->subDays(7)->startOfDay(),
                $now->copy()->endOfDay(),
            ],
            'last_30_days', '30d' => [
                $now->copy()->subDays(30)->startOfDay(),
                $now->copy()->endOfDay(),
            ],
            'last_90_days', '90d' => [
                $now->copy()->subDays(90)->startOfDay(),
                $now->copy()->endOfDay(),
            ],
            LatestHitsWindow::PRESET, '12h' => [
                $now->copy()->subHours(LatestHitsWindow::HOURS),
                $now->copy(),
            ],
            default => null,
        };

        if (is_array($preset)) {
            return [
                $preset[0]->toIso8601String(),
                $preset[1]->toIso8601String(),
            ];
        }

        $start = $this->normalizeOttoRangeStart($request['start_date'] ?? null, $tz);
        $end = $this->normalizeOttoRangeEnd($request['end_date'] ?? null, $tz);

        if ($start === null && $end === null && $defaultLast30Days) {
            return [
                $now->copy()->subDays(30)->startOfDay()->toIso8601String(),
                $now->copy()->endOfDay()->toIso8601String(),
            ];
        }

        if ($start !== null && $end !== null && $start->greaterThan($end)) {
            [$start, $end] = [$end, $start];
        }

        if ($end !== null && $end->isFuture() && ! $this->isOttoDateOnly(trim((string) ($request['end_date'] ?? '')))) {
            $end = $now->copy();
        }

        return [
            $start?->toIso8601String() ?? ($defaultLast30Days ? $now->copy()->subDays(30)->startOfDay()->toIso8601String() : null),
            $end?->toIso8601String() ?? ($defaultLast30Days ? $now->copy()->endOfDay()->toIso8601String() : null),
        ];
    }

    protected function normalizeOttoRangeStart(mixed $value, string $tz): ?Carbon
    {
        $parsed = $this->parseOttoDateTime($value, $tz);

        if ($parsed === null) {
            return null;
        }

        if ($this->isOttoDateOnly(trim((string) $value))) {
            return $parsed->startOfDay();
        }

        return $parsed;
    }

    protected function normalizeOttoRangeEnd(mixed $value, string $tz): ?Carbon
    {
        $parsed = $this->parseOttoDateTime($value, $tz);

        if ($parsed === null) {
            return null;
        }

        if ($this->isOttoDateOnly(trim((string) $value))) {
            return $parsed->endOfDay();
        }

        return $parsed;
    }

    protected function parseOttoDateTime(mixed $value, string $tz): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }

        $raw = trim((string) $value);
        $tz = $this->ottoTimezone($tz);

        try {
            return Carbon::parse($raw, $tz)->timezone($tz);
        } catch (\Throwable) {
            return null;
        }
    }

    protected function isOttoDateOnly(string $value): bool
    {
        return (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $value);
    }

    protected function ottoTimezone(string $timezone): string
    {
        return $timezone !== '' ? $timezone : 'UTC';
    }

    protected function requestHonoringUserTimeRange(Request $request, string $timezone): Request
    {
        if (! isset($this->investigation)) {
            return $request;
        }

        $prompt = $this->investigation->activePrompt();
        $arguments = UserRequestedTimeRange::applyToToolArguments(
            $request->all(),
            $prompt,
            $timezone,
        );

        if ($this->honorsLatestHitsWindow()) {
            $arguments = LatestHitsWindow::applyToToolArguments($arguments, $prompt);
        }

        return new Request($arguments);
    }

    /**
     * Hit search opts in so a bare “latest” is the last 12 hours. Scan search
     * leaves this false and keeps its own default.
     */
    protected function honorsLatestHitsWindow(): bool
    {
        return false;
    }

    protected function ottoTimeRangeSchemaDescription(): string
    {
        return 'Shortcut: today|yesterday|last_7_days|last_30_days|last_90_days (7d/30d/90d also accepted). Use a preset ONLY when the window is EXACTLY one of these. For any other relative window ("last 4 days", "last 2 hours") leave this unset and pass start_date + end_date. NEVER snap to the nearest preset.';
    }

    protected function ottoStartDateSchemaDescription(): string
    {
        return 'Start datetime as the user\'s wall-clock time (e.g. "2026-05-26T18:43:00"). Date-only values use start of day. Append a timezone offset only when the user names a zone (e.g. "2026-05-26T18:43:00-05:00"); otherwise omit it — do not convert between zones yourself.';
    }

    protected function ottoEndDateSchemaDescription(): string
    {
        return 'End datetime as the user\'s wall-clock time (e.g. "2026-06-12T15:00:00"). Date-only values use end of day. Append a timezone offset only when the user names a zone.';
    }
}
