# DEV-2738 — Question Gate

**Gate:** CLEAN.

No material open question. First Glance comment 41854 (2026-09-25) is Verdict Ready, Hardness Low, branch path main→main, hard stop none. Product wording is already stated: Scan Counts and LPR Case Claims.

## Settled by the ticket

- Titles: `Unique Scan Counts` → `Scan Counts`. `Unique LPR Case Claims` → `LPR Case Claims`.
- Chart dataset labels on those two cards drop Unique and use the same titles.
- DEV-2651 stays QA Complete. This is leftover copy. Do not reopen it.
- DEV-2736 is a different card set. Soft parallel only.

## Not a question

Preflight #18 and #25 are N/A. `app/Clickhouse/` does not bind the rendered strings. The only hit is a docblock quote at `UniqueLprCaseClaimsQuery.php:12`. That file stays out of the diff.

Axis titles `Case Claims` and `Scans per Case Claim`, the ratio series `Scans per Case Claim`, and the second series `Total Scans` already contain no Unique. They stay.

Card keys `unique-scan-counts` and `unique-lpr-case-claims`, class names, and cache namespaces stay. DEV-2651 AC-6 forbade renaming surviving keys.
