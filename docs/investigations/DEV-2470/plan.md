# DEV-2470 — implementation plan

One subtask, five files, one concern. Sizing gate: pass (≤6 files, single concern).
Blast radius: none — no migration, billing, auth/Shield, or ClickHouse purge/cleanup path is touched.

## The metric

`unique-scans-to-repo` = **org-wide unique scans ÷ total repossessions** (option B).
A ratio, rendered to one decimal, Pro-gated behind `COMPANY_METRICS`.

- **Numerator** — `ScanPlateStatsBatchQuery` (`uniqExact(ocr)` over `clarity.scans_cold`),
  driven at `'UTC'`. Already shared by `UniqueScanCounts` and `UniqueScansToLprRepossession`.
  No new numerator logic.
- **Denominator** — new `RecoveryTotalsBatchQuery` over `clarity.recoveries`: the same
  quantity `RecoveryCounts` renders as "Total Repossessions", but in **one** batched round
  trip instead of thirteen.

## Files

| file | change |
| --- | --- |
| `app/Clickhouse/Queries/RecoveryTotalsBatchQuery.php` | **new** — batched monthly series + window scalar |
| `app/Filament/Widgets/Dashboard/UniqueScansToRepo.php` | stub body → ratio card |
| `tests/Filament/Widgets/Dashboard/UniqueScansToRepoTest.php` | **new** |
| `tests/Feature/Clickhouse/RecoveryTotalsBatchQueryTest.php` | **new** (`RequiresClickhouse`) |
| `.../Concerns/DashboardCardVisibilityTest.php` | gated allowlist EIGHT → NINE |

Untouched on purpose: `CompanyMetricsOverview::cards()`, `DashboardWidget::getStatColor()`,
`DashboardCardExpansionTest`, `tests/e2e/unique-scans-to-case-claims.spec.ts` — all four already
account for this card at this key and this slot, and the card keeps both.

## The five decisions this plan makes

1. **`count()`, not `uniqExact(id)`** — so the denominator equals the Total Repossessions card
   two slots away. Measured drift 2–7 rows/org over 13 months (~0.02%), invisible at one decimal.
   *Flagged for review to challenge.*
2. **The denominator propagates on failure; the numerator degrades.** Deliberately asymmetric —
   see `plan.json:decisions.d2`. Short version: a zero denominator is a normal reading, so a
   swallowed failure would be indistinguishable from real data; and `RecoveryCounts` already
   reads this table with no catch, so the grid-500 blast radius exists either way. The numerator
   must degrade because `ScanPlateStatsBatchQuery`'s namespace carries a shared backoff contract.
3. **Per-month denominator cache + one batched gap fill**, not one whole-series entry — a
   10-minute whole-series TTL would re-scan twelve immutable months ~144×/day/org, the exact
   shape DEV-2469 RN2 failed its gate on. Closed months ride `getCacheTTL(1)` (1 year), which is
   already what `RecoveryCounts` does for this same quantity.
4. **No cold mutex on the denominator.** 281,882 rows / 5.91 MB / 52.3 MB peak / ~15 ms —
   ~2,000× lighter than the leg that justified the sibling's guard machinery.
5. **`toStartOfMonth(repo_date)`** — a `Date` column carries no timezone axis, so the series
   cannot mix clocks with the UTC-pinned numerator. The window arm stays viewer-local (both legs
   take explicit instants), matching `RecoveryCounts::between()`.

## Inherited risk, disclosed not absorbed

The numerator leg has **no cold mutex** (bare `cache()->get`/`put`) at ~2.44 GB server peak per
concurrent copy on a ClickHouse server with `max_memory_usage = 0`. This card is the **third**
reader of that unguarded leg. Fixing it is a disclosed DEV-2469 residual with its own follow-up
ticket and is out of scope here — but it belongs in the Build Record and the PR, not in silence.

Mitigating, and worth stating precisely: because this card pins the numerator to `'UTC'` exactly
as DEV-2469 does, the two cards address the **same** `sharedMonthCacheKey(org, month, 'UTC')`
entries. So this card adds a third concurrent *reader*, not a third independent `scans_cold`
pass — unlike DEV-2469, which added a genuinely new pass over `UniqueScanCounts`' viewer-tz keys.

## The definition is an engineering call, not Oscar's answer

Oscar never answered. The operator overrode the ticket's "do not build until Oscar answers"
blocker on 2026-09-14, with the risk stated and reaffirmed, on the strength of Oscar's own words
(*"Repo is just the total repos that occurred for that company"*, DEV-2409 c39277). Recorded in
`prep-context.blocker_disposition`. The Build Record must say this plainly.
