# DEV-2584 — Plan

## What changes

The card **Unique Scans to Case Claims** stops being a percentage and becomes a ratio.

| | before | after |
| --- | --- | --- |
| value | `pre_claim / deduped * 100` | `unique_scans / deduped` |
| meaning | share of billed case claims whose vehicle a camera had already scanned | unique scans per case claim |
| render | `number_format(x, 1) . '%'` | `number_format(x, 1)` |
| example | 99.7% | 3,295.6 |
| ClickHouse | billing-events funnel **joined to `clarity.hits_cold`** | funnel alone + the shared scan-stats cache |

Approved at the Phase 2 gate. The full argument on both sides is in `prep-context.json`; the
answers are in `journal.md`.

## One node, no fan-out

`t1` is the whole ticket. Nine files, but one concern: the card, its six test files, and two
sibling docblocks. TDD keeps code and tests in the same node — splitting them lands a red suite
between merges.

## The pattern to copy

`UniqueScansToRepo.php:271-317` is the in-family reference. It fetches two legs separately, zips
them by `Y-m`, and divides with a zero-denominator guard:

```php
$scans = $this->fetchUniqueScansSeries($orgId, $through);   // ScanPlateStatsBatchQuery
$repos = $this->fetchRepoSeries($orgId, $through);
// ... zip by $through->clone()->startOfMonth()->subMonths($i)->format('Y-m')
private static function ratio(array $stage): float
{
    $totalRepos = $stage['total_repos'] ?? 0;
    if ($totalRepos === 0) { return 0.0; }        // a genuine zero is normal, render 0.0
    return ($stage['unique_scans'] ?? 0) / $totalRepos;
}
```

Copy the **shape**, not the code — the denominator source differs.

## Nine sites, not one

The `%` is not just in `toStat()`. The exhaustiveness sweep found it at nine places inside this
class. A fix that touches only the headline leaves the expanded card contradicting the grid:

- `toStat()` :116
- `getDescription()` :366, :370, :378, :379 — all four filter branches
- `rate()` :332 — the `* 100` itself
- class docblock :19, :124 — documents the percentage as load-bearing intent
- `getCardQueryStatements()` :418 — a note string **rendered to admin impersonators**

## Two things prep nearly missed

The grep backstop promoted these from "out of scope" to in-scope:

- **`UniqueScansToRepo.php:41-47`** tabulates all three cards and calls this one
  *"vehicles / vehicles — an honest <=100% rate"*. That becomes false. Docblock-only edit.
- **`UniqueScansToLprRepossession.php`** carries the same cross-reference; confirm and fix.
- `tests/Filament/Pages/DashboardCardExpansionTest.php` and
  `tests/Filament/Widgets/Dashboard/UniqueScansToLprRepossessionTest.php` reference the class and
  may assert the `%`.

## What must keep working

- The **float seam**. `getCardTotal()`/`currentPeriodTotal()`/`getRangeTotal()` are `int` on
  `DashboardWidget`; PHP fatals on an `int`→`float` override. The ratio is computed end to end in
  its own method, never routed through those.
- The **cold-path herd guard** — `heldFlexible()`, the lock constants, and the cache key's
  viewer-local-day + timezone segments. The query gets cheaper, not free.
- The **zero-denominator guard** returns `0.0`, not `N/A`. A zero-claims org is normal.

## No blast radius, no new ClickHouse cost

No target file matches a sensitive glob. The change **removes** a `clarity.hits_cold` pass
(~106.8M read_rows / 3,828 MiB per cold key, per the card's own docblock) and adds no round trip —
the numerator reuses a cache namespace three other cards already populate.

## ClickHouse is not needed to build this

Every PHP test stubs the fetch seams through subclasses
(`UniqueScansToCaseClaimsTest.php:255,277`); none uses `RequiresClickhouse`. Phase 4 leaves Docker
ClickHouse down with the host neutralised to `127.0.0.1`, so a stray read fails loud and local.
Phase 6 QA provisions it, because agent-qa renders the real card.

## Deliberately not in this ticket

- `UniqueLprCaseClaimsQuery::windowWithPreClaimScans()` / `monthlySeriesWithPreClaimScans()` — kept
  and still tested, so the dropped rate can be revived cheaply. **No follow-up ticket.**
- The OKF wiki page for the new business rule — post-merge, per the ticket and `CLAUDE.md`.
  **No follow-up ticket.**
- Re-measuring the cold-path lock constants. They over-provision for a cheaper query, which is
  safe, but the docblock must stop citing the old figures as their justification.
- `HotlistCount`'s "Missing Plates Percentage" — a genuine percentage, graded safe.
