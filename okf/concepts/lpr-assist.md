---
type: DomainEntity
title: LPR Assist
description: A recovery credited to LPR — the target plate was scanned within ~0.1 mi of the eventual repo location. Product meaning, attribution rules, clarity.recovery_scans → clarity.lpr_assists view (no FINAL; best-effort distinct count), and Client Analytics counting via LprAssist.
resource: app/Clickhouse/Models/LprAssist.php
tags: [domain, lpr, recovery, scan, client-analytics, clickhouse]
timestamp: 2026-09-24T16:50:00Z
---

# LPR Assist

An **LPR Assist** is credit that license-plate recognition helped recover a vehicle. Product-facing: *“recoveries where our scanning equipment detected the target vehicle within 0.1 miles of where the recovery ultimately took place.”* Engineering-facing: a [Recovery](recovery.md) whose id appears in ClickHouse `clarity.recovery_scans`, exposed as the view `clarity.lpr_assists` (no `FINAL` on the junction; best-effort distinct count — see [View semantics](#view-semantics-no-final-on-the-junction)).

It answers: of the repos we closed, how many did LPR actually help find near the tow?

## What it means (product aka Oscar Nunez)

- **One assist = one recovery**, not one scan. Ten scans of the same plate near the same repo still count as **one** LPR assist.
- It measures **LPR contribution to successful repos**, not assignment volume or hit volume.
- Clients see it on Client Analytics (`LprAssistsWidget`, lifetime variant, and the Client table’s LPR Assists column — often with assists ÷ repos as a share). Higher is better: more of your recoveries were informed by a nearby plate read.
- It is **not** a Missed Opportunity. Missed opportunities are vehicles you scanned but did **not** hold (and did not recover). An LPR assist is a vehicle you **did** recover, with LPR evidence near the recovery site. See [Missed Opportunity](missed-opportunity.md).

Product copy lives in `resources/views/filament/pages/client-analytics-guide.blade.php` (Metric: LPR Assists).

## What it is not


| Not this                              | Why                                                                                                      |
| ------------------------------------- | -------------------------------------------------------------------------------------------------------- |
| A count of scans or hits              | Metric is `count(DISTINCT recovery_id)`.                                                                 |
| Every repossession                    | Only recoveries with a qualifying near-repo scan join.                                                   |
| “Any hit on this hotlist before repo” | Attribution is **geo + plate + time-window**, not “any hit existed.”                                     |
| Scout check-in / GPS pass             | Check-ins are GPS↔address coverage ([Check-in](check-in.md)); assists are plate scans near recovery geo. |
| A PostgreSQL write path               | Assists are **ClickHouse analytics**. There is no `platform.lpr_assists` table to insert into.           |




## Attribution (how a recovery becomes an assist)

A recovery is LPR-assisted when at least one [Scan](scan.md) qualifies against that recovery’s plate and location. The junction rows live in **ClickHouse** `clarity.recovery_scans` (`ReplacingMergeTree`). Scoring is ClickHouse-native (materialized views / refresh jobs — chlocal / Cloud DDL), not a Postgres procedure.

Rules that define “qualifying” (product + Cloud scoring SQL agree):

| Rule | Detail |
|------|--------|
| **Plate** | Scan OCR matches the recovered vehicle’s plate (`s.ocr = h.plate`). |
| **Distance** | Scan geo within **~0.1 miles** of the recovery location — implemented as `greatCircleDistance(...) <= 162` meters. |
| **Time window** | Scan `scanned_at` must fall in **`[repo_date − 90 days, repo_date + 1 day]`** (see [Time window (exact)](#time-window-exact) below). |
| **Org / kit** | Scans from kits belonging to the recovering org. |
| **Status** | Recovery/hotlist is repossessed (`status = 3`) with a real `repo_date` and geocoded recovery location. |

### Time window (exact)

The Cloud scoring job that credits kit scans to a recovery (`clarity.aggregate_recovery_kit_scans_backfill` → `clarity.aggregate_recovery_kit_scans`) hardcodes this predicate — source: `dev/migrations/cloud_export/cloud_clarity_schema.sql`:

```sql
s.scanned_at >= (toDateTime(hm.repo_date) - toIntervalDay(90))
AND s.scanned_at <= (toDateTime(hm.repo_date) + toIntervalDay(1))
```

`repo_date` is a **Date** (calendar day of the repossession). `toDateTime(Date)` in ClickHouse is **midnight (00:00:00) of that day**. So the bounds are calendar-math, not “vibes”:

| Bound | Expression | Meaning |
|-------|------------|---------|
| **Start (inclusive)** | `midnight(repo_date) − 90 days` | Earliest qualifying scan: start of the calendar day 90 days before the repo. |
| **End (inclusive via ≤)** | `midnight(repo_date) + 1 day` | Latest qualifying scan: up through the **entire repo calendar day**, ending at midnight of the next day. |

**Worked example.** If `repo_date = 2026-08-15`:

- Start = `2026-05-17 00:00:00`
- End = `2026-08-16 00:00:00`
- A scan at `2026-08-15 23:59:59` **counts**. A scan at `2026-08-16 00:00:01` **does not**.

So “+ 1 day” is **not** “we credit scans that happened a day after the tow.” It is the standard way to say “include all of `repo_date`” when the left side is a DateTime and the right side started as a Date at midnight. The product intent is: scans from **90 days before the repo day through the end of the repo day**.

**What `lookback_days` is (and is not).** Hotlists and recoveries carry a `lookback_days` column (factory default **270**). That field is the historical-hit window for PostgreSQL `find_historicals()` — how far back a new/changed hotlist looks for already-captured scans when inserting `H`/`Z` hits (see [Hit](hit.md), [Hotlist](hotlist.md)). The LPR-assist / kit-scan scoring MV **does not read `lookback_days`**; it hardcodes **90**. The column is copied onto aggregate rows for reporting denormalization, but it is **not** the assist attribution window. Do not treat “hotlist lookback = 270” and “assist lookback = 90” as the same knob.

**Job processing window (separate).** The same backfill also filters `hm.repo_date >= (now() - toIntervalMonth(1))` — it only *fills* recoveries from roughly the last month on each refresh. That is a **batch scope** for which recoveries get (re)scored, not the scan lookback that defines an assist.

Once those junction rows exist, the assist set is trivial:

```sql
-- clarity.lpr_assists (View)
SELECT DISTINCT recovery_id
FROM clarity.recovery_scans
WHERE _peerdb_is_deleted = 0
```

Schema dump: `database/schema/clickhouse-schema.sql`. Older Cloud export also documents kit-scan aggregation with the same 162 m rule in `dev/migrations/cloud_export/cloud_clarity_schema.sql`.

## View semantics (no FINAL on the junction)

`clarity.lpr_assists` is a ClickHouse **view** with **no `FINAL`** on `clarity.recovery_scans`. The SQL block above is the live definition (schema dump and Cloud `SHOW CREATE` on 2026-09-24 agree). It filters `_peerdb_is_deleted = 0` and returns `SELECT DISTINCT recovery_id` only.

The Client Analytics metric that reads this view is **best-effort**. `clarity.recovery_scans` is a `ReplacingMergeTree` (Cloud: `SharedReplacingMergeTree` versioned by `_peerdb_version`). Dedup is **not guaranteed without `FINAL`** ([Data Architecture](data-architecture.md)). Two latent risks remain even when today's distinct counts match a `FINAL` read:

- **Tombstones.** A deleted junction row (`_peerdb_is_deleted != 0`) can stay visible until it is merged away, so a recovery can remain in the view after its qualifying scans are logically deleted.
- **Merge lag.** Unmerged duplicate versions can change row counts without changing `uniqExact(recovery_id)`. They can also hide a newer tombstone until merge.

**Prod measure 2026-09-24** (Cloud read-only, 11:30:11 CDT): **delta 0**. `view_uniq` = `final_uniq` = **269429** distinct `recovery_id`. Tombstoned rows were 0. About 4,864 unmerged duplicate-version rows existed and did not change the distinct set. The previous calendar month was also delta 0 (7,573). Artifacts: [measure.md](../../docs/investigations/DEV-2429/measure.md), [measure.json](../../docs/investigations/DEV-2429/measure.json).

### Intentional difference vs FINAL-on-junction admin paths

DEV-2429 disposition is **accept + OKF**. Do not rewrite the view or `LprAssist` to add `FINAL` under this ticket.

| Path | Junction read | Role |
| --- | --- | --- |
| Client Analytics `LprAssist` | `clarity.lpr_assists` (no `FINAL` on `recovery_scans`) | Customer distinct-assist metric |
| `RecoveryLeaderQuery` | `clarity.recovery_scans FINAL` and `_peerdb_is_deleted = 0` (`app/Clickhouse/Queries/RecoveryLeaderQuery.php`) | Admin fractional kit credit. Closest in-tree FINAL-on-junction analogue. |
| Card-cited repossession-count comparator class (not on main) | `recovery_scans FINAL` and deleted = 0 | Admin repossession-count comparator cited on the card (`LprRepossessionCountQuery`). **That class is not on main.** Do not port it. |

Those FINAL-on-junction reads and the view **agree on distinct `recovery_id` counts today** (global delta 0) and **differ by design** after this accept. A later tombstone or an unmerged delete can move them apart. Matching the admin `FINAL` pattern is a new ticket (view DDL or an app query), not a drive-by fix here. Any junction mutate stays with the Staging Live / RecoveryLeader recovery_scans accuracy work; this closeout does not change that table.

## How we count it (dashboards)

`App\Clickhouse\Models\LprAssist` does **not** use Eloquent scopes for the metric — assists require a join, so the model builds SQL:

1. From `clarity.lpr_assists la`
2. `JOIN clarity.recoveries r FINAL ON r.id = la.recovery_id`
3. Filter `r.org_id`, `r._peerdb_is_deleted = 0`, `r.repo_date` in `[start, end)`, optional `branch_id` / `client_org_id`
4. Aggregate `count(DISTINCT la.recovery_id)`

API surface on the model:


| Method                                   | Use                                               |
| ---------------------------------------- | ------------------------------------------------- |
| `getTotal(...)`                          | Org (or client/branch) total for a date range     |
| `groupedByClient` / `groupedByClientIds` | Per-`client_org_id` totals (batch + Client table) |
| `byMonth` / `byClientAndMonth`           | Sparklines / drill-downs                          |


Batch path: `ClientRankedMetricsBatch` → `getLprAssistMetrics()` → `LprAssist::groupedByClientIds()`, key `'lpr_assists'`. See [Client Analytics](client-analytics.md).

Date filtering is on `recoveries.repo_date`, not scan time — an assist “happens” in the period the vehicle was repossessed.

## Related surfaces (same junction, different math)


| Surface                    | Table / model                                                                       | What it measures                                     |
| -------------------------- | ----------------------------------------------------------------------------------- | ---------------------------------------------------- |
| LPR Assists metric         | `clarity.lpr_assists` → `LprAssist`                                                 | Distinct assisted recoveries                         |
| Recovery Leader            | `clarity.recovery_scans FINAL` via `RecoveryLeaderQuery`                            | Fractional credit per kit across assisted recoveries |
| Kit / recovery scan report | `clarity.recovery_kit_scan_report` / PG `aggregate_recoveries_kituser_scans_report` | Per-kit scan counts & share for reporting            |




## Data map


| Layer                  | Where                                                        |
| ---------------------- | ------------------------------------------------------------ |
| Transactional recovery | PostgreSQL `platform.recoveries` (`App\Models\Recoveries`)   |
| Analytical recovery    | ClickHouse `clarity.recoveries`                              |
| Scan↔recovery junction | ClickHouse `clarity.recovery_scans`                          |
| Assist set (view)      | ClickHouse `clarity.lpr_assists` — **only** `recovery_id`    |
| App read model         | `app/Clickhouse/Models/LprAssist.php` (`clarity_clickhouse`) |


Model docs: [lpr_assists](../models/clickhouse/lpr_assists.md), [recovery_kit_scan_report](../models/clickhouse/recovery_kit_scan_report.md), [recoveries](../models/recoveries.md).

## Rules for agents / implementers

- **Count distinct recoveries**, never raw `recovery_scans` rows, for the LPR Assists KPI.
- **The view has no `FINAL`.** Client Analytics counts are best-effort. Latent tombstone and merge-lag risk is accepted (DEV-2429, prod delta 0 on 2026-09-24). Do not add `FINAL` to `clarity.lpr_assists` or `LprAssist` to match `RecoveryLeaderQuery` without a new ticket.
- **Join recoveries with** `FINAL` and `_peerdb_is_deleted = 0` — the view itself has no CDC columns; soft-deletes on the recovery side live on `clarity.recoveries`. Junction deletes are the latent risk above, not something this view collapses with `FINAL`.
- **Filter by** `repo_date`, not `scanned_at`, when matching dashboard date pickers.
- **Assist scan window is hardcoded 90d → end of repo day** — do not substitute `hotlist.lookback_days` (that's historical hits).
- **Do not invent a PG write** for assists; seed tests via the junction / kit-scan report factories the suite already uses (`ClientAnalyticsTestSeeder::seedLprAssists`).
- Table name is `clarity.lpr_assists` (plural). Older wiki rows that said `clarity.lpr_assist` were typos.



## Where it lives in code

- ClickHouse model: `app/Clickhouse/Models/LprAssist.php`
- Widgets: `app/Filament/Widgets/Client/LprAssistsWidget.php`, `app/Filament/Widgets/Client/Lifetime/LifetimeLprAssistsWidget.php`
- Batch / sparklines: `app/Clickhouse/Queries/Client/ClientRankedMetricsBatch.php`, `BatchMonthlySparklineData.php`
- Client table column: `app/Filament/Widgets/Client/ClientTable.php` (`lpr_assists_*`)
- Drill-down: `app/Filament/Pages/Concerns/HasStatDrillDown.php` (`getLprAssistsData`)
- Recovery Leader (related): `app/Clickhouse/Queries/RecoveryLeaderQuery.php`
- Product methodology: `resources/views/filament/pages/client-analytics-guide.blade.php`
- Schema: `database/schema/clickhouse-schema.sql` (`recovery_scans`, `lpr_assists`)



## See also

- [Recovery](recovery.md) — the repossession being credited
- [Scan](scan.md) — the plate read that provides assist evidence
- [Kit](kit.md) — who scanned
- [Missed Opportunity](missed-opportunity.md) — inverse story (scanned, not recovered / not held)
- [Client Analytics](client-analytics.md) — dashboard metric wiring
- [Data Architecture](data-architecture.md) — PG vs ClickHouse placement
- [ClickHouse Integration](clickhouse-integration.md) — `clarity.recovery_scans` / views

