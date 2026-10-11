<?php

namespace Tests\Filament\Resources;

use App\Enums\FeatureEnum;
use App\Enums\VehicleTypeEnum;
use App\Filament\Resources\ScoutUsersResource;
use App\Filament\Resources\ScoutUsersResource\Pages\EditScoutUser;
use App\Filament\Resources\ScoutUsersResource\Pages\ListScoutUsers;
use App\Models\Kit;
use App\Models\KitSettings;
use App\Models\Org;
use App\Models\User;
use App\Notifications\ScoutOnboardingNotification;
use App\Notifications\ScoutPasswordResetNotification;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use InsightLPR\BlackAdam\Enum\RoleEnum;
use Laravel\Pennant\Feature;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Group;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Permission;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\ResourceTestCase;

class ScoutUsersResourceTest extends ResourceTestCase
{
    protected mixed $userRoles = RoleEnum::ADMIN->value;

    protected bool $pro = true;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('app.experimental.clarity_scout', true);
        Permission::findOrCreate('clarity-scout', 'web');
    }

    #[Group('resources')]
    #[Group('clarity-scout')]
    public function test_can_render_index(): void
    {
        $kit = Kit::factory()->forOrg($this->user->org)->create();

        Livewire::test(ListScoutUsers::class)
            ->assertSuccessful()
            ->assertCanSeeTableRecords([$kit]);
    }

    #[Group('resources')]
    #[Group('clarity-scout')]
    public function test_index_excludes_soft_deleted_kits(): void
    {
        // Mirrors the Scout org map: a soft-deleted/deactivated kit
        // (kit_metadata.deleted_at) must not appear in the admin list either.
        $active = Kit::factory()->forOrg($this->user->org)->create();
        $deleted = Kit::factory()->forOrg($this->user->org)->create();
        DB::connection('zod')->table('kit_metadata')->insert([
            'kit_id' => $deleted->id,
            'deleted_at' => now(),
        ]);

        Livewire::test(ListScoutUsers::class)
            ->assertSuccessful()
            ->assertCanSeeTableRecords([$active])
            ->assertCanNotSeeTableRecords([$deleted]);
    }

    #[Group('resources')]
    #[Group('clarity-scout')]
    public function test_creating_new_user_sends_onboarding_email(): void
    {
        Notification::fake();

        $kit = Kit::factory()->forOrg($this->user->org)->create([
            'text_phone' => '+15551234567',
        ]);

        Livewire::test(EditScoutUser::class, ['record' => $kit->id])
            ->set('data.create_new_user', true)
            ->set('data.new_user_email', 'newscanner@example.com')
            ->set('data.mobile_phone', '+15559998888')
            ->set('data.web_access_enabled', true)
            ->call('save')
            ->assertHasNoFormErrors();

        $newUser = User::where('email', 'newscanner@example.com')->first();

        $this->assertNotNull($newUser);
        $this->assertSame('15559998888', $newUser->mobile_phone);
        $this->assertSame($this->user->org_id, $newUser->org_id);

        $this->assertDatabaseHas('kit_settings', [
            'kit_id' => $kit->id,
            'user_id' => $newUser->id,
        ]);

        setPermissionsTeamId($newUser->org_id);
        $this->assertTrue($newUser->hasPermissionTo('clarity-scout'));

        Notification::assertSentTo($newUser, ScoutOnboardingNotification::class);
    }

    #[Group('resources')]
    #[Group('clarity-scout')]
    public function test_creating_new_user_without_scout_access_does_not_send_email(): void
    {
        Notification::fake();

        $kit = Kit::factory()->forOrg($this->user->org)->create();

        Livewire::test(EditScoutUser::class, ['record' => $kit->id])
            ->set('data.create_new_user', true)
            ->set('data.new_user_email', 'noaccess@example.com')
            ->set('data.mobile_phone', '+15559998888')
            ->set('data.web_access_enabled', false)
            ->call('save')
            ->assertHasNoFormErrors();

        $newUser = User::where('email', 'noaccess@example.com')->first();

        $this->assertNotNull($newUser);

        setPermissionsTeamId($newUser->org_id);
        $this->assertFalse($newUser->hasPermissionTo('clarity-scout'));

        Notification::assertNothingSent();
    }

    #[Group('resources')]
    #[Group('clarity-scout')]
    public function test_phone_is_normalized_on_save(): void
    {
        Notification::fake();

        $kit = Kit::factory()->forOrg($this->user->org)->create();

        Livewire::test(EditScoutUser::class, ['record' => $kit->id])
            ->set('data.create_new_user', true)
            ->set('data.new_user_email', 'normalized@example.com')
            ->set('data.mobile_phone', '(555) 999-8888')
            ->set('data.web_access_enabled', true)
            ->call('save')
            ->assertHasNoFormErrors();

        $newUser = User::where('email', 'normalized@example.com')->first();

        $this->assertNotNull($newUser);
        $this->assertSame('5559998888', $newUser->mobile_phone);

        Notification::assertSentTo($newUser, ScoutOnboardingNotification::class);
    }

    #[Group('resources')]
    #[Group('clarity-scout')]
    public function test_creating_new_user_requires_mobile_phone(): void
    {
        Notification::fake();

        $kit = Kit::factory()->forOrg($this->user->org)->create([
            'text_phone' => null,
        ]);

        Livewire::test(EditScoutUser::class, ['record' => $kit->id])
            ->set('data.create_new_user', true)
            ->set('data.new_user_email', 'newscanner@example.com')
            ->set('data.mobile_phone', null)
            ->call('save')
            ->assertHasFormErrors(['mobile_phone']);

        $this->assertDatabaseMissing('users', ['email' => 'newscanner@example.com']);
        Notification::assertNothingSent();
    }

    #[Group('resources')]
    #[Group('clarity-scout')]
    public function test_attaching_existing_unverified_user_sends_onboarding_email(): void
    {
        Notification::fake();

        $kit = Kit::factory()->forOrg($this->user->org)->create();

        $existingUser = User::factory()
            ->forOrg($this->user->org)
            ->unverified()
            ->create([
                'mobile_phone' => '+15553334444',
            ]);

        Livewire::test(EditScoutUser::class, ['record' => $kit->id])
            ->set('data.create_new_user', false)
            ->set('data.assigned_user_id', $existingUser->id)
            ->set('data.web_access_enabled', true)
            ->call('save')
            ->assertHasNoFormErrors();

        Notification::assertSentTo($existingUser, ScoutOnboardingNotification::class);
    }

    #[Group('resources')]
    #[Group('clarity-scout')]
    public function test_attaching_existing_verified_user_does_not_send_onboarding_email(): void
    {
        Notification::fake();

        $kit = Kit::factory()->forOrg($this->user->org)->create();

        $existingUser = User::factory()
            ->forOrg($this->user->org)
            ->create([
                'mobile_phone' => '+15553334444',
                'email_verified_at' => now(),
            ]);

        Livewire::test(EditScoutUser::class, ['record' => $kit->id])
            ->set('data.create_new_user', false)
            ->set('data.assigned_user_id', $existingUser->id)
            ->set('data.web_access_enabled', true)
            ->call('save')
            ->assertHasNoFormErrors();

        Notification::assertNotSentTo($existingUser, ScoutOnboardingNotification::class);
    }

    #[Group('resources')]
    #[Group('clarity-scout')]
    public function test_attaching_existing_user_without_phone_fails_validation(): void
    {
        Notification::fake();

        $kit = Kit::factory()->forOrg($this->user->org)->create();

        $existingUser = User::factory()
            ->forOrg($this->user->org)
            ->create([
                'mobile_phone' => null,
            ]);

        Livewire::test(EditScoutUser::class, ['record' => $kit->id])
            ->set('data.create_new_user', false)
            ->set('data.assigned_user_id', $existingUser->id)
            ->set('data.existing_user_email', $existingUser->email)
            ->set('data.mobile_phone', null)
            ->set('data.web_access_enabled', true)
            ->call('save')
            ->assertHasFormErrors(['mobile_phone']);

        $this->assertDatabaseMissing('kit_settings', [
            'kit_id' => $kit->id,
            'user_id' => $existingUser->id,
        ]);

        Notification::assertNothingSent();
    }

    #[Group('resources')]
    #[Group('clarity-scout')]
    public function test_attaching_existing_user_persists_inline_email_and_phone_edits(): void
    {
        Notification::fake();

        $kit = Kit::factory()->forOrg($this->user->org)->create();

        $existingUser = User::factory()
            ->forOrg($this->user->org)
            ->create([
                'email' => 'stale@example.com',
                'mobile_phone' => '+15550000000',
                'email_verified_at' => now(),
            ]);

        Livewire::test(EditScoutUser::class, ['record' => $kit->id])
            ->set('data.create_new_user', false)
            ->set('data.assigned_user_id', $existingUser->id)
            ->set('data.existing_user_email', 'fresh@example.com')
            ->set('data.mobile_phone', '+15557776666')
            ->set('data.web_access_enabled', true)
            ->call('save')
            ->assertHasNoFormErrors();

        $existingUser->refresh();

        $this->assertSame('fresh@example.com', $existingUser->email);
        $this->assertSame('15557776666', $existingUser->mobile_phone);

        $this->assertDatabaseHas('kit_settings', [
            'kit_id' => $kit->id,
            'user_id' => $existingUser->id,
        ]);
    }

    #[Group('resources')]
    #[Group('clarity-scout')]
    public function test_assigned_user_select_renders_when_org_has_user_with_null_name(): void
    {
        $kit = Kit::factory()->forOrg($this->user->org)->create();

        User::factory()
            ->forOrg($this->user->org)
            ->create([
                'name' => null,
                'email' => 'no-name@example.com',
                'mobile_phone' => '+15553334444',
            ]);

        Livewire::test(EditScoutUser::class, ['record' => $kit->id])
            ->set('data.create_new_user', false)
            ->assertSuccessful();
    }

    #[Group('resources')]
    #[Group('clarity-scout')]
    public function test_revoking_portal_access_does_not_send_email(): void
    {
        Notification::fake();

        $kit = Kit::factory()->forOrg($this->user->org)->create();
        $existingUser = User::factory()->forOrg($this->user->org)->create([
            'mobile_phone' => '+15553334444',
        ]);
        KitSettings::updateOrCreate(['kit_id' => $kit->id], ['user_id' => $existingUser->id]);

        setPermissionsTeamId($existingUser->org_id);
        $existingUser->givePermissionTo('clarity-scout');

        Livewire::test(EditScoutUser::class, ['record' => $kit->id])
            ->set('data.create_new_user', false)
            ->set('data.assigned_user_id', $existingUser->id)
            ->set('data.web_access_enabled', false)
            ->call('save')
            ->assertHasNoFormErrors();

        Notification::assertNothingSent();

        $existingUser->refresh();
        setPermissionsTeamId($existingUser->org_id);
        $this->assertFalse($existingUser->hasPermissionTo('clarity-scout'));
    }

    #[Group('resources')]
    #[Group('clarity-scout')]
    public function test_vehicle_type_saves_to_kit_settings(): void
    {
        $kit = Kit::factory()->forOrg($this->user->org)->create();

        Livewire::test(EditScoutUser::class, ['record' => $kit->id])
            ->set('data.create_new_user', false)
            ->set('data.vehicle_type', VehicleTypeEnum::RepoTruck->value)
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('kit_settings', [
            'kit_id' => $kit->id,
            'vehicle_type' => VehicleTypeEnum::RepoTruck->value,
        ]);

        $this->assertSame(VehicleTypeEnum::RepoTruck, $kit->fresh()->vehicle_type);
    }

    #[Group('resources')]
    #[Group('clarity-scout')]
    public function test_create_and_delete_are_disabled(): void
    {
        $kit = Kit::factory()->forOrg($this->user->org)->create();

        $this->assertFalse(ScoutUsersResource::canCreate());
        $this->assertFalse(ScoutUsersResource::canDelete($kit));
    }

    #[Group('resources')]
    #[Group('clarity-scout')]
    public function test_table_is_scoped_to_the_acting_org(): void
    {
        $ownKit = Kit::factory()->forOrg($this->user->org)->create();
        $otherKit = Kit::factory()->forOrg(Org::factory()->create())->create();

        Livewire::test(ListScoutUsers::class)
            ->assertCanSeeTableRecords([$ownKit])
            ->assertCanNotSeeTableRecords([$otherKit]);
    }

    #[Group('resources')]
    #[Group('clarity-scout')]
    public function test_duplicate_email_is_blocked_for_a_new_user(): void
    {
        Notification::fake();

        User::factory()->forOrg($this->user->org)->create(['email' => 'taken@example.com']);
        $kit = Kit::factory()->forOrg($this->user->org)->create();

        Livewire::test(EditScoutUser::class, ['record' => $kit->id])
            ->set('data.create_new_user', true)
            ->set('data.new_user_email', 'taken@example.com')
            ->set('data.mobile_phone', '+15559998888')
            ->set('data.web_access_enabled', true)
            ->call('save')
            ->assertHasFormErrors(['new_user_email']);

        Notification::assertNothingSent();
    }

    #[Group('resources')]
    #[Group('clarity-scout')]
    public function test_duplicate_email_is_blocked_for_an_existing_user(): void
    {
        User::factory()->forOrg($this->user->org)->create(['email' => 'collision@example.com']);

        $kit = Kit::factory()->forOrg($this->user->org)->create();
        $existingUser = User::factory()->forOrg($this->user->org)->create([
            'mobile_phone' => '+15553334444',
        ]);

        Livewire::test(EditScoutUser::class, ['record' => $kit->id])
            ->set('data.create_new_user', false)
            ->set('data.assigned_user_id', $existingUser->id)
            ->set('data.existing_user_email', 'collision@example.com')
            ->set('data.mobile_phone', '+15553334444')
            ->set('data.web_access_enabled', true)
            ->call('save')
            ->assertHasFormErrors(['existing_user_email']);
    }

    #[Group('resources')]
    #[Group('clarity-scout')]
    public function test_toggle_web_access_grants_then_revokes_permission(): void
    {
        $kit = Kit::factory()->forOrg($this->user->org)->create();
        $driver = User::factory()->forOrg($this->user->org)->forKit($kit)->create([
            'email' => 'driver@example.com',
        ]);

        setPermissionsTeamId($driver->org_id);
        $this->assertFalse($driver->hasPermissionTo('clarity-scout'));

        Livewire::test(ListScoutUsers::class)
            ->callTableAction('toggle_web_access', $kit);

        $this->assertTrue($driver->fresh()->hasPermissionTo('clarity-scout'));

        Livewire::test(ListScoutUsers::class)
            ->callTableAction('toggle_web_access', $kit);

        $this->assertFalse($driver->fresh()->hasPermissionTo('clarity-scout'));
    }

    #[Group('resources')]
    #[Group('clarity-scout')]
    public function test_toggle_web_access_writes_an_audit_row(): void
    {
        $kit = Kit::factory()->forOrg($this->user->org)->create();
        $driver = User::factory()->forOrg($this->user->org)->forKit($kit)->create([
            'email' => 'driver@example.com',
        ]);

        Livewire::test(ListScoutUsers::class)
            ->callTableAction('toggle_web_access', $kit);

        $granted = Activity::query()
            ->where('log_name', 'scout-user')
            ->where('description', 'Scout web access granted')
            ->latest('id')
            ->first();

        $this->assertNotNull($granted);
        $this->assertSame('grant', $granted->properties['action'] ?? null);
        $this->assertSame($kit->id, $granted->properties['kit_id'] ?? null);
        $this->assertSame($this->user->id, $granted->causer_id);

        Livewire::test(ListScoutUsers::class)
            ->callTableAction('toggle_web_access', $kit);

        $this->assertDatabaseHas('activity_log', [
            'log_name' => 'scout-user',
            'description' => 'Scout web access revoked',
        ]);
    }

    #[Group('resources')]
    #[Group('clarity-scout')]
    public function test_assigning_a_scout_user_writes_an_audit_row(): void
    {
        Notification::fake();

        $kit = Kit::factory()->forOrg($this->user->org)->create();
        $existingUser = User::factory()->forOrg($this->user->org)->create([
            'mobile_phone' => '+15553334444',
            'email_verified_at' => now(),
        ]);

        Livewire::test(EditScoutUser::class, ['record' => $kit->id])
            ->set('data.create_new_user', false)
            ->set('data.assigned_user_id', $existingUser->id)
            ->set('data.web_access_enabled', true)
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('activity_log', [
            'log_name' => 'scout-user',
            'description' => 'Scout user assigned',
            'subject_id' => $kit->id,
        ]);
    }

    #[Group('resources')]
    #[Group('clarity-scout')]
    public function test_toggle_web_access_sends_onboarding_email_to_unverified_user(): void
    {
        Notification::fake();

        $kit = Kit::factory()->forOrg($this->user->org)->create();
        $driver = User::factory()->forOrg($this->user->org)->forKit($kit)->unverified()->create([
            'email' => 'driver@example.com',
        ]);

        Livewire::test(ListScoutUsers::class)
            ->callTableAction('toggle_web_access', $kit);

        setPermissionsTeamId($driver->org_id);
        $this->assertTrue($driver->fresh()->hasPermissionTo('clarity-scout'));
        Notification::assertSentTo($driver, ScoutOnboardingNotification::class);
    }

    #[Group('resources')]
    #[Group('clarity-scout')]
    public function test_resend_onboarding_sends_email_for_unverified_scout_user(): void
    {
        Notification::fake();

        $kit = Kit::factory()->forOrg($this->user->org)->create();
        $driver = User::factory()->forOrg($this->user->org)->forKit($kit)->unverified()->create([
            'email' => 'driver@example.com',
        ]);

        setPermissionsTeamId($driver->org_id);
        $driver->givePermissionTo('clarity-scout');

        Livewire::test(ListScoutUsers::class)
            ->callTableAction('resend_onboarding', $kit)
            ->assertNotified('Onboarding email resent to driver@example.com');

        Notification::assertSentTo($driver, ScoutOnboardingNotification::class);

        $this->assertDatabaseHas('activity_log', [
            'log_name' => 'scout-user',
            'description' => 'Scout onboarding email resent',
            'subject_id' => $driver->id,
        ]);
    }

    #[Group('resources')]
    #[Group('clarity-scout')]
    public function test_resend_onboarding_is_hidden_for_verified_users(): void
    {
        $kit = Kit::factory()->forOrg($this->user->org)->create();
        $driver = User::factory()->forOrg($this->user->org)->forKit($kit)->create([
            'email' => 'verified@example.com',
            'email_verified_at' => now(),
        ]);

        setPermissionsTeamId($driver->org_id);
        $driver->givePermissionTo('clarity-scout');

        Livewire::test(ListScoutUsers::class)
            ->assertTableActionHidden('resend_onboarding', $kit);
    }

    #[Group('resources')]
    #[Group('clarity-scout')]
    public function test_password_reset_sends_email_for_verified_scout_user(): void
    {
        Notification::fake();

        $kit = Kit::factory()->forOrg($this->user->org)->create();
        $driver = User::factory()->forOrg($this->user->org)->forKit($kit)->create([
            'email' => 'verified@example.com',
            'email_verified_at' => now(),
        ]);

        setPermissionsTeamId($driver->org_id);
        $driver->givePermissionTo('clarity-scout');

        Livewire::test(ListScoutUsers::class)
            ->callTableAction('password_reset', $kit)
            ->assertNotified('Password reset email sent to verified@example.com');

        Notification::assertSentTo($driver, ScoutPasswordResetNotification::class);

        $this->assertDatabaseHas('activity_log', [
            'log_name' => 'scout-user',
            'description' => 'Scout password reset email sent',
            'subject_id' => $driver->id,
        ]);
    }

    #[Group('resources')]
    #[Group('clarity-scout')]
    public function test_password_reset_is_hidden_for_unverified_users(): void
    {
        $kit = Kit::factory()->forOrg($this->user->org)->create();
        $driver = User::factory()->forOrg($this->user->org)->forKit($kit)->unverified()->create([
            'email' => 'unverified@example.com',
        ]);

        setPermissionsTeamId($driver->org_id);
        $driver->givePermissionTo('clarity-scout');

        Livewire::test(ListScoutUsers::class)
            ->assertTableActionHidden('password_reset', $kit);
    }

    #[Group('resources')]
    #[Group('clarity-scout')]
    public function test_toggle_web_access_does_not_email_an_already_verified_user(): void
    {
        Notification::fake();

        $kit = Kit::factory()->forOrg($this->user->org)->create();
        $driver = User::factory()->forOrg($this->user->org)->forKit($kit)->create([
            'email' => 'verified@example.com',
            'email_verified_at' => now(),
        ]);

        Livewire::test(ListScoutUsers::class)
            ->callTableAction('toggle_web_access', $kit);

        setPermissionsTeamId($driver->org_id);
        $this->assertTrue($driver->fresh()->hasPermissionTo('clarity-scout'));
        Notification::assertNotSentTo($driver, ScoutOnboardingNotification::class);
    }

    #[Group('resources')]
    #[Group('clarity-scout')]
    public function test_toggle_web_access_requires_an_email(): void
    {
        $kit = Kit::factory()->forOrg($this->user->org)->create();
        $driver = User::factory()->forOrg($this->user->org)->forKit($kit)->create([
            'email' => null,
        ]);

        Livewire::test(ListScoutUsers::class)
            ->callTableAction('toggle_web_access', $kit)
            ->assertNotified('Email Required');

        setPermissionsTeamId($driver->org_id);
        $this->assertFalse($driver->fresh()->hasPermissionTo('clarity-scout'));
    }

    #[Group('resources')]
    #[Group('clarity-scout')]
    public function test_force_login_action_is_hidden_without_an_impersonator_session(): void
    {
        $kit = Kit::factory()->forOrg($this->user->org)->create();
        User::factory()->forOrg($this->user->org)->forKit($kit)->create();

        Livewire::test(ListScoutUsers::class)
            ->assertTableActionHidden('force_login_portal', $kit);
    }

    #[Group('resources')]
    #[Group('clarity-scout')]
    public function test_force_login_action_is_visible_while_impersonating_with_an_assigned_user(): void
    {
        session()->put('admin_impersonator', $this->user->id);

        $kit = Kit::factory()->forOrg($this->user->org)->create();
        User::factory()->forOrg($this->user->org)->forKit($kit)->create();

        Livewire::test(ListScoutUsers::class)
            ->assertTableActionVisible('force_login_portal', $kit);
    }

    #[Group('resources')]
    #[Group('clarity-scout')]
    public function test_assigned_zones_section_is_visible_only_when_kit_has_a_user(): void
    {
        // Kit with an assigned user -> "Assigned Zones" section renders.
        $assignedKit = Kit::factory()->forOrg($this->user->org)->create();
        User::factory()->forOrg($this->user->org)->forKit($assignedKit)->create();

        Livewire::test(EditScoutUser::class, ['record' => $assignedKit->id])
            ->assertSuccessful()
            ->assertSeeText('Assigned Zones');

        // Unassigned kit -> section is hidden.
        $unassignedKit = Kit::factory()->forOrg($this->user->org)->create();

        Livewire::test(EditScoutUser::class, ['record' => $unassignedKit->id])
            ->assertSuccessful()
            ->assertDontSeeText('Assigned Zones');
    }

    #[Group('resources')]
    #[Group('clarity-scout')]
    public function test_scout_access_filter_separates_enabled_and_disabled(): void
    {
        $enabledKit = Kit::factory()->forOrg($this->user->org)->create();
        $enabledDriver = User::factory()->forOrg($this->user->org)->forKit($enabledKit)->create([
            'email' => 'enabled@example.com',
        ]);
        setPermissionsTeamId($enabledDriver->org_id);
        $enabledDriver->givePermissionTo('clarity-scout');

        $disabledKit = Kit::factory()->forOrg($this->user->org)->create();
        User::factory()->forOrg($this->user->org)->forKit($disabledKit)->create([
            'email' => 'disabled@example.com',
        ]);

        Livewire::test(ListScoutUsers::class)
            ->filterTable('filters', ['web_access' => 'enabled'])
            ->assertCanSeeTableRecords([$enabledKit])
            ->assertCanNotSeeTableRecords([$disabledKit]);

        Livewire::test(ListScoutUsers::class)
            ->filterTable('filters', ['web_access' => 'disabled'])
            ->assertCanSeeTableRecords([$disabledKit])
            ->assertCanNotSeeTableRecords([$enabledKit]);
    }

    #[Group('resources')]
    #[Group('clarity-scout')]
    public function test_cross_org_kit_cannot_be_edited_via_direct_url(): void
    {
        // A kit belonging to a different org should not be reachable through
        // the resource's edit page — the table query is org-scoped, but a
        // hand-crafted URL must also resolve to a 404.
        $otherKit = Kit::factory()->forOrg(Org::factory()->create())->create();

        try {
            Livewire::test(EditScoutUser::class, ['record' => $otherKit->id]);
            $this->fail('Expected cross-org kit access to be blocked, but EditScoutUser mounted successfully.');
        } catch (ModelNotFoundException) {
            // expected
        } catch (NotFoundHttpException) {
            // also acceptable
        } catch (AuthorizationException) {
            // also acceptable
        }
    }

    // ---- Gating + navigation (DEV-2115) ------------------------------------

    #[Group('resources')]
    #[Group('clarity-scout')]
    public function test_access_requires_the_clarity_scout_flag(): void
    {
        $this->assertTrue(ScoutUsersResource::canAccess());

        config()->set('app.experimental.clarity_scout', false);
        Feature::flushCache();

        $this->assertFalse(ScoutUsersResource::canAccess());
    }

    #[Group('resources')]
    #[Group('clarity-scout')]
    public function test_admin_impersonator_can_access_without_the_clarity_scout_flag(): void
    {
        config()->set('app.experimental.clarity_scout', false);
        Feature::flushCache();

        $this->assertFalse(ScoutUsersResource::canAccess());

        session()->put('admin_impersonator', $this->user->id);

        $this->assertTrue(ScoutUsersResource::canAccess());
    }

    #[Group('resources')]
    #[Group('clarity-scout')]
    public function test_pro_tier_without_an_explicit_scout_grant_cannot_access(): void
    {
        // Scout Users is gated identically to the rest of Scout (DEV-2115): an
        // admin in a Clarity-Pro org with no explicit clarity_scout grant is denied.
        $org = Org::factory()->create();
        $this->makeOrgPro($org, FeatureEnum::FLEX_SEARCH->value);

        $admin = User::factory()->forOrg($org)->create();
        setPermissionsTeamId($admin->org_id);
        $admin->assignRole(RoleEnum::ADMIN->value);

        $this->actingAs($admin);
        Feature::flushCache();

        $this->assertFalse(ScoutUsersResource::canAccess());
    }

    #[Group('resources')]
    #[Group('clarity-scout')]
    public function test_lives_under_the_scout_navigation_group(): void
    {
        $this->assertSame('Scout', ScoutUsersResource::getNavigationGroup());
    }

    #[Group('resources')]
    #[Group('clarity-scout')]
    public function test_index_renders_rows_with_assigned_users_eager_loaded(): void
    {
        $org = $this->user->org;

        $kitA = Kit::factory()->forOrg($org)->create();
        User::factory()->forOrg($org)->forKit($kitA)->create();

        $kitB = Kit::factory()->forOrg($org)->create();
        User::factory()->forOrg($org)->forKit($kitB)->create();

        Livewire::test(ListScoutUsers::class)
            ->assertSuccessful()
            ->assertCanSeeTableRecords([$kitA, $kitB]);
    }

    #[Group('resources')]
    #[Group('clarity-scout')]
    public function test_soft_deleted_user_email_collision_names_user_and_restore_action(): void
    {
        $deleted = User::factory()->forOrg($this->user->org)->create([
            'name' => 'Deleted Driver',
            'email' => 'gone@example.com',
        ]);
        $deleted->delete();

        $kit = Kit::factory()->forOrg($this->user->org)->create();

        $component = Livewire::test(EditScoutUser::class, ['record' => $kit->id])
            ->set('data.create_new_user', true)
            ->set('data.new_user_email', 'gone@example.com')
            ->set('data.mobile_phone', '+15551234567')
            ->set('data.web_access_enabled', true)
            ->call('save')
            ->assertHasFormErrors(['new_user_email']);

        $errors = $component->errors()->get('data.new_user_email') ?? [];

        $this->assertNotEmpty($errors);
        $joined = implode(' ', $errors);
        $this->assertStringContainsString('Deleted Driver', $joined);
        $this->assertStringContainsString('deleted', $joined);
        $this->assertStringContainsString('Restore', $joined);
    }

    #[Group('resources')]
    #[Group('clarity-scout')]
    public function test_soft_deleted_driver_row_uses_deleted_background_wash(): void
    {
        $kit = Kit::factory()->forOrg($this->user->org)->create();
        $driver = User::factory()->forOrg($this->user->org)->forKit($kit)->create([
            'email' => 'wash-deleted@example.com',
        ]);
        $driver->delete();

        Livewire::test(ListScoutUsers::class)
            ->assertSuccessful()
            ->assertCanSeeTableRecords([$kit])
            ->assertSee('!bg-red-200/20', false)
            ->assertSee('dark:!bg-red-900/20', false);
    }

    #[Group('resources')]
    #[Group('clarity-scout')]
    public function test_restore_scout_user_restores_soft_deleted_driver_and_grants_access(): void
    {
        Notification::fake();

        $kit = Kit::factory()->forOrg($this->user->org)->create();
        $driver = User::factory()->forOrg($this->user->org)->forKit($kit)->unverified()->create([
            'name' => 'Soft Deleted',
            'email' => 'softdeleted@example.com',
        ]);

        setPermissionsTeamId($driver->org_id);
        $driver->givePermissionTo('clarity-scout');
        $driver->delete();

        $this->assertTrue($driver->fresh()->trashed());
        $this->assertNotNull(ScoutUsersResource::trashedAssignedUser($kit->fresh()->load('settings')));

        Livewire::test(ListScoutUsers::class)
            ->callTableAction('restore_scout_user', $kit)
            ->assertNotified();

        $restored = $driver->fresh();
        $this->assertFalse($restored->trashed());
        setPermissionsTeamId($restored->org_id);
        $this->assertTrue($restored->hasPermissionTo('clarity-scout'));
        Notification::assertSentTo($restored, ScoutOnboardingNotification::class);
    }

    #[Group('resources')]
    #[Group('clarity-scout')]
    public function test_resend_onboarding_sends_invite_email(): void
    {
        Notification::fake();

        $kit = Kit::factory()->forOrg($this->user->org)->create();
        $driver = User::factory()->forOrg($this->user->org)->forKit($kit)->unverified()->create([
            'email' => 'driver@example.com',
        ]);

        setPermissionsTeamId($driver->org_id);
        $driver->givePermissionTo('clarity-scout');

        Livewire::test(ListScoutUsers::class)
            ->mountTableAction('resend_onboarding', $kit)
            ->assertMountedActionModalSee('The previous link will stop working once they complete onboarding')
            ->assertMountedActionModalSee('expires (3 days)')
            ->callMountedTableAction();

        Notification::assertSentTo($driver, ScoutOnboardingNotification::class);
    }

    #[Group('resources')]
    #[Group('clarity-scout')]
    public function test_edit_with_soft_deleted_driver_can_change_vehicle_type_without_losing_link(): void
    {
        $kit = Kit::factory()->forOrg($this->user->org)->create();
        $driver = User::factory()->forOrg($this->user->org)->forKit($kit)->create([
            'email' => 'deleted-driver@example.com',
        ]);
        $driver->delete();

        KitSettings::where('kit_id', $kit->id)->update([
            'user_id' => $driver->id,
            'vehicle_type' => VehicleTypeEnum::ScanningCar->value,
        ]);

        Livewire::test(EditScoutUser::class, ['record' => $kit->id])
            ->assertSet('data.assigned_user_id', null)
            ->set('data.vehicle_type', VehicleTypeEnum::RepoTruck->value)
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('kit_settings', [
            'kit_id' => $kit->id,
            'user_id' => $driver->id,
            'vehicle_type' => VehicleTypeEnum::RepoTruck->value,
        ]);
        $this->assertNotNull(ScoutUsersResource::trashedAssignedUser($kit->fresh()->load('settings')));
    }
}
