# DEV-2386 — Implementation Plan

Phase 3 arbitration. Design was approved in Phase 2 and is **not** reopened here.

## Outcome

`ScoutCheckInMatchQuery` and `ScoutCheckInRollupQuery` stop re-deriving the org's open-order
address book from the zod CDC chain (`clarity.hotlist FINAL` → `zod.public_hotlist_address` →
`zod.public_address`, EWKT re-parse) on every kit, every cycle. Both read a single
`(org_id, is_staged)` slice of **`clarity.scout_open_addresses`** — the fleet-wide book that
chlocal migration 111 rebuilds every 15 minutes (live in production since 2026-08-18,
~4.5M rows / 1,965 orgs).

Because a full-refresh MV that fails leaves the *old* snapshot in place, the recorder gains a
platform-side freshness gate: if `max(refreshed_at)` is older than 45 minutes, or the book is
empty, the cycle skips with a counted, surfaced failure instead of silently matching against a
stale book. With the per-kit cost collapsed, the `scout:record-check-ins` schedule — commented
out since the 2026-08-12 host OOM — comes back on.

### Authoritative DDL (code wins over the ticket's "lng/lat" wording)

```
org_id Int32, is_staged Bool, hotlist_id Int64, address_id Int64,
geo Point, refreshed_at DateTime
ENGINE = MergeTree ORDER BY (org_id, is_staged, hotlist_id, address_id)
```

There are **no lng/lat columns**. Readers use `tupleElement(geo, 1)` as lng and
`tupleElement(geo, 2)` as lat, exactly like `clarity.sc_positions`. No `FINAL` (plain MergeTree,
one row per `(hotlist_id, address_id)` per rebuild). No `max(_peerdb_version)` join survives.

## DAG

```
t1 fixtures / pgsql bridge / seeder / CI schema dump
 └─> t2 both query classes + the tests that seed the book
      └─> t3 freshness gate + config knob + command tests
           └─> t4 schedule re-enable + schedule pin test
                └─> t5 three okf pages
```

Fully serialized. The partition is file-disjoint (18 distinct files, no overlap), but the
dependencies are real rather than cosmetic: t2's tests cannot run until the table exists (t1),
t3's gate tests seed the same fixtures t2 introduces, t4's `the_gps_lookback_comes_from_config`
needs t3's config key, and t5 documents behavior that must exist first. `TICKET_IMPLEMENT_MODEL=sonnet`.

### t1 — fixtures, bridge, seeder, CI dump

`RequiresClickhouse` gains `clarity.scout_open_addresses` and **keeps** `zod.public_address` /
`zod.public_hotlist_address` (the geohash heatmap query and `ClarityScoutMapControllerTest` still
need them). `local-clickhouse-schema.sql` gets a full `CREATE TABLE`, not a stub — note that
`sc_positions` is *absent* from that pgsql bridge, so there is no Point precedent to copy; use
pgsql's native `point` so the `(lng, lat)` literal the ClickHouse fixtures already use inserts
unchanged. The seeder adds the table to its `REPLICA IDENTITY FULL` list.

`clickhouse-schema.sql` is the blocker — see the STOP condition below.

### t2 — the two query classes

Both lose `HotlistStatusEnum`, `zodDb()`, `open_hotlists`, `address_links`,
`latest_address_versions` and `latest_addresses`. What replaces them is one CTE:

```sql
addresses AS (
    SELECT book.address_id, book.hotlist_id, book.is_staged,
           tupleElement(book.geo, 1) AS addr_lng,
           tupleElement(book.geo, 2) AS addr_lat
    FROM {$db}.scout_open_addresses book
    CROSS JOIN position_bbox b
    WHERE ({$hotlistOrgFilter})
      AND addr_lng != 0 AND addr_lat != 0
      AND addr_lng BETWEEN b.min_lng AND b.max_lng
      AND addr_lat BETWEEN b.min_lat AND b.max_lat
)
```

The bbox stays a **CROSS JOIN against the CTE**, not four scalar subqueries — AC-2's headroom was
measured on that form (1,553,766 vs 1,967,227 read_rows on the org-14 slice). `hotlistOrgFilter`
stays duplicated in both classes verbatim; do not extract a shared helper. The matcher keeps
`::handle()` and the Direct-wins `argMin(hotlist_id, (toUInt8(is_staged), hotlist_id))`; the
rollup keeps `::query(cache: true)`, its `DISTINCT` grain and its two `countIf` buckets, so
`ClarityScoutDashboardController:254` takes no diff. Docblocks are part of AC-1 and must lose
every `public_address` / `public_hotlist_address` mention.

The rollup's `addresses` CTE must **not** gain `hotlist_id` — adding it would change the `DISTINCT`
grain and break the deliberate both-buckets count for a shared address.

### t3 — the freshness gate

In `handle()`, before `acquireConcurrencySlot()`:

```
SELECT toString(max(refreshed_at)) AS refreshed_at FROM {$database}.scout_open_addresses
```

`$database` comes from `config('database.connections.clarity_clickhouse.database')`, never a
hardcoded `clarity` (worktree-suffixed local databases). Empty table returns **epoch, not NULL**
— `timestamp <= 0` is the sentinel check, lifted from `CloseInactiveShiftsCommand::latestGpsFor()`.
Stale or empty → `Log::warning` + `report(new RuntimeException(...))` + `$this->warn()` + `SUCCESS`.
Config key `book_freshness_minutes`, env `SCOUT_CHECK_INS_BOOK_FRESHNESS_MINUTES`, default 45, no
call-site fallback; non-positive disables the gate and skips the probe entirely.

**Probe exceptions are fail-open** (log at info, continue). This is a genuine design decision, so
here is the reasoning: a probe that cannot run is not evidence of a stale book, the per-kit matcher
reads the same table one statement later so a real outage still surfaces there, and
`latestGpsFor()` already sets this precedent for "the local pgsql bridge has no such table". Three
existing tests depend on it —
`clickhouse_unreachable_degrades_gracefully_without_crashing`,
`a_per_kit_failure_is_skipped_and_logged_without_killing_the_run`, and
`a_production_clickhouse_failure_emits_a_failed_signal_not_an_empty_completion` all point the
connection at a dead port and then assert on the *per-kit* skip path. If the probe is ever made
fail-closed, those three break first and the warning/report would also fire on every
ClickHouse-free run.

### t4 — schedule

Uncomment the block verbatim (`everyFiveMinutes`, `withoutOverlapping(10)`, `runInBackground`,
`onOneServer`) and invert `ScoutRecordCheckInsScheduleTest` from "asserts absent" to "asserts
registered". Keep the PG-023 comment that `withoutOverlapping(10)` is a lock TTL, not a duration
cap.

### t5 — wiki

Three pages only. Landed after the behavior exists and its tests pass, per the CLAUDE.md
WRITE-after-approval rule. `php scripts/okf-lint.php` before landing.

## Test plan

Run with `.env.testing`; the ClickHouse-touching classes need `CLICKHOUSE_DRIVER=clickhouse`.
Never `--parallel`. Per the repo execution policy, tests run only when asked.

| Command | Covers |
| --- | --- |
| `php artisan test --compact --filter=ScoutCheckInMatchQueryParityTest` | matcher/rollup grain, Direct-wins, duplicate book rows, cross-org guard |
| `php artisan test --compact tests/Feature/Http/Controllers/Api/ClarityScoutDashboardControllerTest.php` | rewritten rollup through the real dashboard endpoint |
| `php artisan test --compact --filter=ScoutRecordCheckInsCommandTest` | command path plus the three gate cases |
| `php artisan test --compact --filter=ScoutRecordCheckInsMemoryTest` | peak RSS still O(matches) with 20k far book rows |
| `php artisan test --compact --filter=ScoutRecordCheckInsScheduleTest` | schedule registered; lookback still config-driven |
| `php artisan test --compact --filter=ScoutRecordCheckInsOverrunSignalTest` | overrun signal survives the gate |
| `rg 'public_address\|public_hotlist_address' app/Clickhouse/Queries/ScoutCheckIn*.php` | AC-1, must be 0 including docblocks |
| `php scripts/okf-lint.php` | AC-7 |

**Happy paths.** A fresh book row within 160.934 m of a GPS fix records one check-in per
`(address, hour)`; Direct and Staging book rows produce one row each; the dashboard tile returns
`direct_bucket`/`staging_bucket` unchanged.

**Failure paths.** Book older than 45 min → skip, warning, reported `RuntimeException`, zero rows
written even with a matching fix. Empty book → same. ClickHouse unreachable → probe fails open,
per-kit query throws, existing skip/report path unchanged. Gate disabled (`0`) → stale book still
processes.

**Edge cases.** Address on both a Direct and a Staging order: matcher writes one Direct row, rollup
counts it in both buckets. Two book rows for the same `(hotlist_id, address_id)` with different
`refreshed_at` → one match (this is the rewritten CDC-version case, not a deletion). Empty staging
list → the filter collapses to the Direct branch only. Another org's book row inside the bbox →
no match. `(0, 0)` geo → excluded by the reader's own guard. Parked across an hour boundary → two
rows. Address inside the bbox but beyond the radius → no row (pins `geoDistance`, not the box).

## Risks

- **Multi-tenant filter is now the only tenancy boundary.** The book is fleet-wide; before, an
  org's addresses were reachable only through its own hotlists. A dropped or malformed
  `hotlistOrgFilter` leaks another org's addresses into a driver's check-ins. It is duplicated in
  two classes by design, so review both, and keep the cross-org test case.
- **Empty staging list.** `hotlistOrgFilter` must still collapse to the Direct branch alone; an
  `IN ()` with an empty list is a syntax error in ClickHouse and the existing early return is what
  prevents it. Unchanged code, but it now guards a much larger table.
- **Epoch, not NULL.** ClickHouse `max()` over an empty table returns `1970-01-01`. Treating that
  as "just old" happens to work, but the explicit `timestamp <= 0` check is what makes
  empty-vs-stale legible in logs, and it is the documented idiom.
- **Every no-fixture test now passes through the gate.** ~8 tests in `ScoutRecordCheckInsCommandTest`
  seed no addresses at all and would start hitting the empty-book skip, losing their assertions
  (including the concurrency-misconfig test, which asserts output the gate would preempt). The
  fresh sentinel book row in `setUp()` is load-bearing, not decoration. Two ClickHouse-free classes
  (`ScoutRecordCheckInsOverrunSignalTest`, and `the_gps_lookback_comes_from_config` in the schedule
  test) get `book_freshness_minutes => 0` instead, so their result does not depend on whether a
  bridge table happens to exist locally.
- **Schedule blast radius.** A command disabled since a host OOM starts running every five minutes
  in production. `withoutOverlapping(10)` is a lock TTL; the real ceiling is the 2-slot semaphore.
  Human-approved in Phase 2 Q3 — no second approval pause — but AC-4/AC-6 host verification is
  human QA *before* merge.
- **pgsql Point bridge.** `sc_positions` is absent from `local-clickhouse-schema.sql`, so
  `scout_open_addresses` is the first geo-bearing table added there. `tupleElement()` is
  ClickHouse-only, so the bridge row exists for seeding and truncation parity — nobody can run the
  matcher under `CLICKHOUSE_DRIVER=pgsql`, before or after this change.
- **Semantic drift into the MV.** Open-status and `length(plate) > 3` now live in chlocal migration
  111. Platform can no longer enforce them, and two dashboard tests currently pin them. They are
  rewritten (not deleted) to pin what platform still owns: an address absent from the book does not
  count.
- **Book membership can be ~15 min stale.** DEV-1758's open-status re-verification at push time is
  the named follow-up; out of scope here, flagged only.

## Sizing gate

**Fired.** `prep-context.target_files` lists 17 files (>6), so the ticket flow's default is
"recommend a split and STOP". The operator approved single-PR scope in Phase 2 Q3
(`human:Dalton 2026-08-18`), so this plan proceeds as **one PR** and does not park or re-ask.

This plan reaches **18** files — one more than prep. The addition is
`tests/Feature/Console/ScoutRecordCheckInsOverrunSignalTest.php`, which prep expected to need no
change; it needs a one-line gate-disable in `setUp()` because the new gate runs on every cycle,
including that deliberately ClickHouse-free one.

## CH-at-scale perf gate

Not required. `state.perf_validation.required = false` (driver sets this on `state.json`).
`scout_open_addresses` is ~4.5M rows — not the >1B trigger — the design defers no perf work, and
AC-2 already has a measured CROSS JOIN EXPLAIN. Operator accepted the org-14 973,257-row slice as
the sizing figure.

## chlocal STOP condition

**Verified 2026-08-18 while writing this plan:**
`/Users/daltonmccleery/Herd/chlocal/migrations/cloud_export/cloud_clarity_schema.sql` contains
**zero** occurrences of `scout_open_addresses`, and `database/schema/clickhouse-schema.sql`
contains none either. Migration 111 lives on the `dev-2383-e4a13bb` worktree; chlocal main
(`dev-2159-recoveries-mv-repair`) has not re-exported.

So the `/chlocal` re-export **cannot** produce the table today. It must still be run from
`/Users/daltonmccleery/Herd/platform` (the main checkout, so `../chlocal` resolves — not this
worktree). If the export still lacks the table: **STOP and surface to the operator. Do not invent
DDL.**

This is a hard gate on CI, not a nicety: every `RequiresClickhouse` test in t2 and t3 needs the
table to exist in the CI ClickHouse service, which is loaded from that dump. Without the
re-export, those tests fail in CI even though they pass locally.

Fallback **only** on explicit operator authorization (not assumed now): copy the migration-111
`CREATE TABLE` into the dump's CLARITY TABLES section in its one-line style. Migration 111 is
already plain `MergeTree`, so the `Shared*` transform rules are a no-op on it. **Never** copy
`scout_open_addresses_mv` into the CI dump — it is `REFRESH EVERY 15 MINUTE`, full-refresh and
non-APPEND, and would atomically `EXCHANGE` the table out from under a running test's fixtures.

## AC mapping

| AC | Verified by | Where |
| --- | --- | --- |
| AC-1 both classes off the zod chain, docblocks included | agent | `rg` on the two files → 0 |
| AC-2 per-kit p50 < 400 ms, read_rows < 2M (org 14) | **human** | production ClickHouse |
| AC-3 old-vs-new parity on kits with real matches | **human** | production ClickHouse |
| AC-4 one manual prod cycle < 5 min, flat host memory | **human** | prod host, before merge |
| AC-5 staleness gate skips with a counted, surfaced failure | agent | `ScoutRecordCheckInsCommandTest` gate cases |
| AC-6 schedule re-enabled + test flipped | agent | `ScoutRecordCheckInsScheduleTest` |
| AC-6 host `pct_usable` flat afterwards | **human** | prod host |
| AC-7 three okf pages + okf-lint green + schema re-export | agent | `php scripts/okf-lint.php`; dump contains the table |
| AC-8 fixtures/bridge/seeder | agent | t1 files + CH-gated suites pass |

## Standing constraints

PHP 8.3 compatible. No new abstractions — `hotlistOrgFilter` stays duplicated, no shared address
helper. `lookback_minutes` stays 15. No bbox clamping. `vendor/bin/pint --dirty --format agent`
before finishing code. Wiki writes happen in t5, after the behavior lands.
