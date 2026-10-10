---
type: DomainEntity
title: Check-in (Scout automated check-in)
description: The exact definition of a Scout automated check-in — a recorded fact that a Scout kit's GPS passed within 0.1 mi (160.934 m) of an open Direct/Staging order address during a given UTC hour — every qualifying condition, the (kit, address, hour) identity, what gets stamped on the row, and what a check-in is NOT (no hit involved; the no-hit-within-30-min rule is DEV-1758's push filter).
resource: app/Models/ScoutCheckIn.php
tags: [domain, scout, check-in, geo, postgres]
timestamp: 2026-08-19T12:45:00Z
---

# Check-in (Scout automated check-in)

A **check-in** is a persisted record that *a Scout kit's GPS passed within 0.1 mile of one of its org's open-order addresses during a given UTC hour*. It is a **coverage** event — "a driver was physically at/near this address" — recorded fully server-side by the [check-in recorder](scout-check-in-recorder.md) (DEV-2313, per the DEV-1756 spec), with no dependence on the driver's app, connection, or any camera read. Rows live in **PostgreSQL `platform.scout_check_ins`**.

## Exact definition

A check-in exists for `(kit K, address A, hour H)` **iff** all of the following held for at least one GPS fix:

1. **Scout-enabled kit.** K is assigned to a driver who has Scout access exactly as the portal grants it (mirrors `ClarityScoutMiddleware`): a `kit_settings` assignment **and** the team-scoped `clarity-scout` permission **and** the `CLARITY_SCOUT` Pennant feature active for that user. K must have a `kit_mapping`. (Recording is additionally gated globally on `config('app.experimental.clarity_scout')`.)
2. **A real GPS fix.** A `clarity.sc_positions` (ClickHouse) row for K — matched on `lower(kit_mapping)` — with non-zero coordinates, whose `timestamp_utc` falls in hour H.
3. **An open, qualifying order already in the book.** Direct (`is_staged = false`, driver's org) or Staging (`is_staged = true`, an `ApprovalList` partner). Open-status (`HotlistStatusEnum::Open` = 1) and `length(plate) > 3` are applied by `clarity.scout_open_addresses_mv`, not by the matcher.
4. **A geocoded address on that order.** A resolves through `clarity.scout_open_addresses` (the 15-minute full-refresh book from chlocal migration 111 / DEV-2383), with a parsed Point and non-zero lng/lat. An address absent from the book can never produce a check-in.
5. **Proximity.** `geoDistance(fix, A) <= 160.934 m` (**0.1 mile** — same constant as `LprVisitCountQuery` / the dashboard rollup).

**Identity & dedup:** exactly **one row per `(kit_id, address_id, hour_bucket)`** — enforced by a unique index and `firstOrCreate`. H is the **UTC** `toStartOfHour` of the fix (never the driver's local hour). A driver parked at an address across an hour boundary produces one row per hour; a multi-address order can produce one row per address; re-running the recorder never duplicates or rewrites a row (a check-in is an immutable event — first sighting wins).

## Why 0.1 mi and why an hour — provenance

Neither constant is arbitrary; both are the company's **existing, shipped definition of "a vehicle passed a location once"**, inherited from **LPR Visits** (DEV-1661, `LprVisitCountQuery`):

> *"An 'LPR Visit' represents one pass-through of a location by an LPR-equipped vehicle … group scans into 1-hour time blocks (`toStartOfHour`); each 1-hour block with at least one scan = 1 visit."*
> Customer-facing tooltip: *"…Scans are grouped into 1-hour time blocks — each block with at least one scan counts as one visit."*

DEV-1661 fixed a **~60× inflation** (392 raw scans at one address → **6** actual visits) caused by counting raw reads instead of hour blocks. A check-in is the GPS analogue of that same event, so it reuses both the **0.1 mi / 160.934 m** radius and the **1-hour block** verbatim. The rationale is *stronger* here: GPS fixes arrive every ~3 s (far denser than plate reads), so without the hour bucket a single parked driver would generate thousands of "check-ins" per address.

## Where the definition comes from (ticket trail)

| Source | What it establishes |
| --- | --- |
| **DEV-1756** (Done, epic DEV-1623) | The origin spec: *"When a GPS position matches an address tied to a Direct Order, Staging Order, or HH/AA, automatically record it as a check-in… **Every match should be tracked regardless of assignment type**"* — GPS-driven, automated, **no** hit-based exclusion. |
| **DEV-1661** | The 160.934 m radius + 1-hour block rule (above). |
| **DEV-2163** | Corrected the address source to `public_hotlist_address → public_address` (`recovery_address` is repossession-time and has ~no Open rows). |
| **DEV-2313** + Oscar | Scope = open **Direct + Staging** (supersedes "direct only"); dedup = one per **(kit, address, hour)**; historical/closed out of scope. |
| **DEV-1758** (Jeff) | The *"no hit within 30 min, direct-only"* rule — a **push-time filter for the RDN case update**, not part of the check-in definition. |
| **DEV-2386** | Matcher and rollup read `clarity.scout_open_addresses`. Schedule re-enable. Book freshness is chlocal-owned (no platform `refreshed_at` gate). |

**On DEV-1756's "running count per address":** satisfied **by derivation**, not a stored counter — the count for an address is `COUNT(*) FROM platform.scout_check_ins WHERE address_id = ?` (the `(org_id, address_id)` index supports it), and DEV-1757's two tiles (Direct vs Staging) are a `countIf` split on `is_staged`. Because each row is already one deduped (kit, address, hour) event, counting rows *is* the running count — no separate counter column to drift.

## What gets stamped on the row

All from the **earliest** qualifying fix in the bucket (deterministic `argMin` on `timestamp_utc`):

| Column | Meaning |
|---|---|
| `kit_id` / `org_id` | the kit and the **driver's** org (the org whose order book was matched) |
| `address_id` | the matched `clarity.scout_open_addresses` address_id (still a `zod.public_address` id upstream) |
| `hotlist_id` / `is_staged` | the order the address was matched through; when an address is on **both** a Direct and a Staging open order, **Direct wins** (then lowest hotlist id) — the label never flips between runs |
| `hour_bucket` | UTC hour of the fix (`timestampTz`) |
| `matched_at` | the earliest qualifying fix's timestamp |
| `lat` / `lng` | that earliest fix's coordinates (single-tuple `argMin` — never blended across two fixes) |

## How it gets recorded (mechanism summary)

`scout:record-check-ins` runs every five minutes (re-enabled in DEV-2386), flag-gated, with a **15-minute lookback** (`lookback_minutes`). It does **not** skip on book `refreshed_at` — chlocal owns rebuild freshness. Matches each kit's GPS in **one ClickHouse statement** (`ScoutCheckInMatchQuery::handle()` — the address book never enters PHP). Matches are persisted with `insertOrIgnore` against `unique(kit_id, address_id, hour_bucket)`. A hard 2-slot `Cache::add` ceiling (heartbeat-refreshed; lease 10 min ≤ lookback 15) bounds concurrency. Full mechanics, performance, and failure modes: [Scout Automated Check-in Recorder](scout-check-in-recorder.md).

## What a check-in is NOT — read before consuming the table

- **Not a [Hit](hit.md).** No camera read, plate match, or hit row is involved anywhere in the definition — it is pure GPS proximity. DEV-2258's phrasing is the intent: coverage "*even without a camera hit*".
- **No hit-based exclusion.** The recorder records **every** qualifying pass, including passes where a hit *was* created. Jeff's rule — "no hit created for the hotlist address within 30 minutes, direct work only" — is **DEV-1758's push-time filter** deciding which recorded check-ins get sent to RDN as case updates (direct-only because only Direct orders carry `remote_order_id`); it is *not* part of what a check-in is. A consumer that treats `scout_check_ins` as "visited but didn't find the car" **will overcount** — it must apply its own hit exclusion.
- **Absence is not proof the driver skipped the address.** An un-geocoded address, a kit not reporting to `sc_positions`, a CH outage that cycle, a fix arriving in CH more than ~15 min late, or an order opened after the last MV refresh (invisible for up to ~15 min) all silently produce *no* check-in.
- **Not real-time truth of the order book.** A newly approved staging partner lags up to ~2 h (`Org::stagingOrgIds()` cache). There is no PHP address-list cache; open/closed orders are whatever ClickHouse sees in that cycle.
- **Not the dashboard check-in *count*.** The Scout dashboard tile (`ScoutCheckInRollupQuery`, hidden) computes counts **on read from scans**; this table is the persisted GPS-based record. Same radius and address enumeration, different source and storage — see [Scout Dashboard Stats](scout-dashboard-stats.md).

## Worked example

Kit 16's scanner reports fixes at 12:02 and 12:04 within 90 m of `123 Main St`, an address on open Direct order #555 (own org) — and the same address is also on approved-staging order #777. At the next recorder run, one row is created: `(kit 16, address 123-Main, hour 2026-08-11 12:00 UTC)`, `hotlist_id = 555`, `is_staged = false` (Direct wins), `matched_at = 12:02`, coords of the 12:02 fix. The 12:04 fix, later runs re-seeing the same window, and the staging order add nothing. If the driver is still there at 13:01, a **second** row is created for hour 13:00.

## Consumers

None yet by design (the Scout UI stays hidden). Named future consumers:

- **DEV-1758** — RDN case-update push; applies its own *no-hit-within-30-min, direct-only* filter over these rows.
- **DEV-2258** — priority-address dispatch/coverage checklist ("% covered").
- **DEV-1757** (built, unmerged, hidden) — the two Scout tiles: Direct+HH vs Staging+AA counts, i.e. a `countIf` split on `is_staged`. Note the original pairing (HH with Direct, AA with Staging) is consistent with HH/AA being *hit-type* categories — historical direct / historical staging — not a separate address source; see [Scout Analytics Taxonomy](scout-analytics-taxonomy.md).
- **DEV-2251** (To Do) — zone coverage progress indicator. Scoped against raw breadcrumb/GPS, **not** check-ins; related but a different measure — don't conflate zone-area coverage with per-address check-ins.
