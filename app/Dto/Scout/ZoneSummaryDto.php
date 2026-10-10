<?php

declare(strict_types=1);

namespace App\Dto\Scout;

/**
 * Per-zone slice of a driver's shift summary.
 *
 * `time_in_zone_minutes` is computed by walking ordered scans and attributing
 * each scan-to-scan delta (capped at 30 minutes to avoid charging idle gaps)
 * to the zone containing the earlier scan in the pair.
 */
final readonly class ZoneSummaryDto
{
    public function __construct(
        public int $id,
        public string $name,
        public ?string $color,
        public int $scans,
        public int $hits,
        public int $timeInZoneMinutes,
    ) {}
}
