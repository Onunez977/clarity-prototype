# DEV-2451 — Plan

**Decision (Dalton, 2026-08-31):** remove the tier-1 continuous-GPS call path from
`KitUserPathController` **outright** — no feature flag. Global, all orgs, all kits.
Bird's Eye deliberately stays on continuous GPS. Follow-up is **DEV-2450** (DEV-2448 was a typo).

The driver recommended a reversible config flag instead; Dalton chose outright removal with the
tradeoff stated (DEV-2450 rebuilds the wiring rather than flipping a flag; the tier-1 controller
tests are deleted rather than pinned). Proceeding as specified.

## One subtask — `t1` (complex, no fan-out)

Remove tier-1 orchestration from `paths()`, then collapse every branch that existed only to
reconcile two tiers. Tier 2 (`KitScanPathsQuery` → `clarity.kit_scan_paths`) becomes the sole
path source.

### What gets deleted
| Item | Why it's dead |
|---|---|
| `KitRangePathsQuery` import | No longer called here |
| `DEDUP_METERS`, `BROAD_DEDUP_METERS` | Tier-1-only (feed `dedup_meters`) |
| the `if ($mappings !== [])` block | The tier-1 read itself, plus its 422 fast-path and two 503 paths |
| `mappingIndex()` | Only consumers were tier 1 and `segmentRows()` |
| `segmentRows()` | With no segments it can only return `[]` |
| `$byMapping` / `$segments` / `$tier1PointCount` params | Constant once tier 1 is gone |
| the `$coveredKitIds` loop | No segments ⇒ every kit is eligible |

### What survives, changed
- `MAX_RENDER_POINTS` stays; tier 2 is now its **sole** consumer, so its "summed across BOTH
  tiers" docblock is wrong and must be rewritten.
- `over_budget` → unconditional **422** (no tier-1 rows left to degrade to).
- `unavailable` → unconditional **503** (tier 2 *is* the whole response).
- Response becomes `$fallback['rows']`; no `array_merge`.
- `$localStart`/`$localEnd` stay, but their comment explains a capture-before-`Carbon::utc()`
  ordering that no longer exists once the tier-1 block goes. **Rewrite the comment; do not
  "tidy away" the capture.**
- `kitsById()`'s docblock has a `{@see mappingIndex()}` that will dangle — fix it.
- `scanPathFallbackRows()` / `scanPathRow()` docblocks are written tier-1-relative — rewrite.
- **New class docblock**: the interim scan-only state, that it is global, that Bird's Eye
  deliberately diverges, and that DEV-2450 restores enriched rendering.

### What must NOT be touched
`KitRangePathsQuery` and `KitRangePathsQueryTest` — `ClarityScoutBirdsEyeController` still calls
the class, and Q4 keeps Bird's Eye on continuous GPS. **If either moves, the change leaked.**

### Tests (TDD — rewrite first, confirm red, then green)
Delete the tier-1 pins (503-on-tier-1-throw, shared/combined budget, tier-1-not-overridden,
mapping-less rescue, sparse-track per-day fallback). Keep and widen the tier-2 pins — several
currently assert tier-2 behavior *only for zero-coverage kits* and must now assert it for every
kit. Add two: a kit **with** `sc_positions` coverage still renders via `kit_scan_paths`, and
`sc_positions` is **never queried** by this endpoint.

`test_filter`: `KitUserPathControllerTest`

## Gates
- **Sizing:** PASS — 2 files, one concern.
- **Perf (CH-at-scale):** NOT REQUIRED — the change *removes* the `sc_positions` read; the
  surviving `kit_scan_paths` is 1.66M rows, far below the >1B threshold. Cardinality already
  measured in prep.
- **Blast radius:** no sensitive-glob match; no extra approval needed.

## Accepted risk
617 kits (30d) lose continuous routing — global, visible, and explicitly accepted.
