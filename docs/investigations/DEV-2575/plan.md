# DEV-2575 Plan — Scout password recovery (path B)

## Decision (gate)
- Keep passwords. No OTP conversion this ticket.
- Self-serve Forgot Password on `Login.vue` + admin Reset Password action.
- Both email a **7-day relative signed** reset link (existing relative signed-URL pattern).
- Forgotten email → admin finds user (name/kit) and triggers the same email link.

## Subtasks
1. **t1** Signed reset backend (notification, controller, routes, blade)
2. **t2** Admin action on `ScoutUsersResource` (depends on t1)
3. **t3** Forgot-password API + Login.vue (depends on t1; parallel with t2)
4. **t4** Onboarding/reset regression tests (depends on t1)

## Out of scope
OTP conversion, admin-set temp passwords, SMS, force-login/impersonation, nullable passwords.

## Sizing gate (>6 files)
Estimated ~12 files across backend + admin UI + Scout PWA + tests.

**Split option:**
- **Ticket A:** signed reset backend + admin action
- **Ticket B:** self-serve Login.vue + forgot API

**Recommendation: do not split** — both ACs need self-serve and admin; they share the same notification/controller. Splitting ships an incomplete recovery path.

Awaiting confirm: keep together vs split.
