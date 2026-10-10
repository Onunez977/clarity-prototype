# DEV-2736 — Claude dual-plan compare (preflight #23)

Date: 2026-09-25 ~10:49 CT
Claude draft: `claude-plan-draft.md` (headless `claude -p`, Read/Grep/Glob only)
Authority after this compare: updated `plan.md` / `plan.json` (converged judgment). Draft alone is never a done-signal.

## Convergences

- Q1/Q2/Q3 chrome and strings match Dalton CLEAN answers.
- Product file stays `RecoveryLeader.php` (+ `RecoveryLeaderTest.php` only).
- Copy `UniqueScansLeaderboard` getOptions/makeDataset; delete month-zip test; leave `RecoveryLeaderQuery`, chartSeries trait, heading/tooltip, getGraphLabels.
- #25 closed; #18 N/A for stubbed widget tests; no Cloud CH; no third shape; no Unique in new chrome.

## Divergences (material)

- **Plan bug (adopted):** parked `plan.md` step 1 / `plan.json` t1 said `getData` reads `leaderboardForSelectedRange`. On `RecoveryLeader` that method returns `{leader, runner_up}` via `summarizeMonth` (lines 188–209). Staging/Scans return a pluckable top-10 list. Plucking Recovery’s method would yield a 2-bar chart. Code-verified on HEAD `7be416465`.

## Adopted from Claude

- Extract `rankingForSelectedRange(): array<string,float>` as the pre-`summarizeMonth` bucket (move of lines 189–208). `leaderboardForSelectedRange()` becomes `summarizeMonth($this->rankingForSelectedRange())` — zero headline behavior change.
- `getData` builds one dataset from that ranking map (kit names / floats / Distinct Recoveries).
- Keep `getGraphLabels` (DashboardMonthLabelsTest + RecoveryLeaderTest pin it).
- Do not add `declare(strict_types=1)` or memoize ranking (throw/recover tests need two queryClickhouse results).
- Delete `generateKitColors` once unused; richer test list (one-series, date filter, max-bar colors, no Unique in chrome, empty ranking) — implement may trim but direction is sound.

## Rejected / deferred

- Queueing an `okf/` chrome note as part of this key — leave until after approval (already non-goal).
- Any new trait/class/third shape.
- Scope past RecoveryLeader + its test.

## Judgment

Plan rewritten to name `rankingForSelectedRange` explicitly. Dual-plan #23 satisfied. Next remains Provision (fleet ledger).
