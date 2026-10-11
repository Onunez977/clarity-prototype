---
type: Concept
title: Impersonation
description: How InsightLPR staff impersonate an org/user — the Backyard-signed entry, the impersonator vs admin_impersonator session keys, the effects they trigger (Pro-gate bypass, analytics skip, cache-TTL drop), the Scout force-login token, and the DEV-2089 419 gotcha.
resource: app/Http/Controllers/ImpersonateController.php
tags: [impersonation, auth, multi-tenancy, security, black-adam, feature-flags]
timestamp: 2026-07-06T00:00:00Z
---

# Impersonation

Impersonation lets an **InsightLPR staff member act as a customer org/user** to debug and support. It is **initiated from the Backyard staff app**, not from within Clarity, and it leaves session state that a large amount of Clarity code branches on. This page is the map of that mechanism and its effects — several other pages reference the `admin_impersonator` bypass but none own it.

## Entry (`ImpersonateController::impersonate`)

`GET /auth/impersonate`, reached via a **Black Adam signed URL** minted by Backyard (`BlackAdam::validateSignedUrl()` — a bad signature `abort(401)`s). With a valid `user_id` + `impersonator` payload it:

1. Logs out any current Filament user, then `session()->invalidate()` + `regenerateToken()` (this token regeneration is the root of the 419 gotcha below).
2. `Auth::loginUsingId($user_id, true)` — becomes the target user.
3. `session()->put('impersonator', $request->impersonator)` — **always**.
4. `session()->put('admin_impersonator', …)` — **only** if the impersonator's id is in a **hardcoded staff allowlist** in the controller (10 ids, named in comments — Eric, Dalton, …).
5. Logs an `activity('impersonation')` row.

## Two session keys — know the difference

| Key | Set for | Drives |
|-----|---------|--------|
| `impersonator` | **any** impersonation | analytics skip, the `insight-lpr` gate, client-panel access bypass |
| `admin_impersonator` | only the staff allowlist | **Pro-feature gate bypass**, Scout admin access, the near-live cache-TTL drop |

So a non-allowlisted impersonator still suppresses analytics and passes the `insight-lpr` gate, but does **not** get the Pro-gate bypass or Scout admin surfaces.

## Effects

- **Pro-feature gate bypass** — the pervasive `Feature::active(…) || session()->has('admin_impersonator')` pattern (and `HasProFeature`'s `session()->missing('admin_impersonator')` check) so staff see gated features on orgs without the grant. See [Feature Flags & Pro Tiers](feature-flags.md).
- **Analytics skip** — `app/Services/Analytics/AnalyticsService.php` returns early when `session()->has('impersonator')`, so support traffic doesn't pollute `clarity.analytics`; the Scout PWA's client tracker no-ops the same way (see [Scout analytics taxonomy](scout-analytics-taxonomy.md)).
- **Near-live cache** — cached ClickHouse reads drop their TTL to **~3 s** while impersonating (vs minutes), so staff see fresh data: `app/Clickhouse/Queries/ClickhouseQuery.php` (3 s vs 10 min) and the Scout dashboard/map controllers (3 s vs 90 s / 15 min). See [Scout real-time tracking](scout-real-time-tracking.md).

  **This is not a universal guarantee — it holds only where the read goes through a TTL ladder that checks the session key.** Two documented divergences on the Company Metrics grid (DEV-2463):

  - `DashboardWidget::getCacheTTL()` collapses to **10 s**, not 3 s, while impersonating.
  - A card that caches through its **own** path — e.g. `Cache::flexible([3600, 86400])` — never consults the ladder at all, so an impersonating admin can be served a value **1–24 h old** on that card while its neighbours are seconds fresh. Do not assume "impersonating ⇒ near-live" when diagnosing a stale card; check whether that card's read actually passes through `getCacheTTL()`. See [Company Metrics Dashboard](company-metrics-dashboard.md) and PG-009 in [Platform Gotchas](platform-gotchas.md).
- **Access/auth** — the `insight-lpr` gate (`GateServiceProvider`), `EnsureInsightLprAdmin` / `ClarityScoutAdminMiddleware`, the client-panel middleware bypass, and Horizon access all consult one of the two keys.

## Scout force-login token

Impersonating an org is web-session state, but the [Scout](scout.md) driver PWA is a **Sanctum bearer** app — so to open Scout *as a driver*, `ClarityScoutForceLoginController` (`/scout/force-login/{kit}`, itself requiring `admin_impersonator`) mints a personal access token named **`clarity-scout-force-token`** with abilities `['*', 'clarity-scout-force']` and a **60-minute TTL**. `ClarityScoutMiddleware` treats the literal `clarity-scout-force` ability as a bypass of the normal kit/permission/feature checks. See [Scout user assignment](scout-user-assignment.md).

**The token is revoked on exit** (`ImpersonateController::leave()` and `exitAdminMode()` both `->tokens()->where('name','clarity-scout-force-token')->delete()`), so it can't survive as a gate-bypassing credential; the 60-min TTL is only the backstop (DEV-2117/F1).

## Exit

| Route | Method | Effect |
|-------|--------|--------|
| `GET /auth/impersonate/leave` | `leave()` | Revoke the force token, full logout + `session()->invalidate()`, redirect back to Backyard (`black-adam.BACKYARD_URL`) |
| `GET /auth/impersonate/exit-admin` | `exitAdminMode()` | Revoke the force token, `forget('admin_impersonator')` **only** (stays logged in as the impersonated user), redirect back |

## Gotcha — the 419 on hand-off (DEV-2089)

Because entry does `session()->regenerate*`, an already-loaded Filament/Livewire SPA holds a now-stale CSRF token and the next mutation returns **419**. The fix, `->disabledErrorNotification(419)` in `app/Support/Filament/PlatformPanelProvider.php`, suppresses Filament's dead error toast so Livewire's native **page-expired reload** recovers with a fresh token. See [Platform Gotchas](platform-gotchas.md).

## Where it lives in code

- Entry/exit: `app/Http/Controllers/ImpersonateController.php` (routes `auth/impersonate`, `/leave`, `/exit-admin`)
- Force login: `app/Http/Controllers/ClarityScoutForceLoginController.php`, `app/Http/Middleware/ClarityScoutMiddleware.php`
- Bypass consumers: `app/Filament/Concerns/HasProFeature.php`, `app/Services/Analytics/AnalyticsService.php`, `app/Clickhouse/Queries/ClickhouseQuery.php`, `app/Providers/GateServiceProvider.php`, `app/Http/Middleware/{EnsureInsightLprAdmin,ClarityScoutAdminMiddleware,ClientMiddleware,ClientAuthenticate}.php`
- 419 fix: `app/Support/Filament/PlatformPanelProvider.php`
- Signed URL: `InsightLPR\BlackAdam\BlackAdam::validateSignedUrl` (see [Repositories](repositories.md))

## See also

- [Feature Flags & Pro Tiers](feature-flags.md) — the `admin_impersonator` Pro-gate bypass
- [Scout user assignment](scout-user-assignment.md) — the force-login flow into a kit
- [Scout real-time tracking](scout-real-time-tracking.md) / [Scout analytics taxonomy](scout-analytics-taxonomy.md) — cache-TTL drop and analytics skip
- [Multi-Tenancy](multi-tenancy.md) — org context, `setPermissionsTeamId`, the two panels
- [Platform Gotchas](platform-gotchas.md) — the 419 page-expired fix
- [Repositories](repositories.md) — Backyard (initiator) and Black Adam (signed URL)
