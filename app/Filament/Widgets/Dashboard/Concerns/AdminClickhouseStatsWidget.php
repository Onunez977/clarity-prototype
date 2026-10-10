<?php

namespace App\Filament\Widgets\Dashboard\Concerns;

use Filament\Widgets\StatsOverviewWidget;
use InsightLPR\BlackAdam\Enum\RoleEnum;
use Throwable;

abstract class AdminClickhouseStatsWidget extends StatsOverviewWidget
{
    use StatHelper;

    protected int|string|array $columnSpan = 'full';

    protected ?string $pollingInterval = null;

    public function mount(): void
    {
        abort_unless(static::canView(), 403);
    }

    public static function canView(): bool
    {
        return auth()->user()->hasAnyRole(RoleEnum::ADMIN->value, RoleEnum::USER->value);
    }

    /**
     * Query ClickHouse with PostgreSQL cache fallback on error.
     *
     * @param  string  $queryClass  The ClickHouse query class
     * @param  string|null  $fallbackCacheKey  Optional PostgreSQL cache key to use as fallback
     * @param  array  $arguments  Query arguments (org_id, start, end, etc.)
     */
    protected function queryWithFallback(string $queryClass, ?string $fallbackCacheKey, array $arguments): mixed
    {
        try {
            // Try ClickHouse first with caching
            return $queryClass::query($arguments, cache: true);
        } catch (Throwable $e) {
            // Fall back to PostgreSQL cache if available
            if ($fallbackCacheKey) {
                $cachedValue = cache()->get($fallbackCacheKey);
                if ($cachedValue !== null) {
                    return $cachedValue;
                }
            }

            // Log error and return null if no fallback available
            report($e);

            return null;
        }
    }

    /**
     * Build a cache key for PostgreSQL fallback.
     */
    protected function buildFallbackCacheKey(string $key, ?string $jobClass = null): string
    {
        return $this->cacheKey(
            key: $key,
            orgId: auth()->user()->org_id,
            class: $jobClass,
        );
    }
}
