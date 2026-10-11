---
type: Pattern
title: Scout Automated Check-in Recorder
description: The backend command that persists Scout automated check-ins — matching each Scout kit's GPS (clarity.sc_positions) against the MV-backed open-order book (clarity.scout_open_addresses) server-side in ClickHouse and recording one row per (kit, address, hour) into PostgreSQL platform.scout_check_ins. Covers the single-statement matcher, the in-process N-slot concurrency ceiling, the live every-five-minutes schedule, check-in semantics (records every pass; the no-hit exclusion belongs to DEV-1758), and observability.
resource: app/Console/Commands/ScoutRecordCheckIns.php
tags: [scout, clickhouse, check-in, jobs, geo, sc_positions]
timestamp: 2026-08-19T12:45:00Z
---

# Scout Automated Check-in Recorder

Persists Scout **automated check-ins** in the background so they survive independently of the driver's app or connection. A check-in is recorded when a Scout kit's GPS drives within **0.1 mi (160.934 m)** of one of its org's open-order addresses. Introduced by **DEV-2313** (reworking the original DEV-1756 spec); the PHP-side address round-trip that OOMed production on 2026-08-12 was removed by **DEV-2342**. The Scout UI stays hidden — this is additive analytics storage only. Read [Data Architecture](data-architecture.md) first.

## Don't confuse it with the dashboard rollup

There are **two** distinct "check-in" mechanisms — different source table, different storage:

| | Recorder (this page, DEV-2313 / DEV-2342) | Rollup tile (DEV-1756/1757) |
| --- | --- | --- |
| Query | `ScoutCheckInMatchQuery` (one CTE per kit) | `ScoutCheckInRollupQuery` |
| GPS source | **`clarity.sc_positions`** (continuous scanner GPS) | `clarity.scans_hot`/`scans_cold` (plate reads) |
| Result | **persisted** rows in PostgreSQL `platform.scout_check_ins` | computed **on read**, returned by `/api/scout/dashboard/check-in-counts` |
| Surface | none (backend command) | the (unshipped, hidden) dashboard tile |
| Shared-address grain | **Direct-wins** (then lowest hotlist id) — one persisted row | counts the same address in **both** Direct and Staging buckets |

Both use the same open-order address resolution and the same 160.934 m radius; only this page's recorder writes anything down. See [Scout Dashboard Stats](scout-dashboard-stats.md#check-in-counts--scoutcheckinrollupquery) for the rollup.

## Data flow

`scout:record-check-ins` (`app/Console/Commands/ScoutRecordCheckIns.php`):

1. **Flag gate** — no-op unless `config('app.experimental.clarity_scout')`.
2. **Concurrency slot** — acquire one of `max_concurrent_slots` (default **2**) via `Cache::add`. Full pool or a non-positive misconfig → log + `report()` + skip (not "unlimited").
3. **Enumerate Scout drivers** the same way portal access is granted (mirrors `ClarityScoutMiddleware`): a `kit_settings` row + the team-scoped `clarity-scout` permission + the `CLARITY_SCOUT` Pennant feature. **Grouped by org.**
4. **Per kit** (heartbeat the slot first) → `ScoutCheckInMatchQuery::handle(...)` — **not** `::query()`. One ClickHouse statement returns only match rows. Persist with `ScoutCheckIn::query()->insertOrIgnore(...)` against `unique(kit_id, address_id, hour_bucket)`.
5. Release the slot in `finally`.

The command does **not** probe `max(refreshed_at)` or skip cycles when the book looks empty/stale. Rebuild freshness of `clarity.scout_open_addresses` is owned by chlocal (out of this repo). An empty book yields zero matches and SUCCESS.

**Nothing large crosses the PHP boundary.** There is no per-org address list, no `values()` injection, no `array_chunk`, no `firstOrCreate` loop. `ScoutOpenOrderAddressesQuery` was deleted.

All of it still runs **synchronously inside the one command** (Option 1). A later Option 3 — thin command → `Bus::batch` of per-org `RecordOrgCheckIns` jobs on `{low}`, named `Scout check-ins: {orgId}` — is recorded as a follow-up, not current design. Precedent: `app/Jobs/Scout/RecordOrgCheckIns` at `caab5a469` (reverted at `102a3430` because `{low}` `maxProcesses` defaulted to 1 at the *old* ~104-min duration).

### `ScoutCheckInMatchQuery` (per kit, server-side)

`app/Clickhouse/Queries/ScoutCheckInMatchQuery.php`. Lifted from `ScoutCheckInRollupQuery`'s matches CTE onto `clarity.sc_positions`. Cross-database CTE:

`position_window` (`clarity.sc_positions`, `lower(kit_mapping) = ?`, time window, non-zero coords) → `position_bbox` (min/max + `BBOX_PAD_DEGREES = 0.003`) → `addresses` (`clarity.scout_open_addresses` CROSS JOIN bbox, `tupleElement(geo, 1|2)`, the duplicated `hotlistOrgFilter`) → `geoDistance <= 160.934` → `GROUP BY (address_id, hour_bucket)` with Direct-wins `argMin(hotlist_id, (toUInt8(is_staged), hotlist_id))` and a single-tuple `argMin` for coords.

No `FINAL` on the book (plain MergeTree, one row per `(hotlist_id, address_id)` per rebuild). The bbox prefilter is still load-bearing: `ORDER BY (org_id, is_staged, hotlist_id, address_id)` has no geo prefix. Open-status and `length(plate) > 3` live in the chlocal MV (migration 111), not in this class.

`lower(kit_mapping)` still disables the `sc_positions` `(kit_mapping, timestamp_utc)` primary key (DEV-2012). Cloud EXPLAIN: equality and per-org `IN` both scan ~3355/33096 granules today; exact-case equality is ~20/33096. The duration fix is a **chlocal-owned** `lower(kit_mapping)` projection (**W1**, not this repo). Per-kit equality was chosen so that projection can prune once it exists; a per-org `IN` may ignore it.

## Storage — `platform.scout_check_ins` (PostgreSQL `platform` schema)

`database/migrations/2026_08_10_170314_create_scout_check_ins_table.php`. Columns: `kit_id`, `org_id`, `hotlist_id`, `address_id`, `is_staged`, `matched_at` (tz), `hour_bucket` (tz), `lat`, `lng`, timestamps. **`unique(kit_id, address_id, hour_bucket)`** backs `insertOrIgnore` idempotency; **`index(org_id, address_id)`**. Model `app/Models/ScoutCheckIn.php`. Migration runs on deploy; **no backfill** — only forward check-ins are recorded once the flag is on. The table grows unbounded (no retention policy yet). `insertOrIgnore` bypasses model events, so `created_at`/`updated_at` are stamped in the command.

## Check-in semantics — what a row means

> **Canonical definition:** [Check-in](check-in.md) — the exact qualifying conditions, the (kit, address, hour) identity, the stamped attributes, and the full "what a check-in is NOT" list. This section is the summary.

A `scout_check_ins` row means **"this kit's GPS passed within 0.1 mi of an open order address that hour"** — **not** "visited and didn't find it". The recorder records **every** qualifying pass with **no hit-based exclusion**.

- **Scope = open Direct + open Staging** (Oscar, DEV-2313 — supersedes Jeff's earlier "direct only"). Historical/HH-AA out of scope. HH/AA are hit-type categories, not address sources (see [Scout Analytics Taxonomy](scout-analytics-taxonomy.md)).
- **The "no hit within 30 minutes, direct-only" exclusion is NOT here.** That is a *push-time filter* on the RDN case-update sync (**DEV-1758**), applied when deciding which recorded check-ins to send to RDN — not a filter on what gets recorded. Any consumer of `scout_check_ins` (e.g. the DEV-2258 coverage view) must apply the hit exclusion itself or it will overcount, because some of these addresses were actually recovered.

## Schedule & flag

> Recorder tunables live in `config/scout.php` (`lookback_minutes`, `max_concurrent_slots`, `slot_lease_minutes`, `overrun_ms`). Call sites read them **without a fallback**, so that file is the single source of every default — DEV-2342 briefly shipped one knob with three different defaults, and that is the discipline that prevents a repeat. `address_cache_ttl_minutes` is gone (there is no PHP address cache). `book_freshness_minutes` is gone — platform does not gate on the book's `refreshed_at`.

**The schedule is live** (`everyFiveMinutes()->withoutOverlapping(10)->runInBackground()->onOneServer()`, gated `->when(clarity_scout)`). A 15-minute lookback on a 5-minute cadence gives each fix ~3 overlapping chances. The `withoutOverlapping(10)` stays as a belt-and-suspenders cache lock; **the hard ceiling is the in-command N-slot semaphore**.

**DEV-2348 coupling:** `slot_lease_minutes` (default 10) must stay **≤** `lookback_minutes` (default 15). A crashed holder auto-expires inside the overlapping lookback, so the next cycle still covers the stranded window. Raising the lease without raising lookback silently loses `(lease − lookback)` of GPS.

## Performance — what DEV-2342 actually changed

**Incident (2026-08-12):** the previous two-query architecture pulled the org's open-address book out of ClickHouse into PHP (`ScoutOpenOrderAddressesQuery`), re-injected it as `values()` chunks into every per-kit matcher, and `firstOrCreate`d each match. Combined with `withoutOverlapping(10)` as a lock *expiry* (not a duration cap), a measured peak of **17** concurrent processes each holding `O(addresses_per_org)` produced a **~29 GB** marginal footprint and forced a host reboot. `zod.public_hotlist_address` was **318M** rows (measured 2026-08-13). A prior wiki sentence claimed *"the concurrency is load-bearing… currently the only reason the recorder keeps up."* That conclusion is **withdrawn** — it was measured against throughput only; unbounded overlap was the outage. See **PG-023**, **PG-024**, **PG-026** in [Platform Gotchas](platform-gotchas.md).

**Now (DEV-2342 W2+W3):**

- Memory is **`O(matches)`** by construction. PHP never holds the address book.
- One ClickHouse statement per kit. ~43 kits/cycle, not ~5,500 chunk queries.
- Hard ceiling: 2 `Cache::add` slots, heartbeat-refreshed per kit so the lease is crash-recovery TTL, not a runtime cap (AC4).
- Duration of each CH statement is still dominated by `lower(kit_mapping)` missing the `sc_positions` PK (**PG-024**; Cloud EXPLAIN ~3355/33096 granules vs ~20/33096 exact-case). **W1** (chlocal projection) is a remaining per-statement lever, not a reason to keep the schedule commented. **W4** is the prod-host cycle verify of the live schedule (DEV-2386 re-enabled it; do not re-comment it).

Do **not** size lookback, slots, or GPS-gap arithmetic from the 2026-08-12 cycle times. Those **36–209 min** numbers are **incident history** (PHP `values()` chunk storm against `zod.public_hotlist_address`). After DEV-2342 W2+W3 the matcher is one statement per kit; after DEV-2386 it reads `clarity.scout_open_addresses`. Cloud-measured rewrite (org 14, live 15-minute window, 2026-08-18): p50 **~113 ms** / kit vs the old **26–30 s** book re-derivation. A ~43-kit cycle is seconds-to-low-minutes of ClickHouse, not 104 minutes of PHP. Host wall-clock is W4, not those incident averages.

Incident history (not current behavior): a 4-hour window of 17 completed cycles averaged 104.1 min (min 36.3, max 147.7), 701 check-ins. CH 159 timeouts were ~2.7% of that wall clock — DEV-2339 shipped and duration **did not move**. The other ~97% was the chunk storm (`ADDRESS_CHUNK` × kits × `lower()` PK-bypass). At day scale the average went **104 → 161 min**; crash-day cycles ran **63–209 min**. Peak 17 was interval-reconstructed from 58 completions, not the earlier Little's-law 7–8. Load in that window grew 29–32 → **43** kits, 11 → **12** orgs.

## Observability (no Nightwatch)

The command emits structured logs (`Illuminate\Support\Facades\Log`): a per-cycle **completion summary** (`recorded`/`kits_processed`/`orgs_processed`/`skipped`/`duration_ms`/`lookback_minutes`), **warnings on per-kit failures** (with `kit_id` + error), a full-slot no-op, and a slots-misconfigured skip. Grep `scout:record-check-ins` in the app log.

At or over `overrun_ms` (default **9,000,000 ms / 150 min**) the command logs a `warning` **and** `report()`s to the error tracker, carrying `threshold_ms` and `over_threshold_multiple` so a 19.6x overrun no longer logs the identical string a 1.1x one does. The `report()` is the load-bearing half — the log alone pages nobody. A non-positive `overrun_ms` disables the signal.

The 150-minute default was chosen to sit above the **2026-08-12 incident** plateau (36–148 min then). That calibration is **not** a claim that post-rewrite cycles still take hours. Ratchet it down once W4 measures a live cycle on the book matcher.

The overrun signal is **post-hoc**: `duration_ms` is computed after the org/kit loop. A hung run emits nothing — which is what happened on 2026-08-12, when roughly 10–11 processes started and none completed. The slot lease + per-kit heartbeat now bound a dead holder (AC4); they are not an in-flight duration alarm.

## Gotchas

- **The recorder must call `::handle()`, not `::query()`.** `ClickhouseQuery::query()` still swallows `\Throwable` to `[]` in `production|testing`, so a dead ClickHouse and a legitimately empty match set are identical to any caller that goes through `::query()`. The recorder bypasses that at the call site (AC7). Do **not** "fix" this by teaching `ClickhouseQuery` to throw — every other Scout query relies on the swallow. **Evidence the old path was dead:** a 4-hour production window contained **94** CH 159 errors and **0** `org address enumeration failed` warnings.
- **Enabling the flag needs a Pennant purge.** `CLARITY_SCOUT` is a persisted per-user Pennant feature: drivers resolved `false` while the flag was off stay `false` after `app.experimental.clarity_scout` flips on, until `php artisan pennant:purge` (same behavior as the Scout portal middleware — recorder and portal can never disagree).
- **Coverage is only as complete as upstream data.** An open order whose address isn't geocoded (null/empty/`(0,0)` `geo`) gets **no** check-ins; a kit whose scanner isn't reporting to `sc_positions` gets none either — both silently. Absence of a check-in is not proof the driver didn't go there.
- **A newly approved staging partner still lags ≤2 h** (`Org::stagingOrgIds()` has its own 2 h cache). There is no 15-min address-list cache anymore.
- **A multi-address order can produce multiple check-ins** (one per distinct address) — ~26% of hotlists are multi-address (DEV-2163).
- **`withoutOverlapping` is a TTL, not a duration cap.** The live schedule still uses `withoutOverlapping(10)` as a cache-lock expiry that nothing renews. The in-command slot semaphore is the ceiling that holds independent of runtime. The same TTL rule — and the mirror-image trap (no argument ⇒ Laravel's 1440-minute default ⇒ a hard-killed run strands the lock for a day) — is **PG-023**. It applies to every other `withoutOverlapping()` in `routes/console.php`.
- **Do not raise `slot_lease_minutes` without raising `lookback_minutes` to match** (DEV-2348). See Schedule & flag.
- **Keep both `public_address` `id IN (address_links)` prunes on `HotlistAddressGeohashQuery`.** That heatmap class still reads the zod CDC pair. The check-in matcher/rollup no longer do. `argMax(geo, _peerdb_version)` inlined into WHERE is illegal on the ClickHouse version in use (ILLEGAL_AGGREGATION) — use the `max(_peerdb_version)` join.

## Related

- [Check-in](check-in.md) — the domain definition
- [Scout Dashboard Stats](scout-dashboard-stats.md) — the on-read `ScoutCheckInRollupQuery` tile (scans-based)
- [Scout ClickHouse Queries](scout-clickhouse-queries.md) — the Scout query catalog
- [Scout Real-time Tracking](scout-real-time-tracking.md) — `sc_positions` (the GPS source)
- [Platform Gotchas](platform-gotchas.md) — PG-023 (overlap pile-up), PG-024 (`lower()` PK-bypass), PG-026 (PHP join round-trip)
- [Hotlist](hotlist.md) · [Feature Flags](feature-flags.md) · [Data Architecture](data-architecture.md)
