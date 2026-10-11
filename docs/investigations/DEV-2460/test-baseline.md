# Pre-change test baseline (DEV-2460)

Captured with **zero code changes** in the working tree (`git diff origin/main...HEAD -- app/ tests/ resources/`
= 0 files). Every failure below is therefore **pre-existing on `main` in this local
environment**, not caused by this ticket.

## Why they fail

`.env.testing` sets `CLICKHOUSE_DRIVER=pgsql` — the documented local default (the
`RequiresClickhouse` trait's own comment says "locally leave CLICKHOUSE_DRIVER=pgsql in
.env.testing to skip these tests"). That driver bridges ClickHouse queries onto Postgres, but
the `clarity.*` tables do not exist in `zod_testing_claude_dev_2460`, so any test that renders
the **whole Dashboard page** dies on:

```
SQLSTATE[42P01]: Undefined table: relation "clarity.recoveries" does not exist
```

Tests that assert on arrays, labels, CSS or a single widget with pre-seeded cache are
unaffected.

## Baseline numbers

| file | passed | failed |
| --- | --- | --- |
| `tests/Filament/Pages/DashboardCardExpansionTest.php` | 9 | 7 |
| `tests/Filament/Widgets/Dashboard/RecoveryLeaderTest.php` | 12 | 0 |
| `tests/Filament/Widgets/Dashboard/HotlistCountTest.php` | 3 | 0 |
| `tests/Filament/Widgets/Dashboard/RecoveryCountsTest.php` | 1 | 3 |
| **total** | **25** | **10** |

## The tests this ticket must move — ALL GREEN at baseline

Good news: every test the ACs depend on passes without ClickHouse, so the whole change is
TDD-able in this environment.

- ✓ `test_company_metrics_cards_are_in_layout_order` — AC-1, the exact card-order assertion
- ✓ `test_card_widgets_keys_match_widget_get_card_key_where_implemented` — the `assertCount(8)`
- ✓ `test_all_four_hit_cards_are_contiguous` — **AC-5, must stay green, must stay unmodified**
- ✓ `test_theme_matches_stat_height_to_the_top_row_and_sizes_the_expanded_chart` — **not named by
  any prep pass.** It pins `theme.css`, asserting `/\.big-stat-chart\s*\{[^}]*min-h-40/` among
  other rules. Since the operator's answer to Q1 is "update the calibration COMMENT, re-check the
  160px value by eye in QA" and **not** "change min-h-40", this test stays green. Had we changed
  the value blind, this test would have caught it.
- ✓ all 12 of `RecoveryLeaderTest` (so `:214`'s `assertSame('Recovery Leaderboard', ...)` passes
  now and will go red on the relabel — exactly the red→green signal TDD wants)
- ✓ all 3 of `HotlistCountTest`

## The 7 pre-existing failures (all full-page renders)

`opening a card then a different card replaces the open card` · `dispatching dashboard toggle
card expands the matching chart` · `the open card outline binds to the page expanded card` ·
`admin can render dashboard with the card grid` · `expanding a card does not produce duplicate
livewire component ids` · `opening a card scrolls the shared detail into view` · `toggling a
card does not rebuild overview stats`

## Exit criterion for this ticket

**25 passed / 10 failed must not get worse.** Any newly-failing test is mine. The 7 + 3
pre-existing failures are environmental and are not in scope to fix here.

## Note — ClickHouse was NOT provisioned, deliberately

An earlier read of mine said "no target test uses the `RequiresClickhouse` trait, so ClickHouse
is not needed". That was half right and worth correcting: the absence of the trait means those
tests are **not skipped** — they run, and the full-page ones then fail against the incomplete
pgsql bridge. So ClickHouse absence does cost 10 failures. It is still the right call to leave
CH down, because (a) all AC-relevant tests are green without it, and (b) `clickhouse-qa`'s
`up.sh` provisions per-worktree `clarity_<wt>`/`zod_<wt>` database names, which
`RequiresClickhouse` cannot use — it TRUNCATEs the hardcoded literals `clarity.*`/`zod.*`.
Standing CH up correctly means literal `clarity`/`zod` databases, which are shared across
worktrees. Not worth it when nothing in scope needs it.
