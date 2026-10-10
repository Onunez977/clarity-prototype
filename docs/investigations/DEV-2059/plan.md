# DEV-2059 — Plan (single node)

**Scope (post-gate):** driver Scout only; MapShell hit sheet (map-marker + recent-hits taps) only; kit-scoped endpoint; optimistic client-side removal. The CH sync job + the `forDispositions`/`validDisposition` markers filter already exist on `main` and are **not** touched.

## The write chain already exists
`Scan::updateDisposition()` → writes `scans_metadata.meta->disposition` (Postgres) → dispatches `SyncDispositionToClickhouse` → CH `ALTER UPDATE` of the flat `disposition` column on 5 tables → every Scout read surface already filters `disposition IS NULL OR = Valid`, so a marked-bad hit drops off automatically once the mutation lands. This ticket only builds the **front half**: UI + a reachable endpoint + `scan_id` in the payload.

## Subtask t1 (complex, single-node fast path)
Backend + payload + frontend, one cohesive worker, TDD on the backend.

1. **`ClarityScoutScanDispositionController`** (new, `__invoke`, reuses `ScanDispositionRequest`): authorize to the driver's **own kit** — `Scan::where('id',$scanId)->where('kit_id',$user->kit_id)->firstOrFail()` (404 on another kit's scan; **never** `forOrg()`), then `updateDisposition()` + `Analytics::track('disposition-saved', …)`; 200 / 500 mirroring `DispositionController`.
2. **Route**: `POST scout/scan/disposition` in the existing `auth:sanctum` + `ClarityScoutMiddleware` scout group (`routes/api.php`), `throttle:30,1`.
3. **`scan_id` payloads**: add `'scan_id' => $hit->scan_id` to driver `ClarityScoutMapController::hits()` and driver `ClarityScoutDashboardController::recentHits()` (keep existing `id`). No CH query change (already-selected column).
4. **`MapShell.vue`**: disposition `<select>` + Invalid-Scan reason capture inside the existing hit BottomSheet, mirroring `disposition-popup.blade.php`.
5. **`map.js`**: `updateSelectedHitDisposition` action → POST via `lib/fetch.js`; on 200 optimistically drop the hit + clear selection (mirror `app.js:111-113`).

**Tests** (`--filter=ClarityScout`): new endpoint test (own-kit 200 / cross-kit 404 / validation / `Queue::fake` dispatch / Invalid-Scan reason capture, modeled on `DispositionControllerTest`); `scan_id` assertions added to the two payload tests. Frontend has no JS runner → manual/agent QA.

**Security invariant:** kit-scoped tenancy (`where('kit_id',$user->kit_id)`), pinned by a cross-kit 404 test.

## Not in scope (excluded, with reasons)
- `forDispositions`/`validDisposition` on any read surface — already applied everywhere, pinned by tests.
- Dispatching `SyncDispositionToClickhouse` — already dispatched (DEV-2176).
- Admin Bird's-Eye control + route — driver-only per Dalton.
- Day/Trip overlay sheets + their `scan_id` — MapShell only per Dalton; trip/day stay read-only.
- Any CH read repoint to CDC/`scans_metadata` — DEV-2176 measured ~3.7s/query and reverted; keep the flat column.

## Gates
- **Perf gate:** not required (no CH read query added/changed).
- **Blast radius:** none (no migrations / billing / auth-config / CH purge globs). The endpoint is auth-territory → 3 review lanes in Phase 5.
- **Sizing:** one cohesive feature across 3 layers; no ticket split.
