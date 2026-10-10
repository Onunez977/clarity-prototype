# DEV-2460 — review round 3 (3 lanes, in-session, opus)

**Scores: 91 / 92 / 89. MIN = 89 < 90 → gate FAILED.** Round 2's one permitted fix cycle is
spent, so continuing past here is an operator decision, not the pipeline's.

Round 2 was 88/87/88. All seven round-2 scoring findings were independently verified as FIXED
by at least two lanes — including the two that mattered most (the seam tautology and the
layout guards reading the wrong array). The residual score is driven by **new** findings.

## What round 3 confirmed as genuinely fixed

- **S1** — the docblock and the test message now state what is true about the hit-row reflow.
- **S2** — `StubOverview` drives the real `expandableCards()`/`getStats()`; all three lanes
  confirmed both of the driver's mutations now die. Lane 1 found two further mutations that
  die (`getStats()` → `expandableCards()`, and `self::` in either accessor).
- **S3** — both layout guards read `cards()`; no test reads the wrong array.
- **S4** — every lane traced the guest short-circuit independently and cleared it. **Lane 1
  went further and found it is a net security IMPROVEMENT, not a no-op:** `mount()` does not
  re-run on Livewire *update* requests, so the old bare `true` let a user whose role was
  revoked mid-session keep seeing values on re-render. Deferring to `static::canView()`
  closes that.
- **S6** — verified against vendor source: `TableWidget` has no `getHeading()`, only
  `getTableHeading()` (protected, but callable because the trait is flattened into the class),
  and `KitUsers` now resolves `'Active Kit Users'`.
- **S7** — the catch placement is correct and the guard is live code. Lane 2 checked the thing
  that would have made it a dead guard: `cachedActiveKitIds()` uses Eloquent CH models, **not**
  `ClickhouseQuery::query()`, which swallows `Throwable` and returns `[]` in `testing`. Had it
  gone through that wrapper, a CH outage would have cached a fabricated 0 until midnight.
- **N2** — the `strpos` vacuity is properly closed by three `assertNotFalse` guards.
- **`LOCKED_VALUE` on the interface** — legal, correctly justified, no problem.

## NEW scoring findings

| # | Lanes | Finding |
| --- | --- | --- |
| **R1** | **3/3** | **"N camera kits" is an unfiltered count of ALL kit types.** `cameraKitsCount()` is `ClickhouseKit::query()->forOrg($orgId)->count()` with no `kit_type` predicate, and its own docblock calls it "total org kit count". `okf/concepts/kit.md` says three times that `kit_type = 1` is the camera type and instructs "Filter to `kit_type = 1` when you want real LPR cameras"; `GlKitsResource` does exactly that. The KB's measured numbers (3,535 of ~4,755 kits) put the overstatement near 25% fleet-wide. **This diff is the first thing in the repo to state that number to a user as "camera kits"**, and it adds a tooltip restating the claim. Lane 2: `grep kit_type docs/investigations/DEV-2460/` returns ZERO hits — the KB page for the exact table was never consulted, against CLAUDE.md's explicit requirement. |
| **R2** | **3/3** | **`Dashboard::cardWidgets()` has zero test coverage — the third surviving mutation.** Reverting line 99 to `::cards()` leaves the whole suite green. The round-2 S3 fix moved both live callers off `cardWidgets()` and nothing was put back on the other side; every remaining reference in `tests/` is a comment or a failure-message string. That line is the only thing making a locked card non-expandable (the toggle is deliberately unguarded, and Filament's own gate filters `canView()`, not `canViewCardData()`). AC-4's third clause is unverified. |
| **R3** | 2/3 | **The S7 fail-soft wrap is itself untested**, and on partial failure it discards a value it already computed (if `active` succeeds and `cameras` throws, both render 0). Deleting the whole try/catch leaves the suite green. |
| **R4** | 1/3 (lane 3) | **The new implementer contract contradicts `getStats()` eleven lines away.** It tells nine sibling tickets that "`canView()` false removes the card outright" — but `getStats()` iterates `cards()` unconditionally, so `canView() === false` now renders a permanent locked em-dash card instead. |
| **R5** | 2/3 | **`RecoveryCounts`' relabel is pinned by nothing.** Reverting both `$heading` and `getDatasetLabel()` survives green; `grep 'Total Repossessions' tests/` finds only a comment and unrelated client-panel tests. The identical situation for `RecoveryLeader` was treated as a finding and fixed in this same diff — the cost was paid once and applied to one of the two classes. Both lanes call `plan.json`'s exclusion of `RecoveryCountsTest` **wrong**: "stays green" is the defect, not proof of safety. |
| **R6** | 1/3 (lane 1) | **`lockedStat()`'s Livewire key is asserted nowhere** — the fourth surviving mutation. `dashboardStatKey()` was extracted so locked and unlocked renderings "can never diverge"; dropping the `->key()` call entirely survives green. |
| **R7** | 2/3 | **The `lg` breakpoint is now a REGRESSION, not merely un-achieved parity.** The old flat `5` meant `lg: 5` — one row at 1024-1279px. The round-2 N7 change made the inherited value explicit as `'lg' => 3` with six stats, i.e. **two rows**. Making it explicit documented the problem rather than fixing it. |

## Non-scoring, worth keeping

`StubOverview::cards()` includes the real `LiveHits`, whose leg asserts nothing (its fetch paths
swallow and degrade to zeros in `testing`) — the `UngatedStubCard` control already covers that
case, so the real card adds an environment dependency and no coverage. · A static-state reset
sits at the end of a test body rather than in `tearDown()`. · `KitUsers.php:44-46` still claims
its key "must match the key used by `Dashboard::cardWidgets()`", now false; `KitUsersTest.php:220`
still calls it "in the grid". · `driver-verifications.md` §7's aside that `KitUsers::$heading` is
"vestigial: nothing reads it" is **wrong** — `TableWidget::makeTable()` reads it, and
`getCardLabel()` now does too. The conclusion drawn from it was still correct. · `LOCKED_VALUE`
is not `final` while its only reader hard-binds to the interface, so an override would be
silently ignored. · `cachedActiveKitIds()` puts the user's timezone in the query but not the
cache key — pre-existing, but this diff makes it visible to every dashboard viewer rather than
one card. Worth a follow-up ticket.

## Feedback on the driver's own writing, recorded because it is fair

Lane 1: *"The docblocks are unusually assertive and self-justifying ('it is not a hole',
'Guarding here is the bug, not the fix'), which is a reviewer-steering pressure worth naming — I
re-derived S4, the `toggleCard()` no-guard decision, and the `theme.css` breakpoint claim from
vendor source and `config/auth.php` rather than accepting the prose. All three held. **R1 is the
one place where a comment asserted something the code does not establish.**"*

That is a correct criticism. Three assertive claims survived independent re-derivation; one did
not, and it is the one that reached users as product copy.
