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

class ScoutSetPasswordControllerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('app.experimental.clarity_scout', true);
        config()->set('app.force_https', true);
        Permission::findOrCreate('clarity-scout', 'web');
    }

    #[Group('clarity-scout')]
    public function test_signed_url_renders_set_password_form(): void
    {
        $user = $this->makeScannerUser();

        $url = $this->signedSetPasswordUrl($user);

        $this->get($url)
            ->assertSuccessful()
            ->assertSee('Set Your Password')
            ->assertSee($user->email);
    }

    #[Group('clarity-scout')]
    public function test_relative_signed_url_renders_set_password_form(): void
    {
        $user = $this->makeScannerUser();

        $relativeUrl = $this->signedSetPasswordUrl($user, absolute: false);

        $this->get($relativeUrl)
            ->assertSuccessful()
            ->assertSee('Set Your Password');
    }

    #[Group('clarity-scout')]
    public function test_absolute_signed_url_validates_when_request_arrives_as_http(): void
    {
        // Match cloud/local QA (APP_URL with an explicit non-443 port). ForceHttps
        // must flip the scheme without rewriting :8000 → :443, or the absolute
        // signature fails.
        config()->set('app.url', 'http://127.0.0.1:8000');
        URL::forceRootUrl('http://127.0.0.1:8000');
        URL::forceScheme('https');

        $user = $this->makeScannerUser();

        $httpsUrl = $this->signedSetPasswordUrl($user, absolute: true);

        $parts = parse_url($httpsUrl);
        $this->assertSame(8000, $parts['port'] ?? null);

        $httpUrl = 'http://'.$parts['host'].':8000'.$parts['path'].'?'.$parts['query'];

        $this->get($httpUrl)
            ->assertSuccessful()
            ->assertSee('Set Your Password');
    }

    #[Group('clarity-scout')]
    public function test_unsigned_url_shows_friendly_invalid_link_page(): void
    {
        $user = $this->makeScannerUser();

        $this->get(route('scout.set-password', ['user' => $user->getKey()]))
            ->assertForbidden()
            ->assertSee('This link is invalid')
            ->assertSee('resend the Clarity Scout invite');
    }

    #[Group('clarity-scout')]
    public function test_expired_signed_url_shows_friendly_expired_link_page(): void
    {
        $user = $this->makeScannerUser();

        $url = URL::temporarySignedRoute(
            'scout.set-password',
            now()->subMinute(),
            ['user' => $user->getKey()],
        );

        $this->get($url)
            ->assertForbidden()
            ->assertSee('This link has expired')
            ->assertSee('expire after 3 days');
    }

    #[Group('clarity-scout')]
    public function test_user_without_clarity_scout_permission_is_rejected(): void
    {
        $org = Org::factory()->create();
        $user = User::factory()->forOrg($org)->unverified()->create();

        $url = $this->signedSetPasswordUrl($user);

        $this->get($url)
            ->assertForbidden()
            ->assertSee('This account cannot use Clarity Scout')
            ->assertDontSee('This link is invalid');
    }

    #[Group('clarity-scout')]
    public function test_user_without_org_id_is_rejected_before_the_permissions_team_is_set(): void
    {
        $user = User::factory()->unverified()->create(['org_id' => null]);

        $url = $this->signedSetPasswordUrl($user);

        setPermissionsTeamId(4242);

        $this->get($url)
            ->assertForbidden()
            ->assertSee('This account cannot use Clarity Scout')
            ->assertDontSee('This link is invalid');

        $this->assertSame(4242, getPermissionsTeamId());
    }

    #[Group('clarity-scout')]
    public function test_user_without_org_id_is_rejected_on_submit(): void
    {
        $user = User::factory()->unverified()->create(['org_id' => null]);

        $url = URL::temporarySignedRoute(
            'scout.set-password.store',
            now()->addDays(3),
            ['user' => $user->getKey()],
        );

        $this->post($url, [
            'password' => 'NewPassw0rd!',
            'password_confirmation' => 'NewPassw0rd!',
        ])
            ->assertForbidden()
            ->assertSee('This account cannot use Clarity Scout');

        $this->assertNull($user->fresh()->email_verified_at);
    }

    #[Group('clarity-scout')]
    public function test_user_without_clarity_scout_permission_is_rejected_on_submit(): void
    {
        $org = Org::factory()->create();
        $user = User::factory()->forOrg($org)->unverified()->create();

        $url = URL::temporarySignedRoute(
            'scout.set-password.store',
            now()->addDays(3),
            ['user' => $user->getKey()],
        );

        $this->post($url, [
            'password' => 'NewPassw0rd!',
            'password_confirmation' => 'NewPassw0rd!',
        ])
            ->assertForbidden()
            ->assertSee('This account cannot use Clarity Scout');

        $this->assertNull($user->fresh()->email_verified_at);
    }

    /**
     * The invalid-link page's <title> is shared by the guard failures (which pass a
     * $reason) and by ValidateScoutSignedUrl (which passes only $expired). Assert all
     * four states: an inline @elseif after a word character does not compile, which
     * silently blanked the expired/invalid titles once.
     */
    #[Group('clarity-scout')]
    public function test_invalid_link_page_titles_are_correct_for_every_state(): void
    {
        $verified = $this->makeScannerUser(['email_verified_at' => now()]);
        $this->get($this->signedSetPasswordUrl($verified))
            ->assertForbidden()
            ->assertSee('<title>Password Already Set — Clarity Scout</title>', false);

        $org = Org::factory()->create();
        $noPermission = User::factory()->forOrg($org)->unverified()->create();
        $this->get($this->signedSetPasswordUrl($noPermission))
            ->assertForbidden()
            ->assertSee('<title>Account Not Eligible — Clarity Scout</title>', false);

        $user = $this->makeScannerUser();
        $expiredUrl = $this->signedSetPasswordUrl($user, now()->subMinute());
        $this->get($expiredUrl)
            ->assertForbidden()
            ->assertSee('<title>Link Expired — Clarity Scout</title>', false);

        $this->get(route('scout.set-password', ['user' => $user->getKey()]))
            ->assertForbidden()
            ->assertSee('<title>Invalid Link — Clarity Scout</title>', false);
    }

    #[Group('clarity-scout')]
    public function test_already_verified_user_cannot_reuse_link(): void
    {
        $user = $this->makeScannerUser(['email_verified_at' => now()]);

        $url = $this->signedSetPasswordUrl($user);

        $this->get($url)
            ->assertForbidden()
            ->assertSee('already set your password')
            ->assertSee('sign in with the password you set')
            ->assertDontSee('resend')
            ->assertDontSee('This link is invalid');

        $postUrl = URL::temporarySignedRoute(
            'scout.set-password.store',
            now()->addDays(3),
            ['user' => $user->getKey()],
        );

        $this->post($postUrl, [
            'password' => 'NewPassw0rd!',
            'password_confirmation' => 'NewPassw0rd!',
        ])
            ->assertForbidden()
            ->assertSee('already set your password')
            ->assertDontSee('This link is invalid');
    }

    #[Group('clarity-scout')]
    public function test_submitting_password_updates_user_and_boots_into_the_pwa(): void
    {
        $user = $this->makeScannerUser([
            'password' => Hash::make('old-password'),
            'email_verified_at' => null,
        ]);

        $url = URL::temporarySignedRoute(
            'scout.set-password.store',
            now()->addDays(3),
            ['user' => $user->getKey()],
        );

        $response = $this->post($url, [
            'password' => 'NewPassw0rd!',
            'password_confirmation' => 'NewPassw0rd!',
        ]);

        $response->assertSuccessful()
            ->assertSee('__CLARITY_SCOUT_FORCE_AUTH__', false)
            ->assertSee('"is_impersonating":false', false);

        $this->assertTrue(
            $user->tokens()->where('name', 'clarity-scout-token')->exists()
        );

        $user->refresh();
        $this->assertNotNull($user->email_verified_at);
        $this->assertTrue(Hash::check('NewPassw0rd!', $user->password));
    }

    #[Group('clarity-scout')]
    public function test_relative_signed_url_can_submit_password(): void
    {
        $user = $this->makeScannerUser([
            'password' => Hash::make('old-password'),
            'email_verified_at' => null,
        ]);

        $relativeUrl = URL::temporarySignedRoute(
            'scout.set-password.store',
            now()->addDays(3),
            ['user' => $user->getKey()],
            absolute: false,
        );

        $this->post($relativeUrl, [
            'password' => 'NewPassw0rd!',
            'password_confirmation' => 'NewPassw0rd!',
        ])->assertSuccessful();

        $this->assertNotNull($user->fresh()->email_verified_at);
    }

    #[Group('clarity-scout')]
    public function test_submitting_mismatched_password_fails_validation(): void
    {
        $user = $this->makeScannerUser();

        $url = URL::temporarySignedRoute(
            'scout.set-password.store',
            now()->addDays(3),
            ['user' => $user->getKey()],
        );

        $this->from($url)
            ->post($url, [
                'password' => 'NewPassw0rd!',
                'password_confirmation' => 'different',
            ])
            ->assertSessionHasErrors('password');
    }

    private function signedSetPasswordUrl(
        User $user,
        ?\DateTimeInterface $expiration = null,
        bool $absolute = true,
        string $route = 'scout.set-password',
    ): string {
        return URL::temporarySignedRoute(
            $route,
            $expiration ?? now()->addDays(3),
            [
                'user' => $user->getKey(),
            ],
            absolute: $absolute,
        );
    }

    private function makeScannerUser(array $attrs = []): User
    {
        $org = Org::factory()->create();
        $this->makeOrgPro($org);
        $kit = Kit::factory()->forOrg($org)->create();

        $user = User::factory()
            ->forOrg($org)
            ->forKit($kit)
            ->unverified()
            ->create($attrs);

        setPermissionsTeamId($user->org_id);
        $user->givePermissionTo('clarity-scout');

        return $user;
    }
}
