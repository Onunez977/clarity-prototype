# DEV-2235 — Implementation plan

**Enable Hit Disposition in the Admin Birds Eye view (parity with driver)**

Route: feature · Base: `main` · Branch: `claude/DEV-2235` · Gate: CLEAN

Backend-only. The disposition UI in the shared `MapShell.vue` already exists and is gated
only on the selected hit carrying a `scan_id` — not on driver mode — so no frontend change
is required. Three gaps block admins today, and prep found two further defects that the
ticket's suggested shortcut would have introduced.

## Decisions carried in from the Phase-2 gate

| Question | Decision | Source |
|---|---|---|
| Analytics discriminator for a Bird's-Eye-originated save | `from_scout=true` **plus** `page: ScoutAnalyticsPage::BirdsEye->value`. The boolean is **not** widened, so no existing series changes meaning. | human:Dalton 2026-08-03 |
| Enum breadth for admins | Restrict to the driver 5, enforced server-side by reusing `ScoutScanDispositionRequest`. Widening is a separate ticket (needs a UI change to be reachable). | human:Dalton 2026-08-03 |
| Which controller | New thin `ClarityScoutBirdsEyeDispositionController` — **not** `DispositionController` | code (unanimous across 4 prep passes) |
| Staging-Live read/write scope divergence | None exists. `Scan::forOrg` is kit-ownership scoped, the same axis as the read side's `forKitOrg`; write set ⊇ display set. | code (`Scan.php:435-443`) |
| Throttle | `throttle:30,1`, mirroring the driver route | code (`routes/api.php:62`) |

## Subtask DAG

```
wave 1 (parallel, disjoint files):  t1 ── payloads
                                    t2 ── controller + route
wave 2:                             t3 ── routing test  (depends_on: t2)
```

### t1 — Add `scan_id` + `disposition` to both admin hit payloads · *simple*

`app/Http/Controllers/Api/ClarityScoutBirdsEyeController.php` + its test.

Add both keys to the payload map in **`hits()`** and **`recentHits()`**.

- **Locate the maps by content, not by the ticket's line numbers** — two prep passes
  disagreed on whether those drifted, so content is the only reliable anchor.
- **`recentHits()` already emits `'id' => $hit->id`**, which is the ClickHouse Hit *row*
  id. `scan_id` must be a **separate key**; renaming or reusing `'id'` would post the wrong
  identifier and is the likeliest way to get this node wrong.
- `disposition` is not cosmetic: `MapShell.vue:236` seeds the control via
  `hit?.disposition ?? ''`, so without it an admin's saved disposition renders blank on
  re-open — a parity break.

Tests: extend the existing `recentHits` assertions, and **add a first-ever `hits()` test** —
that endpoint has no coverage today, so half this node would otherwise ship unpinned. Cover
a Staging-Live row as well as a Direct one. This class is CH-backed, so date every
`hits_hot` fixture relative to `now()` (the hot tables enforce a 90-day TTL *at insert*; a
hardcoded date silently reads back zero rows).

### t2 — Org-scoped disposition controller + POST route · *complex*

New `app/Http/Controllers/Api/ClarityScoutBirdsEyeDispositionController.php`, one added
route line in `routes/web.php`, new test class.

Modelled on `ClarityScoutScanDispositionController`, **not** `DispositionController`:

1. Type the parameter as the **existing** `ScoutScanDispositionRequest` — no new
   FormRequest (it already restricts to the 5, and `authorize()` is just `auth()->check()`).
2. Resolve the scan **outside** any try/catch so a cross-org scan yields a clean 404:
   `Scan::query()->where('id', …)->forOrg((int) auth()->user()->org_id, withSharedKits: Feature::active(FeatureEnum::SHARED_KITS->slug()))->firstOrFail()`.
3. Normalize `reason` exactly as the driver does:
   `$reason = $request->input('disposition') === DispositionEnum::INVALID_SCAN->value ? ($request->input('reason') ?? 'other') : 'other';`
4. `$scan->updateDisposition(...)` inside a try/catch that logs and returns 500.
5. `Analytics::track('disposition-saved', …, page: ScoutAnalyticsPage::BirdsEye->value)` in
   its **own** try/catch so a tracking failure never blocks the save. Pass the event as a
   raw string — `ScoutAnalyticsEvent` has no such case and both siblings do the same.
6. Route, above the GET-only `{any}` catch-all:
   `Route::post('scan/disposition', ClarityScoutBirdsEyeDispositionController::class)->middleware('throttle:30,1');`

Route and controller are **one node** deliberately: a route pointing at a nonexistent class
does not boot, so splitting them leaves a broken intermediate state.

### t3 — Pin route reachability · *simple* · depends on t2

Extend the existing **non-`RequiresClickhouse`** org-map routing test so the new POST is
proven routable in normal CI (the DEV-2184 precedent recorded in the knowledge card).

## Why the "smallest viable path" wasn't taken verbatim

The ticket suggests pointing the route at the existing `DispositionController`. That would
have shipped two verified defects:

1. **Silent human-review-queue pollution.** `map.js` omits the `reason` key for every
   non-Invalid-Scan disposition (`if (reason) { payload.reason = reason; }`).
   `DispositionController` forwards `reason` raw, and `Scan::updateDisposition`'s guard is
   `if ($reason !== 'other')` — which `null` satisfies. Every *Accessible / New Hit /
   Moving / Inaccessible* admin save would have created a `ScanValidation` row. The failure
   mode is silent: no error, just a polluted queue.
2. **Authorization failures reported as 500s.** `DispositionController` swallows the
   tenancy `ModelNotFoundException` into a 500 (pinned by its own test). The driver
   controller deliberately keeps the lookup outside the try/catch.

Both became derived acceptance criteria, each with a pinning test in t2.

## Not touched

Trip/day markers (intentionally `scan_id`-less), the heatmap/path payloads (no single-hit
disposition concept), `DispositionController` (its own latent null-reason bug is
pre-existing and masked by its caller — flagged as a follow-up), the driver controller
(left byte-identical, which is what makes "driver behavior unchanged" true by
construction), the Filament disposition surfaces, and all of `resources/js/clarity-scout/**`.

## Gates

- **Blast radius:** none. No migrations, billing, Shield/Policy/auth-config, CH purge, or
  PruneScans. Tenancy-relevant, hence t2 is `complex`, but no pre-approval needed.
- **CH-at-scale perf gate:** not applicable — no new query shape; the read side gains two
  additive projected columns and the write side reuses the existing sync job.
- **Review:** must escalate to 3 lanes — semantic-correctness territory (org tenancy on a
  new write path, plus a guard whose failure mode is silent).
