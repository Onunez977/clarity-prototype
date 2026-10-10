# DEV-2738 — Ultra-Plan

Input: `docs/investigations/DEV-2738/prep-context.json`. Gate **CLEAN**.

Machine contract: `plan.json`.

## What ships

Four PHP string literals, the tests that pin them, two e2e heading constants, and two comments that name the displayed row-1 titles.

| Surface | File:line | Now | Next |
| --- | --- | --- | --- |
| Widget title | `UniqueScanCounts.php:53` | Unique Scan Counts | Scan Counts |
| Chart dataset | `UniqueScanCounts.php:114` | Unique Scans | Scan Counts |
| Widget title | `UniqueLprCaseClaims.php:96` | Unique LPR Case Claims | LPR Case Claims |
| Chart dataset | `UniqueLprCaseClaims.php:108` | Unique LPR Case Claims | LPR Case Claims |

`getCardLabel()` reads `getHeading()`, which is `$heading`. `Stat::make` at `UniqueScanCounts.php:519` and `UniqueLprCaseClaims.php:144` follows the heading. No second title string.

`getData()` passes `getDatasetLabel()` into the count series at `UniqueScanCounts.php:534-539` and `UniqueLprCaseClaims.php:170` and `:176`.

## What does not ship

No new helper, trait, card class, or rename. Preflight #24: the win is the literal.

No query edit. Preflight #18 N/A. Preflight #25 N/A. The label is not a query binding. Proof: under `app/Clickhouse/`, the rendered phrases appear only in the docblock at `UniqueLprCaseClaimsQuery.php:12`. SQL, card keys (`CompanyMetricsOverview.php:105-106`), and cache namespaces do not contain them.

Leave these rendered strings. They already have no Unique:

- `UniqueScanCounts.php:546` `Total Scans`
- `UniqueLprCaseClaims.php:177` and `:234` `Scans per Case Claim`
- `UniqueLprCaseClaims.php:219` axis title `Case Claims`
- Tooltips at `UniqueScanCounts.php:57` and `UniqueLprCaseClaims.php:103`

Leave Affiliate Stats `Unique Scans` (`AffiliateStatsResource.php:48`, `AffiliateStatsExporter.php:30`). Leave `UniqueScansLeaderboard.php:56` (`Scans Leaderboard`). Leave the stale e2e constant `Unique Scans Leaderboard` at `company-metrics.ts:30`. That is a different card and a deferred Playwright suite. Do not reopen DEV-2651.

Do not edit `okf/` in this change. No concept page currently names these two titles.

## Tests

Update pins:

- `UniqueScanCountsTest.php:73` and `:86` — `Scan Counts`
- `UniqueScanCountsTest.php:568` — dataset label `Scan Counts`

Add two assertions on the existing `UniqueLprCaseClaimsTest::test_get_card_key` (or the RecoveryCounts twin method if that reads cleaner): `getCardLabel()` and `getDatasetLabel()` are both `LPR Case Claims`. Keep `getCardKey()` as `unique-lpr-case-claims`.

Update heading constants only:

- `tests/e2e/helpers/company-metrics.ts:23` → `LPR Case Claims`
- `tests/e2e/helpers/company-metrics.ts:29` → `Scan Counts`

Specs that import those constants pick up the new locator text. Do not rewrite Playwright specs. Do not run Playwright. It is not in CI.

Comment sync, same diff:

- `CompanyMetricsOverview.php:68` row 1 titles
- `DashboardCardExpansionTest.php:155` row 1 comment

Historical sentences in the `CompanyMetricsOverview` docblock that describe the DEV-2651 absorption stay. They record what was merged, not the current title.

## Verify when implemented

```bash
php artisan test --compact tests/Filament/Widgets/Dashboard/UniqueScanCountsTest.php
php artisan test --compact tests/Filament/Widgets/Dashboard/UniqueLprCaseClaimsTest.php
```

Visual check of both cards is the later QA gate. This plan does not provision.

## Next

Provision. No product PHP in this phase.
