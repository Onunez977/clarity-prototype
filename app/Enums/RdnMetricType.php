<?php

declare(strict_types=1);

namespace App\Enums;

enum RdnMetricType: string
{
    case SCANS = 'scans_by_org_by_hour';
    case HISTORICAL_HITS = 'hh_by_org_by_hour';
    case STAGING_HISTORICAL = 'aa_by_org_by_hour';
    case LIVE_HITS = 'lives_by_org_by_hour';
    case STAGING_LIVE = 'staging_lives_by_org_by_hour';

    public function isScan(): bool
    {
        return $this === self::SCANS;
    }

    /**
     * Column alias in RdnHitHourCountQuery to read for this metric.
     */
    public function hitColumn(): ?string
    {
        return match ($this) {
            self::LIVE_HITS => 'lives',
            self::HISTORICAL_HITS => 'hh',
            self::STAGING_LIVE => 'staging_lives',
            self::STAGING_HISTORICAL => 'aa',
            self::SCANS => null,
        };
    }

    /**
     * @return array<int, self>
     */
    public static function hitCases(): array
    {
        return [self::LIVE_HITS, self::HISTORICAL_HITS, self::STAGING_LIVE, self::STAGING_HISTORICAL];
    }
}
