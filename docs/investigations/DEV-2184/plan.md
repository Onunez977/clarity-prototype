# DEV-2184 — Implementation Plan

**Route:** bug · **Base:** main · **Single-node DAG** (Phase 4 fast path, no worktree fan-out)

## Root cause (reconciled)
Scout admin Bird's Eye reuses the driver Vue SPA; in admin mode `lib/fetch.js` targets `BASE_URL=/scout/org-map/api`. That admin route group ends in a Vue-Router SPA catch-all `Route::get('{any?}', view('clarity-scout-birds-eye'))`. Any ungated driver-only fetch hits a path with no admin mirror → matched by the catch-all → `200 text/html` → `response.json()` throws `Unexpected token '<'`. Not a backend 500. The `kit-position` sink named in the ticket was already fixed (DEV-2164 + DEV-2179); the live sink is `App.vue`'s ungated `shift`/`notifications` watch.

## Subtask t1 — the whole fix (one cohesive change)
1. **`resources/js/clarity-scout/App.vue`** — gate `shift.fetchStatus()` + `notifications.fetch()` in the `watch(auth.isAuthenticated, {immediate:true})` handler on `!mapStore.adminMode`. Driver behavior unchanged.
2. **`routes/web.php`** — inside the `scout/org-map` `Route::prefix('api')->group()`, after the explicit admin routes and before the SPA `{any?}` view, add a JSON-404 catch-all: `Route::get('{any}', fn () => response()->json(['message' => 'Not Found.'], 404))->where('any', '.*');`. Durable AC1 invariant.
3. **`tests/Feature/Http/Controllers/Api/ClarityScoutBirdsEyeControllerTest.php`** — 3 tests (TDD, write first): JSON-404 on unmatched admin-api path; SPA deep link still returns the shell; `driverPositions()` returns 200 JSON (was zero coverage).
4. **`public/build`** — `npm run build` + commit rebuilt assets (tracked repo).

## AC coverage
- **AC1** (JSON incl. on error) → App.vue gate + JSON-404 backstop.
- **AC2** (server-side error identified/fixed) → identified as a client-side routing leak (no server exception); fixed via gate + backstop. Reframing confirmed in QA.
- **AC3** (all-driver positions render) → **verify-only in QA**; no code change scoped (code path looks sound on main). Escalate to a fix only if QA shows dots genuinely don't render.

## Excluded
- `kit-position`/LiveTracking leak — already fixed (DEV-2164/2179).
- ProfilePanel/NotificationsPanel/ScoutHome/`/me` latent leaks — Dalton (Q3) chose the JSON-404 backstop over explicit gating.
- `scopedKits()` outside try/catch — Laravel renders JSON for genuine exceptions; not the HTML-fallback cause. Optional follow-up.

## Gotchas
- **CH-gated tests:** `ClarityScoutBirdsEyeControllerTest` uses `RequiresClickhouse` → whole class skips unless `CLICKHOUSE_DRIVER=clickhouse`. Phase 4 must bring up Docker CH (`QA_PROVISION_CH=yes`) or the new tests silently skip.
- **Committed assets:** the App.vue change won't ship without `npm run build` + committing `public/build`.
- **No JS test runner:** App.vue gate is QA-verified (Network tab), not unit-tested.
