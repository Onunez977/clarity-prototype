# Driver-verified findings (checked against the real code, not taken from a candidate)

## 1. `toggleCard()` — candidate 1's "order-of-checks bug" is a HAZARD, not an existing bug

Candidate 1 reported that `Dashboard::toggleCard()` "must allow closing an already-open key
even if it later falls out of `cardWidgets()` (order-of-checks bug the closed branch had to
fix)". Verified against `app/Filament/Pages/Dashboard.php`. The correction matters:

**Today there is no bug**, because `toggleCard()` performs no validation at all:

```php
public function toggleCard(string $key): void
{
    $this->expandedCard = ($this->expandedCard === $key) ? null : $key;
    ...
}
```

An arbitrary key is simply assigned. `expandedCardComponents()` then does
`$this->cardWidgets()[$this->expandedCard] ?? null` and returns `[]` when it misses. So an
unknown / locked key is **already** a structural no-op, and closing always works.

**The hazard is what an implementer will naturally add.** Narrowing `cardWidgets()` to
`expandableCards()` invites a guard like:

```php
if (! isset($this->cardWidgets()[$key])) { return; }   // ← DO NOT DO THIS
```

That early return fires *before* the close branch, so a card that is open when it becomes
locked can never be closed. That is the bug the closed DEV-2409 branch hit.

**Plan instruction:** do NOT add key validation to `toggleCard()`. The `?? null` lookup in
`expandedCardComponents()` is the enforcement point, and it is already correct. Additionally
`lockedStat()` must emit **no** `wire:click`, so a locked key is never toggled in the first
place — belt and braces, two independent reasons a locked card cannot expand.

## 2. `Dashboard.php:83-89` docblock is stale — confirmed

It still describes "The 8 compact cards ... row 1 = Scan Count, Repossessions, Recovery
Leaderboard, Kit Users; row 2 = the 4 hit cards". After this ticket that is wrong on the
count (7), the labels (both relabels), the membership (Kit Users gone) and the row structure
(mockup is 4x4, not 2x4). Must be rewritten, not just edited.

## 3. `HotlistCount::getColumns()` is a plain `int 5` — confirmed

`return 5;` at `HotlistCount.php:22-24`, with exactly 5 stats in `getStats()` whose order
matches the mockup header row. Active Kit Users inserts at index 4 (position 5 of 6), before
`Staging`.

## 4. `resources/css/filament/app/theme.css` couples card height to the 5-column header row

`.big-stat-chart { @apply min-h-40; }` (160px). The comment states the number was measured as
"that row's height at the default **5-column** width, where its tallest card ('Missing Plates
/ Total Open Assignments') wraps its description onto a second line", and closes with
"**Revisit here if the top row's copy changes.**"

This ticket changes both the top row's copy and its column count, so the calibration comment
is invalidated by construction. Candidate 1 independently reports the closed DEV-2409 branch's
QA pass made `getColumns()` responsive (`default 1 / sm 2 / md 3 / xl 6`) rather than a plain
`6` — corroboration from a second direction that a bare 5→6 is not sufficient.

## 5. `getCardLabel()` gap — candidate 3's finding, DRIVER-VERIFIED. This one would fatal.

**Claim:** adding a shared `lockedStat()` to the `RendersDashboardStat` trait that calls
`$this->getCardLabel()` fatals on any card that uses the trait but does not extend
`DashboardWidget`.

**Verified true.** Three greps settle it:

```
$ grep -rn "function getCardLabel" app/
app/Filament/Widgets/Dashboard/Concerns/DashboardWidget.php:272:    public function getCardLabel(): string
```

`getCardLabel()` is defined in exactly ONE place — `DashboardWidget`. It is **not** in the
`RendersDashboardStat` trait (full file read: the trait has `getCardKey()` abstract,
`dashboardStat()`, `getColumnSpan()`, `dashboardStatAttributes()` — no label method), and it
is **not** on the `DashboardMetricCard` interface (which declares only `getCardKey()` and
`toStat()`).

Two of the current cards use the trait but do NOT extend `DashboardWidget`:

```
KitUsers.php:      class KitUsers extends BaseWidget implements DashboardMetricCard
RecoveryLeader.php: class RecoveryLeader extends ChartWidget implements DashboardMetricCard
```

`RecoveryLeader` is one of the **7 cards that survive this ticket**. So the natural
implementation of AC-4 — put `lockedStat()` in the shared trait, have it render
`Stat::make($this->getCardLabel(), '-')` — is a **runtime fatal** on `recovery-leader`.

**Why this is the dangerous one.** It is invisible in this ticket. No card sets
`canViewCardData()` to false here, so `lockedStat()` is never called on the real grid; every
test passes; it ships green. It detonates later, in whichever sibling ticket first gates a
card — and by then the cause is three tickets away from the symptom. That is precisely the
epic's documented failure pattern.

**Fix (settled):** move `getCardLabel()` from `DashboardWidget` into `RendersDashboardStat`
and delete the original, so there is ONE label source every implementer shares. Both
non-`DashboardWidget` cards already have a `getHeading()` from their Filament base classes
(`ChartWidget`, `TableWidget`), which is what `getCardLabel()` reads — so the move is safe.

Candidate 3 reports the closed `claude/DEV-2409` branch independently hit and solved this
same gap, which is corroboration that it is a real defect class rather than a theoretical one.

## 6. `getStatColor()` has no `kit-users` arm — removal is safe

**CORRECTED in round 5.** The original heading and text below said `recovery-leader` "falls
through to gray". That conflated *"has no arm in the match"* with *"reaches the match"*.
`RecoveryLeader extends ChartWidget`, **not** `DashboardWidget`, so it never inherits
`getStatColor()` at all — and its own `toStat()` hardcodes `->color('info')`. The conclusion
(dropping `kit-users` cannot break the colour map) still holds; the reasoning did not, and it
propagated into a production docblock before two lanes caught it.

`DashboardWidget::getStatColor()`'s `match ($this->getCardKey())` covers `scan-count`,
`recovery-counts`, the two live/historical hit keys and the two staging hit keys; everything
else falls to `default => 'gray'`. Dropping `kit-users` therefore cannot break the colour map.

## 7. The `getCardLabel()` move is safe — but candidate 3's justification was half wrong

Candidate 3 justified moving `getCardLabel()` into the trait by asserting that both
non-`DashboardWidget` cards "already have a `getHeading()` from their respective Filament base
classes (ChartWidget, TableWidget)". Verified — that is true for one and **false** for the other.

| class | extends | has `getHeading()` on `$this`? |
| --- | --- | --- |
| the 6 `DashboardWidget` subclasses | `DashboardWidget` → `ChartWidget` | YES (`ChartWidget::getHeading()`, public) |
| `RecoveryLeader` | `ChartWidget` | YES |
| `KitUsers` | `TableWidget` | **NO** |

`grep -rn "function getHeading" vendor/filament/` shows `getHeading()` on `ChartWidget` (public),
on `StatsOverviewWidget` (protected), and on `Filament\Tables\Table` via `HasHeader` — the
**Table object**, not the `TableWidget` class. `KitUsers` declares
`protected static ?string $heading = 'Active Kit Users';` but that property is **vestigial**:
nothing reads it, because `KitUsers::toStat()` hardcodes the label.

`DashboardWidget` itself does `use RendersDashboardStat` (line 17), so moving the method into
the trait is transparent for all 6 subclasses.

**Consequence for the plan.** A trait `getCardLabel()` that calls `$this->getHeading()`
unguarded is total for every card that survives this ticket, but **fatal if ever called on
`KitUsers`** — which uses the same trait. Nothing calls it there today, so it is latent, which
is exactly the shape of defect this epic keeps shipping. Make it total instead:

```php
public function getCardLabel(): string
{
    $heading = \method_exists($this, 'getHeading') ? $this->getHeading() : null;

    return match (true) {
        $heading instanceof Htmlable => $heading->toHtml(),
        \is_string($heading) => $heading,
        default => \class_basename(static::class),
    };
}
```

Behaviour is byte-identical for every current caller (all of which have `getHeading()`); the
existing `default` arm already absorbs `null`. The only change is that the method can no longer
fatal on a trait user that lacks the method. No candidate produced this refinement.
