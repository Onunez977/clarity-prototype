# DEV-2460 — review round 4 (3 lanes, in-session, opus)

**Scores: 91 / 90 / 92. MIN = 90 ≥ 90 → the gate PASSED.**

**It was fixed anyway.** All three lanes independently showed that **two of round 3's seven
fixes did not work**, and lane 3 found a third staleness. Shipping on a passing number while
the seam's only authorization line stayed unverified — and while a commit message asserted
coverage that did not exist — was the wrong trade.

## What round 4 confirmed as genuinely fixed

R4 (contract text), R5 (`RecoveryCounts` pin), R6 (locked Livewire key), R7 (`lg` value).
Lane 2 and lane 3 both re-derived R7's mechanics from vendor source rather than trusting the
comment: `HasColumns::columns()` maps a bare int to `['lg' => n]` (so the pre-ticket flat `5`
really did mean one row at `lg`), and `SupportServiceProvider` emits `--cols-lg: repeat(6, …)`
as a CSS custom property, so six is a supported count with no Tailwind safelist dependency and
no asset rebuild owed. All three lanes also re-cleared the guest short-circuit, the
`toggleCard()` no-guard decision, and AC-7.

## The two fixes that did not work

| # | Lanes | Finding |
| --- | --- | --- |
| **F1** | **3/3** | **The `cardWidgets()` pin was a TAUTOLOGY.** The round-3 fix added `class StubDashboard extends Dashboard` that **overrode `cardWidgets()`** — so the production line never ran and the assertion reduced to `StubOverview::expandableCards() === StubOverview::expandableCards()`. This is round 2's S2 defect (a test re-implementing the thing it tests) moved up one level. The driver reproduced it: applying the mutation left **12 of 12 passing**. Lane 2's judgement, accepted: *"A false coverage claim on the one authorization line nine sibling tickets will build on is worse than the acknowledged gap it replaced."* |
| **F2** | **3/3** | **The fail-soft test was vacuous in CI — the only environment that gates merges.** Its mechanism was an unseeded cache key reaching an unreachable ClickHouse. True under the local `.env.testing` (`CLICKHOUSE_DRIVER=pgsql`, no `clarity.*`); **false in CI**, where `lint_check_and_test.yml` sets `CLICKHOUSE_DRIVER=clickhouse`, starts `clickhouse-server:26.2`, and loads `clickhouse-schema.sql` (which creates `clarity.kits`). The read therefore returns `0` **without throwing**, indistinguishable from the degraded value, so deleting the `try/catch` stayed green where it mattered. |
| **F3** | 1/3 (lane 3) | **`DashboardMetricCard`'s interface docblock still stated the pre-round-2 default** — *"RendersDashboardStat supplies a `true` default, so implementers opt in to gating"*. False since round 2, and lane 3 correctly notes this is the **more** load-bearing copy: the interface is what the nine siblings read first. R4 rewrote the trait's contract and left the interface's stale one authoritative. |

Also carried: the `KitUsers` docblock still telling future implementers *"The shipped header stat
shows CAMERA kits"* (2/3 lanes — the R1 claim surviving in the one place that tells the future
what the number means), `theme.css`'s parenthetical still recording the reverted `lg 3` while the
paragraph below it says six-columns-one-row (2/3), and `getColumns()` pinned by nothing at all
even though its `lg` value had been wrong **twice** in this ticket (lane 1).

## How each was fixed, with the mutation that now kills it

| Fix | Mutation | Round 3 | Round 4 |
| --- | --- | --- | --- |
| F1 — drive the **real** `Dashboard`. No seam needed: `canViewCardData()` defaults to the card's own `canView()`, so a viewer with neither ADMIN nor USER empties `expandableCards()` while `cards()` stays at 7. `StubDashboard` deleted. *(Lane 3's suggestion.)* | `Dashboard.php:99` → `::cards()` | green | **1 fail** |
| F2 — inject the collaborator: a `kitUsersSource()` seam plus `FailingKitUsers` / `HalfFailingKitUsers` doubles whose reads throw by construction. Environment-independent. | delete the `try/catch` | green (CI) | **2 fail** |
| F2b — the partial-failure half | zero both legs together | green | **1 fail** |
| L2 — pin `getColumns()` | `lg` 6 → 3 | green | **1 fail** |
| F3 + the `KitUsers` and `theme.css` staleness | prose; no mutation | — | corrected |

## Records corrected, not just code

- A previous commit message claimed `cardWidgets() … now pinned (4 mutations verified)`. **That
  was false.** The claim is removed from `state.json` and replaced with what actually happened.
- `plan.json`'s exclusion of `ClientTable.php` / `HasStatDrillDown.php` called them "/client
  panel". Lane 3 showed they are **admin**-panel files (the client panel discovers only
  `app/Filament/Client/Widgets`). The exclusion's conclusion holds; its reasoning did not, and a
  sibling running the same grep would have been misled. Reasoning replaced.
- Lane 2 corrected the driver's own overstatement about the `cachedActiveKitIds()` timezone
  cache-key issue: it is genuinely pre-existing and unchanged, since the card lived on the same
  page. Recorded as a follow-up, not as this ticket's defect.

## Still open, deliberately

~~`makeMetricWidget()`'s `property_exists($widget, 'filters')` branch can never fire~~ —
**THIS WAS WRONG, corrected in round 5 after two lanes flagged it.** `DashboardWidget.php:38`
declares `#[Url] public mixed $filters;` explicitly, so `property_exists()` returns `true` and
the branch **does** execute for all six `DashboardWidget` cards. The code is live, not dead.
The conclusion (leave it alone, out of scope) stands; the reasoning was false, and I wrote it
into the record by repeating a reviewer's claim without verifying it — the same failure mode
this round corrected in `plan.json`'s exclusion reasoning. Recorded so no follow-up deletes
working code on a false premise. Same for the timezone cache key, `LOCKED_VALUE` not being `final`, and
`KitUsersTest.php:220`'s "in the grid" comment.
