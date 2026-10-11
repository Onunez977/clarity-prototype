# Handoff brief — Scout Beta (`scanner-app`) Consolidated UAT + Bug/Gap Audit

> **Purpose of this file.** This is a self-contained briefing you can hand to a fresh Claude Code / cowork session. That session's job is to produce **one comprehensive, code-anchored report** — a consolidated, executable **UAT test matrix** plus an **adversarially-verified bug/gap/risk audit** — covering the entire Clarity Scout / Scout Beta feature, with **DEV-1925 documented as a gap**. Everything the session needs (scope, established findings, domain map, key files, the recommended multi-agent workflow, the exact report structure, and the full ticket text) is in here. The session needs only read access to the `scanner-app` branch at `/Users/daltonmccleery/Herd/platform`.

---

## 0. Task for the receiving session (paste-ready)

> Read `docs/qa/scout-beta-uat-brief.md` in this repo end to end. Then produce the consolidated Scout Beta UAT + bug/gap audit report described in §5, writing it to `docs/qa/scout-beta-uat-report.md`. Anchor every claim to the actual code on the `scanner-app` branch (cite `file:line`). Merge the DEV-1916 + DEV-1921 test cases into one deduped, executable matrix tagged by automated-coverage; rank adversarially-verified findings by severity; document DEV-1925 as an unimplemented gap with readiness notes. Do **not** write to Jira/Confluence and do not modify any source file other than the report. The recommended way to do this at full depth is the ultracode workflow in §4, but you may scale down if asked.

If running as an ultracode workflow, the session should be launched with the **`workflow`** keyword (or ultracode on) so multi-agent orchestration is authorized.

---

## 1. Context

`scanner-app` is the full **Clarity Scout / Scout Beta** feature branch: ~78 commits / ~202 files vs `main` (merge-base `c26f4c336abd4f67f006641e728aba56161e9118`). It adds:

- **Admin Zone management** (Filament page: draw polygons / ZIP-code zones / no-scan zones, assign drivers, quick-reassign).
- The **Clarity Scout driver PWA** (Vue) — dashboard, map, trips, live location.
- **Real-time zone push** (Pusher/Echo private channels).
- **Scout auth & onboarding** (set-password signed links, admin force-login/impersonation).
- **Shifts + end-of-shift summary** (email with miles/scans/hits/per-zone breakdown + Mapbox static map).
- The **`kit_id` → `kit_settings` refactor** (kit↔user is now a `kit_settings` table with derived accessors).

Three Jira tickets bracket it (full text in the Appendix):

| Ticket | Status | Role |
|---|---|---|
| **DEV-1916** | Done | Deep *developer* QA plan (scripted cases + checklists across all areas). |
| **DEV-1921** | To Do | *Non-technical staging* click-through via org impersonation. |
| **DEV-1925** | To Do (Story) | *Product requirement*: driver zone-coverage visibility ("honey holes", 30-day lookback). |

**Decisions already made (do not re-ask):** output = Markdown in the repo; DEV-1925 = document as gap + readiness notes; emphasis = both UAT plan *and* bug/gap audit; **no** Jira/Confluence writes.

---

## 2. Established findings (deepen & verify — do not just rediscover)

A first exploration pass already established the following. The receiving session should **verify and expand** these against the code, not treat them as gospel:

- **DEV-1925 is ABSENT on this branch.** Three independent passes (ClickHouse queries, `ClarityScoutMapController`, the full PWA) found no driver coverage-gap visualization, no honey-hole / high-value toggleable layer, and no configurable lookback. The adjacent-but-different pieces that *do* exist:
  - `HitDensityGeohashQuery` — geohash heatmap of **hotlist addresses** (hardcoded ~9-month cutoff), not the driver's own coverage.
  - `buildDensitySegments()` in `ClarityScoutMapController` — colors roads by **how often the driver has driven them** (frequency), not covered-vs-missed area, and has no 30-day lookback control.
- **Backend is well-covered (~30 test files); the Vue/PWA layer (`resources/js/clarity-scout`) has ZERO automated tests** — all client UI behavior is human-only QA.
- **Candidate logic flags to adversarially verify/expand** (each needs confirmation from the code before it goes in the report):
  - **(a)** Orphaned `zone_user` pivot rows on Zone **soft-delete** — the FK cascade only fires on hard-delete. `app/Filament/Pages/ManageZones.php` delete flow + `app/Models/Zone.php`.
  - **(b)** **N+1** on Scout Users "Assigned Zones" count (`zones()->count()` per row, not eager-loaded). `app/Filament/Resources/ScoutUsersResource.php`.
  - **(c)** `?zone=<id>` **preload race** — Livewire dispatches `zone-selected` during `mount()` before the async Google Maps init is ready. `ManageZones::mount()` + `resources/js/components/zone-manager.js`.
  - **(d)** **No onboarding-email resend path** once `email_verified_at` is set (signed link 403s, no admin re-send). `app/Http/Controllers/Auth/ScoutSetPasswordController.php`.
  - **(e)** **Force-login revokes the driver's live token mid-shift** — admin impersonation deletes the user's existing `clarity-scout-token`. `app/Http/Controllers/ClarityScoutForceLoginController.php`.
  - **(f)** `kit_id` derived accessor lazy-loads `kitSettings` per request in `ClarityScoutMiddleware` / `UserMiddleware`.
  - **(g)** `zod` / `tiger_data.zcta5_all` dependency for ZIP-zone geometry — `Zone::rebuildGeometryFromDefinition`, `ZoneMapDisplay::getZonesGeoJson`. QA environments must have PostGIS + tiger data.

---

## 3. Domain map (the spine of both the matrix and the audit)

Organize the report around these 10 domains. Each lists the key files to read (follow imports as needed) and the ticket sections it answers.

1. **Gating & access control** — `app/Http/Middleware/ClarityScoutMiddleware.php`, `UserMiddleware.php`, `ClientMiddleware.php`, `routes/channels.php`, `routes/api.php`, `routes/web.php`, `config/app.php`, `app/Enums/FeatureEnum.php`, `app/Console/Commands/SeedRolesAndPermissions.php`. _(DEV-1916 §1, §9)_
2. **`kit_id` → `kit_settings` refactor** — `app/Models/KitSettings.php`, `Kit.php`, `User.php`, `tests/Models/KitSettingsTest.php`. _(DEV-1916 §2)_
3. **Zone Management (admin)** — `app/Filament/Pages/ManageZones.php`, `app/Filament/Forms/Components/ZoneMapDisplay.php`, `resources/views/filament/pages/manage-zones.blade.php`, `resources/views/forms/components/zone-map-display.blade.php`, `resources/js/components/zone-manager.js`, `zone-map-display.js`, `tests/Filament/Pages/ManageZonesTest.php`. _(DEV-1916 §3, DEV-1921 PART 1)_
4. **Zone backend & multi-tenancy** — `app/Models/Zone.php`, `app/Http/Controllers/ZoneIndexController.php`, `ZoneZipSearchController.php`, `app/Services/Zones/ZoneAssignmentSyncer.php`, `tests/Feature/Http/Controllers/ZoneIndexControllerTest.php`, `ZoneZipSearchControllerTest.php`, `tests/Feature/Policies/ZonePolicyTest.php`, `tests/Models/ZoneTest.php`. _(DEV-1916 §3, §9)_
5. **Real-time zone push** — `app/Events/Scout/ZoneAssignmentsChanged.php`, `ZoneUpdated.php`, `app/Services/Scout/ScoutNotifier.php`, `routes/channels.php`, `resources/js/clarity-scout/composables/useZoneRealtime.js`, `tests/Feature/Broadcasting/ScoutChannelAuthTest.php`, `tests/Feature/Events/ZoneAssignmentsChangedTest.php`, `ZoneUpdatedTest.php`. _(DEV-1916 §4, DEV-1921 PART 3)_
6. **Scout auth & onboarding** — `app/Http/Controllers/Api/ClarityScoutAuthController.php`, `app/Http/Controllers/Auth/ScoutSetPasswordController.php`, `app/Http/Controllers/ClarityScoutForceLoginController.php`, `app/Notifications/ScoutOnboardingNotification.php`, `resources/views/auth/scout/set-password.blade.php`, `tests/Feature/Http/Controllers/Auth/ScoutSetPasswordControllerTest.php`, `ClarityScoutForceLoginControllerTest.php`, `tests/Feature/Http/Controllers/Api/ClarityScoutAuthControllerTest.php`. _(DEV-1916 §5)_
7. **Shifts & end-of-shift summary** — `app/Services/Scout/EnsureOpenShift.php`, `BuildShiftSummary.php`, `app/Jobs/Scout/SendEndOfShiftSummary.php`, `app/Console/Commands/CloseInactiveShiftsCommand.php`, `app/Http/Controllers/Api/ClarityScoutShiftController.php`, `app/Support/Scout/Haversine.php`, `MapboxStaticMapBuilder.php`, `app/Dto/Scout/ShiftSummaryDto.php`, `resources/views/mail/scout/end-of-shift-summary.blade.php`, plus tests `tests/Feature/Services/Scout/EnsureOpenShiftTest.php`, `tests/Feature/Jobs/Scout/SendEndOfShiftSummaryTest.php`, `tests/Feature/Mail/Scout/EndOfShiftSummaryTest.php`, `tests/Feature/Console/Commands/CloseInactiveShiftsCommandTest.php`, `tests/Unit/Support/Scout/HaversineTest.php`, `MapboxStaticMapBuilderTest.php`. _(DEV-1916 §6 — highest risk)_
8. **Scout Users admin resource** — `app/Filament/Resources/ScoutUsersResource.php` + `Pages/EditScoutUser.php`, `ListScoutUsers.php`, `tests/Filament/Resources/ScoutUsersResourceTest.php`. _(DEV-1916 §7, DEV-1921 PART 4)_
9. **Scout PWA** — `resources/views/clarity-scout.blade.php`, `resources/js/clarity-scout/{router/index.js, views/Dashboard.vue, Trips.vue, DayDetail.vue, TripDetail.vue, Live.vue, components/ScannerMap.vue, composables/useBrowserGeolocation.js, useKitPositionPolling.js, useShift.js, stores/map.js}`, plus the API controllers `ClarityScoutDashboardController.php`, `ClarityScoutMapController.php`, `ClarityScoutTripsController.php`, `ClarityScoutZonesController.php`. _(DEV-1916 §8, DEV-1921 PART 2 & 4)_
10. **DEV-1925 zone-coverage (gap + readiness)** — `app/Clickhouse/Queries/{HitDensityGeohashQuery,HotlistAddressGeohashQuery,ScoutCheckInRollupQuery}.php`, `app/Http/Controllers/Api/ClarityScoutMapController.php`, `resources/js/clarity-scout/components/ScannerMap.vue`, `stores/map.js`. _(DEV-1925)_

---

## 4. Recommended approach — ultracode workflow (find → verify → author → synthesize)

Run a single Workflow that **pipelines each of the 10 domains** through these stages (so domain N verifies while domain N+1 is still being read), then synthesizes one report. Seed every agent prompt with §2 so they deepen rather than rediscover.

- **Phase A — Implementation truth (1 reader/domain).** Read the domain's source + matching tests. Return structured `{ summary, behaviors[{behavior,fileRef,notes}], testedBehaviors[], untestedBehaviors[], ticketRefs[] }`.
- **Phase B — Adversarial bug/gap hunt (find → verify, per domain).** A finder reports up to ~8 material problems (logic bugs, edge cases, org-isolation holes, idempotency/race gaps, unmet ticket ACs) with `{title, severity: blocker|high|medium|low|info, fileRef, why, observation, ticketRef}`. Then **2-of-3 skeptic verifiers** each try to *refute* each finding (default refuted=true if unconfirmed); keep only majority-confirmed findings. For the coverage domain, a "refute" = "did you actually find DEV-1925 implemented?" — this hardens the absence verdict.
- **Phase C — UAT case authoring (per domain).** Merge DEV-1916 scripted cases + DEV-1921 click-throughs for the domain, dedupe, and emit `{id, title, preconditions, steps[], expected, ticketRef, automatedCoverage: covered|partial|none, humanOnly}`. The whole PWA layer is `humanOnly`. The DEV-1925 domain emits one "AC: …" row per acceptance criterion marked unimplemented, plus `readinessNotes`.
- **Phase D — Synthesize.** One agent assembles the full markdown (the structure in §5) from the per-domain results; the orchestrator writes it to `docs/qa/scout-beta-uat-report.md`.

> A complete, runnable workflow script for this was drafted in the originating session (10-domain `pipeline()` with `parallel()` 2-of-3 verification and a synthesis agent). If you have access to it, reuse it; otherwise the spec above is sufficient to re-author it. Scale the finder pool / verifier count down for a quick pass, up for an exhaustive audit.

A non-workflow fallback is fine too: a handful of parallel sub-agents (one per domain group) doing the same read → verify → author, then a manual synthesis. The workflow just makes the adversarial verification systematic.

---

## 5. Required report structure (`docs/qa/scout-beta-uat-report.md`)

1. **Executive summary** — feature scope (3–5 bullets); ship-readiness verdict (Ready / Ready-with-caveats / Not ready) + one-line justification; top blockers/high-risk items (highest severity first); DEV-1925 status in one line.
2. **Scope & gating** — the triple gate (`app.experimental.clarity_scout` config flag **AND** `platform_repo_pro_clarity_scout` Pennant feature **AND** `clarity-scout` permission); how to enable Scout for an org; **test-data prerequisites** (2 orgs A/B; Org A kits — one assigned user *with* email, one *without* email, one unassigned; a scout user with activity today and one with zero activity; flag/feature enabled for A, disabled for B).
3. **Consolidated UAT test matrix** — one `###` subsection per domain (order: gating, refactor, zones-admin, zones-backend, realtime, auth, shifts, scout-users, pwa), each a table: **ID | Test case | Preconditions | Steps | Expected | Ticket | Auto-coverage** (covered/partial/none, append "(human-only)" where applicable). Include every authored case.
4. **Verified findings (ranked)** — one table across all domains ranked by severity: **Severity | Finding | Location (file:line) | Why it matters | Ticket**, followed by a short "suggested checks" paragraph. Only adversarially-confirmed findings.
5. **DEV-1925 — zone coverage visibility (gap + readiness)** — state clearly it is NOT implemented; list each acceptance criterion as unmet; describe the adjacent pieces that exist (hit-density heatmap of hotlist addresses; scan-path frequency coloring); include readiness notes + the ticket's open questions.
6. **Automated-coverage map** — table: **Domain | Has automated tests (examples) | Human-only gaps**. Make explicit: backend ~30 test files; entire Vue/PWA layer untested.
7. **Ship-readiness checklist** — checkbox list of what must pass/resolve before release, derived from blocker/high findings and the highest-risk untested behaviors.

---

## 6. Verification (how to know the report is good)

- The file `docs/qa/scout-beta-uat-report.md` exists, is valid Markdown, all 7 sections populated.
- Spot-check 3–4 ranked findings: open the cited `file:line` and confirm the behavior. Adversarial verification should mean no obviously-false findings survive.
- The UAT matrix is executable (concrete preconditions/steps/expected) and represents all DEV-1916 + DEV-1921 cases (cross-check against the Appendix); auto-coverage tags match the real `tests/` files.
- DEV-1925 appears as unimplemented gaps + readiness, not runnable cases.
- No Jira/Confluence writes; no source files modified except the report.

> **Scope note:** this is code-anchored static analysis + test-plan authoring, **not** live PWA driving. Real UAT execution needs two seeded orgs and two live scout sessions; the matrix is written for a human/QA tester (or a follow-on browser-agent pass with proper scout auth) to run.

---

## Appendix — full Jira ticket text

The complete text of DEV-1916, DEV-1921, and DEV-1925 (as pulled from Jira) is reproduced below so this brief is portable to a session without Atlassian access.

<!-- BEGIN TICKET REFERENCE -->

# Scout Beta — Jira ticket reference (for UAT/audit workflow)

Three tickets bracket the `scanner-app` feature. Agents: read the section(s) relevant to your domain.

---

# DEV-1916 (Done) — QA: Scout Beta — Zones + Clarity Scout (developer QA plan)

> ⚠️ Gating: everything Scout/Zone-facing is gated behind the `app.experimental.clarity_scout` config flag AND the `platform_repo_pro_clarity_scout` Pennant feature AND the `clarity-scout` permission. Confirm both enabled and disabled sides of every gate.

### 0. Test data prerequisites
- At least two orgs (Org A, Org B) for multi-tenancy.
- Org A: an Admin user, 2+ kits — one with an assigned Scout user that HAS an email, one with an assigned user with NO email, one UNASSIGNED kit.
- A Scout user with scan activity today (shifts/dashboard), and one with ZERO activity (zero-activity summary path).
- clarity_scout flag + Pennant feature enabled for Org A; left DISABLED for Org B (negative gating).

### 1. Feature-flag & access-control gating (do first) — confirm both sides
| Surface | Enabled (Org A admin/Scout) | Disabled / wrong role |
| --- | --- | --- |
| Zones admin page (Geo-Spatial nav) | Visible to Admin only | Hidden for non-admin; absent when flag off |
| Scout Users resource (Account nav, "Pro" badge) | Visible to Admin only | Hidden for non-admin / flag off |
| GET /zones/all, GET /zones/zip-search | 200 for admin in-org | 403 when flag off; 403 for non-admin |
| /scout/* PWA route + /api/scout/* | reachable | route does not exist (404 / redirect to /app/login) when flag off |
| CloseInactiveShiftsCommand schedule | runs every 30 min | no-op / "disabled" when flag off |
| Broadcast channel scout.user.{id} auth | authorizes only matching user WITH kit + clarity-scout perm + active feature | rejects otherwise |
- Scout login refused with clear message: wrong password (401), no clarity-scout permission (403 "do not have portal access"), no kit assigned (403), feature inactive (403 "not enabled for your organization").

### 2. Regression sweep — kit_id → kit_settings refactor
users.kit_id is gone; kit↔user is now kit_settings with User->kit, User->kit_id, Kit->assigned_user, Kit->vehicle_type as derived accessors. Re-test:
- Existing kit/user-path features (KitUserPath maps, scan paths) still resolve the right kit per user.
- Admin user create/edit/delete flows elsewhere still work (kit_id removed from User fillable).
- A user can only ever be tied to ONE kit — assigning to a second detaches the first (1:1).
- Reports/exports/widgets grouped by kit still return correct per-kit numbers.

### 3. Zone Management (admin) — scripted
- TC-Z1 Draw & save a polygon zone → name, color, optional description, assign kit users → Save. Expect "Zone saved" toast; appears with color swatch; reopening shows same geometry/color/users.
- TC-Z2 ZIP-code zone → ZIP search partial (≤5 chars) → pick → Save. Expect matching ZCTAs with previews; ZIP-only zone saves; geometry matches boundary.
- TC-Z3 Empty-geometry validation → name only, no polygon/ZIP → Save blocked "Draw a polygon or add at least one ZIP code to define the zone." Name & Color required.
- TC-Z4 No-Scan zone toggle → color forced gray #9ca3af + picker disabled; persists is_no_scan_zone=true & gray even if another color picked first.
- TC-Z5 Assigned-user constraint → only users WITH a kit listed; invalid fails "One or more assigned users do not have a kit."
- TC-Z6 Delete an assigned zone → modal warns unassigns all; soft-deletes; assigned drivers' apps refetch; gone from lists & driver maps.
- TC-Z7 Quick Reassign Replace vs Add → Replace swaps set; Add merges; toast reports count notified, or "No changes".
- Sweep: ?zone=<id> preloads; out-of-org id leaves form blank (no error). Large polygons save w/o timeout. Overlapping zones both save; scan in overlap counts toward both. Color swatch matches map. Description ≤1000, Name ≤255. ZIP no-match returns empty gracefully.

### 4. Real-time zone push (WebSockets) — scripted (admin + driver PWA side by side)
- TC-RT1 Driver added to zone → within seconds driver map refetches & shows zone; toast "You were added to a zone." (plural if multiple).
- TC-RT2 Driver removed → zone disappears; toast "You were removed from a zone."
- TC-RT3 Geometry/color-only edit (no assignment change) → driver map updates geometry/color but NO add/remove toast.
- TC-RT4 Isolation & reconnect → Org B driver never gets Org A events; editing zone for driver X doesn't toast driver Y; kill socket then reassign → on reconnect zones reflect latest; channel auth rejects user whose clarity-scout perm revoked mid-session.

### 5. Scout auth & onboarding — scripted
- TC-A1 New-user onboarding → editing unassigned kit, "Create new user" on, email+phone, Scout Access on → Save. User created (USER role, clarity-scout perm), tied via kit_settings; "Welcome to Clarity Scout" email with "Set Your Password".
- TC-A2 Set-password flow → open signed link → set password → redirected into PWA logged in; email verified. Re-open same link → 403 (one-shot). 403s after 7-day TTL & on signature tampering.
- TC-A3 Existing user assignment → "Create new user" off, pick existing org user, email/phone, Scout Access on → Save. Gets clarity-scout perm; onboarding email only if NOT already verified; detached from any other kit.
- TC-A4 Toggle Scout access → lock/globe action disable/enable with confirmation; disabling blocks PWA login; user with NO email cannot be granted access (error "Email Required").
- TC-A5 Admin force-login (impersonation) → "Login to Scout" opens PWA as driver in new tab, is_impersonating; force token distinct (clarity-scout-force ability), prior force tokens revoked. Hidden when not impersonating or kit has no user.

### 6. Shifts & End-of-Shift Summary — scripted (highest risk)
- TC-S1 Shift status reflects activity → GET /api/scout/shift/status → open:true, started_at at start of current activity window (gaps ≥3h split shifts). After confirmed end with no new scans → open:false/null until driver scans again (fresh shift).
- TC-S2 Confirmed end + summary email → POST /api/scout/shift/end → end_type=confirmed, newly_closed:true; summary email queued & delivered. Email contains: total miles (Haversine over trip paths), total scans, total hits, per-zone breakdown (scans/hits/time-in-zone, busiest first, zero-scan zones omitted), Mapbox static map with path + zone polygons.
- TC-S3 Double-tap idempotency → second call returns already-closed newly_closed:false; exactly ONE summary email; no new empty shift.
- TC-S4 Assumed end (inactivity) → last activity ≥3h ago; run scout:close-inactive-shifts (--dry-run first). Dry-run lists w/o changes; real run closes end_type=assumed, ended_at=last activity, dispatches summary. Shifts within 3h skipped.
- TC-S5 Zero-activity shift → summary still sends showing zeros & no zones; no crash.
- TC-S6 Recipient rules → driver HAS email → To driver, CC org admins. No email → To org admins + banner. No driver email AND no admins → no email; summary_skipped_reason=no_recipients. Mail failure → after retries summary_skipped_reason=mail_failed (not silent success).
- Edge: only ONE open shift per kit ever; started_at/ended_at right timezone; UTC→ClickHouse "today" counts; old shift (>90d) falls back to cold tables; ClickHouse unavailable → degrades to zeros, not job failure.

### 7. Scout Users admin resource — checklist
- Table: Kit Name, GL Number, Vehicle Type badge, Assigned User, Email, Assigned Zones count, Mobile Phone, Scout Access icon — all accurate.
- Filters work & combine: Assigned User (Has/Unassigned), Scout Access (Enabled/Disabled), Vehicle Type (incl Unassigned).
- Vehicle Type (Scanning Car / Repo Truck / Flatbed) saves to kit_settings & reflects in badge.
- "Assigned Zones" map section only when kit has assigned user; shows that user's zones.
- Create & Delete DISABLED (edit-only).
- Duplicate-email blocked "This email address is already in use." for new and existing-user paths.
- All data org-scoped — admin never sees another org's kits/users.

### 8. Scout PWA — dashboard, map, trips — checklist
- Dashboard stat cards (Scans, Hits, Repos, Trips) load & match admin numbers for selected range.
- Recent Hits list: plate, time, status, plate/vehicle images.
- Hotlist stats (open/staged/total active) correct.
- Check-in counts card from ClickHouse rollup, respects range + timezone.
- Map live-location pre-prompt appears once, decision persists; "Enable live tracking" follows driver dot.
- Map zones: assigned render color+description; adjacent (touching same-org) render w/o description; no-scan gray; no other org's zones appear.
- Trips: current, browse, day view, individual detail load & render paths.
- PWA offline/poor-connection reasonable (cached shell; clear error + retry).
- Any endpoint failure surfaces friendly error toast with working Retry, not blank screen.

### 9. Multi-tenancy & negative tests (cross-cutting)
- Org A admin cannot load/edit/delete an Org B zone (id/URL tampering → 404/403).
- Scout API endpoints only return caller's kit/org data.
- Adjacency query never pulls a bordering zone from another org.
- Summary email CC list contains only same-org admins.
- Revoking a driver's Scout access invalidates PWA use AND real-time channel subscription.

---

# DEV-1921 (To Do) — QA (staging): Scout Beta — non-technical click-through

Human click-through on staging via org impersonation. No special QA login, no test data setup. Data won't be perfect. A feature PASSES if it loads and works sensibly; only file a bug if something is outright broken (error page, blank screen, wrong-org data, crash, button that does nothing).

Setup: log into staging admin; find an org with Scout (admin sees "Zones" page + "Scout Users" resource w/ Pro badge) = Org A. Note a second Scout org = Org B. Work with two windows: Admin Zones page + driver PWA via kit "Login to Scout".

PART 1 — Zone editing (Admin) + display in Scout (priority): Z1 draw+save polygon; Z2 ZIP zone; Z3 empty-zone validation; Z4 no-scan gray; Z5 assigned-user list only users with kit; Z6 delete a zone; Z7 quick reassign Replace then Add; Z8 display in driver PWA map — assigned zones color+description, adjacent without description, no-scan gray, Show/Hide Zones toggle, no other org's zones.

PART 2 — Live position tracking (laptop, best-effort): L1 pre-prompt appears once, Enable triggers browser location request; L2 allow → green "Live" indicator + dot near location; L3 decision persists on refresh (not re-prompted); L4 declined/unavailable fallback — map still loads, staleness hint ("GPS ±Xmin" / "Last seen Xh ago") + "Enable live tracking" re-ask link, no crash/blank.

PART 3 — Real-time updates (admin + driver windows): R1 added to zone → map shows zone + toast "You were added to a zone."; R2 removed → disappears + toast "You were removed from a zone."; R3 shape/color-only edit → map updates, no add/remove toast; R4 isolation — Org B driver gets nothing from Org A, editing driver X doesn't toast driver Y.

PART 4 — Light "works/not broken" sweep: Scout Users resource (columns org-scoped, filters, create/delete disabled, duplicate-email blocked); Scout access toggle & onboarding (toggle w/ confirmation, no-email can't be granted, new user gets Welcome email w/ Set Password link that logs into PWA — if staging email not wired, just confirm flow doesn't error); Dashboard & trips load (numbers may be sparse); Shifts & end-of-shift (End Shift closes & sends summary w/o error, double-tap no duplicate); Multi-tenancy (never see Org B while impersonating Org A); Errors friendly (clear error + working Retry, never blank).

---

# DEV-1925 (To Do, Story) — Scout: Zone coverage visibility on the map (self-directed scanning)

Source: product discussion with Oscar Nunez (PM). As a Scanner Driver assigned to a Zone, I want to see where I have and haven't recently scanned within my Zone on the map, so I can self-direct coverage and cover the whole Zone well — without an admin telling me which block to hit each day.

Context: drivers get a Zone and are told "cover it well." They need to see what they've covered vs. missed, and where high-value activity is ("honey holes"), then re-check as they work and fill the whole zone ("grating") even with no hotspots.

### Acceptance Criteria
- A driver can view their assigned Zone on the map.
- The map shows the driver's own recent scan coverage within the Zone, with a configurable lookback window (default 30 days).
- Covered vs. uncovered (missed) areas within the Zone are visually distinguishable.
- The driver can toggle map layers to surface high-value areas / hotspots ("honey holes") to prioritize.
- Coverage and layer data refresh as new data comes in, so the driver can re-check and adjust.
- The experience supports complete Zone coverage even when no hotspots are present.

### Open Questions
- What defines a "Zone" boundary, and where is it assigned/managed?
- What is the unit of "coverage" — roads/segments driven, area grid cells, or scan density?
- What data sources define a "honey hole" / hotspot (hits, recoveries, historical scan density)?
- Is coverage per-driver, or shared across all drivers assigned to the Zone?
- Default and adjustable lookback window — confirm 30 days as default.

<!-- END TICKET REFERENCE -->

---

## Appendix B — runnable ultracode workflow script

Hand this to the `Workflow` tool verbatim (it's plain JavaScript, not TypeScript). It pipelines all 10 domains through MAP → HUNT → VERIFY (2-of-3 adversarial refute) → AUTHOR, then SYNTHESIZE, and **returns** `{ report, stats }`. The script has no filesystem access, so the orchestrating session must take the returned `report` markdown and write it to `docs/qa/scout-beta-uat-report.md` itself.

````javascript
export const meta = {
  name: 'scout-beta-uat-audit',
  description: 'Consolidated Scout Beta UAT matrix + adversarially-verified bug/gap audit across 10 domains; returns one markdown report',
  phases: [
    { title: 'Map', detail: 'Read implementation truth per domain' },
    { title: 'Hunt', detail: 'Find logic bugs / gaps / AC misses per domain' },
    { title: 'Verify', detail: 'Adversarial refute of each finding (2-of-3)' },
    { title: 'Author', detail: 'Author executable UAT cases per domain' },
    { title: 'Synthesize', detail: 'Assemble the consolidated report' },
  ],
}

const REPO = '/Users/daltonmccleery/Herd/platform'
const BASE = 'c26f4c336abd4f67f006641e728aba56161e9118'
const TICKETS = 'docs/qa/scout-beta-uat-brief.md' // ticket text is the Appendix of this brief

const SEED = `Established findings (deepen/verify, don't just rediscover):
- DEV-1925 (driver zone-coverage visibility / honey-hole layers / 30-day lookback) appears ABSENT on this branch. Adjacent-but-different: HitDensityGeohashQuery (hotlist ADDRESSES, hardcoded 9-month cutoff), buildDensitySegments() in ClarityScoutMapController (roads DRIVEN frequency, not covered-vs-missed). No configurable lookback, no per-zone coverage grid, no driver-facing "uncovered" layer.
- Backend has ~30 test files; the Vue/PWA layer (resources/js/clarity-scout) has ZERO automated tests.
- Candidate logic flags to verify/expand: (a) orphaned zone_user pivot rows on Zone SOFT-delete (FK cascade only on hard-delete) in ManageZones delete flow + Zone model; (b) N+1 on Scout Users "Assigned Zones" count zones()->count() per row; (c) ?zone=<id> preload race: Livewire dispatches zone-selected during mount before async Google Maps init ready; (d) no onboarding-email resend once email_verified_at set (signed link 403s); (e) force-login revokes the driver's live token mid-shift; (f) kit_id derived accessor lazy-loads kitSettings in ClarityScoutMiddleware/UserMiddleware; (g) zod/tiger_data.zcta5_all dependency for ZIP-zone geometry.`

const DOMAINS = [
  { key: 'gating', title: 'Gating & access control', ticket: 'DEV-1916 §1, §9',
    files: ['app/Http/Middleware/ClarityScoutMiddleware.php','app/Http/Middleware/UserMiddleware.php','app/Http/Middleware/ClientMiddleware.php','routes/channels.php','routes/api.php','routes/web.php','config/app.php','app/Enums/FeatureEnum.php','app/Console/Commands/SeedRolesAndPermissions.php'] },
  { key: 'refactor', title: 'kit_id -> kit_settings refactor', ticket: 'DEV-1916 §2',
    files: ['app/Models/KitSettings.php','app/Models/Kit.php','app/Models/User.php','tests/Models/KitSettingsTest.php'] },
  { key: 'zones-admin', title: 'Zone Management (admin)', ticket: 'DEV-1916 §3, DEV-1921 PART1',
    files: ['app/Filament/Pages/ManageZones.php','app/Filament/Forms/Components/ZoneMapDisplay.php','resources/views/filament/pages/manage-zones.blade.php','resources/views/forms/components/zone-map-display.blade.php','resources/js/components/zone-manager.js','resources/js/components/zone-map-display.js','tests/Filament/Pages/ManageZonesTest.php'] },
  { key: 'zones-backend', title: 'Zone backend & multi-tenancy', ticket: 'DEV-1916 §3, §9',
    files: ['app/Models/Zone.php','app/Http/Controllers/ZoneIndexController.php','app/Http/Controllers/ZoneZipSearchController.php','app/Services/Zones/ZoneAssignmentSyncer.php','tests/Feature/Http/Controllers/ZoneIndexControllerTest.php','tests/Feature/Http/Controllers/ZoneZipSearchControllerTest.php','tests/Feature/Policies/ZonePolicyTest.php','tests/Models/ZoneTest.php'] },
  { key: 'realtime', title: 'Real-time zone push', ticket: 'DEV-1916 §4, DEV-1921 PART3',
    files: ['app/Events/Scout/ZoneAssignmentsChanged.php','app/Events/Scout/ZoneUpdated.php','app/Services/Scout/ScoutNotifier.php','routes/channels.php','resources/js/clarity-scout/composables/useZoneRealtime.js','tests/Feature/Broadcasting/ScoutChannelAuthTest.php','tests/Feature/Events/ZoneAssignmentsChangedTest.php','tests/Feature/Events/ZoneUpdatedTest.php'] },
  { key: 'auth', title: 'Scout auth & onboarding', ticket: 'DEV-1916 §5',
    files: ['app/Http/Controllers/Api/ClarityScoutAuthController.php','app/Http/Controllers/Auth/ScoutSetPasswordController.php','app/Http/Controllers/ClarityScoutForceLoginController.php','app/Notifications/ScoutOnboardingNotification.php','resources/views/auth/scout/set-password.blade.php','tests/Feature/Http/Controllers/Auth/ScoutSetPasswordControllerTest.php','tests/Feature/Http/Controllers/ClarityScoutForceLoginControllerTest.php','tests/Feature/Http/Controllers/Api/ClarityScoutAuthControllerTest.php'] },
  { key: 'shifts', title: 'Shifts & end-of-shift summary', ticket: 'DEV-1916 §6',
    files: ['app/Services/Scout/EnsureOpenShift.php','app/Services/Scout/BuildShiftSummary.php','app/Jobs/Scout/SendEndOfShiftSummary.php','app/Console/Commands/CloseInactiveShiftsCommand.php','app/Http/Controllers/Api/ClarityScoutShiftController.php','app/Support/Scout/Haversine.php','app/Support/Scout/MapboxStaticMapBuilder.php','app/Dto/Scout/ShiftSummaryDto.php','resources/views/mail/scout/end-of-shift-summary.blade.php','tests/Feature/Services/Scout/EnsureOpenShiftTest.php','tests/Feature/Jobs/Scout/SendEndOfShiftSummaryTest.php','tests/Feature/Mail/Scout/EndOfShiftSummaryTest.php','tests/Feature/Console/Commands/CloseInactiveShiftsCommandTest.php','tests/Unit/Support/Scout/HaversineTest.php','tests/Unit/Support/Scout/MapboxStaticMapBuilderTest.php'] },
  { key: 'scout-users', title: 'Scout Users admin resource', ticket: 'DEV-1916 §7, DEV-1921 PART4',
    files: ['app/Filament/Resources/ScoutUsersResource.php','app/Filament/Resources/ScoutUsersResource/Pages/EditScoutUser.php','app/Filament/Resources/ScoutUsersResource/Pages/ListScoutUsers.php','tests/Filament/Resources/ScoutUsersResourceTest.php'] },
  { key: 'pwa', title: 'Scout PWA (dashboard/map/trips/tracking)', ticket: 'DEV-1916 §8, DEV-1921 PART2 & PART4',
    files: ['resources/views/clarity-scout.blade.php','resources/js/clarity-scout/router/index.js','resources/js/clarity-scout/views/Dashboard.vue','resources/js/clarity-scout/views/Trips.vue','resources/js/clarity-scout/views/DayDetail.vue','resources/js/clarity-scout/views/TripDetail.vue','resources/js/clarity-scout/views/Live.vue','resources/js/clarity-scout/components/ScannerMap.vue','resources/js/clarity-scout/composables/useBrowserGeolocation.js','resources/js/clarity-scout/composables/useKitPositionPolling.js','resources/js/clarity-scout/composables/useShift.js','resources/js/clarity-scout/stores/map.js','app/Http/Controllers/Api/ClarityScoutDashboardController.php','app/Http/Controllers/Api/ClarityScoutMapController.php','app/Http/Controllers/Api/ClarityScoutTripsController.php','app/Http/Controllers/Api/ClarityScoutZonesController.php'] },
  { key: 'coverage', title: 'DEV-1925 zone-coverage visibility (gap + readiness)', ticket: 'DEV-1925',
    files: ['app/Clickhouse/Queries/HitDensityGeohashQuery.php','app/Clickhouse/Queries/HotlistAddressGeohashQuery.php','app/Clickhouse/Queries/ScoutCheckInRollupQuery.php','app/Http/Controllers/Api/ClarityScoutMapController.php','resources/js/clarity-scout/components/ScannerMap.vue','resources/js/clarity-scout/stores/map.js'] },
]

const TRUTH_SCHEMA = { type:'object', additionalProperties:false, properties:{
  domain:{type:'string'}, summary:{type:'string'},
  behaviors:{type:'array', items:{type:'object', additionalProperties:false, properties:{ behavior:{type:'string'}, fileRef:{type:'string'}, notes:{type:'string'} }, required:['behavior','fileRef']}},
  testedBehaviors:{type:'array', items:{type:'string'}},
  untestedBehaviors:{type:'array', items:{type:'string'}},
  ticketRefs:{type:'array', items:{type:'string'}}
}, required:['domain','summary','behaviors','testedBehaviors','untestedBehaviors'] }

const FINDINGS_SCHEMA = { type:'object', additionalProperties:false, properties:{
  findings:{type:'array', items:{type:'object', additionalProperties:false, properties:{
    title:{type:'string'}, severity:{type:'string', enum:['blocker','high','medium','low','info']},
    fileRef:{type:'string'}, why:{type:'string'}, observation:{type:'string'}, ticketRef:{type:'string'}
  }, required:['title','severity','fileRef','why']}}
}, required:['findings'] }

const VERDICT_SCHEMA = { type:'object', additionalProperties:false, properties:{
  refuted:{type:'boolean'}, reason:{type:'string'}
}, required:['refuted','reason'] }

const UAT_SCHEMA = { type:'object', additionalProperties:false, properties:{
  cases:{type:'array', items:{type:'object', additionalProperties:false, properties:{
    id:{type:'string'}, title:{type:'string'}, preconditions:{type:'string'},
    steps:{type:'array', items:{type:'string'}}, expected:{type:'string'},
    ticketRef:{type:'string'}, automatedCoverage:{type:'string', enum:['covered','partial','none']},
    humanOnly:{type:'boolean'}, notes:{type:'string'}
  }, required:['id','title','steps','expected','automatedCoverage']}},
  readinessNotes:{type:'string'}
}, required:['cases'] }

const ctx = (d) => `Repo: ${REPO} (branch scanner-app). Diff base: ${BASE}.
Domain: "${d.title}" (key: ${d.key}). Relevant tickets: ${d.ticket}.
Ticket reference (read the relevant section of the Appendix): ${TICKETS}
Key files for this domain (read them; follow imports as needed):
${d.files.map(f=>'  - '+f).join('\n')}

${SEED}`

const truthPrompt = (d) => `${ctx(d)}

TASK: Establish the IMPLEMENTATION TRUTH for this domain. Read the key files (and the relevant ticket section). Report exactly what is implemented and how, which behaviors already have AUTOMATED test coverage (cite the test file) vs. which do not, and which ticket acceptance-criteria/test-cases map to this domain. Be precise and cite file paths (file:line where you can). Return the structured object.${d.key==='coverage' ? '\n\nNOTE: This domain is DEV-1925. Determine definitively whether driver zone-coverage visibility (covered-vs-missed within a zone), honey-hole/high-value toggleable layers, and a configurable lookback (default 30d) exist. Distinguish clearly from the existing hit-density heatmap (hotlist addresses) and scan-path frequency coloring. List what adjacent pieces exist and what is missing.' : ''}`

const huntPrompt = (d, truth) => `${ctx(d)}

You already have this implementation map for the domain:
${JSON.stringify(truth).slice(0, 6000)}

TASK: Hunt for real problems in THIS domain only: logic bugs, broken edge cases, multi-tenancy/org-isolation holes, race conditions, idempotency gaps, incorrect validation, and ACCEPTANCE-CRITERIA the ticket requires that the code does NOT satisfy. Read the actual code to confirm each claim before reporting. Prefer a handful of MATERIAL, defensible findings over a long speculative list (max ~8). For each: a crisp title, severity (blocker/high/medium/low; use 'info' for a confirmed missing-feature/gap note like DEV-1925), file:line, why it's a real problem, and a concrete observation/repro. Tie to a ticketRef when relevant. If the domain is genuinely clean, return an empty findings array.${d.key==='coverage' ? '\n\nFor DEV-1925: report each unmet acceptance criterion as an "info"-severity gap finding (title = the AC, why = what is missing, observation = the nearest existing adjacent feature).' : ''}`

const refutePrompt = (d, f, i) => `${ctx(d)}

A reviewer claims the following finding in this codebase. Your job is to REFUTE it by reading the actual code. Default to refuted=true if you cannot independently confirm it from the code. Confirm (refuted=false) ONLY if you verify the described behavior is real and is genuinely a problem.

FINDING:
- title: ${f.title}
- severity: ${f.severity}
- file: ${f.fileRef}
- why: ${f.why}
- observation: ${f.observation || ''}

Read ${f.fileRef} and any related code. ${d.key==='coverage' ? 'For a DEV-1925 absence claim: refuted=true means you DID find the feature implemented (cite it); refuted=false means you confirm it is absent.' : ''} Return your verdict with a one-line reason citing what you saw. (Verifier ${i+1} of 3.)`

const uatPrompt = (d, truth) => `${ctx(d)}

Implementation map:
${JSON.stringify(truth).slice(0, 6000)}

TASK: Author executable UAT test cases for THIS domain that a human QA tester can run. MERGE the scripted cases from DEV-1916 and the click-through cases from DEV-1921 that fall in this domain, DEDUPE overlapping cases, and write each as: stable id (prefix by domain, e.g. ${d.key.toUpperCase()}-1), title, preconditions (test data/state needed), concrete numbered steps, expected result, ticketRef, and automatedCoverage = covered|partial|none based on the test files you saw (set humanOnly=true for anything only checkable by hand — the entire PWA UI layer is humanOnly). Aim for thorough but non-redundant coverage. ${d.key==='coverage' ? 'DEV-1925 is NOT implemented: instead of runnable cases, emit one case per acceptance criterion with title="AC: <criterion>", steps=["Feature not implemented on this branch"], expected="<what the AC requires>", automatedCoverage="none", humanOnly=true, notes="gap". Put implementation-readiness guidance (what exists, what would be needed, the open questions) in readinessNotes.' : 'Leave readinessNotes empty.'}`

// ---- Phases A-C: pipeline each domain through truth -> hunt+verify -> author ----
const domainResults = await pipeline(
  DOMAINS,
  (d) => agent(truthPrompt(d), { label:`truth:${d.key}`, phase:'Map', schema: TRUTH_SCHEMA }).then(truth => ({ d, truth })),
  async ({ d, truth }) => {
    const found = await agent(huntPrompt(d, truth), { label:`hunt:${d.key}`, phase:'Hunt', schema: FINDINGS_SCHEMA })
    const list = (found && found.findings) ? found.findings : []
    const verified = await parallel(list.map(f => () =>
      parallel([0,1,2].map(i => () => agent(refutePrompt(d, f, i), { label:`verify:${d.key}`, phase:'Verify', schema: VERDICT_SCHEMA })))
        .then(votes => {
          const v = votes.filter(Boolean)
          const confirms = v.filter(x => x.refuted === false).length
          return { ...f, confirmed: confirms >= 2, confirms, votes: v.length, verdicts: v.map(x => x.reason) }
        })
    ))
    const confirmed = verified.filter(Boolean).filter(f => f.confirmed)
    log(`${d.key}: ${confirmed.length}/${list.length} findings confirmed`)
    return { d, truth, findings: confirmed }
  },
  async ({ d, truth, findings }) => {
    const uat = await agent(uatPrompt(d, truth), { label:`uat:${d.key}`, phase:'Author', schema: UAT_SCHEMA })
    return { key:d.key, title:d.title, ticket:d.ticket, truth, findings, uat }
  }
)

const clean = domainResults.filter(Boolean)
const totalFindings = clean.reduce((n,r)=>n+(r.findings?.length||0),0)
const totalCases = clean.reduce((n,r)=>n+(r.uat?.cases?.length||0),0)
log(`Authoring report from ${clean.length} domains, ${totalFindings} confirmed findings, ${totalCases} UAT cases`)

// ---- Phase D: synthesize one markdown report ----
const compact = clean.map(r => ({
  key:r.key, title:r.title, ticket:r.ticket,
  summary:r.truth?.summary,
  tested:r.truth?.testedBehaviors, untested:r.truth?.untestedBehaviors,
  findings:(r.findings||[]).map(f=>({title:f.title,severity:f.severity,fileRef:f.fileRef,why:f.why,observation:f.observation,ticketRef:f.ticketRef})),
  cases:r.uat?.cases, readinessNotes:r.uat?.readinessNotes,
}))

const report = await agent(`You are assembling the FINAL consolidated QA/UAT report for the Clarity Scout / Scout Beta feature (branch scanner-app) of a Laravel 12 + Filament 4 + Vue PWA app. Output ONLY GitHub-flavored Markdown — your entire message IS the report file contents (no preamble, no code fence around the whole thing).

You are given per-domain structured results (implementation summary, tested/untested behaviors, adversarially-CONFIRMED findings, and authored UAT cases) as JSON:

${JSON.stringify(compact)}

Produce a polished, comprehensive report with EXACTLY these sections:

# Scout Beta (scanner-app) — Consolidated UAT & Bug/Gap Audit
A one-paragraph intro: what the feature is, that this merges DEV-1916 (dev QA), DEV-1921 (staging click-through), and DEV-1925 (coverage story), and that findings are adversarially verified against the code.

## 1. Executive summary
Feature scope in 3-5 bullets; a ship-readiness verdict (Ready / Ready-with-caveats / Not ready) with one-line justification; the top blockers/high-risk items (pull from findings, highest severity first); and the DEV-1925 status in one line.

## 2. Scope & gating
The triple gate (config flag app.experimental.clarity_scout + Pennant platform_repo_pro_clarity_scout + clarity-scout permission), how to enable Scout for an org, and the test-data prerequisites (2 orgs, kits with/without assigned scout users, with/without email, activity vs zero-activity).

## 3. Consolidated UAT test matrix
For EACH domain (in this order: gating, refactor, zones-admin, zones-backend, realtime, auth, shifts, scout-users, pwa), a "### <Domain title>" subsection containing a Markdown table with columns: ID | Test case | Preconditions | Steps | Expected | Ticket | Auto-coverage. Render steps as a numbered inline list (1. … 2. …). Mark Auto-coverage as covered/partial/none and append "(human-only)" where humanOnly. Include every authored case; do not drop any.

## 4. Verified findings (ranked)
All confirmed findings across domains in ONE table ranked by severity (blocker, high, medium, low, info), columns: Severity | Finding | Location (file:line) | Why it matters | Ticket. Then a short "Suggested checks" paragraph.

## 5. DEV-1925 — zone coverage visibility (gap + readiness)
State clearly it is NOT implemented on this branch. List each acceptance criterion as unmet, what adjacent pieces exist (hit-density heatmap of hotlist addresses; scan-path frequency coloring), and the readiness notes / open questions. Use the coverage domain's findings + readinessNotes.

## 6. Automated-coverage map
A table: Domain | Has automated tests (examples) | Human-only gaps. Make explicit that the backend is well-covered (~30 test files) and the entire Vue/PWA layer has no automated tests.

## 7. Ship-readiness checklist
A checkbox list of what must pass/be resolved before release, derived from blockers/high findings and the highest-risk untested behaviors.

Be specific and faithful to the JSON — cite file paths from the data. Do not invent findings not present in the data. Keep tables readable.`,
  { label:'synthesize', phase:'Synthesize' })

return { report, stats: { domains: clean.length, findings: totalFindings, cases: totalCases } }
````

> After the workflow returns, the session writes `result.report` to `docs/qa/scout-beta-uat-report.md` and prints `result.stats` + the executive summary. Nothing else is mutated.
