# DEV-2738 — Claude dual-plan compare (preflight #23)

Date: 2026-09-25 ~10:45 CT
Claude draft: `claude-plan-draft.md` (headless `claude -p`, Read/Grep/Glob only)
Authority: existing `plan.md` / `plan.json` (CloudAgent Ultra-Plan). Draft is never a done-signal.

## Convergences

- Four needles match: `UniqueScanCounts.php:53,:114` and `UniqueLprCaseClaims.php:96,:108` → Scan Counts / LPR Case Claims.
- #18 / #25 N/A with the same proof shape: card key and cache from class basename; labels not query-bound.
- PHP pins: UniqueScanCountsTest `:73,:86,:568` → Scan Counts; add getCardLabel/getDatasetLabel on UniqueLprCaseClaimsTest; keep getCardKey.
- e2e: only `company-metrics.ts:23,:29`; leave `:30` alone; do not reopen DEV-2651.
- Sibling strings left alone (Total Scans, Scans per Case Claim, Case Claims axis, tooltips).
- Affiliate Stats / Scans Leaderboard / okf left alone. One commit, pint dirty. No new types.

## Divergences

- Claude adds a **legend-semantics flag** (Scan Counts vs Total Scans losing the Unique contrast). Rejected as a product rename for this key — AC/prep already set dataset labels to the new titles; tooltip keeps the dedup meaning. Not a silent substitution and not a blocker.
- Claude names more e2e consumer files for the constants. Informational only; plan already says specs import the constants. No extra edits adopted.
- Claude’s T4 comment sync (`CompanyMetricsOverview.php:68`, `DashboardCardExpansionTest.php:155`) already in plan.

## Adopted from Claude

- Stronger binding proof for #18/#25 (DashboardWidget card-key path). Keep as implementer cite; plan text already sufficient.
- Explicit “code before tests / one commit” ordering. Already implied.

## Rejected from Claude

- Any legend rename beyond the four AC literals (scope inflation / #24).
- Running Playwright or inventing a second series name.

## Judgment

Final plan stays the existing `plan.json` / `plan.md`. No rewrite. Dual-plan #23 satisfied for DEV-2738. Next remains Provision (fleet ledger).
