<?php

namespace Tests\Feature\Http\Controllers\Auth;

use App\Models\Kit;
use App\Models\Org;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\URL;
use PHPUnit\Framework\Attributes\Group;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class ScoutResetPasswordControllerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('app.experimental.clarity_scout', true);
        config()->set('app.force_https', true);
        Permission::findOrCreate('clarity-scout', 'web');
    }

    #[Group('clarity-scout')]
    public function test_verified_user_can_open_reset_form(): void
    {
        $user = $this->makeVerifiedScannerUser();

        $url = URL::temporarySignedRoute(
            'scout.reset-password',
            now()->addDays(7),
            ['user' => $user->getKey()],
        );

        $this->get($url)
            ->assertSuccessful()
            ->assertSee('Reset Your Password')
            ->assertSee($user->email);
    }

    #[Group('clarity-scout')]
    public function test_unverified_user_cannot_use_reset_link(): void
    {
        $user = $this->makeVerifiedScannerUser(['email_verified_at' => null]);

        $url = URL::temporarySignedRoute(
            'scout.reset-password',
            now()->addDays(7),
            ['user' => $user->getKey()],
        );

        $this->get($url)
            ->assertForbidden()
            ->assertSee('This account cannot use Clarity Scout');
    }

    #[Group('clarity-scout')]
    public function test_submitting_reset_updates_password_and_boots_pwa(): void
    {
        $user = $this->makeVerifiedScannerUser([
            'password' => Hash::make('old-password'),
        ]);

        $url = URL::temporarySignedRoute(
            'scout.reset-password.store',
            now()->addDays(7),
            ['user' => $user->getKey()],
        );

        $this->post($url, [
            'password' => 'NewPassw0rd!',
            'password_confirmation' => 'NewPassw0rd!',
        ])
            ->assertSuccessful()
            ->assertSee('__CLARITY_SCOUT_FORCE_AUTH__', false);

        $user->refresh();
        $this->assertTrue(Hash::check('NewPassw0rd!', $user->password));
        $this->assertTrue($user->tokens()->where('name', 'clarity-scout-token')->exists());
    }

    #[Group('clarity-scout')]
    public function test_reset_link_is_one_shot_after_successful_submit(): void
    {
        $user = $this->makeVerifiedScannerUser([
            'password' => Hash::make('old-password'),
        ]);

        $url = URL::temporarySignedRoute(
            'scout.reset-password',
            now()->addDays(7),
            ['user' => $user->getKey()],
        );

        $this->post($url, [
            'password' => 'NewPassw0rd!',
            'password_confirmation' => 'NewPassw0rd!',
        ])->assertSuccessful();

        $this->get($url)->assertForbidden();
    }

    #[Group('clarity-scout')]
    public function test_onboarding_set_password_still_rejects_verified_users(): void
    {
        $user = $this->makeVerifiedScannerUser();

        $url = URL::temporarySignedRoute(
            'scout.set-password',
            now()->addDays(7),
            ['user' => $user->getKey()],
        );

        $this->get($url)
            ->assertForbidden();
    }

    #[Group('clarity-scout')]
    public function test_unsigned_reset_url_shows_friendly_invalid_link_page(): void
    {
        $user = $this->makeVerifiedScannerUser();

        $this->get(route('scout.reset-password', ['user' => $user->getKey()]))
            ->assertForbidden()
            ->assertSee('This password reset link is not valid');
    }

    #[Group('clarity-scout')]
    public function test_expired_reset_url_shows_friendly_expired_link_page(): void
    {
        $user = $this->makeVerifiedScannerUser();

        $url = URL::temporarySignedRoute(
            'scout.reset-password',
            now()->subMinute(),
            ['user' => $user->getKey()],
        );

        $this->get($url)
            ->assertForbidden()
            ->assertSee('Scout password reset links expire after 7 days');
    }

    private function makeVerifiedScannerUser(array $attrs = []): User
    {
        $org = Org::factory()->create();
        $this->makeOrgPro($org);
        $kit = Kit::factory()->forOrg($org)->create();

        $user = User::factory()
            ->forOrg($org)
            ->forKit($kit)
            ->create(array_merge([
                'email_verified_at' => now(),
                'password' => Hash::make('password'),
            ], $attrs));

        setPermissionsTeamId($user->org_id);
        $user->givePermissionTo('clarity-scout');

        return $user;
    }
}
