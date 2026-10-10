# Driver-side findings (Phase 1, folded into prep-context at reconcile)

Established by the driver directly, independent of the prep sub-agent.

## 1. The bug is live in production

- `origin/deploy` == `bdfe7c88`; `v3.9.2` is an ancestor of it with 2 commits after.
- The prior GL Kits page commit `421d31ab` is an ancestor of BOTH `v3.9.2` and `origin/deploy`.
- `origin/main` is 31 commits ahead of `deploy` and fully contains it (`deploy..main` = 31,
  `main..deploy` = 0).
- `git diff origin/deploy origin/main -- 'app/Filament/Resources/GlKits*'
  'tests/Filament/Resources/GlKits*' 'app/Clickhouse/Queries/OrgKit*'` is EMPTY — the files
  this ticket touches are byte-identical on both branches.

Consequence: the hotfix route (base `deploy`) is correct, and the second PR to `main`
will carry no extra diff on the touched files.

## 2. The CI schema dump translates Cloud-only engines — appending verbatim DDL will NOT work

`database/schema/clickhouse-schema.sql` header (lines 1-5):

```
-- ClickHouse Schema for CI Testing
-- Regenerated from ClickHouse Cloud (system.tables.create_table_query).
-- Cloud-only Shared*MergeTree engines are translated to their OSS equivalents and the
-- replication path/replica arguments dropped; everything else is verbatim from production.
-- Generated: 2026-09-01
```

Measured: 62 `CREATE TABLE` lines, **zero** `Shared*` engines in the body (the single
`Shared` match in the file is that header comment). Engine census:
`ReplacingMergeTree` 49, `MergeTree` 7, `AggregatingMergeTree` 5, `SummingMergeTree` 2.

The ticket states `zod.aggregate_kit_camera_name` is a `SharedReplacingMergeTree`. So the
DDL cannot be pasted verbatim: `SharedReplacingMergeTree` → `ReplacingMergeTree`, and the
replication path/replica arguments must be dropped, exactly as the other 49 tables were.
CI runs `clickhouse/clickhouse-server:26.2` (OSS), which has no `Shared*` engines at all —
a verbatim paste fails at dump load, taking the whole CI ClickHouse job with it, not just
this ticket's tests.

## 3. The dump is a curated SUBSET, and the last full refresh already missed this table

- `0746450c` "Refresh the CI ClickHouse schema dump from production Cloud" (2026-09-01) is
  ON `deploy`. It was a genuine full verification against `system.tables`/`system.columns`
  (its message reports 5 wrong sort keys, 2 wrong engines, 36 of 61 tables with column
  drift) — and `zod.aggregate_kit_camera_name` is STILL absent afterwards.
- Only one `zod.aggregate_*` table is in the dump at all: `zod.aggregate_scan_count_h`.
- An earlier fleet-growth investigation independently calls the local dump
  "a stale subset".

So the absence is not drift that a re-run of the same refresh would fix — the generator
works from a table list. Adding this table means adding it to that list, not just re-running.

- The generator is an in-repo documented procedure: **`.claude/commands/chlocal.md`**
  ("Export ClickHouse schemas from chlocal's cloud exports into a single
  `clickhouse-schema.sql` file for GitHub Actions CI testing", target
  `database/schema/clickhouse-schema.sql`). It has its own verification block that greps
  for `Shared` and counts tables/views/MVs — i.e. the Shared-engine translation is part of
  that procedure, confirming point 2.

## 4. Local full-suite runs need a raised memory limit

From the `clarity/testing` knowledge card (the OOM-during-discovery test runner issue): `php artisan test` OOMs during test
DISCOVERY at the default 128M limit and still returns **exit 0** — a false green. Full-suite
runs must go through `php -d memory_limit=2G vendor/bin/phpunit` (or `PHPRC`, per the
`phprc-propagates-memory-limit-to-phpunit` note). Expect ~8 pre-existing failures; do not
attribute them to this diff without checking the base commit.

Also: `RequiresClickhouse` TRUNCATEs hardcoded `clarity.*` / `zod.*` literals, so the local
ClickHouse databases must be named literally `clarity` and `zod`, and teardown must never
drop them.
