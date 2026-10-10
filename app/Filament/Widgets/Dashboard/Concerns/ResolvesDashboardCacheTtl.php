<?php

namespace App\Filament\Widgets\Dashboard\Concerns;

/**
 * The Company Metrics cache-TTL ladder.
 *
 * Shared and class-neutral keys call {@see baseDashboardCacheTTL()}. A card may
 * override {@see getCacheTTL()} for keys it owns. That override must not be used
 * on a key another card reads.
 */
trait ResolvesDashboardCacheTtl
{
    /**
     * Seconds after a month ends before a non-zero index may use the year cache.
     * Shorter than this, the bucket stays on the 10-minute arm so the hourly
     * refresh and the 8-day arrival window can still land.
     */
    public const CLOSED_MONTH_SETTLE_FULL_SECONDS = 8 * 86400;

    /**
     * Impersonation is 10 seconds and is evaluated before every other arm.
     * Index 0 is the open period (10 minutes). A non-zero index with no month
     * end keeps the year cache. A named month end younger than
     * {@see CLOSED_MONTH_SETTLE_FULL_SECONDS} stays at 10 minutes; at or after
     * that age it is cached for a year.
     */
    protected function baseDashboardCacheTTL(int $index = 0, ?\DateTimeInterface $monthEndedAt = null): \DateTimeInterface
    {
        if (session()->has('admin_impersonator')) {
            return now()->addSeconds(10);
        }

        if ($index === 0) {
            return now()->addMinutes(10);
        }

        if ($monthEndedAt !== null && now()->getTimestamp() - $monthEndedAt->getTimestamp() < self::CLOSED_MONTH_SETTLE_FULL_SECONDS) {
            return now()->addMinutes(10);
        }

        return now()->addYear();
    }

    /**
     * Card-owned keys. Delegates to {@see baseDashboardCacheTTL()} unless a
     * subclass overrides it for its own keys.
     */
    protected function getCacheTTL($index = 0, ?\DateTimeInterface $monthEndedAt = null): \DateTimeInterface
    {
        return $this->baseDashboardCacheTTL($index, $monthEndedAt);
    }
}
