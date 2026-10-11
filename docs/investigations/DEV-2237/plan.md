# DEV-2237 Plan — Scout option on Clarity Create/Edit User form

**Route:** feature · **Base:** main · **Single node** (one cohesive, non-parallelizable change).

## Decisions (from Dalton's gate answers)
- Mechanism: toggle the `clarity-scout` **permission** directly (no Spatie role).
- Scope: **permission + kit assignment + onboarding email** on the Users form. Zones/vehicle-type/force-login stay in Scout Users.
- Gating: visible only for Scout-enabled orgs to admins (mirror `ScoutUsersResource::canAccess()`).

## t1 — Gated Scout provisioning on UsersResource

**Files:** `UsersResource.php`, `Pages/CreateUsers.php`, `Pages/EditUsers.php`, `Concerns/ProvisionsScoutUser.php` (new), `tests/Filament/Resources/UsersResourceTest.php`.

**Form:** gated Scout `Section` with a `Toggle scout_access` + a `Select scout_kit_id` (options = kits whose `KitSettings.user_id` is null, plus the user's current kit on edit). Virtual/dehydrated; hydrate from current permission + KitSettings on edit. Add a gated Scout Access `IconColumn` to the table.

**Shared provisioner** (`ProvisionsScoutUser`), reused by both pages so it can't drift from `EditScoutUser`:
1. `setPermissionsTeamId($orgId)` (teams-based Spatie — required).
2. Enable: `assignRole(USER)` if roleless → `givePermissionTo('clarity-scout')` → `notify(new ScoutOnboardingNotification)` when `!email_verified_at`.
3. Disable: `revokePermissionTo('clarity-scout')`.
4. Kit: `KitSettings::updateOrCreate(['kit_id'=>$kitId],['user_id'=>$user->id])` with 1:1 detach (null this user off other kits); clear the tie when disabled / kit removed.

Wire in `CreateUsers::afterCreate` and the `EditUsers` save hook.

**Tests (TDD, write first):** section gating (visible Scout org / hidden non-Scout); create-with-Scout grants permission + ties kit + queues `ScoutOnboardingNotification`; edit add then remove flips permission + kit tie; existing (non-Scout) create/edit flows stay green.

**Test filter:** `UsersResource` (matches `UsersResourceTest` + `ScoutUsersResourceTest`).

## Out of scope / excluded
- No Scout role; no `RoleEnum`/seeder/migration change (permission already exists).
- Scout Users workflow, zones, vehicle type, force-login unchanged.

## Data / gates
- PostgreSQL + Spatie only (`permissions`, `model_has_permissions`, `roles`, `kit_settings`, `users`). No ClickHouse → skip CH provisioning; no perf gate.
- No sensitive-glob files → no pre-implement human-approval node. Security-relevant (permission grant) → 3 review lanes in Phase 5.
