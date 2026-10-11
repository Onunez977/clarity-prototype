# Scout Feature — Security / Correctness / Performance Audit

**Date:** 2026-06-29 · **Branch audited:** `main` @ `8466c2ba` · **Fixes branch:** `scout-audit-fixes`
**Scope:** Scout PWA (`resources/js/clarity-scout/`, ~8.5K LOC) + Scout-specific backend (controllers, middleware, ClickHouse query classes, routes, channels) + Scout-specific Filament surfaces (ScoutUsersResource, Zone* , KitUser* pages). The general Hits/Scans/Hotlist/MissedOpportunity/ClientSearchScans search resources were **out of scope** and not audited.

## Method

Orchestrated as a multi-agent workflow: **9 finder lanes** (8 per-area + 1 dedicated cross-cutting multi-tenancy sweep) each read the real files end-to-end, then **every candidate finding was adversarially verified by independent skeptics** instructed to *refute by default* — 2 diverse-lens skeptics (code-reality + exploitability) for Critical/High, 1 for Medium/Low. A finding was only reported as real if a majority of its skeptics independently confirmed it after re-reading the cited code. **38 agents, 622 tool calls.**

All ClickHouse access was treated as **read-only**; the test suite was **not run** (CI owns that). Findings were verified by reading code + read-only class/route inspection only.

**Result:** 27 candidate findings → **14 confirmed**, **13 refuted/killed**.

| Severity | Confirmed |
|---|---|
| Critical | 0 |
| High | 2 |
| Medium | 2 |
| Low | 10 |

> **Note on the prior-knowledge seeds:** all four were verified against current code. (1) `hotlist_status` frozen-at-write → **still present on 3 Scout surfaces** (F7/F9/F10 — the known DEV-2117 deferred sibling work). (2) geohash precision is controller-gated; the query-class `?? N` fallbacks are indeed unused by Scout → **no Scout bug there** (confirmed; see refutations). (3) `kit_mapping` casing → the live position query already does `lower(kit_mapping) = lower(?)` → **handled, not a bug** (see refutations). (4) Scans-stat FINAL over-count → the `repos_affected` instance is **intentional/correct per the DEV-2080 live audit** (refuted); no new over-count surface found.

---

## Multi-tenancy verdict — VERIFIED CLEAN (no cross-org data hole)

The dedicated cross-cutting sweep traced **every** Scout endpoint and **confirmed no cross-tenant data-access hole survives verification.** Recorded for the record:

- Every Scout endpoint resolves **org from `$user->org_id`** and **kit from `$user->kit_id`** (the `kit_settings` accessor) — both auth/token-derived, **never from a request param.**
- Request params that flow into queries are all constrained: `range` (safe default), `date`/`since`/`start_date`/`end_date` (validated `date_format`), bbox `north/south/east/west` (float-cast in `snapBbox`), `precision` (config-gated + `sanitizePrecision` 6–9), `layers` (`in:direct,staging`), `zone_ids` (`intval` + **403 unless in `$user->zones()`**), and Bird's-Eye `kit_ids` (**intersected against org-scoped `Kit::forOrg`** via `scopedKits()`).
- The `forOrg` vs `kit_org_id` pitfall is handled correctly and consistently (Hit surfaces use `forKitOrg`, Scan uses `forOrg`) across both driver and Bird's-Eye paths.
- `Org::stagingOrgIds()` resolves the ApprovalList by `agent_org_id = $this->id` and is only ever expanded **inside** the staging layer keyed by the caller's own `org_id` → **no cross-tenant widening.**
- **ClickHouse injection:** every interpolation into raw SQL is `(int)`/`(float)`-cast (org_id, kit_id, orgList, bbox, precision, statuses), enum-mapped (`tierConditions`, dispositions), or `addslashes`'d over **CH-derived (not user)** timestamps/coords; `OrgLivePositions`/`OrgLivePaths` use bound `?` placeholders. **No user-controllable unbound interpolation found.**

The two "cross-org" candidates that the finders raised were both correctly **refuted** (see R12/R13): they reduce to *trusted-staff-by-design* impersonation behavior, not a tenant-to-tenant boundary break. The one residual is an **internal-staff lateral-movement / auditability** weakness (F1, F5, F13) on the force-login impersonation tool — not a customer-to-customer hole.

---

## CONFIRMED FINDINGS

### F1 · HIGH · Security — Scout force-login (impersonation) token is not revoked on impersonation exit; lives 8h as a gate-bypassing, cross-org bearer credential
**Files:** `app/Http/Controllers/ClarityScoutForceLoginController.php:22-24` (mint) · `app/Http/Controllers/ImpersonateController.php:70-92` (leave/exitAdminMode) · `config/sanctum.php:53` (expiration 480)
**Verdict:** confirmed (2 skeptics, severity adjusted to High)

**What's wrong:** The force-login controller (route `web.php:103`, gated by `auth` + `session()->has('admin_impersonator')` — i.e. ~10 hardcoded Insight-LPR staff) mints a Sanctum token with abilities `['*','clarity-scout-force']` for the kit's assigned driver and injects the plaintext into the `clarity-scout` blade, where the PWA persists it to `localStorage` as `scanner_token`. The `clarity-scout-force` ability is a **hard bypass**: `ClarityScoutMiddleware:25-27` and `channels.php:30-32` both short-circuit *before* the `clarity-scout` permission, `kit_id`-assignment, and `CLARITY_SCOUT` feature-flag checks. `createToken` is called with **no `expiresAt`** and no Sanctum override exists, so the token inherits the global **480-minute (8h)** lifetime. Ending impersonation via `ImpersonateController::leave()` / `exitAdminMode()` only invalidates/forgets the **web session** — it never deletes the impersonated user's Sanctum tokens. The lone force-token deletion (`controller:22`) runs only on the *next* mint.

**How it manifests:** After a staff admin exits impersonation, the force token remains a fully valid, gate-bypassing bearer credential for that driver's account for up to 8 hours, persisting in the staff browser's `localStorage`. It also **defeats the normal-path staleness protection**: if the driver is un-enrolled (kit_settings removed) or moved to another org, the normal middleware would reject them, but the force token keeps working because it short-circuits before the kit/org/permission re-check. Realistic exploitation requires the long-lived token to leak from a trusted staff browser (shared/admin machine, dev tools, screen share, proxy logs) — it is **not** reachable by an ordinary org user.

**Recommended fix:** Bind the force token's lifetime to the impersonation session: **(a)** in `ImpersonateController::leave()` AND `exitAdminMode()`, delete the impersonated user's force tokens — `$request->user()?->tokens()->where('name','clarity-scout-force-token')->delete();` (in `leave()` do this *before* `session()->invalidate()`); and **(b)** mint with a short explicit expiry — `createToken('clarity-scout-force-token', ['*','clarity-scout-force'], now()->addMinutes(60))`. Keep the `controller:22` prior-token cleanup (it's a partial mitigation).

**Remediation:** ⏸ **Report-only.** The complete fix touches the *shared, non-Scout* `ImpersonateController` and changes token-lifetime semantics that QA/force-login workflows depend on (force-login is used heavily in QA). Apply deliberately with QA sign-off, not as a drive-by.

---

### F2 · HIGH · Security — Service worker caches authenticated `/api/scout/*` PII responses by URL and never purges on logout
**File:** `public/clarity-scout-sw.js:73-77, 100-126` (caching) · `resources/js/clarity-scout/stores/auth.js:80-97` (logout, no purge)
**Verdict:** confirmed (2 skeptics)

**What's wrong:** The SW routes every GET to `/api/scout/*` through `networkFirst(request, API_CACHE)`, which `cache.put()`s any `response.ok` into a persistent, version-keyed Cache Storage bucket — ignoring `Cache-Control`, with **no per-user/per-token keying** (the Cache API matches purely by URL; `Authorization` is not part of the match). These endpoints return per-driver PII / LPR intelligence (`/me` → name/email/org; `dashboard/recent-hits`, `map/hits`, `map/kit-position`, `map/live-path`, `trips/*`, `notifications` → plate / vehicle / hit-GPS / real-time track). On network failure `networkFirst` serves `caches.match(request)`. Neither `auth.logout()`, `fetch.js handleUnauthorized()`, nor the SW itself ever deletes the API bucket (the SW's only `caches.delete` runs in `activate` and only drops prior-version buckets).

**How it manifests:** On a shared/loaner field unit: Driver A uses the app then logs out; Driver B logs in. While B is online each fetch overwrites A's entry per-URL — but for any `/api/scout/*` URL B has **not yet fetched online**, the moment B drops offline `networkFirst` returns **A's cached body** (e.g. A's `/me`, recent-hits, trips, live-path). Independently, A's PII bodies sit in Cache Storage at rest past sign-out.

**Recommended fix:** On `auth.logout()` and on the 401 path of `handleUnauthorized()`, purge the API cache before redirecting: `postMessage({type:'CLEAR_API_CACHE'})` to a new `message` handler in the SW that does `caches.delete(API_CACHE)`, plus a direct page-side `caches.delete(\`clarity-scout-api-${version}\`)` for when the SW is inactive. Better still, **don't cache identity/PII endpoints at all** — restrict the API_CACHE route to map/zone/stats data that genuinely needs offline support and exclude `/me`, `dashboard/recent-hits`, `map/hits`, `map/kit-position`, `map/live-path`, `trips/*`, `notifications`.

**Remediation:** ⏸ **Report-only.** Pure-frontend service-worker change with **no JS test harness in the repo** to verify against, and SW caching/versioning/activation is deployment-sensitive. Recommend implementing with manual device QA (login as A → logout → login as B → go offline → confirm no A data).

---

### F3 · MEDIUM · Performance — `trips/browse` has no max date-range cap; materializes all kit scan/hit timestamps for the window into PHP memory
**File:** `app/Http/Controllers/Api/ClarityScoutTripsController.php:104-154` (validation 107-110), `156-197` (pluck)
**Verdict:** confirmed (severity adjusted to Medium)

**What's wrong:** `browse()` validates `start_date`/`end_date` only as `Y-m-d` with `after_or_equal` — **no upper bound on the span** and no throttle on the route. `buildTripSummaries()` then `->pluck('scanned_at')` from `scans_cold` and `->pluck('hit_created_at')` from `hits_cold` across the **entire requested window** and materializes every matching timestamp into a PHP Collection (`->map(strtotime)->sort()`). Both cold tables have **no TTL** (full history); `kit_trips` is TTL-capped to 90 days so the trip *list* is bounded, but the scan/hit pluck is bounded only by the requested window × the kit's volume. The per-`(org,kit,start,end)` cache is trivially defeated by varying dates.

**How it manifests:** An authenticated driver (or buggy/malicious PWA client) requests a multi-year window and streams up to millions of a busy kit's timestamps into a worker → slow responses or OOM. Scope is the caller's **own** org+kit (not cross-tenant) and ClickHouse-side reads stay partition-pruned, so harm is PHP-side memory/CPU — hence Medium.

**Recommended fix:** Cap the span in validation (≤ ~90 days, aligning with the `kit_trips` 90-day TTL — trips beyond that don't exist anyway), returning 422 for over-long windows. Longer-term, push the per-trip aggregation into ClickHouse (`countIf` per trip window) so only small integer counts cross into PHP.

**Remediation:** ✅ **FIXED on `scout-audit-fixes`** — added a 90-day span cap to `browse()` validation + a regression test asserting 422 for an over-long window. (The `countIf` push-down is left as a follow-up.)

---

### F4 · MEDIUM · Correctness — Driver-path 403 (Scout access revoked mid-session) leaves the driver on a stuck-authenticated dead screen
**File:** `resources/js/clarity-scout/lib/fetch.js:31-44`
**Verdict:** confirmed

**What's wrong:** `handleUnauthorized()` wipes the token + redirects to `/scout/login` only on `401`; the `403` branch is gated behind `ADMIN_MODE`. On the driver path `ADMIN_MODE` is false, so a `403` matches neither branch and the function silently no-ops. But `ClarityScoutMiddleware:31-41` returns **403 (not 401)** for every mid-session access-loss case (clarity-scout permission removed, kit unassigned, `CLARITY_SCOUT` disabled). When access is revoked while the PWA is open, every driver endpoint 403s, `handleUnauthorized` does nothing, the token survives, and the client-only guard (`isAuthenticated = !!token.value`) keeps reporting authenticated — the driver is stranded on a dead map with no path back to login. This is reachable in normal operation (Scout is explicit-grant-only; orgs can lose the grant on deploy). It's a **UX dead-state, not a data leak** (the server correctly denies access).

**⚠️ Important caveat (found during fix verification):** The finder's proposed fix — treat **all** driver 403s as logout — is **too broad and would cause false logouts.** Not every Scout 403 is access-revocation: `ClarityScoutMapController.php:745` does `abort_unless(in_array($zoneId, $assigned, true), 403)` (driver requested an unassigned zone) and `ClarityScoutShiftController.php:43,79` returns 403 `"No kit assigned"`. Blindly logging out on any 403 would eject a driver who merely hit one of those in-app authorization errors.

**Recommended fix:** Discriminate access-revocation 403s from in-app-authorization 403s — e.g. have `ClarityScoutMiddleware` return **401** for the three access-loss cases (so the existing 401 path handles them), **or** key the logout on the middleware's specific messages / a distinguishing response flag. Also route `auth.js fetchUser()` (the `/me` boot call) through `handleUnauthorized` so a stale PWA recovers on boot.

**Remediation:** ⏸ **Report-only.** The naive one-line fix is unsafe (false logouts above); the correct fix requires a small server/client contract decision (401-on-revocation vs message discrimination) and there is no JS test harness to guard the frontend half. Recommend the middleware-returns-401 approach, which is cleanly backend-testable.

---

### F5 · LOW · Security — `scout/force-login/{kit}` mints a driver credential with no audit log and no throttle (unlike the sibling impersonate flow)
**File:** `app/Http/Controllers/ClarityScoutForceLoginController.php:14-24` · route `routes/web.php:103`
**Verdict:** confirmed (severity adjusted to Low)

**What's wrong:** `ImpersonateController::impersonate()` writes `activity('impersonation')`; force-login writes **no** activity record of which kit/org/driver a staff admin pivoted into, so incident review cannot reconstruct it. The route also lacks any throttle (cf. `/scout/login`'s `throttle:5,1`). The cross-org *reach* is by-design staff behavior; the value here is the **audit trail**.

**Recommended fix:** Write an `activity()` record capturing the acting admin (`session('admin_impersonator')`), the target `$kit->id`, `$kit->org_id`, and `$user->id`, mirroring `ImpersonateController`. Add a throttle to the route for defense in depth.

**Remediation:** ✅ **FIXED on `scout-audit-fixes`** — added the `activity()` audit record (purely additive observability) + a test asserting the log row is written. The throttle was **left off** to avoid friction with QA force-login loops; recommended in the report only.

---

### F6 · LOW · Performance — Scout map polling routes have no per-user rate limit
**File:** `routes/api.php:38-43`
**Verdict:** confirmed

**What's wrong:** The six map routes (`scan-paths`, `hits`, `heatmap/hits`, `heatmap/orders`, `kit-position`, `live-path`) sit in the `auth:sanctum`+`ClarityScoutMiddleware` group with **no `throttle`**, unlike sibling write routes (`login` `5,1`, `shift/end` `10,1`). No global API limiter is registered. `kitPosition` (polled ~every 10s) and `livePath` are **entirely uncached** and hit ClickHouse on every request. An authenticated driver — or anyone with a stolen bearer token — can loop these with no ceiling and drive avoidable ClickHouse load. Bounded to availability/cost (all access authenticated + strictly org/kit-scoped; no cross-tenant exposure). `precision` is **not** an attack axis (coerced to 7 in prod).

**Recommended fix:** Wrap the six map routes (or the whole authed group) in `->middleware('throttle:120,1')`.

**Remediation:** ✅ **FIXED on `scout-audit-fixes`** — added `throttle:120,1` to the map-route group + a test asserting the throttle middleware is applied. (120/min comfortably clears the ~6/min kit-position poll.)

---

### F7 · LOW · Correctness — Direct Hit Density (`heatmapHits`) shades cells by FROZEN `hotlist_status` (DEV-2117 sibling of the DEV-2113 fix)
**File:** `app/Clickhouse/Queries/HitDirectHotspotQuery.php:62-66`
**Verdict:** confirmed (severity adjusted to Low)

**What's wrong:** The density query filters `h.hotlist_status = {OPEN}` against the denormalized column frozen at hit-write time and never CDC-back-updated. The sibling marker endpoint `hits()` was fixed under DEV-2113 to semi-join CURRENT status from `clarity.hotlist` (FINAL); this density query was not (the known deferred DEV-2117 sibling). An earlier Open-stamped Direct-Live hit of an order since repossessed/closed still contributes to the cell. **Impact is narrow:** the query is `hit_type='L'` (Direct Live only), excluding the dominant Staging-Live leaker, and Direct orders generally record a closing hit → modest over-shading, not stale actionable markers. The existing `test_excludes_non_open_orders` seeds the *frozen* column = Closed and so **does not cover the real leak** (frozen=Open, current=Closed).

**Recommended fix:** Mirror the DEV-2113 `hits()` correction — constrain to currently-open Direct hotlist ids via a FINAL semi-join to `clarity.hotlist`. Add a regression test where the frozen `hotlist_status=Open` but the seeded hotlist row is Closed.

**Remediation:** ⏸ **Report-only — DEV-2117.** This is explicitly the deferred sibling-surface work (human decision); a correct fix requires the semi-join + live-CH QA. Bundle with F9/F10 under DEV-2117.

---

### F8 · LOW · Code quality — Dead, unreachable legacy-MV query block after `return` in `HotlistAddressGeohashQuery::handle()`
**File:** `app/Clickhouse/Queries/HotlistAddressGeohashQuery.php:51-81`
**Verdict:** confirmed

**What's wrong:** `handle()` ends with an unconditional `return self::queryClickhouseRaw(...)` at line 39. The `// OLD` block (51-81) — which queries the retired precision-6 MV table `hotlist_address_geohash` and ends in its own `return` — sits after that return and **can never execute.** Misleading dead code referencing a retired table.

**Recommended fix:** Delete lines 51-81. The helpers it calls (`runAreaClause`, `tierConditions`) remain used by the live path, and the `DB` import remains used at line 190 — so the deletion orphans nothing.

**Remediation:** ✅ **FIXED on `scout-audit-fixes`** — removed the unreachable block. No new test (the existing `HotlistAddressGeohashQueryTest` covers the live `queryClickhouseRaw` path; dead-code removal changes no behavior).

---

### F9 · LOW · Correctness — Driver dashboard `recentHits` filters & labels by FROZEN `hotlist_status` (DEV-2117)
**File:** `app/Http/Controllers/Api/ClarityScoutDashboardController.php:131-152` (esp. `136` `forStatus(Open)`, `146` label)
**Verdict:** confirmed (severity adjusted to Low)

**What's wrong:** `recentHits` selects `->liveValid()->forStatus(Open)` → `where('hotlist_status', 1)` on the frozen per-hit column, and emits that frozen value as the display label. The sibling `ClarityScoutMapController::hits` was fixed under DEV-2113 to derive CURRENT open status via a FINAL semi-join; `recentHits` was deliberately left on the frozen column (DEV-2117). A Direct-Live hit whose order repossessed/closed after the scan still shows in Recent Hits labeled Open — so the map view and this card can disagree. **Direct-Live-only + plate-deduped → real-world incidence is materially lower than the map endpoint.**

**Recommended fix:** Mirror the DEV-2113 map fix (pluck candidate `hotlist_id`s → FINAL semi-join to `ClickhouseHotlist` for currently-open ids → `whereIntegerInRaw` constrain + label from the live-open fact).

**Remediation:** ⏸ **Report-only — DEV-2117.** Deferred product decision; bundle with F7/F10.

---

### F10 · LOW · Correctness — Bird's-Eye `recentHits()` filters by FROZEN `hotlist_status` (DEV-2117)
**File:** `app/Http/Controllers/Api/ClarityScoutBirdsEyeController.php:505-513`
**Verdict:** confirmed (severity adjusted to Low)

**What's wrong:** Same pattern as F9 on the admin org-wide view — `->forStatus(Open)` on the frozen column, where the same controller's `hits()` endpoint already resolves CURRENT status via the `ClickhouseHotlist` semi-join. A since-closed order can still appear in the "Hits Pending Repossession" overlay card. Limited by `->liveValid()` (Direct-Live only) + plate dedup.

**Recommended fix:** Apply the same CURRENT-status semi-join `hits()` already uses; fix F9 and F10 together so both feeds stay aligned.

**Remediation:** ⏸ **Report-only — DEV-2117.** Bundle with F7/F9.

---

### F11 · LOW · Correctness/UX — Bird's-Eye heatmap layers silently ignore the `kit_ids` driver selector
**File:** `app/Http/Controllers/Api/ClarityScoutBirdsEyeController.php:347-424`
**Verdict:** confirmed

**What's wrong:** Every other Bird's-Eye endpoint honors the driver selector via `scopedKits()`, and the frontend `buildUrl` auto-appends `kit_ids[]` to **all** admin-mode requests including `/map/heatmap/*`. But `heatmapHits()` and `heatmapOrders()` never call `scopedKits()`/read `kit_ids` → the param is sent and silently dropped. Filtering to one driver narrows the dot/path/hit layers but leaves the heatmap overlays org-wide. **Strictly org-scoped (no cross-tenant exposure)** and largely correct-by-necessity (order density is address-based with no kit dimension). The defect is an undocumented selector-contract inconsistency / minor UX surprise.

**Recommended fix:** Document on both endpoints that heatmap layers are intentionally org-wide + selector-independent, and skip appending `kit_ids` for `/map/heatmap/*` in `buildUrl` (mirroring the existing `/dashboard/stats` special-case) so the querystring/cache key stay clean.

**Remediation:** ⏸ **Report-only.** Effectively working-as-intended with a documentation/UX gap; low value, frontend-touching, no clean automated test.

---

### F12 · LOW · Performance — `ScoutUsersResource` list issues ~3 N+1 queries per assigned-user row
**File:** `app/Filament/Resources/ScoutUsersResource.php:237-250, 406-409`
**Verdict:** confirmed (severity adjusted to Low)

**What's wrong:** `modifyQueryUsing` eager-loads only `['settings.user']`, but two columns compute un-preloaded relations per row: `zones_count` (`239`) calls `$record->assigned_user?->zones()->count()` (a correlated COUNT per row), and `web_access` (`250`) calls `hasPermissionTo('clarity-scout')`, whose Spatie internals `loadMissing('permissions','roles')` per user (2 queries). ~3 extra queries per row with an assigned user. Admin-only + paginated, so bounded — hence Low.

**Recommended fix:** Eager-load `settings.user.roles`, `settings.user.permissions`, `settings.user.zones` and derive `zones_count` from the loaded collection (`$record->assigned_user?->zones?->count() ?? 0`).

**Remediation:** ✅ **FIXED on `scout-audit-fixes`** — eager-loaded the three relations + switched `zones_count` to the loaded collection + a query-count regression test (query count does not scale with row count).

---

### F13 · LOW · Multi-tenancy — Force-login route mints a token for any org's kit (`{kit}` binding not org-scoped)
**File:** `app/Filament/Resources/ScoutUsersResource.php:340-347` · `app/Http/Controllers/ClarityScoutForceLoginController.php:16` · route `routes/web.php:103`
**Verdict:** confirmed Low by the Filament lane — **but contested** by the multi-tenancy sweep (see R12)

**What's wrong:** The `{kit}` implicit binding has no org constraint and the controller gates only on `session()->has('admin_impersonator')` — never comparing `$kit->org_id` to anything. A staff member with an active impersonation session can hit `/scout/force-login/{anyKitId}` directly and mint a force token for any org's driver. The resource action's in-org `visible()` guard only hides the button; it does not protect the route. **Blast radius is internal-staff lateral movement, not customer-to-customer** — hence Low.

**⚠️ Why this is contested / report-only:** The two lanes proposed *different* org checks. The sweep refuted `abort_unless($kit->org_id === $kit->assigned_user->org_id)` as a **tautology** (a kit and its own assigned user always share an org — correct). The Filament finding's `abort_unless($kit->org_id === $request->user()->org_id, 404)` is **not** a tautology — during force-login `$request->user()` is the *impersonated platform user* (Auth logged in as `user_id`), so it would restrict force-login to kits in the **currently-impersonated** org. That aligns the route with the resource action's existing in-org `visible()` filter. **However**, whether staff *should* be constrained to the impersonated org (vs. the by-design cross-org capability) is a workflow-semantics decision, and force-login is QA-critical.

**Recommended fix (if cross-org force-login is not required):** `abort_unless($kit->org_id === $request->user()->org_id, 404)` in the controller — comparing against the impersonated user's org, **not** the kit's assigned user's org. Pairs naturally with F1/F5.

**Remediation:** ⏸ **Report-only.** Contested + subtle impersonation semantics + QA-critical path. Decide alongside F1/F5 as one "harden force-login" change.

---

### F14 · LOW · Multi-tenancy — `getOptionLabelsUsing` resolves driver names with no org filter (display-time cross-tenant name disclosure)
**File:** `app/Filament/Actions/ZoneReassignAction.php:58-63` · `app/Filament/Pages/ManageZones.php:170-175`
**Verdict:** confirmed

**What's wrong:** In both the reassign action and the ManageZones form, the `users` multi-select's `getOptionLabelsUsing` callback runs `User::query()->whereIn('id', $values)->pluck('name','id')` with **no `where('org_id', ...)`**, unlike the sibling `options()` (org-scoped) and `rule()` (correctly rejects foreign-org/non-kit users). Filament resolves these labels from raw, attacker-controllable Livewire state. An org admin who tampers the component state to inject a foreign-org user ID (and knows/guesses that numeric ID) would see that user's **name** rendered as a selected-option label. The **save is safe** (the org-scoped `rule()` rejects the submission) — but note Filament's built-in `in:` validation derives its allowed set from this same unscoped callback, so the custom `rule()` is the *only* effective save-side gate. Net impact: leaking a single name string per guessed ID.

**Recommended fix:** Mirror `options()`/`rule()` scoping on the label query at both sites: `->whereIn('id', $values)->where('org_id', auth()->user()->org_id)->whereHas('kitSettings')->pluck('name','id')->all()`.

**Remediation:** ✅ **FIXED on `scout-audit-fixes`** — added `->where('org_id', ...)->whereHas('kitSettings')` to the label query at both sites (mirrors the proven, org-scoped sibling `options()`/`rule()` in the same files) + a test asserting a foreign-org user's label is not resolved.

---

## REFUTED / KILLED FINDINGS (13)

Candidates raised by finders but **independently refuted** by skeptics (default-to-refuted). Recorded so they aren't re-raised.

| # | Lane | Claim | Why killed |
|---|---|---|---|
| R1 | auth-session | `/scout/me` has no rate limit / no impersonation-token re-validation | Facts accurate but no impact — authed, token-gated, per-request-revalidated reads; impersonation bypass is intentional + staff-gated. Hardening nit, not a hole. |
| R2 | auth-session | Login leaks account/enrollment state via distinct 403 messages | Requires valid credentials first; discloses only the *same account's own* provisioning state; no cross-account/tenant probing. Defense-in-depth nit. |
| R3 | map-queries | Direct Hit Density reads only `hits_cold`, dropping recent ~90d | **Premise inverted.** `hits_cold` has NO TTL (= all history); `hits_hot` is the TTL'd recent-perf copy. Cold is correct for all ranges. |
| R4 | map-queries | `scanPaths` kit-identity mismatch (id vs mapping) yields blank overlay | `kit_mapping` is a plain `kits` column (not the kit_settings accessor); `KitHistoricPositionsQuery` already does `lower(kit_mapping)=lower(?)`. Empty-degrade is deliberate, consistent design. |
| R5 | dashboard | `stats()` `repos_affected` uses frozen `hotlist_status` | Intentional point-in-time metric ("hit while *already* Repossessed", status=3). DEV-2080 live audit classifies it **NOT a divergence**; 3 tests lock it in. Frozen-staleness can't over-count here. |
| R6 | dashboard | Force-token bypasses kit_id guard → `scopeForKit(null)` widens org-wide | **Structurally impossible.** Force token only minted for `$kit->assigned_user`; `user_id` UNIQUE + `kit_id` PK ⇒ `$user->kit_id` always equals `$kit->id`, never null on this path. |
| R7 | birdseye | `recentHits()` returns Direct-only despite "Live/Staging-Live" docblock | Only defect is a **stale one-word docblock**; Live-only is the intended, UI-named behavior ("Live Hits Pending Repossession") mirroring the driver dashboard. Not a correctness bug. |
| R8 | birdseye | Scalar `kit_ids` silently widens to all org drivers | Not client-reachable (`buildUrl` always emits `kit_ids[]`); fail-open is **into the caller's own already-authorized org-wide view**; no boundary crossed. Hardening nit. |
| R9 | filament-users | `KitUserPath::load()` dispatches raw client `kits` without org-intersect | Triple-guarded: FormRequest `in:` rule (422), `KitUsersFilter::format()` intersect, and `org_id` query scope. Dispatched events carry only `tiers`; the kits event routes back through the guarded controller. Zero impact. |
| R10 | pwa-core | Sanctum token in `localStorage` is XSS-exfiltratable | No XSS sink exists — the two `v-html` sinks render only static build-time SVG/instructions; no server/user/CH data reaches an HTML sink. `localStorage` token is the conventional PWA design. Logout already clears it. |
| R11 | pwa-sw | `networkFirst` ignores `Cache-Control: no-store/private` | No Scout endpoint sets those headers, so no server opt-out is being defeated; explicitly framed as defense-in-depth that only compounds F2. Subsumed by F2's fix. |
| R12 | mt-sweep | Force-login can mint a token for ANY org's kit | Only a hardcoded, **trusted, by-design cross-org** staff impersonator can reach it; token grants exactly that one driver's scope (not arbitrary org-wide reads); the proposed `$kit->org_id===assigned_user->org_id` fix is a **tautology**. (The auditability/lateral-movement angle survives as F1/F5/F13.) |
| R13 | mt-sweep | Force-token + null kit_settings widens single-kit queries org-wide | Same invariant as R6 — a force token implies a kit_settings row exists; `kit_id` can't be null on this path. `scopeForKit(null)` is a latent footgun but unreachable here. |

---

## COVERAGE CHECKLIST

Every file each lane actually read (verified, not assumed). Skipped items noted per lane.

### Lane: auth-session (auth/session/impersonation) — 14 files, 2/4 confirmed
`ClarityScoutAuthController`, `Auth/ScoutSetPasswordController`, `ClarityScoutForceLoginController`, `ClarityScoutMiddleware`, `ClarityScoutAdminMiddleware`, `routes/channels.php`, `routes/api.php`, `routes/web.php`, `ImpersonateController`, `Models/User`, `Models/Kit`, `Notifications/ScoutOnboardingNotification`, `config/sanctum.php`, `config/app.php`.
**Skipped:** none noted.

### Lane: map-queries (map controller + CH map queries) — 16 files, 3/5 confirmed
`ClarityScoutMapController`, `KitScanPathsQuery`, `KitHistoricPositionsQuery`, `HitDirectHotspotQuery`, `HotlistAddressGeohashQuery`, `ClickhouseQuery`, `Clickhouse/Models/{Hit,ClickhouseModel,KitTrip}`, `Models/{Zone,Org}`, `ClarityScoutMiddleware`, `routes/api.php`, `bootstrap/app.php`, `config/app.php`, framework `Query/Builder`.
**Skipped:** none noted.

### Lane: dashboard-trips-shift — 23 files, 2/4 confirmed
`ClarityScout{Dashboard,Trips,Shift,Notifications,Zones}Controller`, `ScoutCheckInRollupQuery`, `ClarityScoutMapController`, `ClarityScoutAuthController`, `ClarityScoutForceLoginController`, `ClarityScoutMiddleware`, `Clickhouse/Models/{Hit,Scan,KitTrip,ClickhouseModel}`, `Services/Scout/EnsureOpenShift`, `Models/{User,Org,Hotlist,Zone}`, `routes/api.php`, `helpers.php`, `clickhouse-schema.sql`, `ClarityScoutClickhouseSeeder`.
**Skipped:** `Jobs/Scout/SendEndOfShiftSummary` (out of lane); Pennant flag-resolution internals (assumed correct); live CH/Postgres verification (read-only policy, no live creds); `kit_trips` `trip_date` timezone semantics inferred from schema + seeder, not confirmed against the production refresh job (not in this repo).

### Lane: birdseye-admin — 18 files, 2/4 confirmed
`ClarityScoutBirdsEyeController`, `OrgLivePositionsQuery`, `OrgLivePathsQuery`, `ClarityScoutAdminMiddleware`, `routes/web.php`, `Concerns/ScoutMapHelpers`, `KitScanPathsQuery`, `HitDirectHotspotQuery`, `HotlistAddressGeohashQuery`, `ClickhouseQuery`, `Clickhouse/Models/{Hit,Hotlist,Scan,ClickhouseModel}`, `Models/{Kit,Zone,Org}`, `ClarityScoutDashboardController`.
**Skipped:** `KitSettings` model body (connection/relation confirmed via grep only); consuming Vue stores (other lane); no CH queries executed (read-only).

### Lane: filament-scout-users — 14 files, 2/3 confirmed
`ScoutUsersResource` (+ `EditScoutUser`, `ListScoutUsers`), `Pages/{KitUserPath,KitUserRecoveries}`, `Tables/Filters/KitUsersFilter`, `Models/{Kit,User}`, `Clickhouse/Models/RecoveryKitScanReport`, `Concerns/HasProFeature`, `Middleware/{OrgMiddleware,UserMiddleware}`, `ClarityScoutForceLoginController`, `KitUserPathController`.
**Skipped:** none noted.

### Lane: filament-zones — 16 files, 1/1 confirmed
`ZoneResource` (+ `ListZones`), `Pages/ManageZones`, `Policies/ZonePolicy`, `Services/Zones/ZoneBroadcaster`, `Forms/Components/{ZoneMapDisplay,ProFeatureToggle}`, `Tables/Filters/ZoneDriverFilter`, `Actions/{ZoneQuickEditAction,ZoneReassignAction}`, `Models/Zone`, `Services/Zones/ZoneAssignmentSyncer`, `Events/Scout/ZoneUpdated`, `Models/User`, `routes/channels.php`, `ScoutUsersResource`.
**Skipped:** `ZoneZipSearchController` (out of lane — confirmed `searchZipCodes` uses a bound `LIKE ?`); map-controller geohash/zone-clip endpoints (other lane; spot-checked `Zone::intersectingGeohashes` org binding); `zone-map-display.blade.php` client-side escaping (PHP side emits structured arrays, not raw HTML); `ScoutNotifier` internals (payload confirmed per-user/zone-id scoped).

### Lane: pwa-core — 28 files, 1/2 confirmed
`main.js`, `birdseye.js`, `App.vue`, `views/{Login,ScoutHome}.vue`, `router/index.js`, `stores/{auth,map,dashboard,trips,notifications}.js`, `lib/{fetch,echo,adminScope,scoutNav}.js`, `composables/{useZoneRealtime,useBirdsEyePolling,useConnectionStatus}.js`, `components/{DashboardOverlay,HelpPanel}.vue` (v-html sources), `routes/{channels,web,api}.php`, `ClarityScoutMiddleware`, `ClarityScoutForceLoginController`, `ClarityScoutBirdsEyeController`, `clarity-scout-birds-eye.blade.php`, `clarity-scout.blade.php`.
**Skipped (other lanes):** deep correctness of the CH query classes (verified only that the controller server-enforces org scoping); driver-side controller SQL building (verified routes are gated + params URL-encoded); `clarity-scout-sw.js` + peripheral composables (skimmed where they touched auth/fetch); adjacent Vue components inspected for `v-html`/XSS only, not full review.

### Lane: pwa-components-sw — 32 files, 1/2 confirmed
`clarity-scout-sw.js`, `manifest.json`, `scripts/version-sw.mjs`, `clarity-scout.blade.php`, all 11 `composables/`, `lib/{fetch,echo,adminScope,scoutNav,formatters,pooled,vehicleIcons}.js`, `stores/auth.js`, components `{ProfilePanel,OfflineBanner,Toast,DriverSelector,HelpPanel,DashboardOverlay,DayOverlay,ScannerMap}.vue`, `routes/{api,web}.php`.
**Skipped:** server-side authz/org-scoping of `/api/scout/*` (other lanes); the remaining 17 components were **grep-swept for XSS sinks** (`v-html`/`innerHTML`/`:href`/`eval`) and only those with hits were read line-by-line (remainder had no sinks); Bird's-Eye admin blade (other lane).

### Lane: multitenancy-sweep (cross-cutting) — 32 files, 0/2 confirmed
All 8 Scout API controllers + `Concerns/ScoutMapHelpers`, `ClarityScoutForceLoginController`, `ImpersonateController`, both middleware, `ClickhouseQuery` + all 7 Scout CH query classes, `Clickhouse/Models/{Hit,Scan,KitTrip}`, `Models/{Org,Zone,Hotlist,Kit,User}`, `routes/{api,web,channels}.php`.
**Skipped/Out-of-lane:** Vue/Pinia client; `ScoutNotifier`/`SendEndOfShiftSummary`/`EnsureOpenShift`/`HistoricPathBuilder` internals (read call sites only for arg-shape); `HitDensityGeohashQuery`/`KitUserRecoveryLeaderboard` (non-Scout). The frozen-`hotlist_status` surfaces (F9/F10) were noted but deferred to the correctness lanes, not double-reported as tenancy holes.

### Areas explicitly NOT covered by this audit
- The general (non-Scout) search resources: Hits / Scans / Hotlist / MissedOpportunity / ClientSearchScans (out of scope per the request).
- Live execution / verification against ClickHouse Cloud or production Postgres (read-only policy; no live queries run).
- The PWA build pipeline beyond `version-sw.mjs` (Vite config, asset hashing).
- Pennant feature-flag resolution internals for `CLARITY_SCOUT` / `CLARITY_SCOUT_TRIPS`.

---

## Remediation summary

**Applied on `scout-audit-fixes`** (backend, low-risk, high-confidence, each with a test except dead-code removal):
- **F3** trips/browse 90-day span cap (+422 test)
- **F5** force-login audit-log record (+test) — audit only, not throttle
- **F6** map-route `throttle:120,1` (+middleware-presence test)
- **F8** dead-code removal (existing test covers live path)
- **F12** ScoutUsersResource eager-load / N+1 fix (+query-count test)
- **F14** `getOptionLabelsUsing` org-scope at both sites (+test)

**Report-only (risky / ambiguous / deferred):**
- **F1** (High) force-token revocation+expiry — touches shared `ImpersonateController` + QA-critical token lifetime.
- **F2** (High) SW PII cache purge — frontend, no JS test harness, deployment-sensitive.
- **F4** (Medium) 403 handling — naive fix causes false logouts; needs a 401-on-revocation contract decision.
- **F7 / F9 / F10** frozen `hotlist_status` — the deferred **DEV-2117** product work.
- **F11** heatmap selector — effectively WAI + doc gap.
- **F13** force-login org-scoping — contested + QA-critical; decide with F1/F5.
