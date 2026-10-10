# DEV-2596 — Implementation plan

**Phase 3 (ultra-plan, single arbiter).** Input: [`prep-context.json`](prep-context.json).
Machine-readable DAG: [`plan.json`](plan.json).

> **HUMAN APPROVAL REQUIRED BEFORE PHASE 4.** Five of the nine subtasks (S1, S2, S3, S4, S6)
> carry auth/session blast radius: a live session payload shape change, a route verb change on a
> banner rendered on every admin page, and a relocation of the credential every Scout PWA request
> in the field reads. Pairing with the PR #410 author is advisable — S1 reshapes the tracking
> mechanism that PR introduced.

---

## What this plan fixes

Three defects confirmed from code, independent of whether the 2026-09-16 demo failure can be
attributed to any one of them (it cannot — Q2/Q4/Q5 are unknown per the 2026-09-17 interview, and
the resolved blocker authorises fixing the code-grounded root-cause class instead).

| ID | Defect | Fixed by |
| --- | --- | --- |
| **H1** | `GET /auth/impersonate/exit-admin` strips `admin_impersonator` and mass-revokes every tracked force token, then `redirect()->back()` with no log, no message and no visible change — so the stale Filament table keeps offering a button that now returns a bare 403 | S3 (observability) + S4 (POST) + S2 (the 403 now names a reason) |
| **H2** | The Scout PWA reads its bearer from the origin-shared `localStorage['scanner_token']` on every request (`fetch.js:24`) while `forceAuthenticate()` overwrites it (`auth.js:207`), so each new force-login tab hijacks older tabs' REST identity — and the force ability's gate bypass makes those mismatched requests return **200 with the wrong driver's data** | S5 + S6 (tab-scoped credential) |
| **H3** | `Session::push` accumulates token ids in one flat, driver-blind array (`ScoutForceTokens:49`), and pruned ids linger there forever | S1 (driver-keyed, prune-aware tracking) |

## Settled decisions carried in from prep

- **AC5 — coexist.** Force-login must **not** evict the driver's own `clarity-scout-token`.
  `ClarityScoutAuthController:64/:66` already guarantee this by exact token-name match; the plan
  changes nothing there and re-asserts it in S7.
- **Refuted C2 is not adopted.** No signed URL replacing the `admin_impersonator` gate, and no
  persisted DB flag. That session check (`ClarityScoutForceLoginController:16`) is the only staff
  gate on a route carrying `auth` middleware alone (`routes/web.php:105`), guarding a token that
  hard-bypasses kit, permission and feature checks in two places
  (`ClarityScoutMiddleware:25`, `routes/channels.php:30`).
- **Revoke-all on exit stays correct.** All three `revokeTracked()` call sites
  (`ImpersonateController:32`, `:83`, `:94`) end the impersonation, so revoking *every* tracked
  token there is the intended semantics. S1's driver-scoping exists to make tracking **bounded and
  targetable**, not to spare other drivers' tabs on exit — doing that would leave gate-bypassing
  credentials alive to their 60-minute TTL. This is called out explicitly so Phase 4 does not
  "improve" it into a token leak.
- **Do not reorder `revokeTracked()` relative to `session()->invalidate()`.** The current order is
  required; reordering makes revocation a silent no-op.

---

## Subtasks

### Wave 1 — parallel (disjoint files)

**S1 · Driver-scope force-token session tracking (H3)** — `app/Support/Scout/ScoutForceTokens.php`

`SESSION_KEY` becomes a driver-keyed map instead of a flat list. `mintFor()` writes the driver's
bucket from `prune()`'s surviving ids, so ids deleted by `KEEP_PER_DRIVER` pruning stop lingering.
`revokeTracked(?User $driver = null)` keeps revoke-all as the default — byte-identical observable
behaviour at all three existing call sites — and gains a targeted mode. A `normalise()` helper maps
the pre-deploy flat shape onto a single bucket so in-flight admin sessions still revoke on their
first post-deploy exit. `TTL_MINUTES`, `KEEP_PER_DRIVER` and `prune()`'s per-driver query are
untouched, and the bucket is **not** cleared on mint (that re-breaks the PR #410 duplicate-GET case
pinned at `ClarityScoutForceLoginControllerTest:87`).

**S2 · Surface and audit force-login rejections (AC4)** — `ClarityScoutForceLoginController.php`

Both intentional blocks get an explicit reason and an `activity_log` row written *before* the abort.
The 403 message names the cause and the remedy (admin mode ended or the admin session expired —
return to the panel and re-enter admin mode); the 404 keeps its message and gains a denied row.
There is no `resources/views/errors/403.blade.php` override in this repo, so the framework error
view renders the abort message verbatim — **no new view file is needed**. The success log and the
view payload stay byte-identical (pinned at `:216` and `ScoutSetPasswordControllerTest:273`).

**S3 · Make `exitAdminMode()` observable (H1, server half)** — `ImpersonateController.php`

Mirror `leave()`: write an `activity('impersonation')` row *before* revoking, then send a warning
Filament notification stating that admin mode ended and Scout force-login is unavailable until it is
re-entered. Revocation semantics unchanged.

**S5 · Tab-scoped credential accessor** — `resources/js/clarity-scout/lib/scoutCredentials.js` *(new)*

One module owns where the Scout credential lives. `markTabScoped()` writes a
`scout_credential_scope='tab'` marker into `sessionStorage` (survives reload, not shared across
tabs); `resolveStorage()` returns `sessionStorage` when the marker is present, else `localStorage`,
so normal driver login keeps rehydrating on PWA relaunch. Deliberately a marker check rather than a
read-through fallback — a force-login tab must never silently adopt the driver's own token. Nothing
imports it yet, so this node is behaviourally inert on its own.

### Wave 2

**S4 · Harden exit-admin against GET reachability (H1, route half)** — `routes/web.php`,
`resources/views/filament/hooks/impersonate.blade.php` · *after S3*

`Route::get` → `Route::post` at `:45` (name unchanged), and the banner's `<a href>` becomes a
CSRF-protected form submit. A prefetch, a history replay or a stray click can no longer revoke
silently. The `Leave` link stays a GET — it is outside the H1 mechanism and its effect is
self-evident; noted rather than silently widened.

**S6 · Route every credential read/write through the accessor (H2)** — `fetch.js`, `stores/auth.js`,
`main.js`, `lib/track.js` · *after S5*

All four known readers/writers change together, because prep's caution is explicit: change them
apart and analytics or boot diverge silently. `forceAuthenticate()` calls `markTabScoped()` before
its first write. `main.js` also gains the downgraded defence-in-depth payload check (route to
`/scout/login` with a recorded reason rather than booting on a malformed object) at zero extra file
cost. **`echo.js` needs no edit** — `connectEcho(token)` already takes the bearer as an argument, so
it becomes per-tab-correct for free, which closes the realtime/REST split-brain from Q15.

### Wave 3 — parallel (disjoint test files)

- **S7** — `ClarityScoutForceLoginControllerTest.php` + new `tests/Unit/Support/Scout/ScoutForceTokensTest.php`:
  three *different* drivers in one admin session all keep working (the reported failure, and a
  confirmed gap — every existing test is single-driver or same-driver-twice); coexistence with an
  active driver token in both directions; denied 403/404 outcomes are audited and named; and at unit
  level, per-driver bucketing, targeted vs full revocation, legacy flat-shape tolerance, and pruned
  ids not surviving in the session.
- **S8** — `ImpersonateControllerTest.php`: `:153` switches to `post()`; a GET now 405s; exit writes
  an activity row; a force-login after exit is denied with an explicit reason (the end-to-end H1
  regression test); re-impersonation still revokes across drivers (pinned as *intended*).
- **S9** — new `tests/e2e/dev-2596-force-login-tab-isolation.spec.ts`: H2 is untestable in PHPUnit.
  The repo already has a Playwright harness and a `.test`-host-gated `/__qa/login` lever, so drive
  two force-login tabs in one context and assert tab A still sends driver A's bearer after tab B
  boots, that a reload of tab A keeps driver A, and that the driver's own normal-login tab still
  rehydrates. **No new dependency** — do not add vitest (that needs separate approval).

---

## Sizing

| | |
| --- | --- |
| Production files | **10** |
| Test files | 4 |
| Concerns | 4 (token tracking scope · exit-admin observability+reachability · rejection surfacing/audit · per-tab credential isolation) |
| `<=6` production-file preference | **exceeded** |
| Sizing gate STOP | **No** |

The gate does not stop the plan because the excess is already partitioned along a clean seam. The
**server lane** (S1–S4, S7, S8 — 5 production files, PHPUnit) and the **client lane** (S5, S6, S9 —
5 production files, Playwright) share zero files, zero symbols and zero test runners, and each
closes its defects on its own. Nothing was padded: the tenth file is reached only because H2 has
four independent `localStorage` readers that prep explicitly requires changing together.

**Recommended split — two PRs.** Server lane first: it carries the session-shape deploy hazard and
should ship and be observed before the client credential relocation follows. Reviewing them together
would put a session payload migration and a field-PWA credential move in one auth/session diff.

## Verification

```bash
php artisan test --compact --filter='ClarityScoutForceLoginControllerTest|ScoutForceTokensTest|ImpersonateControllerTest'
php artisan test --compact --filter='ClarityScoutMiddlewareTest|ScoutChannelAuthTest'   # regression, files unchanged
npm run build
npx playwright test tests/e2e/dev-2596-force-login-tab-isolation.spec.ts
vendor/bin/pint --dirty --format agent && composer analyse
```

`perf_validation` is not required — no ClickHouse, and this is auth/session code on a 1:1
admin-to-driver request path (`resource_envelope.scale_relevant = false`).

Manual QA: `prep-context.qa_hint` steps 1, 2, 5, 6, 7. Step 6 is the PR #410 non-regression pass
(duplicate GET keeps the live token, pruning caps at 3 per driver, the service worker still refuses
to cache force-login HTML). Step 3 — re-impersonation killing all tabs — is expected behaviour, not
a bug to fix.

**Deploy note.** S1 changes a session payload shape while pre-deploy sessions are in flight. The
`normalise()` legacy tolerance is what stops their first post-deploy exit from leaving
gate-bypassing tokens alive. Prove it with the legacy-shape unit test before release, not after.

## Deliberately not covered

Full list with reasons in `plan.json → excluded_sites`. The ones worth knowing:

- **`ClarityScoutMiddleware:25` / `channels.php:30`** force-ability bypass — intentional and
  documented; H2 is fixed on the client instead.
- **`ScoutUsersResource:353`** stale `visible()` — unfixable without polling the table (broad UI and
  query cost on every admin page); AC4 is met by S2's named rejection and S3's notification instead.
- **Concurrent-mint session-write clobber (H4 variant)** — not deterministically testable in PHPUnit
  and a real fix needs request-level session locking. Bounded by the 60-minute TTL and reduced by S1
  storing `prune()`'s survivors rather than appending blindly.
- **`config/session.php:35`** 120-minute idle expiry — global session policy, surfaced by S2's
  message but not eliminated.
- **`okf/concepts/impersonation.md:49`** still documents pre-#410 by-name revocation. `CLAUDE.md`
  forbids writing to `okf/` while work is in flight — queued for the post-approval pass.
- **~60 unrelated `admin_impersonator` consumers** — only in play if that key's storage moves, which
  is exactly what was rejected.

## Still open after this plan

- **AC2 single-incident attribution.** Root cause stays competing-open; the plan closes the
  code-grounded root-cause class (H1/H2/H3), which is what the resolved blocker authorises.
- The 120-minute idle-session 403 mechanism is surfaced, not removed.
