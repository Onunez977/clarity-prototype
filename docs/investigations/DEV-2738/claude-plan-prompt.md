# Dual-plan co-pilot · DEV-2738 (read-only)

Tools: Read, Grep, Glob only. Do not mutate env, commit, or push.

## Ticket
Drop residual “Unique” wording from Scan Counts and LPR Case Claims (widget + chart). Relates DEV-2651 leftover only — do not reopen. Branch path main→main. Copy literals only unless label string is the only binding.

## Needles (primary plan)
- UniqueScanCounts.php heading/dataset → Scan Counts
- UniqueLprCaseClaims.php heading/dataset → LPR Case Claims
- Pins UniqueScanCountsTest + e2e company-metrics.ts

## Your job
Independently verify needles and write a minimal Implement plan. Prefer simple wins. #18/#25 N/A unless you prove a query binding. Output markdown only to stdout.
