<?php

namespace Tests\Filament\Resources;

use App\Filament\Resources\UsersResource\Pages\CreateUsers;
use App\Filament\Resources\UsersResource\Pages\EditUsers;
use App\Filament\Resources\UsersResource\Pages\ListUsers;
use App\Models\Kit;
use App\Models\KitSettings;
use App\Models\Role;
use App\Models\User;
use App\Notifications\ScoutOnboardingNotification;
use App\Support\Scout\ScoutUserProvisioner;
use Illuminate\Support\Facades\Notification;
use InsightLPR\BlackAdam\Enum\PermissionEnum;
use InsightLPR\BlackAdam\Enum\RoleEnum;
use Laravel\Pennant\Feature;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Group;
use Spatie\Permission\Models\Permission;
use Tests\ResourceTestCase;

class UsersResourceTest extends ResourceTestCase
{
    protected mixed $userPermissions = PermissionEnum::MANAGE_ACCOUNT_USERS->value;

    protected bool $pro = true;

    protected function setUp(): void
    {
        parent::setUp();

        Permission::findOrCreate('clarity-scout', 'web');
    }

    /**
     * Puts the acting user in a Scout-enabled org (Pro + explicit grant +
     * Admin role), mirroring ScoutUsersResourceTest's gating setup.
     */
    private function enableScoutForActingUser(): void
    {
        config()->set('app.experimental.clarity_scout', true);

        setPermissionsTeamId($this->user->org_id);
        $this->user->assignRole(RoleEnum::ADMIN->value);

        Feature::flushCache();
    }

    #[Group('resources')]
    #[Group('users')]
    public function test_can_render_table(): void
    {
        $users = User::factory()
            ->forOrg($this->user->org)
            ->count(3)
            ->create();

        Livewire::test(ListUsers::class)
            ->assertSuccessful()
            ->assertCanSeeTableRecords($users);
    }

    #[Group('resources')]
    #[Group('users')]
    public function test_cannot_render_without_permissions(): void
    {
        $this->actingAs(
            $this->createUser(permissions: [])
        );

        Livewire::test(ListUsers::class)
            ->assertForbidden();
    }

    #[Group('resources')]
    #[Group('users')]
    public function test_can_render_table_columns(): void
    {
        User::factory()
            ->forOrg($this->user->org)
            ->create();

        Livewire::test(ListUsers::class)
            ->assertSuccessful()
            ->assertCanRenderTableColumn('name')
            ->assertCanRenderTableColumn('email')
            ->assertCanRenderTableColumn('roles.name')
            ->assertCanRenderTableColumn('created_at');
    }

    #[Group('resources')]
    #[Group('users')]
    public function test_can_see_trashed(): void
    {
        $users = User::factory()
            ->forOrg($this->user->org)
            ->count(2)
            ->create();

        $trashedUser = User::factory()
            ->forOrg($this->user->org)
            ->create();

        $trashedUser->delete();

        Livewire::test(ListUsers::class)
            ->assertSuccessful()
            ->assertCanSeeTableRecords($users)
            ->assertCanNotSeeTableRecords([$trashedUser])
            ->assertTableFilterExists('trashed')
            ->assertTableFilterVisible('trashed')
            // With Trashed
            ->filterTable('trashed', 1)
            ->assertCanSeeTableRecords($users->merge([$trashedUser]))
            // Only Trashed
            ->filterTable('trashed', 0)
            ->assertCanSeeTableRecords([$trashedUser])
            ->assertCanNotSeeTableRecords($users)
            // Reset
            ->removeTableFilter('trashed')
            ->assertCanSeeTableRecords($users)
            ->assertCanNotSeeTableRecords([$trashedUser]);
    }

    #[Group('resources')]
    #[Group('users')]
    public function test_cannot_see_users_from_other_org(): void
    {
        $users = User::factory()
            ->count(2)
            ->create();

        Livewire::test(ListUsers::class)
            ->assertSuccessful()
            ->assertCanNotSeeTableRecords($users);
    }

    #[Group('resources')]
    #[Group('users')]
    public function test_can_create_user(): void
    {
        $email = fake()->email();
        $role = Role::query()
            ->where('name', RoleEnum::USER->value)
            ->first();

        Livewire::test(CreateUsers::class)
            ->assertSuccessful()
            ->fillForm([
                'name' => fake()->firstName().' '.fake()->lastName(),
                'email' => $email,
                'roles' => [$role->id],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $user = User::where('email', $email)->first();

        $this->assertTrue($user->hasRole($role->name));

        Livewire::test(ListUsers::class)
            ->assertSuccessful()
            ->assertCanSeeTableRecords([$user])
            ->assertSeeText($email);
    }

    #[Group('resources')]
    #[Group('users')]
    public function test_can_edit_user(): void
    {
        $this->user->assignRole(RoleEnum::ADMIN->value);

        $newEmail = fake()->email();

        $this->assertNotEquals($newEmail, $this->user->email);

        Livewire::test(EditUsers::class, ['record' => $this->user->id])
            ->assertSuccessful()
            ->fillForm([
                'email' => $newEmail,
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->user->refresh();

        $this->assertEquals($newEmail, $this->user->email);

        Livewire::test(ListUsers::class)
            ->assertSuccessful()
            ->assertCanSeeTableRecords([$this->user])
            ->assertSeeText($newEmail);
    }

    #[Group('resources')]
    #[Group('users')]
    public function test_cannot_edit_user_from_other_org(): void
    {
        $user = User::factory()->create();

        Livewire::test(EditUsers::class, ['record' => $user->id])
            ->assertForbidden();
    }

    #[Group('resources')]
    #[Group('users')]
    public function test_cannot_trigger_delete_on_self(): void
    {
        $user = User::factory()
            ->forOrg($this->user->org)
            ->create();

        Livewire::test(ListUsers::class)
            ->assertSuccessful()
            ->assertCanSeeTableRecords([$this->user, $user])
            ->assertTableActionVisible('delete', record: $user)
            ->assertTableActionHidden('delete', record: $this->user);
    }

    // ---- Scout provisioning (DEV-2237) -------------------------------------

    #[Group('resources')]
    #[Group('users')]
    #[Group('clarity-scout')]
    public function test_scout_section_visible_for_scout_enabled_org_admin(): void
    {
        $this->enableScoutForActingUser();

        Livewire::test(CreateUsers::class)
            ->assertSuccessful()
            ->assertFormFieldExists('scout_access')
            ->assertFormFieldExists('scout_kit_id');
    }

    #[Group('resources')]
    #[Group('users')]
    #[Group('clarity-scout')]
    public function test_scout_section_hidden_for_non_scout_org(): void
    {
        // No config('app.experimental.clarity_scout') grant + no Admin role:
        // the Scout section must not render at all.
        Livewire::test(CreateUsers::class)
            ->assertSuccessful()
            ->assertFormFieldDoesNotExist('scout_access')
            ->assertFormFieldDoesNotExist('scout_kit_id');
    }

    #[Group('resources')]
    #[Group('users')]
    #[Group('clarity-scout')]
    public function test_scout_section_hidden_without_admin_role_even_if_scout_enabled(): void
    {
        config()->set('app.experimental.clarity_scout', true);
        Feature::flushCache();

        Livewire::test(CreateUsers::class)
            ->assertSuccessful()
            ->assertFormFieldDoesNotExist('scout_access');
    }

    #[Group('resources')]
    #[Group('users')]
    #[Group('clarity-scout')]
    public function test_can_create_scout_user_with_kit_and_role(): void
    {
        $this->enableScoutForActingUser();
        Notification::fake();

        $kit = Kit::factory()->forOrg($this->user->org)->create();
        $email = fake()->email();
        $role = Role::query()->where('name', RoleEnum::USER->value)->first();

        Livewire::test(CreateUsers::class)
            ->assertSuccessful()
            ->fillForm([
                'name' => fake()->firstName().' '.fake()->lastName(),
                'email' => $email,
                'roles' => [$role->id],
                'scout_access' => true,
                'scout_kit_id' => $kit->id,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $user = User::where('email', $email)->first();

        setPermissionsTeamId($user->org_id);
        $this->assertTrue($user->hasPermissionTo('clarity-scout'));
        $this->assertTrue($user->hasRole($role->name));

        $this->assertSame($user->id, KitSettings::where('kit_id', $kit->id)->value('user_id'));

        Notification::assertSentTo($user, ScoutOnboardingNotification::class);
    }

    #[Group('resources')]
    #[Group('users')]
    public function test_create_form_does_not_pre_check_any_role_by_default(): void
    {
        Livewire::test(CreateUsers::class)
            ->assertSuccessful()
            ->assertFormSet(['roles' => []]);
    }

    #[Group('resources')]
    #[Group('users')]
    #[Group('clarity-scout')]
    public function test_enabling_scout_force_checks_the_default_user_role(): void
    {
        $this->enableScoutForActingUser();

        $defaultUserRoleId = (int) Role::query()->where('name', RoleEnum::USER->value)->value('id');

        Livewire::test(CreateUsers::class)
            ->assertSuccessful()
            ->assertFormSet(['roles' => []])
            ->fillForm(['scout_access' => true])
            ->assertFormSet(fn (array $state): bool => in_array($defaultUserRoleId, array_map('intval', $state['roles'] ?? []), true));
    }

    #[Group('resources')]
    #[Group('users')]
    #[Group('clarity-scout')]
    public function test_default_user_role_is_re_checked_if_removed_while_scout_is_on(): void
    {
        $this->enableScoutForActingUser();

        $defaultUserRoleId = (int) Role::query()->where('name', RoleEnum::USER->value)->value('id');
        $adminRoleId = (int) Role::query()->where('name', RoleEnum::ADMIN->value)->value('id');

        Livewire::test(CreateUsers::class)
            ->assertSuccessful()
            ->fillForm(['scout_access' => true])
            // Try to drop Default User (keep only Administrator) while Scout is on.
            ->fillForm(['roles' => [$adminRoleId]])
            // It is re-checked, so a Scout user always keeps the base role.
            ->assertFormSet(fn (array $state): bool => in_array($defaultUserRoleId, array_map('intval', $state['roles'] ?? []), true));
    }

    #[Group('resources')]
    #[Group('users')]
    #[Group('clarity-scout')]
    public function test_default_user_shows_required_for_scout_hint_only_when_scout_is_on(): void
    {
        $this->enableScoutForActingUser();

        Livewire::test(CreateUsers::class)
            ->assertSuccessful()
            ->assertDontSee('Required for Scout users.')
            ->fillForm(['scout_access' => true])
            ->assertSee('Required for Scout users.');
    }

    #[Group('resources')]
    #[Group('users')]
    #[Group('clarity-scout')]
    public function test_roles_are_not_forced_when_scout_is_off(): void
    {
        $this->enableScoutForActingUser();

        $defaultUserRoleId = (int) Role::query()->where('name', RoleEnum::USER->value)->value('id');
        $adminRoleId = (int) Role::query()->where('name', RoleEnum::ADMIN->value)->value('id');

        Livewire::test(CreateUsers::class)
            ->assertSuccessful()
            ->fillForm(['scout_access' => false, 'roles' => [$adminRoleId]])
            ->assertFormSet(fn (array $state): bool => ! in_array($defaultUserRoleId, array_map('intval', $state['roles'] ?? []), true));
    }

    #[Group('resources')]
    #[Group('users')]
    #[Group('clarity-scout')]
    public function test_editing_user_turns_scout_access_on_and_ties_kit(): void
    {
        $this->enableScoutForActingUser();
        Notification::fake();

        $kit = Kit::factory()->forOrg($this->user->org)->create();
        $user = User::factory()
            ->forOrg($this->user->org)
            ->unverified()
            ->create();

        setPermissionsTeamId($user->org_id);
        $user->assignRole(RoleEnum::USER->value);

        Livewire::test(EditUsers::class, ['record' => $user->id])
            ->assertSuccessful()
            ->fillForm([
                'scout_access' => true,
                'scout_kit_id' => $kit->id,
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $user->refresh();
        setPermissionsTeamId($user->org_id);

        $this->assertTrue($user->hasPermissionTo('clarity-scout'));
        $this->assertSame($user->id, KitSettings::where('kit_id', $kit->id)->value('user_id'));

        Notification::assertSentTo($user, ScoutOnboardingNotification::class);
    }

    #[Group('resources')]
    #[Group('users')]
    #[Group('clarity-scout')]
    public function test_editing_user_turns_scout_access_off_revokes_and_clears_kit_tie(): void
    {
        $this->enableScoutForActingUser();
        Notification::fake();

        $kit = Kit::factory()->forOrg($this->user->org)->create();
        $user = User::factory()
            ->forOrg($this->user->org)
            ->forKit($kit)
            ->create();

        setPermissionsTeamId($user->org_id);
        $user->assignRole(RoleEnum::USER->value);
        $user->givePermissionTo('clarity-scout');

        Livewire::test(EditUsers::class, ['record' => $user->id])
            ->assertSuccessful()
            ->fillForm([
                'scout_access' => false,
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $user->refresh();
        setPermissionsTeamId($user->org_id);

        $this->assertFalse($user->hasPermissionTo('clarity-scout'));
        $this->assertNull(KitSettings::where('kit_id', $kit->id)->value('user_id'));
    }

    #[Group('resources')]
    #[Group('users')]
    #[Group('clarity-scout')]
    public function test_cross_org_kit_submission_is_rejected_and_kit_settings_untouched(): void
    {
        $this->enableScoutForActingUser();
        Notification::fake();

        // Call the provisioner directly: Filament's dynamic Select `in:` rule already rejects a
        // foreign kit id at save(), so this pins the server-side re-validation independently.
        $foreignKit = Kit::factory()->create();
        $foreignUser = User::factory()
            ->forOrg($foreignKit->org)
            ->forKit($foreignKit)
            ->create();

        $user = User::factory()->forOrg($this->user->org)->create();

        ScoutUserProvisioner::reconcile($user, [
            'scout_access' => true,
            'scout_kit_id' => $foreignKit->id,
        ]);

        setPermissionsTeamId($user->org_id);
        $this->assertTrue($user->hasPermissionTo('clarity-scout'));

        // The foreign org's kit-user tie must be completely untouched.
        $this->assertSame($foreignUser->id, KitSettings::where('kit_id', $foreignKit->id)->value('user_id'));

        // The new org-A user must NOT have been tied to the foreign kit.
        $this->assertNull(KitSettings::where('user_id', $user->id)->value('kit_id'));
    }

    #[Group('resources')]
    #[Group('users')]
    #[Group('clarity-scout')]
    public function test_provisioning_no_ops_when_scout_access_guard_is_closed(): void
    {
        // No enableScoutForActingUser(): non-Scout org, no Admin role, so
        // ScoutUserProvisioner::canAccess() is false. This pins the server-side
        // guard directly against the provisioner, independent of whether Filament's
        // own schema dehydration would already strip the hidden fields.
        Notification::fake();

        $kit = Kit::factory()->forOrg($this->user->org)->create();
        $user = User::factory()->forOrg($this->user->org)->unverified()->create();

        $data = [
            'name' => 'Crafted',
            'scout_access' => true,
            'scout_kit_id' => $kit->id,
        ];

        $scoutData = ScoutUserProvisioner::extract($data);

        $this->assertSame([], $scoutData);
        $this->assertArrayNotHasKey('scout_access', $data);
        $this->assertArrayNotHasKey('scout_kit_id', $data);

        ScoutUserProvisioner::reconcile($user, [
            'scout_access' => true,
            'scout_kit_id' => $kit->id,
        ]);

        setPermissionsTeamId($user->org_id);
        $this->assertFalse($user->hasPermissionTo('clarity-scout'));
        $this->assertNull(KitSettings::where('kit_id', $kit->id)->value('user_id'));

        Notification::assertNothingSent();
    }

    #[Group('resources')]
    #[Group('users')]
    #[Group('clarity-scout')]
    public function test_editing_already_verified_user_grants_scout_without_notification(): void
    {
        $this->enableScoutForActingUser();
        Notification::fake();

        $user = User::factory()->forOrg($this->user->org)->create();
        $kit = Kit::factory()->forOrg($this->user->org)->create();

        setPermissionsTeamId($user->org_id);
        $user->assignRole(RoleEnum::USER->value);

        Livewire::test(EditUsers::class, ['record' => $user->id])
            ->assertSuccessful()
            ->fillForm([
                'scout_access' => true,
                'scout_kit_id' => $kit->id,
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $user->refresh();
        setPermissionsTeamId($user->org_id);

        $this->assertTrue($user->hasPermissionTo('clarity-scout'));

        Notification::assertNotSentTo($user, ScoutOnboardingNotification::class);
    }

    #[Group('resources')]
    #[Group('users')]
    #[Group('clarity-scout')]
    public function test_resaving_already_scout_user_does_not_resend_onboarding(): void
    {
        $this->enableScoutForActingUser();
        Notification::fake();

        $user = User::factory()
            ->forOrg($this->user->org)
            ->unverified()
            ->create();
        $kit = Kit::factory()->forOrg($this->user->org)->create();

        setPermissionsTeamId($user->org_id);
        $user->assignRole(RoleEnum::USER->value);
        $user->givePermissionTo('clarity-scout');

        Livewire::test(EditUsers::class, ['record' => $user->id])
            ->assertSuccessful()
            ->fillForm([
                'scout_access' => true,
                'scout_kit_id' => $kit->id,
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $user->refresh();
        setPermissionsTeamId($user->org_id);

        $this->assertTrue($user->hasPermissionTo('clarity-scout'));

        Notification::assertNothingSent();
    }

    #[Group('resources')]
    #[Group('users')]
    #[Group('clarity-scout')]
    public function test_scout_requires_a_kit_when_enabled(): void
    {
        $this->enableScoutForActingUser();
        Notification::fake();

        $email = fake()->email();
        $role = Role::query()->where('name', RoleEnum::USER->value)->first();

        Livewire::test(CreateUsers::class)
            ->assertSuccessful()
            ->fillForm([
                'name' => fake()->firstName().' '.fake()->lastName(),
                'email' => $email,
                'roles' => [$role->id],
                'scout_access' => true,
                // no scout_kit_id
            ])
            ->call('create')
            ->assertHasFormErrors(['scout_kit_id']);

        // Nothing was provisioned since the form rejected the save.
        $this->assertNull(User::where('email', $email)->first());
        Notification::assertNothingSent();
    }

    #[Group('resources')]
    #[Group('users')]
    #[Group('clarity-scout')]
    public function test_scout_access_combines_with_a_normal_role(): void
    {
        $this->enableScoutForActingUser();
        Notification::fake();

        $kit = Kit::factory()->forOrg($this->user->org)->create();
        $email = fake()->email();
        $role = Role::query()->where('name', RoleEnum::USER->value)->first();

        Livewire::test(CreateUsers::class)
            ->assertSuccessful()
            ->fillForm([
                'name' => fake()->firstName().' '.fake()->lastName(),
                'email' => $email,
                'roles' => [$role->id],
                'scout_access' => true,
                'scout_kit_id' => $kit->id,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $user = User::where('email', $email)->first();

        setPermissionsTeamId($user->org_id);
        $this->assertTrue($user->hasRole($role->name));
        $this->assertTrue($user->hasPermissionTo('clarity-scout'));
    }
}
