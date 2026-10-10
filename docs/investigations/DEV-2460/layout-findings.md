# DEV-2460 — dashboard layout findings

Everything measured in a browser against the QA environment. This file exists so the source
docblocks can stay short: they keep the rule, this keeps the evidence.

## The defect human QA found

The operator screenshotted the dashboard between 768px and 1024px. Header labels were breaking
mid-word — "Total Open Assign/ments", "Missing Plates Percenta/ge", "Approxi/mate" — and half
the page was empty.

Two root causes, both triggered by this ticket:

1. **`lg => 6`.** At 1024px the default-OPEN sidebar (20rem/320px) leaves a 672px grid, so six
   cards are 92px and each label's text box is 44px against an 86.83px widest word
   ("Assignments"), breaking four text nodes.

   The six-abreast threshold moved when the padding was trimmed to 20px/side (see below):
   it is now roughly a **127px card ≈ a 1240px viewport**, not the ≥135px/≥1282px derived at
   24px padding. That does fall inside the top ~40px of `lg`, so "it fits nowhere in `lg`" is
   false as stated — `lg => 3` is still right because 1024, the bulk of the range, is broken,
   and a breakpoint that changes shape 40px before `xl` is not worth having.
2. **A scalar `columnSpan`.** Filament's `gridColumn` macro normalises a scalar span to
   `['lg' => $span]` (`vendor/filament/support/src/SupportServiceProvider.php` ~line 250).
   Both dashboard widgets declared the string `'full'`, so each spanned the page only from
   `lg` up. Below it `--col-span-default` is never emitted, so
   `grid-column: var(--col-span-default)` resolves invalid and falls back to `auto` — one
   track, i.e. — half the page width, since
   `Dashboard::getColumns()` is 2 at `md`. Verified by A/B in the real page grid: scalar form
   → 356px at 768 / 372px at 800; array form → 736px / 768px; identical at ≥1024.

This was latent before DEV-2460 and harmless: a scalar `getColumns()` hits the same lg-only
rule, so the row also fell back to ONE column below `lg`, and a single full-width card filled
the narrow container. Making the row multi-column below `lg` is what turned it into a visible
bug. It was twice wrongly filed as "pre-existing, out of scope".

## Measured, after the fix

Sidebar in its default OPEN state. `default => 'full'` alone is sufficient for the span — the
base `.fi-grid-col { grid-column: var(--col-span-default) }` rule is not media-queried, so it
covers `sm`, `md`, `lg`, `xl` and `2xl`.

| viewport | main | grid | % used | header cols | card | rows |
| --- | --- | --- | --- | --- | --- | --- |
| 1440 | 1120 | 1088 | 97% | 6 | 161px | 1 |
| 1280 | 960 | 928 | 97% | 6 | 135px | 1 |
| 1200 | 880 | 848 | 96% | 3 | 267px | 2 |
| 1024 | 704 | 672 | 96% | 3 | 208px | 2 |
| 900 | 900 | 868 | 96% | 3 | 273px | 2 |
| 800 | 800 | 768 | 96% | 3 | 240px | 2 |
| 768 | 768 | 736 | 96% | 3 | 229px | 2 |
| 640 | 640 | 608 | 95% | 2 | 292px | 3 |

900px gives this row MORE grid width than 1024px (868 vs 672) because the sidebar hides below
`lg`. So `md` is not the tighter range, which is why `md` and `lg` are both 3. Three rather
than four because six stats divide evenly by 1/2/3/6 — four still costs two rows but leaves a
ragged 4+2.

## The 1280px hairline, and why detection was hard

Round 8 caught a defect at exactly 1280px, where `xl` turns on with the least room it will ever
have: six cards of 134.7px, a 87px label box, an 86.8px word. That **0.2px** deficit made three
labels render as "Assignment / s". Clean at 1279 (in `lg`, 3 columns) and at 1281.

Two of my own detectors could not see it:

- A canvas word-width check with a `+0.5px` float tolerance — the deficit is 0.2px.
- A `lines > words` heuristic — "Total Open Assignment / s" is 3 lines for 3 words.

What worked: walking each character with a `Range`, recording which line box it lands on, and
flagging when two adjacent non-space characters sit on different lines.

Fixed by trimming this row's horizontal padding from Filament's 24px to 20px per side. Measured
breaks at 1280 by padding: 24px → 3, 22px → 0 (4px margin), 20px → 0 (8px margin). Chose 20px
for real headroom rather than a hairline. Re-verified 0 mid-word breaks at 640, 768, 800, 900,
1024, 1200, 1279, 1280, 1281, 1366, 1440, 1536 and 1920.

## `overflow-wrap: anywhere`

Kept as a backstop. It is NOT idle — before the padding fix it was what turned the 1280px
deficit into an ugly wrap rather than a 0.2px overhang. `break-word` will not substitute: only
`anywhere` participates in min-content sizing, so with `break-word` the box never shrinks and
the rule measures as a complete no-op.

## Same defect class elsewhere — NOT fixed here

`rg "columnSpan" app/` for scalar declarations in containers that are multi-column below `lg`:

| Site | Host columns below `lg` | Status |
| --- | --- | --- |
| `Widgets/Client/ClientTable.php:26` | `ClientAnalytics` d1/sm2/md3/lg5 | **live bug** — measured 240px inside a 768px 3-column row at 800px |
| `Widgets/Client/Concerns/ClientChartWidget.php:20` | `IndividualClientAnalytics` d1/md2/lg4 | **live bug** — half width 768-1023 (2 charts) |
| `Widgets/Client/Concerns/ClientClickhouseChartWidget.php:16` | same page | **live bug** — half width 768-1023 (1 chart) |
| `Widgets/Dashboard/Concerns/AdminClickhouseStatsWidget.php:13` | — | dead: abstract with ZERO subclasses (`rg "extends AdminClickhouseStatsWidget" app/` is empty) |

Three client-panel widgets carry the identical bug and are out of scope for this ticket. An
earlier version of this record had it backwards — naming the dead site as the sibling and
missing the three live ones.

`Widgets/KitUserRecoveries/LeaderboardTableWidget.php:20` also carries the scalar, and is
inert for a different reason: it is rendered by `@livewire(...)` inside a hand-rolled
`grid grid-cols-1 lg:grid-cols-3` in `resources/views/filament/pages/kit-user-recoveries.blade.php`,
so Filament never wraps it in a `fi-grid-col` and `getColumnSpan()` is never consumed.

Safe scalar declarations, for completeness: `RecoveryStatsWidget.php:17` and
`SubscribedAlertsWidget.php:19` sit in containers that are 1 column below `lg` (Filament's own
default scalar `2` hits the same lg-only rule), where span-1 equals full;
`ClientStatWidget.php:17` and `LifetimeStatWidget.php:16` are scalar `1`, and the below-`lg`
fallback `grid-column: auto` is also span 1.

## Queued for `okf/` after this lands

The `gridColumn` scalar → `['lg' => …]` normalisation deserves a `PG-0xx` entry in
`okf/concepts/platform-gotchas.md`. It has a live cross-panel blast radius and no existing page
documents it. Not written now, per CLAUDE.md's WRITE-after-approval rule.
