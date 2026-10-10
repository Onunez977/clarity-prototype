# DEV-2801 prep-context

**Re-GET:** 2026-10-06 ~15:36 CDT · Jira To Do · FG comment **43292** · Branch path **main→main**
**Base SHA:** `551ce86e43ba7e2678fac6e19d66fa3e0436cf53` (`origin/main`)
**Branch:** `cursor/dev-2801-cm-lpr-hotlist-single-join`
**Reach #31:** PASS · status `torn_down` · Path A not stood

## Intent (restated)

DEV-2660 shipped the recoveries timestamp swap (`clarity.hotlist.first_repossessed_at`) while **keeping** the `zod.public_hotlist FINAL` (org, vin, status=3) match join. That dual-join is intentional for 2660. This ticket evaluates whether match + timestamp can collapse onto a **single** `clarity.hotlist` join. Collapsing changes match semantics (asymmetric CDC: FINAL public_hotlist vs no-FINAL clarity.hotlist, ~13.3% raw over-count) — not a timestamp-only swap. Ship only if a fresh Cloud READ-ONLY #25 clears vs the post–2660 baseline.

## Surface scout (post–DEV-2660 on main)

| File | Role | Lines of interest |
|------|------|-------------------|
| `app/Clickhouse/Queries/LprRepossessionsQuery.php` | Dual-join recoveries leg | Docblock L39–66 (dual-join intentional); `recoveryJoins()` L275–294 (`public_hotlist FINAL` + `clarity.hotlist`); `REPO_PREDICATE` L201; listing joins L301–306; decoration still uses `public_hotlist FINAL` for plate L311–314 |
| `tests/Feature/Clickhouse/LprRepossessionsQueryTest.php` | Query fixtures / sentinels | 1127 lines — agent_org_id != 0, unmatched-id default-0 |
| `app/Filament/Widgets/Dashboard/LprRepossessions.php` | CM card UI | 731 lines — consumers shared cache keys |
| `app/Ai/Tools/Metrics/LprRepossessionsTool.php` | AI tool surface | soft collision |
| `app/Filament/Exports/LprRepossessionExporter.php` | CSV export (DEV-2904) | listing SQL path |
| `tests/Filament/Widgets/Dashboard/LprRepossessionsTest.php` | Widget tests | |
| `tests/Filament/Exports/LprRepossessionExporterTest.php` | Export tests | |

### Dual-join shape (current)

```
LEFT JOIN (public_hotlist FINAL WHERE deleted=0 AND status=3) AS h
  ON h.org_id = d.agent_org_id AND h.vin = d.vin AND d.agent_org_id != 0
LEFT JOIN (clarity.hotlist WHERE deleted=0 AND first_repossessed_at >= {floor}) AS ch
  ON ch.id = h.id AND h.id != 0
```

VIN on match leg: **un-normalised** (`h.vin = d.vin`). Do not change unless deliberate re-measure.

### Evaluate collapse (Gate 1)

Candidate single join (illustrative — Planner decides / documents):

```
LEFT JOIN (
  SELECT id, org_id, vin, first_repossessed_at FROM clarity.hotlist
  WHERE _peerdb_is_deleted = 0 AND status = 3  -- if status projected
    AND toDate(first_repossessed_at, 'UTC') >= {floor}
) AS ch ON ch.org_id = d.agent_org_id AND ch.vin = d.vin AND d.agent_org_id != 0
```

Open: does `clarity.hotlist` project `status` / org / vin needed for match? Cardinality vs FINAL public_hotlist? Keep dual-join if CDC/FINAL/vin/cardinality reason holds.

## Hard stops

- Do **not** reopen DEV-2660 tip/undraft (PR #470 MERGED).
- No Cloud CH writes — READ-ONLY measure only.
- Do **not** touch `hotlist_status_aggregate`.
- VIN un-normalised unless deliberate re-measure.
- Do **not** invent Mac/localhost CH; Path A is Galadriel future stand.
- Spec must include `ch_touch: yes` in test map.

## Forecast gates

1. Documented decide: collapse **or** keep dual-join with concrete CDC/FINAL/vin/cardinality reason.
2. If collapse: #25 Cloud READ-ONLY parity vs post–2660 baseline (agreement %, Δ, read_bytes/memory).
3. If collapse: single hotlist join + sentinel fixtures (`agent_org_id != 0`, unmatched-id default-0).
4. Agent QA real Docker CH (#18) + UI evidence on CM LPR Repossessions card (#27).
5. Class docblock updated.
6. Spec `ch_touch: yes` (#31).

## Out of scope

- Re-opening DEV-2660 product bar
- Cloud/prod CH writes
- `hotlist_status_aggregate`
- DEV-2892 hotlist_status_transitions
- Provision / Path A stand in this Prep→Plan turn

## Collisions (soft)

- DEV-2660 / DEV-2657 / DEV-2904 — LprRepossessions surfaces
- DEV-2892 — out of scope

## Preflight cites

#23 Kiro harness · #24 simple wins · #25 old-vs-new parity · #26 Branch path · #30 trust allowlist · #31 ch_touch + Reach · #18 real Docker CH · #17 no bot names on Jira/GitHub · #29 Galadriel-first
