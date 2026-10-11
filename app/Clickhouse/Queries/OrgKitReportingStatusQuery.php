<?php

declare(strict_types=1);

namespace App\Clickhouse\Queries;

use Illuminate\Support\Facades\DB;

/**
 * Batched "did this kit report GPS in the last 2 weeks?" for an org's GL kits,
 * from raw scanner fixes in `clarity.sc_positions`. Sibling of
 * {@see OrgLivePositionsQuery} but lean: presence-of-key is the answer, so it
 * selects only `max(timestamp_utc)` per mapping, not lat/lng/speed/heading.
 *
 * The cutoff is bound through `toDateTime(?, 'UTC')` rather than compared as a bare
 * string: ClickHouse resolves a naked string literal against a DateTime column in the
 * SERVER timezone, which would slide the 14-day boundary by the server offset.
 *
 * `lower(kit_mapping)` on both the column and the caller-supplied list is
 * mandatory: CH `IN` is case-sensitive, platform lowercases kit_mapping, the
 * scanner feed does not (DEV-2012). This shape also engages DEV-2381's
 * `proj_kit_mapping_lc` projection.
 */
class OrgKitReportingStatusQuery extends ClickhouseQuery
{
    /**
     * @param  array{mappings?: list<string>, cutoff?: string}  $arguments
     * @return array<array-key, string> lowercased kit_mapping => latest timestamp_utc string (PHP casts all-numeric mappings to int keys)
     */
    public static function handle(array $arguments = []): array
    {
        $mappings = array_values(array_unique(array_filter(
            array_map(static fn ($m) => strtolower((string) $m), $arguments['mappings'] ?? []),
            static fn (string $m) => $m !== ''
        )));

        if ($mappings === []) {
            return [];
        }

        $cutoff = (string) ($arguments['cutoff'] ?? self::defaultCutoff());

        $placeholders = implode(', ', array_fill(0, count($mappings), '?'));

        $rows = DB::clickhouse('sc_positions')
            ->selectRaw('lower(kit_mapping) AS mapping')
            ->selectRaw("toString(max(timestamp_utc), 'UTC') AS ts")
            ->whereRaw('timestamp_utc >= toDateTime(?, \'UTC\')', [$cutoff])
            ->whereRaw("lower(kit_mapping) IN ({$placeholders})", $mappings)
            ->groupByRaw('lower(kit_mapping)')
            ->get();

        $result = [];

        foreach ($rows as $row) {
            $mapping = (string) ($row['mapping'] ?? $row->mapping);
            $result[$mapping] = (string) ($row['ts'] ?? $row->ts);
        }

        return $result;
    }

    /**
     * Default 14-day lookback floor as a UTC datetime string, compared against
     * the UTC `timestamp_utc` column — must be built in UTC regardless of
     * APP_TIMEZONE (DEV-2164).
     */
    protected static function defaultCutoff(): string
    {
        return now()->utc()->subDays(14)->toDateTimeString();
    }
}
