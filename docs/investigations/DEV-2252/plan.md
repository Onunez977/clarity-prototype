# DEV-2252 — Implementation plan

**Route:** bug · **Base:** `main` · **PR:** #414 (draft)
**Authored:** Phase 3, inline on the driver (opus, the `TICKET_PLAN_ARBITER_MODEL` tier).

## What this fixes

The admin ZIP→zone path has four separately confirmed defects (D1–D4) plus one dataset
property (D5, not a code defect). Each of D1–D4 produces a symptom matching the ticket's
wording. They split cleanly into three nodes.

| Node | Defect | Surface |
| --- | --- | --- |
| t1 | D1 + D2 | `ManageZones::save()` reports success over a NULL geometry; the test suite pins that as correct |
| t2 | D3 + D4 | client accepts truncated ZIPs, then silently attaches an unrelated ZIP's polygon |
| t3 | Q5 | `RunArea\Materializer` — same missing guard, different table |

## The shape of the fix

`Zone::rebuildGeometryFromDefinition()` is **contractually never-throw**. When the ZIP piece
resolves to nothing, `ST_Union` over an empty set yields NULL, `ST_MakeValid(NULL)` yields
NULL, and `geom` is written NULL. That is by design — `KmlZoneParser` depends on it. So the
post-condition belongs to the **caller**, and the repo already says so: `ZoneKmlImporter.php:64-72`
runs exactly the guard being added here. `ManageZones::save()` is the only creation caller
missing it.

The same idiom, and the same omission, appears in `Materializer::materialize()` on
`org_run_areas` — with one extra edge: it stamps `materialized_at = NOW()` alongside the NULL,
so the row claims to be materialized, and a re-materialize overwrites a previously-good
geometry with NULL. Wrapping the write in a transaction and throwing on empty fixes both.

## Nodes

### t1 — `ManageZones::save()` guard + un-pin the bug from the suite

Check `(geom IS NULL OR public.ST_IsEmpty(geom))` right after the rebuild, inside the existing
transaction. On empty: resolve which ZIPs matched no tiger row, throw to roll back, catch
outside, and surface `ValidationException::withMessages(['data.definition' => …])` — the repo's
existing Filament idiom (`CustomLoginPage.php:78`), landing on the same `definition` form key
the existing empty-definition rule uses.

Rolling back matters on the edit path too: it keeps the previous good geometry instead of
replacing it with NULL.

> **Cross-connection trap — this will silently break the test if missed.** `zod` and `platform`
> are the same database with different `search_path`, but **separate PDO connections**, and
> `TestCase::connectionsToTransact()` wraps both. A tiger row inserted on `zod` is invisible to
> `platform`. `rebuildGeometryFromDefinition()` reads `tiger_data.zcta5_all` on **`platform`**,
> so the seed in `ManageZonesTest` must go through `DB::connection('platform')` — not `'zod'`
> as `ZoneZipSearchControllerTest` does (that test reads back on `zod` too, so it never hit this).
> From `platform`, `spatial_ref_sys` needs the `public.` prefix.

### t2 — client-side ZIP handling

`/^\d{3,5}$/` → `/^\d{5}$/` (commit-time tokens are complete tokens, not keystrokes), and drop
`|| rows[0]` so `addZip()` is exact-match-only like its sibling `hydrateZips()`. Guard the now
possibly-undefined `exact` before dereferencing it, or the fix trades a wrong-area bug for a
TypeError.

`public/build` is tracked — the bundle must be rebuilt and committed or the change ships nothing.

**Not pinnable by the PHP suite.** There is no JS unit-test runner (Playwright e2e only) and the
Livewire tests set `data.definition` directly, bypassing this file. D3/D4 need browser QA in
Phase 6.

### t3 — Materializer

Wrap the UPDATE in a transaction, check emptiness, throw `RuntimeException`. Preserve the two
contracts the current tests pin: empty inputs and unrecognized-kind-only inputs still return
early, leave geometry NULL, and do **not** throw. The new throw fires only when pieces were
actually built and the union still resolved to nothing.

Consumers degrade safely on NULL geometry (`loadRunAreaForClip:146` falls back to the viewport
bbox), so the harm is silent non-clipping + false metadata + the destructive overwrite — not a
crash. Grade it accordingly.

## Sizing gate — evaluated, not tripped

6 target files, at the threshold and not over it. The one separable concern (`Materializer.php`)
is in scope by an **explicit operator override** of the driver's own follow-up-ticket
recommendation (Q5). No split recommended.

## Blast radius

None. No target file matches the sensitive globs. No pre-approval line required.

## Execution

t1 and t3 both exercise the shared branch test DB, so t3 is serialized behind t1 rather than
fanned out into per-node worktrees — `CLARITY_BOOTSTRAP_CMD` is unset, so per-node worktrees
would not get their own re-keyed databases, and concurrent `migrate:fresh` on one database is
exactly the contention the flow warns about. t2 touches no database and is parallel-safe. All
nodes run in `REPO_PATH` against the standing Phase 3.5 isolated environment.

**Tests:** filtered runs only (`--filter` on the touched class). CLAUDE.md forbids running the
full suite automatically; the repo's own boost guidelines require the affected tests to be run.
Filtered runs satisfy both.

## Still open

**Q1 — which ZIP codes actually failed for the reporter — was never answered.** All four code
defects are fixed regardless, so it does not change what is built. It does mean this ticket
cannot claim which defect produced the reported symptom, and the Build Record must say so.

## Queued for the wiki (after approval, never during the run)

- `okf/concepts/zone.md` — creation callers must check the rebuilt geometry is non-empty.
- `okf/concepts/run-area.md` — a recognized input resolving to nothing now fails loudly.
