# DEV-2113 — Implementation Plan

**Route:** bug · **Base:** `scanner-app` · **Single-node DAG** (no fan-out)

## Problem
Scout historical map logo markers leak repossessed/closed plates. Root cause (reproduced on live CH): `clarity.hits_hot.hotlist_status` is a per-row snapshot frozen at hit-write and never back-updated. The marker endpoint includes Staging Live (`'S'`) rows — almost always stamped Open, rarely re-scanned to ever record a closing row — and filters status at the raw-row level, so long-repossessed plates stay pinned. Leak is 91% Staging.

## Fix (t1) — current-status filter, `complexity: complex`, no blast-radius
`app/Http/Controllers/Api/ClarityScoutMapController.php@hits`:
1. Resolve the scoped hit set's candidate `hotlist_id`s (kit_org_id + kit_id + hit types L/S + date window + validDisposition, distinct).
2. Resolve which are **currently** Open via the `Hotlist` model (applies `ApplyFinal` globally): `Hotlist::query()->whereIntegerInRaw('id', $candidateIds)->where('status', Open)->pluck('id')`.
3. Constrain the marker query to `whereIntegerInRaw('hotlist_id', $openIds)` **before** `orderByDesc('hit_created_at')->limit(MAX+1)` — so the cap counts only currently-open hits.
4. Keep `->validDisposition()` (per-hit, correct). Empty candidate set → empty payload short-circuit. Cache key/TTL unchanged. **No plate dedup** (per-hit markers preserved).

## Tests — `tests/Feature/Http/Controllers/Api/ClarityScoutMapControllerTest.php`
- Existing `test_map_hits_*` seed bare `hits_hot` rows (`hotlist_id=1`, no `clarity.hotlist` row) → they will return 0 under the new semi-join. **Update each to seed a matching `clarity.hotlist` row** with the intended current status (follow the `seedHit()` helper at ~line 1141 — it already seeds an open clarity.hotlist row keyed on `hotlist_id`).
- Rework `test_map_hits_only_returns_open_status_hits`: exclusion now driven by **current hotlist status**, not the hit row's frozen status (seed a stale-Open hit whose `clarity.hotlist.status=Repossessed` → excluded).
- **Add** `test_map_hits_excludes_repossessed_staging_live_hits`: Staging Live (`'S'`) hit with stale-Open hit-row status + `clarity.hotlist.status=Repossessed` → EXCLUDED; Staging Live with hotlist Open → INCLUDED.
- **Add/extend** a Direct stale-Open-but-now-Repossessed case → EXCLUDED.
- Carry `#[Group('controllers')] #[Group('clarity-scout')]`.

## Acceptance criteria coverage
- AC-1 (open-only on history): current-status filter → ✔ (Direct + Staging).
- AC-2 (matches left pane *rule*): both now apply the currently-open rule → ✔.
- AC-3 (regression test): Staging + Direct current-status tests → ✔.
