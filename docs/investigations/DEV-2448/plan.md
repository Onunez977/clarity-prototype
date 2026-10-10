# DEV-2448 — Implementation plan

**Add a Clarity page listing GL kits with owner, last scan, and SC_POSITIONS reporting status**

Route: feature · Base: `main` · Branch: `claude/DEV-2448` · PR [#376](https://github.com/insight-lpr/platform/pull/376)

## Decisions locked at the gate

| # | Question | Answer |
|---|---|---|
| Q1 | "Last scan received" source | **`zod.kit_last_scan`** (0.25 MB/load vs 9,586 MB for `clarity.scans_cold`) |
| Q2 | ClickHouse read failure | **Explicit "Unknown"** state, distinct from a confirmed "Not reporting" |
| Q3 | Access gating | **`RoleEnum::ADMIN`** for v1; no `black-adam` `PermissionEnum` change |
| Q4 | Shared kits | **Owned-only**; "Company owner" shows the viewing org (a deliberate constant column) |

Answered from code + data during prep: "GL kits" = `kit_type = 1` (the only type present in `sc_positions`); the 14-day window is UTC; "last scan received" counts all visibilities; "each company its own page" needs no new mechanism.

## Shape

```
app-panel request (an admin belongs to exactly one org)
  └─ GlKitsResource::getEloquentQuery()          ← PostgreSQL zod.kits: THE TENANCY BOUNDARY
       org_id = auth org · kit_type = 1 · excludingDeleted() · with('org')
  └─ table render, two once()-memoized batch calls (never per-row):
       ├─ OrgKitLastScanQuery      → zod.kit_last_scan       0.23s / 0.25 MB   (measured)
       └─ OrgKitReportingStatusQuery → clarity.sc_positions   0.20s / 167 MB    (measured)
  └─ row closures do O(1) lookups into the two maps
```

Both queries receive an id/mapping list **already scoped by the Eloquent query** and must never derive scope themselves.

## Subtasks

**t1 · `OrgKitLastScanQuery`** — parallel, no deps · filter `OrgKitLastScanQueryTest`

`max(last_scan) GROUP BY kit_id`, `WHERE org_id = ? AND kit_id IN (?)`.
Two traps a reviewer should check: `max()` is required because the table is a `ReplacingMergeTree` that was *observed un-merged* mid-prep; and tenancy must come from the caller's PostgreSQL-resolved ids because the `(org_id, kit_id)` sort key leaves stale rows under a kit's former org (**275 of 4,755 kits** measurably affected).

**t2 · `OrgKitReportingStatusQuery`** — parallel, no deps · filter `OrgKitReportingStatusQueryTest`

`max(timestamp_utc) GROUP BY lower(kit_mapping)`, `WHERE timestamp_utc >= <14d UTC> AND lower(kit_mapping) IN (?)`.
`lower()` on **both** sides is mandatory — ClickHouse `IN` is case-sensitive, platform lowercases `kit_mapping`, the scanner feed does not, and the failure is silent and total (every kit reads false). Confirmed by `EXPLAIN` to use DEV-2381's `proj_kit_mapping_lc` projection (13 of 42,128 granules).

**t3 · `GlKitsResource` + `ListGlKits`** — depends on t1 + t2 · filter `GlKitsResourceTest`

Read-only Resource, `Reports` nav group, `canAccess()` on `RoleEnum::ADMIN`. Four columns; the reporting column renders **three** states. Both lookups call `::handle()` inside their own `try/catch` — **never `::query()`**, which swallows `Throwable` to `[]` on production and would render an outage as a fully dark fleet. Caught failure → `null` → "Unknown"; successful-but-absent → `false` → "Not reporting".

Note: use a plain `TextColumn` with `getStateUsing()`, not the shared `DateTimeColumn` — that class reads the Eloquent attribute directly and cannot display a cross-connection computed value.

## Deliberately excluded

- **`FilamentServiceProvider::$filterableResources`** — no filters in v1, so nothing to register. This is the considered answer to the grep backstop's strongest finding, not an omission.
- **`OrgLivePositionsQuery` and its 3 consumers** — not touched. t2 adds a new class specifically so Scout Bird's Eye stays out of this change's blast radius.
- **The stale `clickhouse-schema.sql` sort keys** — a real defect, but it misrepresents the system's two largest tables to every reader; wider than this ticket, and this change reads neither table. Needs its own ticket.
- **Four `okf/` wiki corrections** — held for the post-approval pass per CLAUDE.md.

## Sizing gate

7 files (4 production, 3 tests), **one** concern. Not split: the page cannot ship without its data sources, the queries have no other consumer until DEV-2450, and splitting would produce two tickets that must merge together. Recorded so the judgement can be challenged rather than left implicit.

## Review

**3 lanes.** Two triggers fire independently: semantic-correctness territory (date-boundary + dedup/aggregation logic) and `scale_relevant = true`. No node carries a blast-radius tag; re-checked mechanically at Phase 4 anyway.
