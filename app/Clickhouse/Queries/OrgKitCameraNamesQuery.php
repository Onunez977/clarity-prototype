<?php

declare(strict_types=1);

namespace App\Clickhouse\Queries;

use Illuminate\Support\Facades\DB;

/**
 * Batched "which GLs does each of these kits drive?", inverted to GL => kit_ids,
 * from the chlocal-owned `zod.aggregate_kit_camera_name`. Three things are
 * load-bearing:
 *
 * - `kit_ids` come from PostgreSQL and are the only scoping this class applies: the
 *   table has no `org_id` column, so nothing here can filter by org. Callers must
 *   derive the list the way {@see GlKitsResource} does. The ticket's evidence query
 *   joined `zod.public_kits` for scoping; that was a one-off, and reproducing it
 *   would split tenancy across two stores for no gain.
 *
 *   That scoping is CURRENT-ownership only, and it is not airtight over time. The
 *   feeding MV is append-only with no expiry, so a (kit_id, camera_name) pair is
 *   permanent: a kit that changes org carries its former cameras into the new one.
 *   Measured 2026-09-04: 535 of 1,365 GLs (39%) resolve for more than one org, and
 *   12 of org 1143's 49. So a returned camera means "a kit now in this org has
 *   scanned with it at some point", NOT "this org owns it". No scan data crosses —
 *   the rollups in {@see GlKitsResource} read only the requesting org's own kit ids —
 *   but the identifier and the row count do. The follow-up per-GL ClickHouse table is the fix;
 *   until then this is a known property of the source the ticket specified, not a
 *   guarantee this class can make.
 * - `FINAL` collapses the table's unmerged duplicates at the source, so fewer rows
 *   cross the wire: it is a ReplacingMergeTree whose BOTH columns are in the sort
 *   key, so every duplicate is byte-identical (12,441 raw rows for 10,601 distinct
 *   pairs, measured 2026-09-04). It is not what keeps the returned lists unique —
 *   the `in_array` guard below does that, and would do it alone. Kept because the
 *   repo's ReplacingMergeTree convention expects it and it reduces the read.
 * - The `in_array` guard is NOT redundant with `FINAL`, and does a different job:
 *   case-folding merges two rows that are genuinely DISTINCT — ('GLx', kit 5) and
 *   ('GLX', kit 5) — onto one key, which `FINAL` cannot collapse because they are
 *   not duplicates. Without the guard that kit appears twice under its own GL.
 * - Case-variant names are merged onto one entry, keeping the first spelling seen
 *   in sort-key order. The MV applies no `lower()` and the scanner feed is not
 *   case-normalized (measured: 16 of 2,190 names exist in two case variants), so
 *   without this one camera renders as two rows with independently computed
 *   rollups — the ticket's own "one row per GL" invariant, broken by a syntactic
 *   variant of its root cause. {@see OrgKitReportingStatusQuery} records the same
 *   normalization on this feed as mandatory.
 * - `camera_name` is returned verbatim. The feeding MV already applies
 *   `replaceRegexpOne(camera_name, ' .*', '')`, so "GL3113 Left-Right" arrives as
 *   "GL3113" — stripping again would corrupt the identifier. It is also deliberately
 *   un-filtered by any `GL####` pattern: real fleets carry tokens like `Clone` and
 *   `Relentless`, and a pattern filter would hide those cameras.
 *
 * Ordered by the sort key so the returned grouping is deterministic: callers sort
 * GLs themselves, but an arbitrary row order makes both the result and any test
 * asserting it unstable.
 *
 * This table is MV-fed, NOT a PeerDB CDC mirror — it has no `_peerdb_is_deleted`,
 * so the usual soft-delete predicate errors here rather than filtering.
 */
class OrgKitCameraNamesQuery extends ClickhouseQuery
{
    /**
     * @param  array{kit_ids?: list<int>}  $arguments
     * @return array<array-key, list<int>> camera_name => kit_ids (PHP casts an
     *                                     all-numeric camera_name to an int key)
     */
    public static function handle(array $arguments = []): array
    {
        $kitIds = array_values(array_unique(array_map('intval', $arguments['kit_ids'] ?? [])));

        if ($kitIds === []) {
            return [];
        }

        $placeholders = implode(', ', array_fill(0, count($kitIds), '?'));

        $rows = DB::clickhouse('aggregate_kit_camera_name', 'zod', final: true)
            ->select(['kit_id', 'camera_name'])
            ->whereRaw("kit_id IN ({$placeholders})", $kitIds)
            ->orderBy('kit_id')
            ->orderBy('camera_name')
            ->get();

        $result = [];
        $canonical = [];

        foreach ($rows as $row) {
            $cameraName = (string) ($row['camera_name'] ?? $row->camera_name);
            $kitId = (int) ($row['kit_id'] ?? $row->kit_id);

            // A blank name is not a camera. Measured 0 in the aggregate table (2026-09-04),
            // but the MV's source column is a non-nullable String whose default is '', so this
            // costs nothing and keeps a blank row off a page that is one-row-per-camera.
            if ($cameraName === '') {
                continue;
            }

            $key = $canonical[strtolower($cameraName)] ??= $cameraName;

            if (! in_array($kitId, $result[$key] ?? [], strict: true)) {
                $result[$key][] = $kitId;
            }
        }

        return $result;
    }
}
