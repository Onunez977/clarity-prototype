# DEV-2177 — Ultra-Plan (rebuild)

**Route:** bug · **Base:** `main` · **PR:** #287 · **Approach:** "Flavor A" (read-only CH-side assembly)

One coherent node (single-node fast path). The controller change depends on the new query class, so no parallel fan-out.

## t1 — Continuous route via CH-side `sc_positions` assembly + controller swap  (complexity: complex)

**New `app/Clickhouse/Queries/KitRangePathsQuery.php`** (read-only over `clarity.sc_positions`)
- Inner subquery: the identical 3s decimation used by `OrgLivePathsQuery`/`KitHistoricPositionsQuery` — `argMin(lng/lat, timestamp_utc)` per `toStartOfInterval(timestamp_utc, INTERVAL 3 SECOND)`, `WHERE lower(kit_mapping) IN (?) AND timestamp_utc BETWEEN ? AND ?`. **3s is fixed — never widen** (breaks `HistoricPathBuilder`'s 500 ft segmentation).
- Outer wrap: `GROUP BY mapping, toStartOfHour(ts)`, collect `arraySort(x -> x.1, groupArray(tuple(ts, lng, lat)))` per (mapping, hour). **`arraySort` is required** — `groupArray` does not preserve input order.
- Cap each bucket to `POINTS_PER_SEGMENT_CAP = 200` (private const) **after** sorting, via even-interval subsample (not head-truncate) so a dense/idle hour isn't visually chopped. This is the memory-bound lever (~90 MB vs 2.2 GB OOM on All-Kits/30d).
- `handle()` flattens the grouped rows into the exact `list<array{mapping,lng,lat,timestamp_utc}>` shape `OrgLivePathsQuery::handle()` returns.

**`KitUserPathController::paths()` only**
- Resolve org-scoped `$kits` → `Kit` models → private `byMapping()` (`lower(kit_mapping) => Kit`, skip null/empty, mirrors `ClarityScoutBirdsEyeController::kitsByMapping()`).
- Call `KitRangePathsQuery::query([...], cache: true)` instead of `KitScanPathsQuery`.
- Feed points to the already-public `OrgLivePathsQuery::segmentByMapping($points, $timezone)` — **segmentation logic is not forked**.
- Map segments back to the **byte-identical** row shape `{kit_id, name, color (KitScanPathsQuery::getColor), path, layerId, trip_date}`.

**Untouched (excluded):** `geojson()`/`hitDensity()`; `KitScanPathsQuery` (shared — only its `getColor()` reused); `kit-user-path.js` (shape preserved → no JS change); `HistoricPathBuilder`/`OrgLivePathsQuery` (reused as-is).

**Tests (TDD)**
- NEW `tests/Unit/Clickhouse/Queries/KitRangePathsQueryTest.php` — decimation, per-kit-hour grouping row-count collapse, chronological order (arraySort), 200-pt cap bounding, AC-5 single/zero-point degrade, absent-mapping returns nothing.
- NEW `tests/Feature/Http/Controllers/KitUserPathControllerTest.php` (closes a real gap — no `paths()` coverage today) — permission gate, cross-org isolation, All-Kits resolution, scan-free continuous drive = non-empty path (the bug), AC-5, exact response shape.

## Gates
- **Sizing:** 4 files, 1 concern → in-bounds, no split.
- **Blast-radius:** none (read-only CH; no migrations/billing/auth/purge globs).
- **Perf (WS6):** `sc_positions` is a CH-at-scale surface, but perf is **not deferred** — validated at prod scale in Phase 3.5 **before** implementation (the reason this rebuild exists).
