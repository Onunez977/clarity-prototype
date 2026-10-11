<?php

namespace Tests\Filament\Auth;

use App\Filament\Auth\CustomLoginPage;
use App\Filament\Auth\OneTimePassword;
use App\Filament\Client\Resources\ClientSearchScansResource;
use App\Filament\Pages\Dashboard;
use App\Models\ClientOrgAccess;
use App\Models\Org;
use App\Notifications\OneTimePasswordNotification;
use Illuminate\Support\Facades\Notification;
use InsightLPR\BlackAdam\Enum\RoleEnum;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Group;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class CustomLoginPageTest extends TestCase
{
    /**
     * Force the application environment to production for the duration of
     * the callback, restoring the original environment afterwards. The OTP
     * flow (DEV-2521) is only exercised in production, so tests that assert
     * on that flow specifically must opt into it explicitly.
     */
    private function withProductionEnvironment(callable $callback): void
    {
        $original_container_env = app()['env'];
        $original_config_env = config('app.env');

        app()['env'] = 'production';
        config(['app.env' => 'production']);

        try {
            $this->assertTrue(
                app()->isProduction(),
                'withProductionEnvironment() did not flip the signal CustomLoginPage guards on.',
            );

            $callback();
        } finally {
            app()['env'] = $original_container_env;
            config(['app.env' => $original_config_env]);
        }
    }

    #[Group('login')]
    public function test_login_page_displays_correctly(): void
    {
        $this->get(route('filament.app.auth.login'))
            ->assertStatus(200)
            ->assertSee('Login');
    }

    #[Group('login')]
    public function test_sends_otp(): void
    {
        $this->withProductionEnvironment(function () {
            Notification::fake();

            $user = $this->createUser(['email' => 'test@example.com']);

            Livewire::test(CustomLoginPage::class)
                ->set('data.email', 'test@example.com')
                ->call('authenticate')
                ->assertSuccessful()
                ->assertSee('Please enter the One Time Password sent to your email');

            Notification::assertSentTo($user, OneTimePasswordNotification::class);
        });
    }

    #[Group('login')]
    public function test_client_org_user_with_active_access_grant_can_request_otp_without_client_role(): void
    {
        $this->withProductionEnvironment(function () {
            Notification::fake();

            $user = $this->createClientPortalUser(['email' => 'client@example.com']);

            Livewire::test(CustomLoginPage::class)
                ->set('data.email', 'client@example.com')
                ->call('authenticate')
                ->assertSuccessful()
                ->assertSet('otp_sent', true)
                ->assertSee('Please enter the One Time Password sent to your email');

            $this->assertFalse($user->hasRole(RoleEnum::CLIENT->value));
            Notification::assertSentTo($user, OneTimePasswordNotification::class);
        });
    }

    #[Group('login')]
    public function test_client_org_user_can_authenticate_with_otp_without_client_role(): void
    {
        $this->withProductionEnvironment(function () {
            Notification::fake();

            $user = $this->createClientPortalUser(['email' => 'client@example.com']);

            OneTimePassword::generate($user);

            Livewire::test(CustomLoginPage::class)
                ->set('data.email', 'client@example.com')
                ->set('otp_sent', true)
                ->set('data.otp', OneTimePassword::get($user)['otp'])
                ->call('authenticate')
                ->assertRedirect(ClientSearchScansResource::getUrl(panel: 'client'));

            $this->assertFalse($user->hasRole(RoleEnum::CLIENT->value));
            $this->assertAuthenticatedAs($user);
        });
    }

    #[Group('login')]
    public function test_client_org_user_starting_at_app_login_authenticates_to_client_panel(): void
    {
        $this->withProductionEnvironment(function () {
            Notification::fake();

            $user = $this->createClientPortalUser(['email' => 'client@example.com']);

            $this->get(route('filament.app.auth.login'))
                ->assertSuccessful();

            OneTimePassword::generate($user);

            Livewire::test(CustomLoginPage::class)
                ->set('data.email', 'client@example.com')
                ->set('otp_sent', true)
                ->set('data.otp', OneTimePassword::get($user)['otp'])
                ->call('authenticate')
                ->assertRedirect(ClientSearchScansResource::getUrl(panel: 'client'));

            $this->assertAuthenticatedAs($user);
        });
    }

    #[Group('login')]
    public function test_client_org_user_without_active_access_grants_cannot_request_otp(): void
    {
        $this->withProductionEnvironment(function () {
            Notification::fake();

            $user = $this->createClientPortalUser(
                ['email' => 'client@example.com'],
                withAccessGrant: false,
            );

            Livewire::test(CustomLoginPage::class)
                ->set('data.email', 'client@example.com')
                ->call('authenticate')
                ->assertSet('otp_sent', false)
                ->assertSeeText('Account Not Found')
                ->assertDontSeeText('Please enter the One Time Password sent to your email');

            Notification::assertNotSentTo($user, OneTimePasswordNotification::class);
        });
    }

    #[Group('login')]
    public function test_client_org_user_with_only_revoked_access_grants_cannot_request_otp(): void
    {
        $this->withProductionEnvironment(function () {
            Notification::fake();

            $user = $this->createClientPortalUser(
                ['email' => 'client@example.com'],
                withAccessGrant: false,
            );

            ClientOrgAccess::factory()
                ->revoked()
                ->create([
                    'client_org_id' => $user->org_id,
                    'repo_org_id' => Org::factory()->create()->id,
                ]);

            Livewire::test(CustomLoginPage::class)
                ->set('data.email', 'client@example.com')
                ->call('authenticate')
                ->assertSet('otp_sent', false)
                ->assertSeeText('Account Not Found')
                ->assertDontSeeText('Please enter the One Time Password sent to your email');

            Notification::assertNotSentTo($user, OneTimePasswordNotification::class);
        });
    }

    #[Group('login')]
    public function test_client_org_user_with_disabled_portal_cannot_request_otp(): void
    {
        $this->withProductionEnvironment(function () {
            Notification::fake();

            $user = $this->createClientPortalUser(
                ['email' => 'client@example.com'],
                portalEnabled: false,
            );

            Livewire::test(CustomLoginPage::class)
                ->set('data.email', 'client@example.com')
                ->call('authenticate')
                ->assertSet('otp_sent', false)
                ->assertSeeText('Account Not Found')
                ->assertDontSeeText('Please enter the One Time Password sent to your email');

            Notification::assertNotSentTo($user, OneTimePasswordNotification::class);
        });
    }

    #[Group('login')]
    public function test_does_not_send_otp_for_inactive_org(): void
    {
        $this->withProductionEnvironment(function () {
            Notification::fake();

            $user = $this->createUser();

            $user->org->update([
                'enable_genetech_hotlist' => false,
            ]);

            Livewire::test(CustomLoginPage::class)
                ->set('data.email', $user->email)
                ->call('authenticate')
                ->assertSet('otp_sent', false)
                ->assertSeeText('Account Not Found')
                ->assertDontSeeText('Please enter the One Time Password sent to your email');

            Notification::assertNotSentTo($user, OneTimePasswordNotification::class);
        });
    }

    #[Group('login')]
    public function test_authenticate_with_valid_otp(): void
    {
        $this->withProductionEnvironment(function () {
            Notification::fake();

            $user = $this->createUser(['email' => 'test@example.com'], role: RoleEnum::ADMIN->value);

            OneTimePassword::generate($user);

            Livewire::test(CustomLoginPage::class)
                ->set('data.email', 'test@example.com')
                ->set('otp_sent', true)
                ->set('data.otp', OneTimePassword::get($user)['otp'])
                ->call('authenticate')
                ->assertRedirect(Dashboard::getUrl());

            $this->assertAuthenticatedAs($user);
        });
    }

    #[Group('login')]
    public function test_repo_org_user_with_hotlist_enabled_authenticates_to_app(): void
    {
        $this->withProductionEnvironment(function () {
            Notification::fake();

            $user = $this->createUser(['email' => 'repo@example.com']);

            Livewire::test(CustomLoginPage::class)
                ->set('data.email', 'repo@example.com')
                ->call('authenticate')
                ->assertSet('otp_sent', true);

            OneTimePassword::generate($user);

            Livewire::test(CustomLoginPage::class)
                ->set('data.email', 'repo@example.com')
                ->set('otp_sent', true)
                ->set('data.otp', OneTimePassword::get($user)['otp'])
                ->call('authenticate')
                ->assertRedirect(Dashboard::getUrl(panel: 'app'));

            Notification::assertSentTo($user, OneTimePasswordNotification::class);
            $this->assertAuthenticatedAs($user);
        });
    }

    #[Group('login')]
    public function test_authenticate_with_invalid_otp(): void
    {
        $this->withProductionEnvironment(function () {
            $otp = '123456';

            $this->createUser(['email' => 'test@example.com']);

            Livewire::test(CustomLoginPage::class)
                ->set('data.email', 'test@example.com')
                ->set('otp_sent', true)
                ->set('data.otp', $otp)
                ->call('authenticate')
                ->assertSuccessful()
                ->assertSee('Invalid One Time Password');

            $this->assertGuest();
        });
    }

    #[Group('login')]
    public function test_non_production_bypasses_otp_for_eligible_user(): void
    {
        $this->assertFalse(app()->isProduction());

        Notification::fake();

        $user = $this->createUser(['email' => 'bypass@example.com']);

        Livewire::test(CustomLoginPage::class)
            ->set('data.email', 'bypass@example.com')
            ->call('authenticate')
            ->assertSet('otp_sent', false)
            ->assertRedirect(Dashboard::getUrl(panel: 'app'));

        $this->assertAuthenticatedAs($user);
        Notification::assertNotSentTo($user, OneTimePasswordNotification::class);

        $this->assertSame(
            'BYPASSED_NON_PROD',
            Activity::query()->inLog('Login')->latest('id')->first()?->properties['otp'] ?? null,
        );
    }

    #[Group('login')]
    public function test_non_production_bypass_logs_in_client_org_user_to_client_panel(): void
    {
        $this->assertFalse(app()->isProduction());

        Notification::fake();

        $user = $this->createClientPortalUser(['email' => 'client-bypass@example.com']);

        Livewire::test(CustomLoginPage::class)
            ->set('data.email', 'client-bypass@example.com')
            ->call('authenticate')
            ->assertSet('otp_sent', false)
            ->assertRedirect(ClientSearchScansResource::getUrl(panel: 'client'));

        $this->assertAuthenticatedAs($user);
        Notification::assertNotSentTo($user, OneTimePasswordNotification::class);
    }

    #[Group('login')]
    public function test_non_production_bypass_still_rejects_ineligible_user(): void
    {
        $this->assertFalse(app()->isProduction());

        Notification::fake();

        $user = $this->createClientPortalUser(
            ['email' => 'revoked@example.com'],
            withAccessGrant: false,
        );

        Livewire::test(CustomLoginPage::class)
            ->set('data.email', 'revoked@example.com')
            ->call('authenticate')
            ->assertSet('otp_sent', false)
            ->assertSeeText('Account Not Found');

        $this->assertGuest();
        Notification::assertNotSentTo($user, OneTimePasswordNotification::class);
    }

    #[Group('login')]
    public function test_non_production_bypass_still_rejects_unknown_email(): void
    {
        $this->assertFalse(app()->isProduction());

        Notification::fake();

        Livewire::test(CustomLoginPage::class)
            ->set('data.email', 'unknown@example.com')
            ->call('authenticate')
            ->assertSet('otp_sent', false)
            ->assertSeeText('Account Not Found')
            ->assertDontSeeText('Please enter the One Time Password sent to your email');

        $this->assertGuest();
    }

    #[Group('login')]
    public function test_production_environment_still_requires_otp_end_to_end(): void
    {
        $this->withProductionEnvironment(function () {
            Notification::fake();

            $user = $this->createUser(['email' => 'prod@example.com']);

            Livewire::test(CustomLoginPage::class)
                ->set('data.email', 'prod@example.com')
                ->call('authenticate')
                ->assertSuccessful()
                ->assertSet('otp_sent', true)
                ->assertSee('Please enter the One Time Password sent to your email');

            $this->assertGuest();
            Notification::assertSentTo($user, OneTimePasswordNotification::class);

            $otp = (string) OneTimePassword::get($user)['otp'];

            Livewire::test(CustomLoginPage::class)
                ->set('data.email', 'prod@example.com')
                ->set('otp_sent', true)
                ->set('data.otp', $otp)
                ->call('authenticate')
                ->assertRedirect(Dashboard::getUrl(panel: 'app'));

            $this->assertAuthenticatedAs($user);

            $activity = Activity::query()->inLog('Login')->latest('id')->first();

            $this->assertNotNull($activity, 'The production login wrote no Login activity row.');
            $this->assertSame($otp, (string) $activity->properties['otp']);
        });
    }
}
