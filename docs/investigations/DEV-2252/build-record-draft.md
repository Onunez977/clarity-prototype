Insight AI — Build Record

<!-- build-record: DEV-2252 -->

**TL;DR**

Creating a zone from ZIP codes could fail in three different ways, and all three failed *quietly*. A ZIP with no Census boundary saved a zone with no geometry and reported "Zone saved" — the zone then existed but was invisible on every map. A ZIP typed as a 4-digit fragment (the shape Excel produces when it strips a leading zero) was accepted and prefix-matched, and a 5-digit ZIP with no exact match fell back to whichever row came first — so a zone could save successfully with an entirely unrelated geography attached, looking perfectly healthy. The save path now refuses a zone whose geometry is empty or whose ZIP list contains any ZIP with no usable boundary, and it shows the admin why; the ZIP input now requires exactly 5 digits and only accepts an exact match. The same silent-empty-geometry hole was closed in `RunArea\Materializer`, which had it too.

Verified by 17 agent-QA checks (11 in a real browser, 6 backend), all passing, plus `ManageZonesTest` and `MaterializerTest` at 38 passed / 0 failed. Five review rounds scored 82 → 91 → 93 → 94 → 94; the round-1 failure caught that the new rejection rendered nothing at all on screen, which is now fixed and confirmed visible in a browser. Two things remain open and neither blocks the code: we never learned which ZIP codes actually failed for the reporter, so this ticket cannot claim which defect produced the reported symptom; and `tiger_data.state_all.simplified_geom` should be checked in production before merge, because the new guard turns mixed polygon+state run areas from silently under-clipping into loud job failures.

---

**Route:** bug · **Base branch:** `main` · **PR:** https://github.com/insight-lpr/platform/pull/414

## What changed, and why each change was needed

Prep ran four independent passes and reconciled them. The conclusion that shaped everything else: **this is not one bug.** It is five distinct findings on the ZIP→zone path, four of them reproducible locally with no production data.

### D1 + D2 — `ManageZones::save()` reported success unconditionally (server)

`app/Filament/Pages/ManageZones.php`

`save()` called `rebuildGeometryFromDefinition()` and **ignored the result**, then sent "Zone saved" regardless. An unresolvable ZIP therefore produced a zone row with `geom = NULL` — invisible on every map, with nothing anywhere saying so. The sibling importer `ZoneKmlImporter` already guarded this exact case with `(geom IS NULL OR ST_IsEmpty(geom))`, so the correct behaviour was established in the codebase; this path just never adopted it.

D2 is why D1 shipped: `ManageZonesTest::test_saves_a_zip_only_zone` never seeded `tiger_data.zcta5_all` and never asserted `geom`. It **passed because an unresolvable ZIP was accepted as a successful save** — the test pinned the bug as correct behaviour. That test was corrected, not deleted.

The guard is deliberately more than an emptiness check. `ST_Union` over `ARRAY[...]` **drops NULL members**, so a zone containing one good ZIP and one unresolvable ZIP still yields a valid, non-empty geometry. Checking emptiness alone would have accepted that zone and silently discarded the bad ZIP — the very failure class this ticket exists to close. The ZIPs are therefore resolved separately, via `unresolvedZipCodes()`, and that lookup runs **outside** the save transaction: a swallowed query error inside an open Postgres transaction poisons it, and the COMMIT then returns as a silent ROLLBACK underneath a "Zone saved" toast.

### D3 — the client accepted truncated ZIPs (client)

`resources/js/components/zone-manager.js`

`commitZipInput()` validated tokens with `/^\d{3,5}$/`. A 4-digit token is the classic shape of a leading-zero ZIP mangled by Excel — every ZIP in CT, MA, RI, NH, VT, NJ and PR begins with `0`. Because the search endpoint matches by **prefix**, a 4-digit fragment resolved to a real but different ZIP. Now `/^\d{5}$/`.

### D4 — `addZip()` substituted the wrong area (client)

Same file. `rows.find(r => r.zip === token) || rows[0]` fell back to the first row whenever there was no exact match, silently attaching an unrelated ZIP's polygon. Its own sibling, `hydrateZips()`, was already exact-match-only. Combined with D3 this is the worst of the five: the zone saves **successfully, with the wrong geography, and nothing looks broken.** The fallback is removed — no exact match now returns `not_found`.

There is a structural note worth keeping: `searchZipCodes()` matches by **prefix** (`LIKE 'q%'`) while `rebuildGeometryFromDefinition()` matches **exactly** (`zcta5ce IN (...)`). A ZIP staged via prefix match can therefore resolve differently at save time than it did at search time. The prefix behaviour is intentional and test-pinned, so the fix belongs client-side — which is where it was made.

### The `Materializer` sibling guard

`app/Services/RunArea/Materializer.php`

`materialize()` had the same hole: recognized inputs that resolved to nothing produced a NULL union, and the row was then **stamped as materialized over that NULL** — so a re-materialize could silently replace a previously-good boundary with nothing. It now refuses and throws, rolling back so the stamp is not applied and the prior geometry survives. This was originally recommended as a follow-up ticket; the operator overrode that and asked for it here.

## Resolved questions

| Question | Answer | Source |
| --- | --- | --- |
| What should happen for a ZIP with no Census boundary? | Clear error + draw the zone manually. A geocoding fallback was **explicitly declined** as a product/cost decision. | human:Dalton |
| DEV-2320 / `PersistZone` carries the same missing guard — block on it? | No. Land on main now; DEV-2320 rebases and picks the guard up. | human:Dalton |
| DEV-2557 touches the same file — wait? | Proceed; accept the mechanical conflict. | human:Dalton |
| Fix `Materializer` here or file a follow-up? | **Fix in this ticket** (overrode the follow-up recommendation). | human:Dalton |
| Is the failure an SRID mismatch? | Ruled out. | code |
| Is it a cross-state ZCTA union problem? | Ruled out. | code |
| Is the server truncating ZIPs as integers? | Ruled out — string end to end. | code |
| **Q1: which ZIP codes failed for the reporter?** | **Never answered.** | — |

**Q1 matters and is stated plainly rather than buried:** because it was never answered, this ticket **cannot claim which defect produced the reported symptom.** All four code defects are fixed regardless, so it does not change what was built — but the causal link between the report and the fix is an inference, not a finding.

## What this ticket did NOT do

- **Geocoding fallback** for ZIPs with no Census ZCTA boundary — explicitly declined, not forgotten.
- **`searchZipCodes()` prefix-LIKE behaviour** — intentional and test-pinned; the fix belonged client-side.
- **DEV-2320 `PersistZone::persist()`** — carries the same missing guard but does not exist on `main`; it rebases onto this.
- **`app/Jobs/Rdn/GetOrgBranchZips.php` and `UpdateUsPostalCodesWithGeocodioAndTigerJob`** — they operate on `tiger.us_zipcodes` (RDN branch metadata), a different table from `tiger_data.zcta5_all` despite both matching a "zip" grep.
- **`KmlZoneParser`** — already guarded via `ZoneKmlImporter`.
- **Read-only `zones.geom` consumers** — unaffected.
- **The pre-existing `definition` `->rule()` path, which also renders nothing.** Same root cause as the bug that was fixed, different trigger; needs a view change outside this ticket's scope. **Follow-up recommended** — it is the same "doesn't register" symptom users report. No ticket filed yet.
- **`hydrateZips()` silent truncation during edit hydration**, which writes the truncated list back as authoritative. Pre-existing, same class as D4, but its sibling half (`geometryToRings`, interior rings) is owned by the unmerged DEV-2557, so fixing it here would collide. **Follow-up recommended.** No ticket filed yet.
- **`Materializer::polygonPiece()` ring-arity check** — the Zone side has one, this does not. Pre-existing.
- **A dead `...$malformed` spread at `Materializer.php:78`** — harmless clutter introduced in review round 5, left in place because removing it would have invalidated `reviewed_sha` and forced a sixth review round for a no-op.

**Is the feature operational after this ticket?** Yes. ZIP-based zone creation works end to end for ZIPs that have a boundary, and fails visibly and recoverably for those that do not. The exclusions above are adjacent defects and hardening, not missing pieces of this fix.

## Review

Five rounds, in-session adversarial lanes (`sources=in-session-agent:1` — recorded, not silent): **82 (gate FAIL) → 91 → 93 → 94 → 94.** Final gate: `REVIEW_GATE: pass score=94 src=arbitration lane_min=94 lanes=1 rn=5`. `reviewed_sha = d2217b6e4`.

Round 1 failed the gate on a defect the build had missed outright: **the new guard rejected the save correctly but rendered nothing at all**, because `definition` is a `Hidden` component with no error slot and the page has no form-level error region. On the edit path that is *worse* than the original bug — a lying "Zone saved" replaced by silence. Fixed with a persistent danger notification, asserted by a test, and confirmed visible in a browser during QA.

Four further rounds each found a real defect, two of them introduced by this work:

- `ST_Union` drops NULL members, so a polygon plus an unresolvable ZIP still produced a valid geometry and the ZIP was silently discarded — the ticket's own failure class surviving inside the fix for it.
- A hardening fix swallowed a query error **inside** the save transaction; Postgres aborts the whole transaction on any statement error, so COMMIT returned as a silent ROLLBACK under a "Zone saved" toast — AC-2 exactly inverted, reintroduced by the thing meant to protect it.
- The `ST_Union` lesson was applied to `ManageZones` but not to its sibling `Materializer`, and then only to the mixed case rather than the all-malformed one — which is the *likelier* production shape, since one bad serialization drops every entry.
- Two `Materializer` tests could have passed for the wrong reason: `QueryException` **and** PHPUnit's `AssertionFailedError` both extend `RuntimeException`.

Stopping at round 5 was a decision, not an oversight: round 5 found nothing at HIGH, and the remaining items are smaller than the cost of another ~10-minute cycle.

## Tests

| Command | Result |
| --- | --- |
| `php artisan test --env=testing tests/Filament/Pages/ManageZonesTest.php tests/Services/RunArea/MaterializerTest.php` | **38 passed, 0 failed** |
| `php artisan test --env=testing tests/Models/ZoneTest.php` | 12 passed, **3 failed — pre-existing** |
| Pint | clean |

Every new guard was verified red→green by reverting the production file during review.

The 3 `ZoneTest` failures (`rebuild geometry from zip only definition`, `rebuild geometry unions polygons and zips`, `is no scan zone defaults to false and casts to boolean`) were confirmed pre-existing **three independent ways**, rather than asserted: (1) `app/Models/Zone.php` is untouched by this diff, so the code those tests exercise is byte-identical to `main` — unchanged tests over unchanged code cannot have a different outcome; (2) the diff to `ZoneTest.php` only *adds* one test, which passes; (3) the review's own round-1 test log independently recorded the same three failing on pristine `main`.

**One honest coverage limit:** the *resolvable*-ZIP happy path cannot be tested in the PHP harness at all. `ST_Transform` must read `public.spatial_ref_sys`, which the test setup holds an `AccessExclusiveLock` on for the life of every test (captured from `pg_locks`). That path, and both client-side fixes (there is no JS test runner in this repo, and the Livewire tests set `data.definition` directly, bypassing `zone-manager.js` entirely), are browser-QA only — which is exactly what QA below covered.

## QA performed

**Environment.** Worktree-isolated Postgres (`zod_claude_dev_2252_f748a2` + `zod_testing_claude_dev_2252_f748a2`), Herd site `clarity-claude_dev_2252_f748a2.test`, dev-login. **ClickHouse was deliberately not provisioned** — this change touches no ClickHouse table — and its host was neutralized to `127.0.0.1` in both `.env` and `.env.testing` so a stray call fails loud-and-local instead of silently reaching ClickHouse Cloud, which is where this worktree's `.env` had been pointing. Provisioning was reused from the earlier phase rather than rebuilt (`bootstrap: skipped-already-provisioned`, `migrations: already-migrated`). No frontend build was run: `public/build` is tracked in this repo and the committed bundle already carries the change (verified: `zone-manager-B0LDtuw6.js` contains `\d{5}` and no `\d{3,5}`, and `manifest.json` points at it).

**Seeded world** (tinker, idempotent, persisted at `.context/qa-seed.tinker.php`). Three `tiger_data.zcta5_all` rows chosen to separate three states the guard must tell apart: **75201** and **75202** with real boundaries; **75203** with a row whose `the_geom` **IS NULL**; and **99999**/**75209** with no row at all. The NULL case is not contrived — the table's own constraint is `CHECK ((geometrytype(the_geom) = 'MULTIPOLYGON') OR (the_geom IS NULL))`, so a NULL boundary is a **schema-permitted** state. Plus two ADMIN personas in a `CLARITY_SCOUT`-enabled org (capabilities asserted **through the application** — `$user->can(...)`, `Feature::for($user)->active(...)` — not by reading a column) and one pre-existing hand-drawn zone.

**Agent QA: 17 of 17 items pass.** 11 browser + 6 backend. No console errors, no unexpected 4xx/5xx.

The three items the ticket said only a browser could settle, with the exact on-screen text captured:

| Check | Result |
| --- | --- |
| 4-digit fragment `7520` | `Not a valid ZIP: 7520` — nothing attached |
| 5-digit ZIP with no row `75209` | `No boundary found for: 75209` — **no unrelated polygon substituted** |
| Unresolvable ZIP on save | Red, **persistent** "Zone not saved" toast: *"No Census boundary found for ZIP code(s): 75203. Remove them, or draw the zone manually."* |

Three more worth recording:

- The save check **re-opened the zone from the list** rather than trusting the success toast. The original bug was precisely a truthful-looking toast over a zone with no geometry, so a toast alone could not pass that item. The polygon genuinely persisted.
- A valid ZIP staged alongside an unresolvable one still rejects, naming only the bad one — the `ST_Union`-drops-NULL trap is closed end to end.
- After a rejection, removing the bad ZIP and drawing a polygon by hand saved successfully **without a reload** — the workflow chosen over a geocoding fallback is genuinely usable, not just theoretically available.

Backend: boot integrity clean; `Materializer` throws for an unresolvable `state` (`state:TX`) and `msa` (`msa:19100`) reference; and — the case review recorded as having **no coverage** — it stays **quiet** on a good polygon-only run area, producing `ST_MultiPolygon`, non-empty, stamped `tiger:2019`. Every `Materializer` call ran inside an always-rolled-back transaction; `org_run_areas` count afterwards was 0.

**Human QA.** The interactive guide (`.context/qa-human-guide.html`, 2 sections / 11 steps, 2 dev-login personas, agent-QA's findings folded in) was rendered and run by the operator against the same standing environment. Completed with no findings reported and no failing acceptance criterion.

**What changed because of QA:** nothing. No item failed, in either the agent pass or the human pass, and no fix was required.

**Log noise:** 5 `ERROR` lines, all one cause and none from the zone pages — a cURL connect failure to `127.0.0.1:8443`, the deliberately neutralized ClickHouse host, raised by the Filament stats-overview widget on `App\Filament\Pages\Dashboard`. That is the neutralization working as designed.

**ClickHouse seed SQL:** none. No ClickHouse table is touched by this change, so nothing was seeded and no read-only verification statements apply.

## Acceptance criteria

| AC | Status | Basis |
| --- | --- | --- |
| AC-1 — ZIP zone creation works for ZIPs that have a Census boundary | **SATISFIED** | Browser: 75201 resolved, drew, saved, and re-rendered on a fresh re-open |
| AC-2 — an unresolvable ZIP fails loudly, never silently saves an invisible zone | **SATISFIED** | Visible persistent rejection; a valid ZIP alongside it does not mask it; null/empty-geom zone count still 0 afterwards |
| AC-3 — malformed/truncated input never attaches an unrelated boundary | **SATISFIED** | 4-digit rejected as malformed; 5-digit with no row reports no boundary; mixed batch keeps the good token and names both bad ones |
| AC-4 — is this data/formatting validation or a lookup failure? | **N/A** | Not a behaviour — it is the ticket's question. Answer: **both**. Kept N/A because Q1 was never answered, so which defect produced the reported symptom is unknown |
| AC-5 — clear, actionable error and the admin can draw manually | **SATISFIED** | The message names the ZIP and the remedy; recovery in place was exercised |
| AC-6 — materialization cannot silently persist a NULL/empty geometry | **SATISFIED** | Guard throws for unresolvable `state` and `msa`; stays quiet on valid polygon-only input |

## Please check before merge

Two production facts could not be settled from the development environment. An attempt to read them read-only against the production CDC mirror was **denied by the environment's permission classifier as a production read, and was not worked around.**

```sql
-- 1. Does the new whereNotNull guard change behaviour for existing run areas?
SELECT count(*) AS rows, count(simplified_geom) AS with_geom FROM tiger_data.state_all;
SELECT count(*) AS rows, count(geom) AS with_geom FROM tiger_data.tl_2019_us_county;

-- 2. Zones created before this fix that are invisible on every map, with no way for an admin to find them
SELECT count(*) FROM platform.zones WHERE deleted_at IS NULL AND (geom IS NULL OR public.ST_IsEmpty(geom));
```

Also worth confirming: production `QUEUE_CONNECTION` is redis/Horizon and not `sync`. Under `sync`, the `Materializer` throw would return a 500 to Backyard's signed webhook instead of producing a single `failed_jobs` row.

**Why this matters and how far it reaches.** Nothing in this repo populates `simplified_geom` on `tiger_data.state_all` — `StateSeeder` adds that column to `tiger.state`, a *different* table, with no backfill — and both `state_all` and `tl_2019_us_county` are **empty (0 rows)** in the QA database, so every `state`/`msa` reference was unresolvable there by construction. QA therefore proved the guard **fires**; nothing anywhere proved it stays **quiet** on a valid reference. If `simplified_geom` is empty in production, mixed polygon+state run areas move from silently under-clipping to loud `failed_jobs` entries. That is the intended direction, but it is a live behaviour change. The blast radius is narrow: a state-**only** run area already threw via the pre-existing emptiness guard, so only the *mixed* case changes.

Question 2 is a remediation question, not a regression: the guard stops **new** invisible zones, but any created before this fix keep `geom = NULL`, stay invisible on every map, and are surfaced nowhere — the Zones list never selects `geom`. Re-saving one repairs it, but an admin has no way to find it.

## How numbers were derived

- **"38 passed, 0 failed"** — `php artisan test --env=testing` over `ManageZonesTest.php` and `MaterializerTest.php`, run against the isolated testing database, one process at a time (concurrent phpunit runs deadlock this branch DB and strand `idle in transaction` backends).
- **"12 passed, 3 failed (pre-existing)"** — the same command over `ZoneTest.php`, cross-checked against the review's round-1 log and against the fact that `app/Models/Zone.php` is absent from `git diff --name-only origin/main...HEAD`.
- **"17 of 17"** — 11 browser items driven through a real Chrome against the seeded site, plus 6 backend items; each recorded individually in `.context/qa-ac-results.json` and `.context/qa-agent-report.md`, with 12 screenshots in `.context/qa-screenshots/`.
- **"null/empty-geom zone count still 0"** — `SELECT count(*) FROM platform.zones WHERE deleted_at IS NULL AND (geom IS NULL OR public.ST_IsEmpty(geom))`, run before and after the rejected saves, and re-run independently by the driver rather than taken from the browser agent's report.
- **Review scores** — `review-gate.sh` output per round, recorded in `state.record.review.scores`; the final gate line is quoted verbatim above.
- **Resource envelope** — not measured, recorded as an explicit N/A. The mechanical scale trigger *did* fire, on two new `->pluck()` calls, and was examined rather than waved off: both are `whereIn(<caller-supplied list>)->distinct()`, so the result set is bounded by the zone's ZIP list or the run area's reference list, not by table cardinality. No command, job, ClickHouse query class, or migration is touched.

No raw rows, plates, or identifiers were recorded anywhere in this ticket's artifacts.

## Process note

Partway through QA, a concurrent session started DEV-2591 and adopted this run's worktree — it had a generic name rather than the ticket key, so nothing marked it as in use. Its branch and `.env` were re-keyed underneath this run. No DEV-2252 work was lost (the branch was already pushed, and the seeded database was never touched by the other run), and the other session's tree was left completely unmodified; this run was recovered into a dedicated worktree. Recorded because it explains the run-root change in `state.json`, and because the same trap will recur for any run whose worktree is not named for its ticket.
