# DEV-2255 — Plan

**Route:** bug · **Base:** `main` · **Branch:** `claude/DEV-2255` · **PR:** [#378](https://github.com/insight-lpr/platform/pull/378)

## What this ticket turned out to be

The ticket asks to "fix the invite link signature validation issue". That defect no longer
exists: **DEV-2246** (PR #296 `01cffb93b`, PR #297 `29101af15`) root-caused it as absolute
signed URLs failing validation behind a TLS-terminating proxy, and fixed it four ways —
relative signature minting, global `trustProxies(at: '*')` + prepended `ForceHttpsRequest`,
`ValidateScoutSignedUrl` accepting either signature form with a friendly error page, and a
resend-onboarding admin action. Those commits are contained in tags `v3.8.2` / `v3.9.0` /
`v3.9.1` and present on `origin/deploy`, so the fix has been live in production since
**2026-08-05** — eight days after DEV-2255 was filed (2026-07-28). The report describes
pre-fix traffic.

A repo-wide sweep for the root-cause idiom (`temporarySignedRoute`, `signedRoute`,
`hasValidSignature`, `ValidateSignature`, the bare `'signed'` middleware alias) over
`app/ routes/ config/ bootstrap/ resources/ tests/` found **9 production sites across 5
files: 8 safe, 1 residual gap.**

## The residual gap (the whole scope of this ticket)

`ScoutSetPasswordController::guardUser()` still emits three bare `abort(403)`s — link
already used (`email_verified_at` set), missing `org_id`, missing the `clarity-scout`
permission. DEV-2246 made *signature* failures friendly but never touched these, so a
driver who taps the emailed link a second time gets Laravel's blank 403 with no
explanation and no resend affordance. That is the genuine per-account failure mode behind
the ticket's second requirement ("a subset of invite links … for certain accounts").

Pre-fix, the signature bug affected **all** absolute-signed onboarding links behind the
proxy, not a subset — which is why one global scheme fix resolved it.

## Approval

Dalton approved this exact change at the Phase 2 gate on 2026-09-01, with the intended diff
shown in the option preview. That is the human approval line Phase 3 requires before the
auth blast-radius node may start. He separately **declined** hardening the alert-unsubscribe
links in this ticket — note-only in the Build Record, and explicitly no new Jira ticket.

## Subtasks

### t1 — Render the friendly invalid-link page for `guardUser()` failures
`complexity: complex` · `blast_radius: [auth]` · `test_filter: ScoutSetPasswordControllerTest`

**Files**
- `app/Http/Controllers/Auth/ScoutSetPasswordController.php`
- `resources/views/auth/scout/invalid-link.blade.php`
- `tests/Feature/Http/Controllers/Auth/ScoutSetPasswordControllerTest.php`

**Approach.** Replace each bare abort with a throw of
`Illuminate\Http\Exceptions\HttpResponseException` wrapping
`response()->view('auth.scout.invalid-link', [...], 403)`. It lets `guardUser()` keep its
`void` return type — so `show()` and `update()` keep their declared `View` return types and
the change stays additive rather than a signature change.

> **Mechanism, corrected post-review (lanes A + B).** An earlier draft of this plan said the
> exception "is returned straight to the client without passing through the exception
> handler's rendering". That is wrong. It *does* pass through
> `Illuminate\Foundation\Exceptions\Handler::render()`, whose
> `$e instanceof HttpResponseException => $e->getResponse()` arm returns the prepared
> response verbatim — not re-rendered, not mapped by `prepareException()`, and listed in
> `$internalDontReport` so it is not logged. The outcome is the same; the mechanism claim
> was inaccurate, and it is corrected here so it cannot be copied into the wiki addendum.

Pass a new **optional** `$reason` to the view: `'used'` for the already-verified one-shot
case, `'ineligible'` for both missing-`org_id` and missing-permission. In the Blade
template read it as `($reason ?? null)` and leave the existing `$expired` branch's **text**
intact — that way `ValidateScoutSignedUrl`, which passes only `$expired`, needs no edit and
the shipped DEV-2246 behaviour is untouched. (Precise post-review statement, lane A: the
middleware-path output is byte-identical *except* for added leading whitespace inside
`<h1>`, since the new `@if` wrapper indents it. The asserted substrings stay contiguous, so
DEV-2246's tests remain green.)

**Three hazards to respect**
1. **Guard order is load-bearing.** `setPermissionsTeamId($user->org_id)` sits *between* the
   `org_id` guard and the permission guard. The `org_id` guard must still short-circuit
   first, or the permission check evaluates against the wrong permissions team.
2. **Only the rendering changes.** The authorization *conditions* stay exactly as they are.
   This is not a change to who may set a password.
3. **Do not weaken the DEV-2246 signature tests.** They must stay green; they are the
   regression guard for the shipped hotfix.

**TDD order.** Extend the existing already-verified guard test to assert the friendly copy
and confirm it goes RED first, then implement, then add the `org_id` and permission cases
on both the GET `show` and POST `update` routes. Follow the file's conventions —
`#[Group('clarity-scout')]` and the `makeScannerUser()` helper.

## Deliberately excluded

| Site | Why |
| --- | --- |
| `app/Mail/Hits/NewNotification.php:66,75`, `app/Mail/MissedOpportunities/NewNotification.php:51,60`, `EmailController:17,37` | Absolute-signature idiom present but operationally safe behind DEV-2246's global middleware. Dalton declined hardening here; latent risk noted in the Build Record, no new ticket. |
| `ScoutOnboardingNotification:28`, `ValidateScoutSignedUrl:22` | Already correct as of DEV-2246. Re-touching risks regressing a shipped hotfix. |
| `Invite` / `InviteRedemptionController` / `InviteTypeEnum` / `app/Mail/Invites/*` | Verified unrelated — email + 6-character DB code, no signed URL; Scout drivers never route through it. |
| `tests/**` `temporarySignedRoute` hits | Fixture construction, not production sites. |

## Gates

- **Sizing:** pass — 3 files, one coherent concern.
- **Perf (CH-at-scale):** not required — no ClickHouse surface, `scale_relevant: false`.
- **Blast radius:** `auth` tag on t1, pre-approved by Dalton at the gate (above).
