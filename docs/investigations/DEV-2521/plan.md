# DEV-2521 — Implementation Plan

**Route:** feature · **Base:** `main` · **Guard decision:** environment-only (`! app()->isProduction()`)

## t1 — Non-production OTP bypass in `CustomLoginPage`, with tests

**Files:** `app/Filament/Auth/CustomLoginPage.php`, `tests/Filament/Auth/CustomLoginPageTest.php`
**Complexity:** complex (auth blast radius — approved by the operator at the Phase 2 gate)

### Approach
1. **Extract the eligibility check.** `sendOtp()` currently inlines
   `User::where('email', ...)->first()` plus `canAccessPanel(client) || canAccessPanel(app)`.
   Move it to one private helper so the prod path and the bypass cannot drift apart — this is the
   security-relevant check, and two copies of it is how a guard quietly weakens.
2. **Add the non-prod branch** at the top of `authenticate()`'s body, after `rateLimit(5)`:
   when `! app()->isProduction()`, resolve the eligible user; no user → the same
   `Account Not Found` `ValidationException` `sendOtp()` throws (so AC2 holds unchanged);
   otherwise `Filament::auth()->login($user)` and fall through to the existing shared tail.
3. **Mark the bypass in the audit log.** `$otp_data` is only assigned in the else-branch and read
   as `$otp_data['otp'] ?? 'N/A'`. Don't lean on that undefined read — thread an explicit flag so a
   bypassed login is distinguishable from a genuine `N/A`.
4. **No form change.** `otp_sent` stays `false`, so the OTP field stays hidden and unvalidated —
   exactly as it already is on the current first submit.

### Tests
| Test | Pins |
|---|---|
| non-prod, known eligible email | authenticates in one submit; no OTP notification sent |
| non-prod, unknown email | still fails `Account Not Found`; stays guest |
| production | OTP is still sent and required end-to-end |

### Excluded
- `OneTimePassword.php` — production path only, untouched.
- `routes/web.php` `__qa/login` — separate shipped mechanism; template for guard rigor only.
- `PlatformPanelProvider.php` — one wiring point; both panels covered automatically.
