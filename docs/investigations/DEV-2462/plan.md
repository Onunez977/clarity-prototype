# DEV-2462 — Plan

**Route** feature · **Base** `main` · **Gate** CLEAN · **Reviewer lanes** 3

## What ships

One compact **Missed Opportunities** stat card on the Company Metrics dashboard at mockup
row 3 col 3, plus a one-line **grain fix** to the orphaned `MissedOpportunityClientGraph`.

Single subtask, no worktree fan-out — the whole change is ~35 lines of production code
across 4 files (3 of them one-liners) plus 4 test files.

## Decisions locked at the gate

| # | Decision |
| --- | --- |
| Q1 | `canViewCardData()` returns **false** for a guest: `auth()->check() && (Feature::active(...) \|\| admin_impersonator)`. the Company Metrics foundation's guest-parity test keeps its no-error purpose; its incidental key-equality assertion is relaxed. |
| Q2 | **Fix in place.** The graph is NOT wired up. `Dashboard.php` is out of scope. The graph's hardcoded YTD window is unchanged. The grain fix is therefore **latent — no user-visible effect today**, and the Build Record must say so. |
| Q3 | Stat colour `'primary'`. Never `'gray'` (that is the locked state). |

## Steps

1. **New widget** `MissedOpportunities.php` — mirror `RecoveryCounts` (28 lines). Implement only
   `getModelQueryForDates()`, using the model's existing `forOrg` / `notNicb` / `between` /
   `selectTotal` scopes. **No `->final()`** (measured: delta 0). **No new ClickHouse query class**
   and no raw `clarity.` prefix — the model resolves its own database.
2. **Registry** — insert the key **between `recovery-counts` and `recovery-leader`**. Not appended;
   the array is mockup-relative and its docblock says so in caps.
3. **Colour** — one additive `match` arm in `DashboardWidget::getStatColor()`.
4. **Grain fix** — `count()` → `countDistinct(hotlist_id)` and bump the cache key to `:v2`
   (the value changes while the shape does not, so a warm cache would serve wrong numbers for
   15 minutes). Touch nothing else in that file.
5. **Repair two broken test files** — `DashboardCardExpansionTest` (7→8 + order) and
   `DashboardCardVisibilityTest` (7→8, the ungated tripwire, the guest assertion).
6. **Two new test files** — for the card and for the graph, which has zero coverage today.
7. `composer format`.

## Why 8 files does not warrant a split

Four are tests. Three of the four production changes are single lines. The only substantial new
artifact is a ~30-line widget. The two nominal concerns are bundled by the ticket's own
reasoning ("same widget family, same query owner") and the operator re-affirmed that bundle
when answering Q2.

## Reviewer notes

- `DashboardWidget.php` is a **shared base class** inherited by 6 sibling cards. The change is a
  single additive match arm that cannot alter an existing arm; the operator approved it via Q3.
- No changed file matches the sensitive globs, so there is no formal blast-radius tag.
- The **closed reference implementation is wrong** (against the current test contract) on the
  guest gate. The ticket says to read it for approach — this is the one place to deviate
  deliberately.

## Test-run traps

- Filtered runs during implementation: `php artisan test --filter='DashboardCard|MissedOpportunit'`.
- Full suite: **`php -d memory_limit=2G vendor/bin/phpunit`**. Never `php artisan test` for the
  full suite — it OOMs during discovery and still exits 0, reporting a false pass. Expect ~1668
  tests and ~8 pre-existing failures.
- `.env.testing` here is currently on `CLICKHOUSE_DRIVER=pgsql`, which silently **skips every**
  ClickHouse test while reporting green. The graph's grain test is CH-backed, so Phase 4 must
  stand ClickHouse up with the **literal** database names `clarity` and `zod`.
- Those literal databases are **shared across worktrees** — teardown must never drop them.
