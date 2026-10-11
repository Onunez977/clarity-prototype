# DEV-2586 — Plan

**One subtask (t1), single-node fast path.** No worktree fan-out: one coherent change across two sibling cards.

## What changes

| File | Change |
| --- | --- |
| `RecoveryLeader.php` | `toStat()` gains `->description('Top Kit: …')`; headline unchanged; docblock corrected |
| `StagingLiveHitLeader.php` | `toStat()` swaps `max('total')` → `sortByDesc('total')->first()` so value + name come from one row; gains the same description |
| `RecoveryLeaderTest.php` | Invert 6 call sites + the helper that pin name **absence** |
| `StagingLiveHitLeaderTest.php` | Add name assertion + headline-parity assertion |

## Why this shape

`UniqueScansLeaderboard::toStat():228-239` already ships exactly this line in the same grid. Copying it verbatim
beats inventing a second convention, and it is what the operator chose for the copy (Q3).

The `max('total')` → `sortByDesc('total')->first()` swap on `StagingLiveHitLeader` is the one non-obvious bit:
`max()` returns a scalar, so a name cannot be derived from it. Taking both from one row is the only way the
displayed number and the displayed name cannot disagree. A test pins that the headline value did not move.

## Risk

Low. No query, schema, MV or CDC change — the name is already fetched and cached. No CSS change: `theme.css:171`
records `min-h-47` = 188px and "Every card is the same height whether or not it has a description", so the
new line costs 0px and Oscar's DEV-2324 height constraint (comments 38495/38501) stays satisfied.

The real risk is **test intent**, not runtime: `RecoveryLeaderTest` pins the old behaviour *on purpose*.
Those assertions are inverted deliberately and the reason is recorded, not silently deleted.

## Gates

- Sizing: 4 files — pass (< 6).
- Blast radius: none. No migrations, billing, auth/Shield, or CH purge globs.
- Perf gate: not required — presentation-only, no deferred-perf CH-at-scale design.
- Resource envelope: not `scale_relevant` (no command/job/query/migration; no new per-row lookup).
