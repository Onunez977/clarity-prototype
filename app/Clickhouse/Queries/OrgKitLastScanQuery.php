<?php

declare(strict_types=1);

namespace App\Clickhouse\Queries;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Batched "last scan received" per kit, from the chlocal-CDC-owned
 * `zod.kit_last_scan`. Three things are load-bearing:
 *
 * - `max(last_scan)` + GROUP BY, because the table is a ReplacingMergeTree that
 *   is not reliably merged (observed at 7,405 rows for 4,755 distinct kits).
 * - `kit_ids` come from PostgreSQL, never from this table: its sort key is
 *   (org_id, kit_id), so a kit that changed orgs keeps a stale row under the old
 *   one (275 of 4,755 kits measured). The org_id predicate is defence-in-depth
 *   against a caller passing foreign ids — NOT a perf measure, the table is only
 *   ~5k rows. Its accepted cost: a kit that moved orgs and hasn't scanned since
 *   reads "Never" until its next scan. Failing safe was preferred to failing open.
 * - The timestamp is aliased `ts`, NOT `last_scan`: aliasing an aggregate back onto
 *   its own source column shadows the real DateTime column, which two sibling
 *   classes document as a shape ClickHouse rejects once anything else references it
 *   ({@see OrgLivePositionsQuery}, {@see KitHistoricPositionsQuery}).
 * - Platform is this table's first consumer, so a chlocal reshape blanks the
 *   column silently ({@see ClickhouseQuery::query()} swallows Throwable). Chosen
 *   anyway: `clarity.scans_cold` measured 9,586 MB per page load, because its
 *   live sort key is (org_id, scanned_at, kit_id, id).
 */
class OrgKitLastScanQuery extends ClickhouseQuery
{
    /**
     * @param  array{org_id?: int, kit_ids?: list<int>}  $arguments
     * @return array<int, string> kit_id => last_scan datetime string
     */
    public static function handle(array $arguments = []): array
    {
        $orgId = (int) ($arguments['org_id'] ?? 0);
        $kitIds = array_values(array_unique(array_map('intval', $arguments['kit_ids'] ?? [])));

        if ($kitIds === []) {
            return [];
        }

        // A missing org_id would otherwise become 0, match nothing, and render every
        // row "Never" — a caller wiring bug indistinguishable from a fleet with no scans.
        if ($orgId <= 0) {
            throw new InvalidArgumentException('OrgKitLastScanQuery requires a positive org_id.');
        }

        $placeholders = implode(', ', array_fill(0, count($kitIds), '?'));

        $rows = DB::clickhouse('kit_last_scan', 'zod')
            ->select(['kit_id'])
            ->selectRaw("toString(max(last_scan), 'UTC') AS ts")
            ->where('org_id', $orgId)
            ->whereRaw("kit_id IN ({$placeholders})", $kitIds)
            ->groupBy('kit_id')
            ->get();

        $result = [];

        foreach ($rows as $row) {
            $kitId = (int) ($row['kit_id'] ?? $row->kit_id);
            $result[$kitId] = (string) ($row['ts'] ?? $row->ts);
        }

        return $result;
    }
}
