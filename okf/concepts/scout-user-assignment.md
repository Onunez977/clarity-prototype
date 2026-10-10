---
type: Pattern
title: Scout User Assignment
description: How a person becomes a Scout driver — the kit ↔ user ↔ zone linkage via kit_settings, the clarity-scout permission grant, and the ScoutUsersResource admin flow.
resource: app/Models/KitSettings.php
tags: [scout, postgres, multi-tenancy, permissions, filament]
timestamp: 2026-06-30T12:00:00Z
---

# Scout User Assignment

To use [Scout](scout.md), a person must be linked through a three-step chain:

```
Kit  ──(kit_settings)──>  User  ──(zone_user)──>  Zone(s)
                          + `clarity-scout` permission
```

1. A physical scanner **[Kit](kit.md)** is associated with a **User** via the `kit_settings` bridge.
2. That user is granted the **`clarity-scout`** permission (driver portal access).
3. The user is assigned to one or more **[Zones](zone.md)**.

## `kit_settings` — the kit ↔ user bridge

`KitSettings` (`app/Models/KitSettings.php`) is the join between a kit and the user who drives it, plus per-kit metadata.

- **Connection / table:** `platform` connection, `$table = 'platform.kit_settings'` → `platform.kit_settings`. Created by `database/migrations/2026_04_29_000000_create_kit_settings_table.php`.
- **Primary key:** `kit_id` (non-incrementing). Columns: `user_id` (nullable, **unique** → one user maps to at most one kit), `vehicle_type` (cast to `VehicleTypeEnum`), timestamps.
- **Relationships:** `kit()` → `BelongsTo` [Kit](kit.md); `user()` → `BelongsTo` User.

`VehicleTypeEnum` (`app/Enums/VehicleTypeEnum.php`) values: `ScanningCar` (`scanning_car`), `RepoTruck` (`repo_truck`), `Flatbed` (`flatbed`).

### `users.kit_id` is an accessor, not a column

The `create_kit_settings_table` migration **dropped the `users.kit_id` column**. `User::kit` and `User::kit_id` are now **accessors over the `kitSettings` relationship** (`User::kitSettings()` → `HasOne(KitSettings, 'user_id')`), and `Kit::assigned_user` reads through `settings.user`. Treat them as derived, never as columns:

- A user with **no `kit_settings` row** has no kit — and a Scout 403 usually means a missing `kit_settings` row, not a permission problem.
- Enumerating Scout-enrolled kits via `whereHas('settings')` across the kit/settings connection boundary can silently return zero rows (cross-connection `EXISTS`) — prefer a separate keyed `KitSettings` lookup.

## Granting Scout access (`ScoutUsersResource`)

`app/Filament/Resources/ScoutUsersResource.php` is the admin surface for assignment. It is modeled on **`Kit`** (`$model = Kit::class`), under the "Scout" navigation group, gated by `Feature::active(CLARITY_SCOUT) || session()->has('admin_impersonator')` plus the ADMIN role. Scout users can't be created or deleted here — only kit assignment and permissions are edited.

The edit flow (`Pages/EditScoutUser.php`):

- **Assign a driver** — either *create a new user* (email, mobile phone, random password) or *assign an existing* unassigned org user. The 1:1 kit↔user rule is enforced on save: any other `kit_settings` row pointing at the chosen `user_id` is cleared, then `KitSettings::updateOrCreate(['kit_id' => …], ['user_id' => …, 'vehicle_type' => …])`.
- **Toggle Scout access** — grants/revokes the **`clarity-scout`** permission. On first grant the user is given the `USER` role if roleless, and an unverified user is sent the `ScoutOnboardingNotification` (a signed set-password link → `scout/set-password/{user}`, 7-day TTL).
- **Resend onboarding** — envelope action for unverified Scout users; sends a fresh `ScoutOnboardingNotification` without toggling access (DEV-2246).
- **Force login** — visible only when impersonating (`session()->has('admin_impersonator')`); links to `route('clarity-scout.force-login', $kit)` to open the driver PWA as that driver.

Signed set-password links use a **relative** signature (host/scheme-independent) and are validated by `ValidateScoutSignedUrl`, which also accepts older absolute signatures. `ForceHttpsRequest` + trusted proxies keep absolute links working behind TLS-terminating nginx/ALB. Invalid/expired links render `auth.scout.invalid-link` instead of a bare `403 Invalid signature`.

Permission checks throughout call `setPermissionsTeamId($user->org_id)` first (team-scoped permissions — see [Platform Gotchas](platform-gotchas.md)).

## Zone assignment eligibility

Only users **with a `kit_settings` row** can be assigned to a [Zone](zone.md). The zone forms (`ManageZones`, `ZoneReassignAction`) populate their user picker with `User::query()->where('org_id', …)->whereHas('kitSettings')` and re-validate the submitted IDs against the same constraint, failing with *"One or more assigned users do not have a kit."* The assignment itself (and its real-time driver notification) is handled by `ZoneAssignmentSyncer` — see [Zone](zone.md) and [Scout real-time tracking](scout-real-time-tracking.md).

## Where it lives in code

- Bridge model: `app/Models/KitSettings.php` (`platform.kit_settings`)
- Migration: `database/migrations/2026_04_29_000000_create_kit_settings_table.php` (also drops `users.kit_id`)
- Accessors: `app/Models/User.php` (`kitSettings()`, `kit`, `kit_id`, `zones()`), `app/Models/Kit.php` (`settings()`, `assigned_user`)
- Vehicle enum: `app/Enums/VehicleTypeEnum.php`
- Admin resource: `app/Filament/Resources/ScoutUsersResource.php` (+ `Pages/ListScoutUsers.php`, `Pages/EditScoutUser.php`)
- Onboarding / set-password: `app/Notifications/ScoutOnboardingNotification.php`, `app/Http/Controllers/Auth/ScoutSetPasswordController.php`
- Force login: `app/Http/Controllers/ClarityScoutForceLoginController.php`

## See also

- [Kit](kit.md) — a kit is a user/login for a physical camera; `kit_settings` extends it with the driver + vehicle type
- [Zone](zone.md) — what kit-assigned users get assigned to
- [Scout](scout.md) — feature gating and the `clarity-scout` permission
- [Multi-Tenancy](multi-tenancy.md) — roles, permissions, `setPermissionsTeamId`
- [Platform Gotchas](platform-gotchas.md) — team-scoped permission pitfalls
