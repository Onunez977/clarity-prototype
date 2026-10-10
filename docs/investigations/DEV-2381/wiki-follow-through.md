# DEV-2381 — queued wiki follow-through (do not apply yet)

`AGENTS.md` WRITE rule: do not edit `okf/` while work is in flight, and do not teach a design that has not shipped. The projection is **not on Cloud yet**. These notes land **after** chlocal `ADD PROJECTION` + `MATERIALIZE` succeed and EXPLAIN shows tens of granules (see [operator-handoff.md](operator-handoff.md)).

Run `php scripts/okf-lint.php` before landing wiki edits.

## Pages to update

### `okf/concepts/platform-gotchas.md` (PG-024)

Today: wrap `lower(kit_mapping)` disables PK; measured 3056/32732 vs 13/32732; fix is a projection; DDL is chlocal-owned.

After apply: keep that `lower()` is **correct**. Add that prune now comes from Cloud projection **`proj_kit_mapping_lc`** (`ORDER BY (lower(kit_mapping), timestamp_utc)`). Replace “fix is a projection” (future) with “Cloud has `proj_kit_mapping_lc`; EXPLAIN should show tens of granules, not ~3355.” Cite DEV-2381. Do not claim Docker/CI proves Cloud prune: CI fixtures are tiny, and the dump is still column-stale versus Cloud.

### `okf/concepts/scout-clickhouse-queries.md`

The callout that `lower()` costs the primary key stays historically true. After apply: Scout PHP still emits `lower(kit_mapping)`; the optimizer should read `proj_kit_mapping_lc`. Note `IN` / `GROUP BY lower(kit_mapping)` may still ignore the projection (`OrgLivePositionsQuery`, `KitRangePathsQuery`).

### `okf/concepts/scout-real-time-tracking.md`

DEV-2012 casing note stays. Add one sentence: live/historic `sc_positions` reads keep `lower(kit_mapping)`; Cloud prune is the lowercase projection, not a PHP rewrite.

### `okf/concepts/scout-check-in-recorder.md`

The Performance section already says W1 (chlocal projection) is the remaining duration lever. After apply: record that W1 landed (`proj_kit_mapping_lc`), EXPLAIN pass (tens of granules), and that **W4** (schedule uncomment + host `pct_usable`) is still gated on a proven manual cycle. Do **not** write that the schedule is enabled.

### `okf/log.md` + catalogs

Append an ingest line; bump Last updated on the four pages and `okf/index.md` / `okf/concepts/index.md` if those catalogs list them.

## Schema dump (same gate)

The CI dump was **hand-edited ahead of Cloud** so PHPUnit sees `proj_kit_mapping_lc`. After Cloud apply, from a checkout that has sibling `../chlocal`:

Follow `.claude/commands/chlocal.md` to rebuild `database/schema/clickhouse-schema.sql` from `../chlocal/migrations/cloud_export/cloud_{zod,clarity}_schema.sql`. Transform Shared* engines, strip DEFINERs. That re-export **replaces** the hand edit and must still contain `proj_kit_mapping_lc`. Verify `grep -c Shared` is 0.

If `../chlocal` is missing: stop; clone/locate chlocal; do not proceed.

## PHP / tests

No matcher rewrite. No PHPUnit Cloud EXPLAIN assertion. `ScPositionsLowercaseProjectionTest` only asserts the CI dump defines the projection; it does not prove Cloud granule prune.
