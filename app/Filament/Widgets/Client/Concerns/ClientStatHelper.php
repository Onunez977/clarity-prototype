<?php

namespace App\Filament\Widgets\Client\Concerns;

use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

trait ClientStatHelper
{
    protected function getDateRangeForType(string $dateType, mixed $startDate = null, mixed $endDate = null, ?string $timezone = null): array
    {
        $now = now()->setTimezone($timezone ?? auth()->user()?->timezone ?? 'UTC');

        return match ($dateType) {
            'ytd' => [
                'start' => $now->copy()->startOfYear()->startOfDay(),
                'end' => $now,
            ],
            'last_30d' => [
                'start' => $now->copy()->subDays(30)->startOfDay(),
                'end' => $now,
            ],
            'last_90d' => [
                'start' => $now->copy()->subDays(90)->startOfDay(),
                'end' => $now,
            ],
            'custom' => [
                'start' => Carbon::parse($startDate, $timezone),
                'end' => Carbon::parse($endDate, $timezone),
            ],
            default => [
                'start' => $now->copy()->startOfMonth()->startOfDay(),
                'end' => $now,
            ],
        };
    }

    protected function getCacheTTL(int $defaultMinutes = 10): \DateTimeInterface|\DateInterval|int
    {
        if (config('app.env') === 'production') {
            if (session()->has('admin_impersonator')) {
                return now()->addSeconds(10);
            }

            return now()->addMinutes($defaultMinutes);
        }

        return now()->addSeconds(5);
    }

    protected function getPreviousPeriodDates(string $dateType, ?string $timezone = null): ?array
    {
        $tz = $timezone ?? auth()->user()?->timezone ?? 'UTC';
        $now = now()->setTimezone($tz);

        return match ($dateType) {
            'this_month' => [
                'start' => $now->copy()->startOfMonth()->subMonth()->startOfDay()->toDateTimeString(),
                'end' => $now->copy()->subMonthNoOverflow()->toDateTimeString(),
            ],
            'ytd' => [
                'start' => $now->copy()->subYear()->startOfYear()->startOfDay()->toDateTimeString(),
                'end' => $now->copy()->subYear()->toDateTimeString(),
            ],
            'last_30d' => [
                'start' => $now->copy()->subDays(60)->startOfDay()->toDateTimeString(),
                'end' => $now->copy()->subDays(30)->toDateTimeString(),
            ],
            'last_90d' => [
                'start' => $now->copy()->subDays(180)->startOfDay()->toDateTimeString(),
                'end' => $now->copy()->subDays(90)->toDateTimeString(),
            ],
            default => null, // No comparison for custom ranges
        };
    }

    protected function buildCacheKey(
        string $dateType,
        int $orgId,
        ?string $jobClass = null,
        ?string $startDate = null,
        ?string $endDate = null,
        ?int $branchId = null,
        ?int $clientOrgId = null
    ): string {
        $class = Str::of(\class_basename($jobClass ?? $this))
            ->replace('Stat', '')
            ->snake('-')
            ->toString();

        $branch = $branchId ? 'branch='.$branchId : '';
        $client = $clientOrgId ? 'client='.$clientOrgId : '';

        if ($dateType === 'custom' && $startDate && $endDate) {
            $dates = \str_replace(' ', '_', $startDate.'_'.$endDate);

            return $class.':custom:'.$dates.':org='.$orgId.':'.$branch.':'.$client;
        }

        return $class.':'.$dateType.':org='.$orgId.':'.$branch.':'.$client;
    }
}
