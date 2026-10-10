# DEV-2236 — Implementation Plan

**App:** clarity · **Repo:** platform · **Base:** main · **Sizing verdict:** no split (2 source files + 2 test files, one concern)

## The defect in one paragraph

A Scout force-login impersonator (an admin holding a Sanctum token with the `clarity-scout-force`
ability) currently leaves driver-attributed footprints just by using the PWA. DEV-2232 fixed the
shift-**start** half of this in `ClarityScoutShiftController::status()` and deliberately left the rest.
Two sites still misbehave:

1. `ClarityScoutShiftController::end()` — tapping **End Shift** fires a `ShiftEnd` analytics event,
   lazily creates a zero-activity `kit_shifts` row via `EnsureOpenShift::forKit()`, closes it,
   dispatches `SendEndOfShiftSummary`, and writes a "Scout shift ended" audit row.
2. `ClarityScoutAuthController::logout()` — exiting impersonation fires a `Logout` analytics event
   attributed to the driver.

Site 2 was **scope-expanded into this ticket by a human answer at the Phase 2 gate** (2026-09-14).
It is intentional, not drift.

---

## ⚠ Reviewer warning — the two guards are DIFFERENT SHAPES on purpose

This is the single thing most likely to be misread as a mistake, so it is stated up front.

| | `end()` | `logout()` |
|---|---|---|
| Shape | **Early return** — the whole method short-circuits | **Not** an early return |
| What is suppressed | analytics + `kit_shifts` create/close + summary job + audit row | **only** the `Analytics::track(Logout)` call |
| What still runs | nothing | **`currentAccessToken()->delete()` — the token revocation** |

`logout()` must still revoke the token, because **that delete is how an impersonating admin exits
impersonation**. An early return there would strand the admin inside the driver's session holding a
live `clarity-scout-force` token.

Ordering is also load-bearing in `logout()`: `isScoutForceImpersonator()` reads
`currentAccessToken()?->abilities` (`app/Models/User.php:252`), so **the predicate must be evaluated
before the token is deleted**.

**Second intentional asymmetry:** `status()` and `end()` will each carry a literal, duplicated
`isScoutForceImpersonator()` block. Extracting a private helper would DRY it, but the shipped DEV-2232
precedent did not extract one, and CLAUDE.md says to follow the existing pattern. The duplication is a
deliberate choice, not an oversight.

---

## Subtasks

Both are parallelizable — their `target_files` are disjoint (one controller + its own test file each).

### t1 — Guard `ClarityScoutShiftController::end()` (early return)

**Files:** `app/Http/Controllers/Api/ClarityScoutShiftController.php`,
`tests/Feature/Http/Controllers/Api/ClarityScoutShiftControllerTest.php`
**Blast radius:** none · **Test filter:** `ClarityScoutShiftControllerTest`

Insert the guard after the `if (! $kit)` 403 block (~line 111) and **before** the unconditional
`Analytics::track(ScoutAnalyticsEvent::ShiftEnd->value, ...)` at line 115:

```php
if ($user->isScoutForceImpersonator()) {
    return response()->json([
        'data' => [
            'shift_id' => null,
            'open' => false,
            'started_at' => null,
            'ended_at' => null,
            'end_type' => null,
            'newly_closed' => false,
        ],
    ]);
}
```

Returning before the `DB::connection('platform')->transaction()` suppresses every side effect by
construction — analytics, the lazy `EnsureOpenShift::forKit()` create, the close write, the
`SendEndOfShiftSummary` dispatch, and the audit row.

**Payload shape:** `end()`'s own six-key set with nulled values plus `newly_closed: false` — *not*
`status()`'s five-key payload. This was settled at the Phase 2 gate. The PWA consumer
(`resources/js/clarity-scout/composables/useShift.js:64-94`) optional-chains every field with a
fallback and never reads `newly_closed`, so a fully-null payload is safe.

**Docblock:** the class-level block (lines 23-34) says "Both endpoints lazily materialize an open
`kit_shifts` row via `EnsureOpenShift`". That is now wrong for `end()` as well as `status()`. Add a
one-line exception. Keep it terse — CLAUDE.md: *keep comments and docblocks minimal*.

### t2 — Gate only the `Logout` analytics call in `ClarityScoutAuthController::logout()`

**Files:** `app/Http/Controllers/Api/ClarityScoutAuthController.php`,
`tests/Feature/Http/Controllers/Api/ClarityScoutAuthControllerTest.php`
**Blast radius:** `auth` (see call below) · **Test filter:** `ClarityScoutAuthControllerTest`

```php
public function logout(Request $request): JsonResponse
{
    $user = $request->user();

    if (! $user->isScoutForceImpersonator()) {
        Analytics::track(ScoutAnalyticsEvent::Logout->value, $user, ['action' => 'logout'], page: ScoutAnalyticsPage::App->value);
    }

    $user->currentAccessToken()->delete();

    return response()->json([
        'message' => 'Logged out successfully',
    ]);
}
```

`$request->user()` is resolved once (it is currently called twice). Nothing else in the method changes.

#### Blast-radius judgement call

**Tagged `auth`.** The sensitive glob "Shield/Policies/auth config" literally means config and policy
files, and a controller method is not config — so a purely literal reading would leave this untagged.
I am tagging it anyway because this node's only realistic failure mode is an **authentication-session**
outcome, not an analytics one: implementing it as an early return (the shape t1 uses, and the shape
anyone pattern-matching on t1 would reach for) leaves the admin's `clarity-scout-force` token alive and
the admin stuck in the driver's session. That is a privilege-exit failure, exactly the class of risk the
approval gate exists for. The tag costs one human approval line before t2 is implemented; under-tagging
to dodge that gate would be the wrong trade.

---

## Test plan

**Neither test class is ClickHouse-gated.** Both were read directly: `ClarityScoutShiftControllerTest`
and `ClarityScoutAuthControllerTest` each `extends Tests\TestCase` with **no class-level attributes, no
traits in the class body, and no `RequiresClickhouse` token anywhere in either file**. The known trap
(a class-level `RequiresClickhouse` silently skipping a whole class in normal CI) does not bite here,
so both new tests go in their natural home files.

**The two files use different test-method conventions — match the host file, not the sibling subtask:**

| | `ClarityScoutShiftControllerTest` | `ClarityScoutAuthControllerTest` |
|---|---|---|
| Method naming | `#[Test]` attribute + descriptive snake_case | `test_`-prefixed snake_case (no `#[Test]` anywhere) |
| Group attributes | none | `#[Group('controllers')]` + `#[Group('clarity-scout')]` on every method |
| Setup | shared `$this->user` / `$this->kit` / `$this->token` in `setUp()` | file-private `createScannerUser()` helper (line 430) |

**Both** `setUp()` methods install a *permissive* `Analytics::shouldReceive('track')->zeroOrMoreTimes()`.
You cannot assert "never tracked" against that. Use the shift file's own precedent:

```php
$spy = \Mockery::spy(AnalyticsService::class);
Analytics::swap($spy);
// ...
$spy->shouldNotHaveReceived('track');
```

`ClarityScoutAuthControllerTest` does **not** currently import `App\Services\Analytics\AnalyticsService`
or `App\Enums\ScoutAnalyticsEvent` — both imports must be added there.

### t1 test — one new `#[Test]` method

Modelled line-for-line on the DEV-2232 sibling
`status_does_not_open_a_shift_or_track_shift_start_for_a_force_login_impersonator` (lines 227-258).
With a `['*', 'clarity-scout-force']` token, `POST /api/scout/shift/end` must assert:

- 200
- the full `data.{shift_id, open, started_at, ended_at, end_type, newly_closed}` key set, with
  `shift_id` null, `open` false, `newly_closed` false
- `assertDatabaseCount('kit_shifts', 0)`
- `$spy->shouldNotHaveReceived('track')`
- zero `activity_log` rows with `log_name` `scout-shift` / description `Scout shift ended`
- `Queue::assertNotPushed(SendEndOfShiftSummary::class)` (needs `Queue::fake()`)

> Note: the existing `status_and_end_response_shapes_are_locked` test uses a **normal** scout token, so
> it does not pin the impersonator payload. The new test must assert that key set itself.

### t2 tests — two new `test_`-prefixed methods

1. **Impersonator:** force token → `POST /api/scout/logout` → 200, `shouldNotHaveReceived('track')`,
   **and** `assertDatabaseMissing('personal_access_tokens', [...])` proving the token was revoked. That
   revocation assertion is the load-bearing half — without it, an early-return implementation would pass.
2. **Normal driver:** plain `clarity-scout-token` → `POST /api/scout/logout` →
   `shouldHaveReceived('track')` with `ScoutAnalyticsEvent::Logout->value`, `->once()`.

Existing `test_can_logout` and every existing `end()` test stay untouched — they are the
normal-driver-unchanged evidence.

Run `composer format` (Pint) before finishing. Do not run the suite beyond the two filters.

---

## Exclusions (surfaced on the PR and in the Build Record)

| Site | Why it is not in this ticket |
|---|---|
| `ClarityScoutShiftController::status()` (:62) | Already fixed by DEV-2232; it is t1's precedent. |
| `ClarityScoutAuthController::login()` (:68) | Unreachable via force-login — force tokens are minted by `ClarityScoutForceLoginController`, never through password login. |
| `ClarityScoutAuthController::me()` (:105) | Read-only; uses the predicate only to set `is_impersonating`. |
| `ScoutOrgSettings.php:92` | Read-only flag surfacing. |
| `ClarityScoutNotificationsController::markRead()` (:46) / `markAllRead()` (:62) | Graded `safe`: explicit user-initiated writes — the impersonator deliberately acting as the driver, not an implicit lazy side effect of being logged in. |
| `ClarityScoutScanDispositionController::__invoke()` (:39) | Same: explicit driver disposition action. |
| `ClarityScoutTripsController` view analytics (:269) | Read-only view events, no mutation. |
| `CloseInactiveShiftsCommand::handle()` | Scheduled, not request-scoped; no impersonator context exists. |
| `logShiftActivity()`'s `session('impersonator')` vs `session('admin_impersonator')` key mismatch | Pre-existing unrelated drift (the `_impersonator` audit property is likely never populated today). Flagged, **not** fixed — CLAUDE.md forbids silently fixing unrelated drift. |
| `okf/concepts/scout-shifts-and-trips.md` Observability section | Two lines go stale with this fix, but the OKF WRITE rule forbids wiki edits while work is in flight. **Queued** for the post-approval pass. |
| `resources/js/clarity-scout/composables/useShift.js` | Consumer verified safe as-is; no frontend edit, so no asset rebuild. |

---

## Data impact

No schema change, no migration, no ClickHouse read. The only ClickHouse touch is *indirect and
subtractive*: the fix **suppresses** an `Analytics::track` → `TrackAnalytics` → `clarity.analytics`
insert. Nothing downstream needs backfilling. `resource_envelope.scale_relevant` is **false** — a
request-scoped guard on a throttled (10/min) impersonator-only path.
