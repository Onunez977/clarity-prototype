## DEV-2738 — Implement plan (independent verification)

### Verification of the needles — all four confirmed

| # | File:line | Now | Next | Binding proof |
|---|---|---|---|---|
| 1 | `app/Filament/Widgets/Dashboard/UniqueScanCounts.php:53` | `Unique Scan Counts` | `Scan Counts` | `$heading` → `getHeading()` → `getCardLabel()` (`Concerns/RendersDashboardStat.php:60-76`) → `Stat::make()` at `UniqueScanCounts.php:519` |
| 2 | `UniqueScanCounts.php:114` | `Unique Scans` | `Scan Counts` | `getDatasetLabel()` consumed at `:534` → `staticColourDataset()` `:539` |
| 3 | `UniqueLprCaseClaims.php:96` | `Unique LPR Case Claims` | `LPR Case Claims` | same heading path, `Stat::make()` at `:144` |
| 4 | `UniqueLprCaseClaims.php:108` | `Unique LPR Case Claims` | `LPR Case Claims` | `getDatasetLabel()` consumed at `:170` (degraded arm) and `:176` (two-series arm) |

**#18 / #25 remain N/A — proven, not assumed.** The card key is `class_basename(static::class)` snake-cased (`Concerns/DashboardWidget.php:307-312`), and the cache namespace is the same derivation (`:290-296`, plus `UniqueScanCounts::buildWidgetCacheKey()`'s `:tz=` suffix and `ScanPlateStatsBatchQuery::sharedMonthCacheKey()`). Neither reads `$heading` or `getDatasetLabel()`. No ClickHouse query interpolates either string. So `unique-scan-counts` / `unique-lpr-case-claims`, every cache key, the Livewire `dashboard-stat-*` keys and the `toggleCard()` wiring are untouched by a copy edit. This is a four-literal change — the "label string is the only binding" escape hatch is not needed.

### Test pins — verified exhaustively

- PHP, **three** pins, all in `tests/Filament/Widgets/Dashboard/UniqueScanCountsTest.php`: `:73` `assertSeeText`, `:86` `assertDontSeeText`, `:568` `assertSame('Unique Scans', $data['datasets'][0]['label'])`.
- PHP, claims card: **zero** pins. `UniqueLprCaseClaimsTest:38-40` asserts only `getCardKey()`.
- e2e: every spec reaches these strings through the two constants — `company-metrics.ts:23` and `:29`. Confirmed consumers: `unique-lpr-case-claims.spec.ts` (`:108/:177/:197/:209/:266/:311/:342`), `lpr-repossessions.spec.ts:121`, `unique-scans-leaderboard.spec.ts:439`, and the `COMPANY_METRICS_CARD_HEADINGS` loop at `staging-live-hit-leader.spec.ts:311-324`. Editing the two constant values covers all of them; no spec hardcodes the literal in an assertion (the remaining occurrences are describe/message text).
- No substring hazard from the stale `SCAN_COUNT_HEADING = 'Scan Count'` (`company-metrics.ts:28`) once the other constant becomes `Scan Counts`: the grid loop scopes to `card(page, key)` before `toContainText`, and that entry's key `scan-count` is no longer in `CompanyMetricsOverview::cards()`, so the loop `continue`s past it. The one `{ exact: true }` match (`unique-lpr-case-claims.spec.ts:209`) stays unambiguous — `LPR Case Claims` ≠ `LPR Repossessions`, and the `Case Claims` y-axis title is canvas, not DOM.

### Steps

**T1 — the four literals.** Edit `UniqueScanCounts.php:53`, `:114`; `UniqueLprCaseClaims.php:96`, `:108`. Nothing else in either class: `Total Scans` (`:546`), `Scans per Case Claim` (`:177`, `:234`), `Case Claims` (`:219`) and both tooltip strings (`:57`, `:103`) are already clean and are AC-2's "sibling series that stay".

**T2 — update the three PHP pins.** `UniqueScanCountsTest.php:73` → `Scan Counts`; `:86` → `Scan Counts`; `:568` → `Scan Counts`. Leave `:567`'s message about the "Total Scans series" as-is — it names the *other* dataset, which is not renamed.

**T3 — the two e2e constants.** `company-metrics.ts:23` → `'LPR Case Claims'`, `:29` → `'Scan Counts'`. Leave the constant *names* alone (they track card keys, which do not move) and leave `UNIQUE_SCANS_LEADERBOARD_HEADING` at `:30` — different card, DEV-2651 AC-5 territory.

**T4 — two comments that will otherwise name titles that no longer exist.** `CompanyMetricsOverview.php:68` (row-1 line of the grid docblock) and `DashboardCardExpansionTest.php:155`. Comment-only; leave the DEV-2651 history sentences at `:72-75` and every key literal untouched.

**T5 — two assertions, recommended.** Add to `UniqueLprCaseClaimsTest::test_get_card_key()` (`:38`): `getCardLabel()` and `getDatasetLabel()` both `'LPR Case Claims'`, keeping the existing `getCardKey()` assertion. AC-2 currently has *no* PHP coverage on the claims card, and colocating it with the key assertion pins AC-3 (copy changed, key didn't) in the same method. Precedent: `RecoveryCountsTest.php:39`, `MissedOpportunitiesTest.php:49`. No new test class.

One commit, code before tests. `vendor/bin/pint --dirty --format agent` before finishing.

### Verification

`php artisan test --compact tests/Filament/Widgets/Dashboard/UniqueScanCountsTest.php tests/Filament/Widgets/Dashboard/UniqueLprCaseClaimsTest.php` — **only on request**, per the repo's execution policy. Playwright is not wired into CI for these specs; the constant edit is the deliverable there.

### Flags (not blockers)

- **Legend semantics on Scan Counts.** After T1 the expanded chart's two series read `Scan Counts` and `Total Scans`. `Unique Scans` was carrying the deduped-vs-raw distinction that the grey second series exists to contrast; the new pair no longer says it. The approved copy (prep-context AC-2, "dataset labels match the new titles") is what I've planned — the tooltip at `:57` still explains the dedup, so the meaning survives on the card. If a sharper series name is wanted, that is a product call and a separate ticket, not a silent substitution here.
- **Out of scope, confirmed by grep, leave alone:** `AffiliateStatsResource.php:48` / `AffiliateStatsExporter.php:30` (`Unique Scans` column), `UniqueScansLeaderboard.php:56` (already `Scans Leaderboard`), `UniqueLprCaseClaimsQuery.php:12` (docblock quote, not rendered), class names and file names.
- **okf:** no concept page names either title — the only hit is `okf/log.md:308`, a historical ingest entry that should not be rewritten. No wiki edit due, and none before approval regardless.
