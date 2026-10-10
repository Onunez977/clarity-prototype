<?php

namespace Tests\Filament\Pages;

use App\Enums\FeatureEnum;
use App\Enums\ProTierEnum;
use App\Facades\Analytics;
use App\Filament\Pages\Subscription;
use App\Jobs\FlushPennant;
use App\Mail\Pro\DowngradeCancellation;
use App\Mail\Pro\DowngradeRequest;
use App\Mail\Pro\SubscriptionReceipt;
use App\Mail\Pro\UpgradeRequest;
use Filament\Notifications\Notification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use InsightLPR\BlackAdam\Enum\RoleEnum;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Group;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class SubscriptionTest extends TestCase
{
    #[Group('pages')]
    #[Group('subscription')]
    public function test_render(): void
    {
        $this->actingAs($this->createUser(role: RoleEnum::ADMIN->value, pro: true));

        Livewire::test(Subscription::class)
            ->assertSuccessful();
    }

    #[Group('pages')]
    #[Group('subscription')]
    public function test_mount_sets_current_tier_for_pro_org(): void
    {
        $this->actingAs($this->createUser(role: RoleEnum::ADMIN->value, pro: true));

        Livewire::test(Subscription::class)
            ->assertSet('currentTier', ProTierEnum::CLARITY_PRO);
    }

    #[Group('pages')]
    #[Group('subscription')]
    public function test_mount_sets_basic_tier_for_non_pro_org(): void
    {
        $this->actingAs($this->createUser(role: RoleEnum::ADMIN->value, pro: false));

        Livewire::test(Subscription::class)
            ->assertSet('currentTier', ProTierEnum::BASIC);
    }

    #[Group('pages')]
    #[Group('subscription')]
    public function test_mount_loads_pending_downgrade_from_metadata(): void
    {
        $user = $this->createUser(role: RoleEnum::ADMIN->value, pro: true);

        $user->org->meta->updateProperties([
            'platform_repo_pro_downgrade_target_tier' => 'basic',
            'platform_repo_pro_downgrade_effective_at' => '2026-05-01T00:00:00+00:00',
        ]);

        $this->actingAs($user);

        Livewire::test(Subscription::class)
            ->assertSet('pendingDowngradeTier', 'basic')
            ->assertSet('pendingDowngradeDate', 'May 1, 2026');
    }

    #[Group('pages')]
    #[Group('subscription')]
    public function test_admin_can_request_upgrade(): void
    {
        Mail::fake();
        Analytics::partialMock();

        $this->actingAs($this->createUser(role: RoleEnum::ADMIN->value, pro: true));

        Livewire::test(Subscription::class)
            ->call('requestUpgrade', ProTierEnum::CLARITY_PRO->value)
            ->assertDispatched('pro-upgrade-success');
    }

    #[Group('pages')]
    #[Group('subscription')]
    public function test_non_admin_cannot_request_upgrade(): void
    {
        Mail::fake();
        Analytics::partialMock();

        $this->actingAs($this->createUser(pro: true));

        Livewire::test(Subscription::class)
            ->call('requestUpgrade', ProTierEnum::CLARITY_PRO->value)
            ->assertNotDispatched('pro-upgrade-success');

        Mail::assertNothingSent();
    }

    #[Group('pages')]
    #[Group('subscription')]
    public function test_upgrade_sends_emails_to_sales_and_user(): void
    {
        Mail::fake();
        Analytics::partialMock();

        $this->actingAs($this->createUser(role: RoleEnum::ADMIN->value, pro: true));

        Livewire::test(Subscription::class)
            ->call('requestUpgrade', ProTierEnum::CLARITY_PRO->value);

        Mail::assertQueued(UpgradeRequest::class);
        Mail::assertQueued(SubscriptionReceipt::class);
    }

    #[Group('pages')]
    #[Group('subscription')]
    public function test_upgrade_clears_pending_downgrade_metadata(): void
    {
        Mail::fake();
        Analytics::partialMock();

        $user = $this->createUser(role: RoleEnum::ADMIN->value, pro: true);

        $user->org->meta->updateProperties([
            'platform_repo_pro_downgrade_target_tier' => 'basic',
            'platform_repo_pro_downgrade_effective_at' => '2026-05-01T00:00:00+00:00',
            'platform_repo_pro_end' => '2026-05-01T00:00:00+00:00',
        ]);

        $this->actingAs($user);

        Livewire::test(Subscription::class)
            ->call('requestUpgrade', ProTierEnum::CLARITY_PRO->value)
            ->assertSet('pendingDowngradeTier', null)
            ->assertSet('pendingDowngradeDate', null);

        $user->org->meta->refresh();
        $this->assertNull($user->org->meta->properties['platform_repo_pro_downgrade_target_tier']);
        $this->assertNull($user->org->meta->properties['platform_repo_pro_downgrade_effective_at']);
        $this->assertNull($user->org->meta->properties['platform_repo_pro_end']);
    }

    #[Group('pages')]
    #[Group('subscription')]
    public function test_admin_can_request_downgrade(): void
    {
        Mail::fake();
        Analytics::partialMock();

        $this->actingAs($this->createUser(role: RoleEnum::ADMIN->value, pro: true));

        Livewire::test(Subscription::class)
            ->call('requestDowngrade', ProTierEnum::BASIC->value)
            ->assertDispatched('pro-downgrade-success');
    }

    #[Group('pages')]
    #[Group('subscription')]
    public function test_non_admin_cannot_request_downgrade(): void
    {
        Mail::fake();
        Analytics::partialMock();

        $this->actingAs($this->createUser(pro: true));

        Livewire::test(Subscription::class)
            ->call('requestDowngrade', ProTierEnum::BASIC->value)
            ->assertNotDispatched('pro-downgrade-success');

        Mail::assertNothingSent();
    }

    #[Group('pages')]
    #[Group('subscription')]
    public function test_downgrade_sends_emails_to_sales_and_user(): void
    {
        Mail::fake();
        Analytics::partialMock();

        $this->actingAs($this->createUser(role: RoleEnum::ADMIN->value, pro: true));

        Livewire::test(Subscription::class)
            ->call('requestDowngrade', ProTierEnum::BASIC->value);

        Mail::assertQueued(DowngradeRequest::class);
        Mail::assertQueued(SubscriptionReceipt::class);
    }

    #[Group('pages')]
    #[Group('subscription')]
    public function test_downgrade_sets_metadata_on_org(): void
    {
        Mail::fake();
        Analytics::partialMock();

        $user = $this->createUser(role: RoleEnum::ADMIN->value, pro: true);
        $this->actingAs($user);

        Livewire::test(Subscription::class)
            ->call('requestDowngrade', ProTierEnum::BASIC->value);

        $user->org->meta->refresh();
        $this->assertEquals('basic', $user->org->meta->properties['platform_repo_pro_downgrade_target_tier']);
        $this->assertNotNull($user->org->meta->properties['platform_repo_pro_downgrade_effective_at']);
        $this->assertEquals(
            $user->org->meta->properties['platform_repo_pro_downgrade_effective_at'],
            $user->org->meta->properties['platform_repo_pro_end']
        );
    }

    #[Group('pages')]
    #[Group('subscription')]
    public function test_downgrade_to_pro_lite_does_not_set_pro_end(): void
    {
        Mail::fake();
        Analytics::partialMock();

        $user = $this->createUser(role: RoleEnum::ADMIN->value, pro: true);
        $this->actingAs($user);

        Livewire::test(Subscription::class)
            ->call('requestDowngrade', ProTierEnum::PRO_LITE->value);

        $user->org->meta->refresh();
        $this->assertEquals('pro_lite', $user->org->meta->properties['platform_repo_pro_downgrade_target_tier']);
        $this->assertNull($user->org->meta->properties['platform_repo_pro_end'] ?? null);
    }

    #[Group('pages')]
    #[Group('subscription')]
    public function test_downgrade_sets_component_properties(): void
    {
        Mail::fake();
        Analytics::partialMock();

        $this->actingAs($this->createUser(role: RoleEnum::ADMIN->value, pro: true));

        Livewire::test(Subscription::class)
            ->call('requestDowngrade', ProTierEnum::BASIC->value)
            ->assertSet('pendingDowngradeTier', 'basic')
            ->assertNotSet('pendingDowngradeDate', null);
    }

    #[Group('pages')]
    #[Group('subscription')]
    public function test_downgrade_effective_date_fallback_when_no_billing_cycle(): void
    {
        Mail::fake();
        Analytics::partialMock();
        Carbon::setTestNow('2026-03-20');

        $user = $this->createUser(role: RoleEnum::ADMIN->value, pro: true);
        $this->actingAs($user);

        Livewire::test(Subscription::class)
            ->call('requestDowngrade', ProTierEnum::BASIC->value);

        $user->org->meta->refresh();
        $effectiveAt = Carbon::parse($user->org->meta->properties['platform_repo_pro_downgrade_effective_at']);
        $this->assertTrue($effectiveAt->isSameDay(Carbon::parse('2026-04-20')));

        Carbon::setTestNow();
    }

    #[Group('pages')]
    #[Group('subscription')]
    public function test_downgrade_effective_date_when_anchor_day_already_passed(): void
    {
        Mail::fake();
        Analytics::partialMock();
        Carbon::setTestNow('2026-03-20');

        $user = $this->createUser(role: RoleEnum::ADMIN->value, pro: true);

        $user->org->meta->updateProperties([
            'platform_repo_pro_billing' => '2026-01-10',
        ]);

        $this->actingAs($user);

        Livewire::test(Subscription::class)
            ->call('requestDowngrade', ProTierEnum::BASIC->value);

        $user->org->meta->refresh();
        $effectiveAt = Carbon::parse($user->org->meta->properties['platform_repo_pro_downgrade_effective_at']);
        $this->assertTrue($effectiveAt->isSameDay(Carbon::parse('2026-04-10')));

        Carbon::setTestNow();
    }

    #[Group('pages')]
    #[Group('subscription')]
    public function test_downgrade_effective_date_when_anchor_day_not_yet_reached(): void
    {
        Mail::fake();
        Analytics::partialMock();
        Carbon::setTestNow('2026-03-20');

        $user = $this->createUser(role: RoleEnum::ADMIN->value, pro: true);

        $user->org->meta->updateProperties([
            'platform_repo_pro_billing' => '2026-01-25',
        ]);

        $this->actingAs($user);

        Livewire::test(Subscription::class)
            ->call('requestDowngrade', ProTierEnum::BASIC->value);

        $user->org->meta->refresh();
        $effectiveAt = Carbon::parse($user->org->meta->properties['platform_repo_pro_downgrade_effective_at']);
        $this->assertTrue($effectiveAt->isSameDay(Carbon::parse('2026-03-25')));

        Carbon::setTestNow();
    }

    #[Group('pages')]
    #[Group('subscription')]
    public function test_admin_can_cancel_downgrade(): void
    {
        Mail::fake();

        $user = $this->createUser(role: RoleEnum::ADMIN->value, pro: true);

        $user->org->meta->updateProperties([
            'platform_repo_pro_downgrade_target_tier' => 'basic',
            'platform_repo_pro_downgrade_effective_at' => '2026-05-01T00:00:00+00:00',
            'platform_repo_pro_end' => '2026-05-01T00:00:00+00:00',
        ]);

        $this->actingAs($user);

        Livewire::test(Subscription::class)
            ->call('cancelDowngrade')
            ->assertSet('pendingDowngradeTier', null)
            ->assertSet('pendingDowngradeDate', null);

        $user->org->meta->refresh();
        $this->assertNull($user->org->meta->properties['platform_repo_pro_downgrade_target_tier']);
        $this->assertNull($user->org->meta->properties['platform_repo_pro_downgrade_effective_at']);
        $this->assertNull($user->org->meta->properties['platform_repo_pro_end']);
    }

    #[Group('pages')]
    #[Group('subscription')]
    public function test_non_admin_cannot_cancel_downgrade(): void
    {
        $user = $this->createUser(pro: true);

        $user->org->meta->updateProperties([
            'platform_repo_pro_downgrade_target_tier' => 'basic',
            'platform_repo_pro_downgrade_effective_at' => '2026-05-01T00:00:00+00:00',
        ]);

        $this->actingAs($user);

        Livewire::test(Subscription::class)
            ->call('cancelDowngrade');

        $user->org->meta->refresh();
        $this->assertEquals('basic', $user->org->meta->properties['platform_repo_pro_downgrade_target_tier']);
        $this->assertEquals('2026-05-01T00:00:00+00:00', $user->org->meta->properties['platform_repo_pro_downgrade_effective_at']);
    }

    // ── 1. Pending Downgrade Partial Metadata ─────────────────────────

    #[Group('pages')]
    #[Group('subscription')]
    public function test_mount_does_not_load_pending_downgrade_when_only_target_tier_set(): void
    {
        $user = $this->createUser(role: RoleEnum::ADMIN->value, pro: true);

        $user->org->meta->updateProperties([
            'platform_repo_pro_downgrade_target_tier' => 'basic',
        ]);

        $this->actingAs($user);

        Livewire::test(Subscription::class)
            ->assertSet('pendingDowngradeTier', null)
            ->assertSet('pendingDowngradeDate', null);
    }

    #[Group('pages')]
    #[Group('subscription')]
    public function test_mount_does_not_load_pending_downgrade_when_only_effective_at_set(): void
    {
        $user = $this->createUser(role: RoleEnum::ADMIN->value, pro: true);

        $user->org->meta->updateProperties([
            'platform_repo_pro_downgrade_effective_at' => '2026-05-01T00:00:00+00:00',
        ]);

        $this->actingAs($user);

        Livewire::test(Subscription::class)
            ->assertSet('pendingDowngradeTier', null)
            ->assertSet('pendingDowngradeDate', null);
    }

    // ── 2. PRO_LITE Tier Coverage ─────────────────────────────────────

    #[Group('pages')]
    #[Group('subscription')]
    public function test_mount_sets_pro_lite_tier(): void
    {
        $user = $this->createUser(role: RoleEnum::ADMIN->value, pro: true);

        $user->org->meta->updateProperties([
            'platform_repo_pro_tier' => 'pro_lite',
        ]);

        $this->actingAs($user);

        Livewire::test(Subscription::class)
            ->assertSet('currentTier', ProTierEnum::PRO_LITE);
    }

    #[Group('pages')]
    #[Group('subscription')]
    public function test_upgrade_from_basic_to_pro_lite(): void
    {
        Mail::fake();
        Analytics::partialMock();

        $this->actingAs($this->createUser(role: RoleEnum::ADMIN->value, pro: true));

        Livewire::test(Subscription::class)
            ->call('requestUpgrade', ProTierEnum::PRO_LITE->value)
            ->assertDispatched('pro-upgrade-success');

        Mail::assertQueued(UpgradeRequest::class);
        Mail::assertQueued(SubscriptionReceipt::class);
    }

    // ── 3. Analytics Tracking ─────────────────────────────────────────

    #[Group('pages')]
    #[Group('subscription')]
    public function test_mount_tracks_page_view_analytics(): void
    {
        Analytics::shouldReceive('track')
            ->once()
            ->withArgs(function (string $event, $user, array $params, ?string $page) {
                return $event === 'page-view'
                    && $page === 'subscription'
                    && isset($params['action'], $params['current_tier'], $params['source_feature'], $params['source_features']);
            });

        $this->actingAs($this->createUser(role: RoleEnum::ADMIN->value, pro: true));

        Livewire::test(Subscription::class)
            ->assertSuccessful();
    }

    #[Group('pages')]
    #[Group('subscription')]
    public function test_upgrade_tracks_analytics_event(): void
    {
        Mail::fake();

        $trackCalls = [];
        Analytics::shouldReceive('track')
            ->andReturnUsing(function (string $event, $user, array $params) use (&$trackCalls) {
                $trackCalls[] = ['event' => $event, 'params' => $params];
            });

        $this->actingAs($this->createUser(role: RoleEnum::ADMIN->value, pro: true));

        Livewire::test(Subscription::class)
            ->call('requestUpgrade', ProTierEnum::CLARITY_PRO->value);

        $proRequest = collect($trackCalls)->firstWhere('event', 'pro-request');
        $this->assertNotNull($proRequest);
        $this->assertEquals('upgrade', $proRequest['params']['action']);
        $this->assertArrayHasKey('current_tier', $proRequest['params']);
        $this->assertArrayHasKey('target_tier', $proRequest['params']);
    }

    #[Group('pages')]
    #[Group('subscription')]
    public function test_downgrade_tracks_analytics_event(): void
    {
        Mail::fake();

        $trackCalls = [];
        Analytics::shouldReceive('track')
            ->andReturnUsing(function (string $event, $user, array $params) use (&$trackCalls) {
                $trackCalls[] = ['event' => $event, 'params' => $params];
            });

        $this->actingAs($this->createUser(role: RoleEnum::ADMIN->value, pro: true));

        Livewire::test(Subscription::class)
            ->call('requestDowngrade', ProTierEnum::BASIC->value);

        $proRequest = collect($trackCalls)->firstWhere('event', 'pro-request');
        $this->assertNotNull($proRequest);
        $this->assertEquals('downgrade', $proRequest['params']['action']);
        $this->assertArrayHasKey('current_tier', $proRequest['params']);
        $this->assertArrayHasKey('target_tier', $proRequest['params']);
    }

    // ── 4. Session / Source Handling ──────────────────────────────────

    #[Group('pages')]
    #[Group('subscription')]
    public function test_mount_loads_and_sanitizes_upgrade_sources_from_session(): void
    {
        Analytics::partialMock();

        $validSlug = FeatureEnum::SHARED_KITS->slug();
        $anotherValidSlug = FeatureEnum::FLEX_SEARCH->slug();

        session(['upgrade_sources' => [$validSlug, 'invalid-feature', $anotherValidSlug, 'xss-attempt']]);

        $this->actingAs($this->createUser(role: RoleEnum::ADMIN->value, pro: true));

        Livewire::test(Subscription::class)
            ->assertSet('from', [$validSlug, $anotherValidSlug]);
    }

    #[Group('pages')]
    #[Group('subscription')]
    public function test_upgrade_forgets_session_upgrade_sources(): void
    {
        Mail::fake();
        Analytics::partialMock();

        session(['upgrade_sources' => [FeatureEnum::SHARED_KITS->slug()]]);

        $this->actingAs($this->createUser(role: RoleEnum::ADMIN->value, pro: true));

        Livewire::test(Subscription::class)
            ->call('requestUpgrade', ProTierEnum::CLARITY_PRO->value);

        $this->assertNull(session('upgrade_sources'));
    }

    // ── 5. Email Recipient Verification ───────────────────────────────

    #[Group('pages')]
    #[Group('subscription')]
    public function test_upgrade_emails_sent_to_correct_recipients(): void
    {
        Mail::fake();
        Analytics::partialMock();

        $user = $this->createUser(role: RoleEnum::ADMIN->value, pro: true);
        $this->actingAs($user);

        Livewire::test(Subscription::class)
            ->call('requestUpgrade', ProTierEnum::CLARITY_PRO->value);

        Mail::assertQueued(UpgradeRequest::class, function ($mail) {
            return $mail->hasTo(config('app.pro.sales_email'));
        });

        Mail::assertQueued(SubscriptionReceipt::class, function ($mail) use ($user) {
            return $mail->hasTo($user->email);
        });
    }

    #[Group('pages')]
    #[Group('subscription')]
    public function test_downgrade_emails_sent_to_correct_recipients(): void
    {
        Mail::fake();
        Analytics::partialMock();

        $user = $this->createUser(role: RoleEnum::ADMIN->value, pro: true);
        $this->actingAs($user);

        Livewire::test(Subscription::class)
            ->call('requestDowngrade', ProTierEnum::BASIC->value);

        Mail::assertQueued(DowngradeRequest::class, function ($mail) {
            return $mail->hasTo(config('app.pro.sales_email'));
        });

        Mail::assertQueued(SubscriptionReceipt::class, function ($mail) use ($user) {
            return $mail->hasTo($user->email);
        });
    }

    // ── 6. Billing Cycle Edge Cases ───────────────────────────────────

    #[Group('pages')]
    #[Group('subscription')]
    public function test_downgrade_effective_date_when_anchor_day_is_today(): void
    {
        Mail::fake();
        Analytics::partialMock();
        Carbon::setTestNow('2026-03-15');

        $user = $this->createUser(role: RoleEnum::ADMIN->value, pro: true);

        $user->org->meta->updateProperties([
            'platform_repo_pro_billing' => '2026-01-15',
        ]);

        $this->actingAs($user);

        Livewire::test(Subscription::class)
            ->call('requestDowngrade', ProTierEnum::BASIC->value);

        $user->org->meta->refresh();
        $effectiveAt = Carbon::parse($user->org->meta->properties['platform_repo_pro_downgrade_effective_at']);
        $this->assertTrue($effectiveAt->isSameDay(Carbon::parse('2026-04-15')));

        Carbon::setTestNow();
    }

    #[Group('pages')]
    #[Group('subscription')]
    public function test_downgrade_effective_date_with_end_of_month_anchor(): void
    {
        Mail::fake();
        Analytics::partialMock();
        Carbon::setTestNow('2026-02-15');

        $user = $this->createUser(role: RoleEnum::ADMIN->value, pro: true);

        $user->org->meta->updateProperties([
            'platform_repo_pro_billing' => '2026-01-31',
        ]);

        $this->actingAs($user);

        Livewire::test(Subscription::class)
            ->call('requestDowngrade', ProTierEnum::BASIC->value);

        $user->org->meta->refresh();
        $effectiveAt = Carbon::parse($user->org->meta->properties['platform_repo_pro_downgrade_effective_at']);
        // Anchor day 31 clamped to Feb 28, so effective date is Feb 28
        $this->assertTrue($effectiveAt->isSameDay(Carbon::parse('2026-02-28')));

        Carbon::setTestNow();
    }

    // ── 7. Upgrade Without Pending Downgrade ──────────────────────────

    #[Group('pages')]
    #[Group('subscription')]
    public function test_upgrade_works_cleanly_without_pending_downgrade(): void
    {
        Mail::fake();
        Analytics::partialMock();

        $user = $this->createUser(role: RoleEnum::ADMIN->value, pro: true);
        $this->actingAs($user);

        Livewire::test(Subscription::class)
            ->call('requestUpgrade', ProTierEnum::CLARITY_PRO->value)
            ->assertDispatched('pro-upgrade-success')
            ->assertSet('pendingDowngradeTier', null)
            ->assertSet('pendingDowngradeDate', null);
    }

    // ── 8. Downgrade → Upgrade Edge Cases ────────────────────────────

    #[Group('pages')]
    #[Group('subscription')]
    public function test_downgrade_completes_after_effective_date_then_upgrade_clears_stale_metadata(): void
    {
        Mail::fake();
        Analytics::partialMock();
        Carbon::setTestNow('2026-03-15');

        $user = $this->createUser(role: RoleEnum::ADMIN->value, pro: true);

        $user->org->meta->updateProperties([
            'platform_repo_pro_billing' => '2026-01-10',
        ]);

        $this->actingAs($user);

        // Request downgrade to Pro Lite
        Livewire::test(Subscription::class)
            ->call('requestDowngrade', ProTierEnum::PRO_LITE->value)
            ->assertDispatched('pro-downgrade-success');

        $user->org->meta->refresh();
        $this->assertEquals('pro_lite', $user->org->meta->properties['platform_repo_pro_downgrade_target_tier']);
        $this->assertNotNull($user->org->meta->properties['platform_repo_pro_downgrade_effective_at']);

        Mail::assertQueued(DowngradeRequest::class);
        Mail::assertQueued(SubscriptionReceipt::class);

        // Advance time past effective date — downgrade should now be applied
        Carbon::setTestNow('2026-05-15');

        $user->org->refresh();
        $this->assertEquals(ProTierEnum::PRO_LITE, $user->org->proTier());

        // Upgrade back to Clarity Pro — should clear stale downgrade metadata
        Livewire::test(Subscription::class)
            ->call('requestUpgrade', ProTierEnum::CLARITY_PRO->value)
            ->assertDispatched('pro-upgrade-success');

        $user->org->meta->refresh();
        $this->assertNull($user->org->meta->properties['platform_repo_pro_downgrade_target_tier']);
        $this->assertNull($user->org->meta->properties['platform_repo_pro_downgrade_effective_at']);

        Mail::assertQueued(UpgradeRequest::class);
        Mail::assertQueued(SubscriptionReceipt::class, 2);

        Carbon::setTestNow();
    }

    #[Group('pages')]
    #[Group('subscription')]
    public function test_full_downgrade_then_upgrade_before_effective_date_clears_pending_and_sends_all_emails(): void
    {
        Mail::fake();
        Analytics::partialMock();
        Carbon::setTestNow('2026-03-15');

        $user = $this->createUser(role: RoleEnum::ADMIN->value, pro: true);

        $user->org->meta->updateProperties([
            'platform_repo_pro_billing' => '2026-01-25',
        ]);

        $this->actingAs($user);

        // Request downgrade to Basic
        Livewire::test(Subscription::class)
            ->call('requestDowngrade', ProTierEnum::BASIC->value)
            ->assertDispatched('pro-downgrade-success');

        $user->org->meta->refresh();
        $this->assertEquals('basic', $user->org->meta->properties['platform_repo_pro_downgrade_target_tier']);

        // Effective date hasn't passed — tier should still be Clarity Pro
        $this->assertEquals(ProTierEnum::CLARITY_PRO, $user->org->proTier());

        // Upgrade before window closes — should clear pending downgrade
        Livewire::test(Subscription::class)
            ->assertSet('pendingDowngradeTier', 'basic')
            ->call('requestUpgrade', ProTierEnum::CLARITY_PRO->value)
            ->assertDispatched('pro-upgrade-success')
            ->assertSet('pendingDowngradeTier', null)
            ->assertSet('pendingDowngradeDate', null);

        $user->org->meta->refresh();
        $this->assertNull($user->org->meta->properties['platform_repo_pro_downgrade_target_tier']);
        $this->assertNull($user->org->meta->properties['platform_repo_pro_downgrade_effective_at']);

        Mail::assertQueued(DowngradeRequest::class, 1);
        Mail::assertQueued(UpgradeRequest::class, 1);
        Mail::assertQueued(SubscriptionReceipt::class, 2);

        Carbon::setTestNow();
    }

    #[Group('pages')]
    #[Group('subscription')]
    public function test_downgrade_completes_after_effective_date_then_upgrade_clears_stale_metadata_full_cycle(): void
    {
        Mail::fake();
        Analytics::partialMock();
        Carbon::setTestNow('2026-03-15');

        $user = $this->createUser(role: RoleEnum::ADMIN->value, pro: true);

        $user->org->meta->updateProperties([
            'platform_repo_pro_billing' => '2026-01-10',
        ]);

        $this->actingAs($user);

        // Request downgrade to Pro Lite
        Livewire::test(Subscription::class)
            ->call('requestDowngrade', ProTierEnum::PRO_LITE->value)
            ->assertDispatched('pro-downgrade-success');

        $user->org->meta->refresh();
        $this->assertEquals('pro_lite', $user->org->meta->properties['platform_repo_pro_downgrade_target_tier']);
        $this->assertNotNull($user->org->meta->properties['platform_repo_pro_downgrade_effective_at']);

        Mail::assertQueued(DowngradeRequest::class);
        Mail::assertQueued(SubscriptionReceipt::class);

        // Advance well past effective date
        Carbon::setTestNow('2026-05-15');

        $user->org->refresh();
        $this->assertEquals(ProTierEnum::PRO_LITE, $user->org->proTier());

        // Upgrade back — should clear stale metadata
        Livewire::test(Subscription::class)
            ->call('requestUpgrade', ProTierEnum::CLARITY_PRO->value)
            ->assertDispatched('pro-upgrade-success');

        $user->org->meta->refresh();
        $this->assertNull($user->org->meta->properties['platform_repo_pro_downgrade_target_tier']);
        $this->assertNull($user->org->meta->properties['platform_repo_pro_downgrade_effective_at']);

        Mail::assertQueued(UpgradeRequest::class);
        Mail::assertQueued(SubscriptionReceipt::class, 2);

        Carbon::setTestNow();
    }

    #[Group('pages')]
    #[Group('subscription')]
    public function test_full_downgrade_to_basic_then_upgrade_before_effective_date_clears_pending_and_sends_all_emails(): void
    {
        Mail::fake();
        Analytics::partialMock();
        Carbon::setTestNow('2026-03-15');

        $user = $this->createUser(role: RoleEnum::ADMIN->value, pro: true);

        $user->org->meta->updateProperties([
            'platform_repo_pro_billing' => '2026-01-25',
        ]);

        $this->actingAs($user);

        // Request downgrade to Basic
        Livewire::test(Subscription::class)
            ->call('requestDowngrade', ProTierEnum::BASIC->value)
            ->assertDispatched('pro-downgrade-success');

        $user->org->meta->refresh();
        $this->assertEquals('basic', $user->org->meta->properties['platform_repo_pro_downgrade_target_tier']);

        // Effective date hasn't passed — tier should still be Clarity Pro
        $this->assertEquals(ProTierEnum::CLARITY_PRO, $user->org->proTier());

        // Mount fresh page, verify banner, then upgrade
        Livewire::test(Subscription::class)
            ->assertSet('pendingDowngradeTier', 'basic')
            ->call('requestUpgrade', ProTierEnum::CLARITY_PRO->value)
            ->assertDispatched('pro-upgrade-success')
            ->assertSet('pendingDowngradeTier', null)
            ->assertSet('pendingDowngradeDate', null);

        $user->org->meta->refresh();
        $this->assertNull($user->org->meta->properties['platform_repo_pro_downgrade_target_tier']);
        $this->assertNull($user->org->meta->properties['platform_repo_pro_downgrade_effective_at']);

        Mail::assertQueued(DowngradeRequest::class, 1);
        Mail::assertQueued(UpgradeRequest::class, 1);
        Mail::assertQueued(SubscriptionReceipt::class, 2);

        Carbon::setTestNow();
    }

    #[Group('pages')]
    #[Group('subscription')]
    public function test_double_downgrade_overwrites_pending_with_new_target(): void
    {
        Mail::fake();
        Analytics::partialMock();
        Carbon::setTestNow('2026-03-15');

        $user = $this->createUser(role: RoleEnum::ADMIN->value, pro: true);

        $user->org->meta->updateProperties([
            'platform_repo_pro_billing' => '2026-01-10',
        ]);

        $this->actingAs($user);

        // First downgrade to Pro Lite
        Livewire::test(Subscription::class)
            ->call('requestDowngrade', ProTierEnum::PRO_LITE->value)
            ->assertDispatched('pro-downgrade-success');

        $user->org->meta->refresh();
        $this->assertEquals('pro_lite', $user->org->meta->properties['platform_repo_pro_downgrade_target_tier']);

        // Second downgrade to Basic — should overwrite
        Livewire::test(Subscription::class)
            ->call('requestDowngrade', ProTierEnum::BASIC->value)
            ->assertDispatched('pro-downgrade-success');

        $user->org->meta->refresh();
        $this->assertEquals('basic', $user->org->meta->properties['platform_repo_pro_downgrade_target_tier']);

        // Tier should still be Clarity Pro (neither has taken effect)
        $this->assertEquals(ProTierEnum::CLARITY_PRO, $user->org->proTier());

        Mail::assertQueued(DowngradeRequest::class, 2);
        Mail::assertQueued(SubscriptionReceipt::class, 2);

        Carbon::setTestNow();
    }

    #[Group('pages')]
    #[Group('subscription')]
    public function test_downgrade_to_same_tier_creates_pending(): void
    {
        Mail::fake();
        Analytics::partialMock();

        $user = $this->createUser(role: RoleEnum::ADMIN->value, pro: true);
        $this->actingAs($user);

        Livewire::test(Subscription::class)
            ->call('requestDowngrade', ProTierEnum::CLARITY_PRO->value)
            ->assertDispatched('pro-downgrade-success');

        $user->org->meta->refresh();
        $this->assertEquals('clarity_pro', $user->org->meta->properties['platform_repo_pro_downgrade_target_tier']);
        $this->assertNotNull($user->org->meta->properties['platform_repo_pro_downgrade_effective_at']);

        Mail::assertQueued(DowngradeRequest::class);
        Mail::assertQueued(SubscriptionReceipt::class);
    }

    #[Group('pages')]
    #[Group('subscription')]
    public function test_cancel_downgrade_when_none_exists_is_silent_noop(): void
    {
        $user = $this->createUser(role: RoleEnum::ADMIN->value, pro: true);
        $this->actingAs($user);

        Livewire::test(Subscription::class)
            ->call('cancelDowngrade')
            ->assertSet('pendingDowngradeTier', null)
            ->assertSet('pendingDowngradeDate', null);

        $user->org->meta->refresh();
        $this->assertNull($user->org->meta->properties['platform_repo_pro_downgrade_target_tier'] ?? null);
        $this->assertNull($user->org->meta->properties['platform_repo_pro_downgrade_effective_at'] ?? null);
    }

    #[Group('pages')]
    #[Group('subscription')]
    public function test_stale_downgrade_metadata_shows_on_mount_after_effective_date(): void
    {
        Analytics::partialMock();
        Carbon::setTestNow('2026-06-01');

        $user = $this->createUser(role: RoleEnum::ADMIN->value, pro: true);

        $user->org->meta->updateProperties([
            'platform_repo_pro_downgrade_target_tier' => 'basic',
            'platform_repo_pro_downgrade_effective_at' => '2026-04-01T00:00:00+00:00',
        ]);

        $this->actingAs($user);

        // Stale metadata still shows on mount (not auto-cleaned)
        Livewire::test(Subscription::class)
            ->assertSet('pendingDowngradeTier', 'basic')
            ->assertNotSet('pendingDowngradeDate', null);

        // proTier() applies the downgrade since effective date is past
        $this->assertEquals(ProTierEnum::BASIC, $user->org->proTier());

        Carbon::setTestNow();
    }

    // ── 9. Input Validation / Guard Rails ────────────────────────────

    #[Group('pages')]
    #[Group('subscription')]
    public function test_upgrade_with_invalid_tier_string_throws_value_error(): void
    {
        Mail::fake();
        Analytics::partialMock();

        $this->actingAs($this->createUser(role: RoleEnum::ADMIN->value, pro: true));

        $this->expectException(\ValueError::class);

        Livewire::test(Subscription::class)
            ->call('requestUpgrade', 'invalid');
    }

    #[Group('pages')]
    #[Group('subscription')]
    public function test_downgrade_with_invalid_tier_string_throws_value_error(): void
    {
        Mail::fake();
        Analytics::partialMock();

        $this->actingAs($this->createUser(role: RoleEnum::ADMIN->value, pro: true));

        $this->expectException(\ValueError::class);

        Livewire::test(Subscription::class)
            ->call('requestDowngrade', 'invalid');
    }

    #[Group('pages')]
    #[Group('subscription')]
    public function test_upgrade_to_current_tier_still_sends_emails_and_events(): void
    {
        Mail::fake();

        $trackCalls = [];
        Analytics::shouldReceive('track')
            ->andReturnUsing(function (string $event, $user, array $params) use (&$trackCalls) {
                $trackCalls[] = ['event' => $event, 'params' => $params];
            });

        $this->actingAs($this->createUser(role: RoleEnum::ADMIN->value, pro: true));

        // Current tier is CLARITY_PRO — upgrading to same tier has no server guard
        Livewire::test(Subscription::class)
            ->call('requestUpgrade', ProTierEnum::CLARITY_PRO->value)
            ->assertDispatched('pro-upgrade-success');

        Mail::assertQueued(UpgradeRequest::class);
        Mail::assertQueued(SubscriptionReceipt::class);

        $proRequest = collect($trackCalls)->firstWhere('event', 'pro-request');
        $this->assertNotNull($proRequest);
        $this->assertEquals('upgrade', $proRequest['params']['action']);
    }

    #[Group('pages')]
    #[Group('subscription')]
    public function test_downgrade_when_already_on_basic_still_creates_pending(): void
    {
        Mail::fake();
        Analytics::partialMock();

        $this->actingAs($this->createUser(role: RoleEnum::ADMIN->value, pro: false));

        // Current tier is BASIC — downgrading to BASIC has no server guard
        Livewire::test(Subscription::class)
            ->call('requestDowngrade', ProTierEnum::BASIC->value)
            ->assertDispatched('pro-downgrade-success');

        Mail::assertQueued(DowngradeRequest::class);
        Mail::assertQueued(SubscriptionReceipt::class);
    }

    // ── 10. Tier Transition Paths ────────────────────────────────────

    #[Group('pages')]
    #[Group('subscription')]
    public function test_upgrade_from_pro_lite_to_clarity_pro(): void
    {
        Mail::fake();

        $trackCalls = [];
        Analytics::shouldReceive('track')
            ->andReturnUsing(function (string $event, $user, array $params) use (&$trackCalls) {
                $trackCalls[] = ['event' => $event, 'params' => $params];
            });

        $user = $this->createUser(role: RoleEnum::ADMIN->value, pro: true);

        $user->org->meta->updateProperties([
            'platform_repo_pro_tier' => 'pro_lite',
        ]);

        $this->actingAs($user);

        Livewire::test(Subscription::class)
            ->assertSet('currentTier', ProTierEnum::PRO_LITE)
            ->call('requestUpgrade', ProTierEnum::CLARITY_PRO->value)
            ->assertDispatched('pro-upgrade-success');

        Mail::assertQueued(UpgradeRequest::class);
        Mail::assertQueued(SubscriptionReceipt::class);

        $proRequest = collect($trackCalls)->firstWhere('event', 'pro-request');
        $this->assertNotNull($proRequest);
        $this->assertEquals('upgrade', $proRequest['params']['action']);
        $this->assertEquals('pro_lite', $proRequest['params']['current_tier']);
        $this->assertEquals('clarity_pro', $proRequest['params']['target_tier']);
    }

    #[Group('pages')]
    #[Group('subscription')]
    public function test_downgrade_from_pro_lite_to_basic(): void
    {
        Mail::fake();

        $trackCalls = [];
        Analytics::shouldReceive('track')
            ->andReturnUsing(function (string $event, $user, array $params) use (&$trackCalls) {
                $trackCalls[] = ['event' => $event, 'params' => $params];
            });

        $user = $this->createUser(role: RoleEnum::ADMIN->value, pro: true);

        $user->org->meta->updateProperties([
            'platform_repo_pro_tier' => 'pro_lite',
        ]);

        $this->actingAs($user);

        Livewire::test(Subscription::class)
            ->assertSet('currentTier', ProTierEnum::PRO_LITE)
            ->call('requestDowngrade', ProTierEnum::BASIC->value)
            ->assertDispatched('pro-downgrade-success');

        Mail::assertQueued(DowngradeRequest::class);
        Mail::assertQueued(SubscriptionReceipt::class);

        $proRequest = collect($trackCalls)->firstWhere('event', 'pro-request');
        $this->assertNotNull($proRequest);
        $this->assertEquals('downgrade', $proRequest['params']['action']);
        $this->assertEquals('pro_lite', $proRequest['params']['current_tier']);
        $this->assertEquals('basic', $proRequest['params']['target_tier']);
    }

    // ── 11. Cancel Downgrade Side Effects ────────────────────────────

    #[Group('pages')]
    #[Group('subscription')]
    public function test_cancel_downgrade_sends_emails_to_sales_and_user(): void
    {
        Mail::fake();

        $user = $this->createUser(role: RoleEnum::ADMIN->value, pro: true);

        $user->org->meta->updateProperties([
            'platform_repo_pro_downgrade_target_tier' => 'basic',
            'platform_repo_pro_downgrade_effective_at' => '2026-05-01T00:00:00+00:00',
        ]);

        $this->actingAs($user);

        Livewire::test(Subscription::class)
            ->call('cancelDowngrade');

        Mail::assertQueued(DowngradeCancellation::class);
        Mail::assertQueued(SubscriptionReceipt::class);
    }

    #[Group('pages')]
    #[Group('subscription')]
    public function test_cancel_downgrade_emails_sent_to_correct_recipients(): void
    {
        Mail::fake();

        $user = $this->createUser(role: RoleEnum::ADMIN->value, pro: true);

        $user->org->meta->updateProperties([
            'platform_repo_pro_downgrade_target_tier' => 'basic',
            'platform_repo_pro_downgrade_effective_at' => '2026-05-01T00:00:00+00:00',
        ]);

        $this->actingAs($user);

        Livewire::test(Subscription::class)
            ->call('cancelDowngrade');

        Mail::assertQueued(DowngradeCancellation::class, function ($mail) {
            return $mail->hasTo(config('app.pro.sales_email'));
        });

        Mail::assertQueued(SubscriptionReceipt::class, function ($mail) use ($user) {
            return $mail->hasTo($user->email);
        });
    }

    #[Group('pages')]
    #[Group('subscription')]
    public function test_cancel_downgrade_sends_no_emails_when_no_pending_downgrade(): void
    {
        Mail::fake();

        $user = $this->createUser(role: RoleEnum::ADMIN->value, pro: true);
        $this->actingAs($user);

        Livewire::test(Subscription::class)
            ->call('cancelDowngrade');

        Mail::assertNothingSent();
    }

    #[Group('pages')]
    #[Group('subscription')]
    public function test_non_admin_cancel_downgrade_sends_no_emails(): void
    {
        Mail::fake();

        $user = $this->createUser(pro: true);

        $user->org->meta->updateProperties([
            'platform_repo_pro_downgrade_target_tier' => 'basic',
            'platform_repo_pro_downgrade_effective_at' => '2026-05-01T00:00:00+00:00',
        ]);

        $this->actingAs($user);

        Livewire::test(Subscription::class)
            ->call('cancelDowngrade');

        Mail::assertNothingSent();
    }

    #[Group('pages')]
    #[Group('subscription')]
    public function test_cancel_downgrade_receipt_has_cancellation_change_type(): void
    {
        Mail::fake();

        $user = $this->createUser(role: RoleEnum::ADMIN->value, pro: true);

        $user->org->meta->updateProperties([
            'platform_repo_pro_downgrade_target_tier' => 'basic',
            'platform_repo_pro_downgrade_effective_at' => '2026-05-01T00:00:00+00:00',
        ]);

        $this->actingAs($user);

        Livewire::test(Subscription::class)
            ->call('cancelDowngrade');

        Mail::assertQueued(SubscriptionReceipt::class, function (SubscriptionReceipt $mail) {
            return $mail->changeType === 'cancellation';
        });
    }

    #[Group('pages')]
    #[Group('subscription')]
    public function test_cancel_downgrade_tracks_no_analytics(): void
    {
        Mail::fake();

        $trackCalls = [];
        Analytics::shouldReceive('track')
            ->andReturnUsing(function (string $event, $user, array $params) use (&$trackCalls) {
                $trackCalls[] = ['event' => $event, 'params' => $params];
            });

        $user = $this->createUser(role: RoleEnum::ADMIN->value, pro: true);

        $user->org->meta->updateProperties([
            'platform_repo_pro_downgrade_target_tier' => 'basic',
            'platform_repo_pro_downgrade_effective_at' => '2026-05-01T00:00:00+00:00',
        ]);

        $this->actingAs($user);

        Livewire::test(Subscription::class)
            ->call('cancelDowngrade');

        // Only the page-view event from mount — no pro-request event
        $proRequests = collect($trackCalls)->where('event', 'pro-request');
        $this->assertCount(0, $proRequests);
    }

    // ── 12. Session Handling ─────────────────────────────────────────

    #[Group('pages')]
    #[Group('subscription')]
    public function test_downgrade_does_not_clear_session_upgrade_sources(): void
    {
        Mail::fake();
        Analytics::partialMock();

        $validSlug = FeatureEnum::SHARED_KITS->slug();
        session(['upgrade_sources' => [$validSlug]]);

        $this->actingAs($this->createUser(role: RoleEnum::ADMIN->value, pro: true));

        Livewire::test(Subscription::class)
            ->call('requestDowngrade', ProTierEnum::BASIC->value);

        // Downgrade does NOT call session()->forget('upgrade_sources')
        $this->assertEquals([$validSlug], session('upgrade_sources'));
    }

    #[Group('pages')]
    #[Group('subscription')]
    public function test_mount_with_empty_session_upgrade_sources_produces_empty_from(): void
    {
        Analytics::partialMock();

        session(['upgrade_sources' => []]);

        $this->actingAs($this->createUser(role: RoleEnum::ADMIN->value, pro: true));

        Livewire::test(Subscription::class)
            ->assertSet('from', []);
    }

    // ── 13. FlushPennant Dispatch ──────────────────────────────────

    #[Group('pages')]
    #[Group('subscription')]
    public function test_upgrade_dispatches_flush_pennant_job(): void
    {
        Mail::fake();
        Queue::fake();
        Analytics::partialMock();

        $this->actingAs($this->createUser(role: RoleEnum::ADMIN->value, pro: true));

        Livewire::test(Subscription::class)
            ->call('requestUpgrade', ProTierEnum::CLARITY_PRO->value);

        Queue::assertPushed(FlushPennant::class);
    }

    #[Group('pages')]
    #[Group('subscription')]
    public function test_downgrade_dispatches_flush_pennant_job(): void
    {
        Mail::fake();
        Queue::fake();
        Analytics::partialMock();

        $this->actingAs($this->createUser(role: RoleEnum::ADMIN->value, pro: true));

        Livewire::test(Subscription::class)
            ->call('requestDowngrade', ProTierEnum::BASIC->value);

        Queue::assertPushed(FlushPennant::class);
    }

    // ── 14. Billing Anchor Preservation ─────────────────────────────

    #[Group('pages')]
    #[Group('subscription')]
    public function test_upgrade_preserves_existing_billing_anchor(): void
    {
        Mail::fake();
        Analytics::partialMock();

        $user = $this->createUser(role: RoleEnum::ADMIN->value, pro: true);

        $user->org->meta->updateProperties([
            'platform_repo_pro_tier' => 'pro_lite',
            'platform_repo_pro_billing' => '2026-01-15',
        ]);

        $this->actingAs($user);

        Livewire::test(Subscription::class)
            ->call('requestUpgrade', ProTierEnum::CLARITY_PRO->value);

        $user->org->meta->refresh();
        $this->assertEquals('2026-01-15', $user->org->meta->properties['platform_repo_pro_billing']);
    }

    #[Group('pages')]
    #[Group('subscription')]
    public function test_upgrade_sets_billing_anchor_when_none_exists(): void
    {
        Mail::fake();
        Analytics::partialMock();
        Carbon::setTestNow('2026-03-20');

        $user = $this->createUser(role: RoleEnum::ADMIN->value, pro: false);
        $this->actingAs($user);

        Livewire::test(Subscription::class)
            ->call('requestUpgrade', ProTierEnum::PRO_LITE->value);

        $user->org->refresh();
        $this->assertEquals('2026-03-20', $user->org->meta->properties['platform_repo_pro_billing']);

        Carbon::setTestNow();
    }

    // ── 15. Sales Email Guard ────────────────────────────────────────

    #[Group('pages')]
    #[Group('subscription')]
    public function test_upgrade_skips_sales_email_when_not_configured(): void
    {
        Mail::fake();
        Analytics::partialMock();

        config(['app.pro.sales_email' => null]);

        $this->actingAs($this->createUser(role: RoleEnum::ADMIN->value, pro: true));

        Livewire::test(Subscription::class)
            ->call('requestUpgrade', ProTierEnum::CLARITY_PRO->value)
            ->assertDispatched('pro-upgrade-success');

        Mail::assertNotQueued(UpgradeRequest::class);
        Mail::assertQueued(SubscriptionReceipt::class);
    }

    // ── 16. Leap Year Billing Anchor ─────────────────────────────────

    // ── 17. RDN Metrics Push Toggle (DEV-1854) ────────────────────────

    #[Group('pages')]
    #[Group('subscription')]
    #[Group('rdn-metrics')]
    public function test_mount_reads_rdn_metrics_push_state_from_meta(): void
    {
        Analytics::partialMock();

        $user = $this->createUser(role: RoleEnum::ADMIN->value, pro: true);
        $user->org->update(['rdn_id' => 'TX1234']);
        $user->org->meta->updateProperties([
            'platform_repo_pro_rdn_metrics_push' => true,
        ]);

        $this->actingAs($user);

        Livewire::test(Subscription::class)
            ->assertSet('rdnMetricsPushEnabled', true)
            ->assertSet('orgRdnId', 'TX1234');
    }

    #[Group('pages')]
    #[Group('subscription')]
    #[Group('rdn-metrics')]
    public function test_admin_can_toggle_rdn_metrics_push_on(): void
    {
        Analytics::partialMock();

        $user = $this->createUser(role: RoleEnum::ADMIN->value, pro: true);
        $this->actingAs($user);

        Livewire::test(Subscription::class)
            ->assertSet('rdnMetricsPushEnabled', false)
            ->call('toggleRdnMetricsPush', true)
            ->assertSet('rdnMetricsPushEnabled', true)
            ->assertNotified('RDN Statistics Push enabled');

        $user->org->meta->refresh();
        $this->assertTrue($user->org->meta->properties['platform_repo_pro_rdn_metrics_push']);

        $log = Activity::query()
            ->where('log_name', 'rdn_metrics_toggle')
            ->latest('id')
            ->first();

        $this->assertNotNull($log);
        $this->assertSame('enabled', $log->description);
        $this->assertSame($user->id, $log->causer_id);
        $this->assertSame($user->org_id, $log->properties['org_id']);
        $this->assertFalse($log->properties['old']);
        $this->assertTrue($log->properties['new']);
    }

    #[Group('pages')]
    #[Group('subscription')]
    #[Group('rdn-metrics')]
    public function test_admin_can_toggle_rdn_metrics_push_off(): void
    {
        Analytics::partialMock();

        $user = $this->createUser(role: RoleEnum::ADMIN->value, pro: true);
        $user->org->meta->updateProperties([
            'platform_repo_pro_rdn_metrics_push' => true,
        ]);

        $this->actingAs($user);

        Livewire::test(Subscription::class)
            ->assertSet('rdnMetricsPushEnabled', true)
            ->call('toggleRdnMetricsPush', false)
            ->assertSet('rdnMetricsPushEnabled', false)
            ->assertNotified('RDN Statistics Push disabled');

        $user->org->meta->refresh();
        $this->assertFalse($user->org->meta->properties['platform_repo_pro_rdn_metrics_push']);

        $log = Activity::query()
            ->where('log_name', 'rdn_metrics_toggle')
            ->latest('id')
            ->first();

        $this->assertSame('disabled', $log->description);
        $this->assertTrue($log->properties['old']);
        $this->assertFalse($log->properties['new']);
    }

    #[Group('pages')]
    #[Group('subscription')]
    #[Group('rdn-metrics')]
    public function test_non_admin_cannot_toggle_rdn_metrics_push(): void
    {
        Analytics::partialMock();

        $user = $this->createUser(pro: true);
        $this->actingAs($user);

        Livewire::test(Subscription::class)
            ->call('toggleRdnMetricsPush', true);

        $user->org->meta->refresh();
        $this->assertNull($user->org->meta->properties['platform_repo_pro_rdn_metrics_push'] ?? null);

        $this->assertSame(0, Activity::query()->where('log_name', 'rdn_metrics_toggle')->count());
    }

    #[Group('pages')]
    #[Group('subscription')]
    #[Group('rdn-metrics')]
    public function test_basic_tier_admin_cannot_toggle_rdn_metrics_push(): void
    {
        Analytics::partialMock();

        $user = $this->createUser(role: RoleEnum::ADMIN->value, pro: false);
        $this->actingAs($user);

        Livewire::test(Subscription::class)
            // A Basic-tier org must not be offered the control at all — pins the other
            // direction of the render gate that this ticket's bug came from.
            ->assertDontSee('RDN Statistics Push')
            ->call('toggleRdnMetricsPush', true)
            ->assertNotified(
                Notification::make()
                    ->title('Unable to update RDN Statistics Push')
                    ->body('This feature is not available for your subscription tier.')
                    ->danger(),
            );

        $user->org->refresh()->load('meta');
        $this->assertNull($user->org->meta?->properties['platform_repo_pro_rdn_metrics_push'] ?? null);

        $this->assertSame(0, Activity::query()->where('log_name', 'rdn_metrics_toggle')->count());
    }

    #[Group('pages')]
    #[Group('subscription')]
    #[Group('rdn-metrics')]
    public function test_mount_reads_rdn_metrics_push_state_for_pro_plus_org(): void
    {
        Analytics::partialMock();

        $user = $this->createUser(role: RoleEnum::ADMIN->value, pro: true);
        $user->org->update(['rdn_id' => 'TX1234']);
        $user->org->meta->updateProperties([
            'platform_repo_pro_tier' => ProTierEnum::CLARITY_PRO_PLUS->value,
            'platform_repo_pro_rdn_metrics_push' => true,
        ]);

        $this->actingAs($user);

        Livewire::test(Subscription::class)
            ->assertSet('currentTier', ProTierEnum::CLARITY_PRO_PLUS)
            ->assertSet('rdnMetricsPushEnabled', true)
            ->assertSet('orgRdnId', 'TX1234')
            // The bug was a render-gate/write-gate disagreement, so pin the rendered control
            // itself — a property assertion alone passes even if the section stops rendering.
            // Anchored to the RDN switch (see subscription.blade.php) so an unrelated element
            // emitting aria-checked cannot satisfy it.
            ->assertSee('RDN Statistics Push')
            ->assertSeeHtmlInOrder([
                'aria-checked="true"',
                'aria-labelledby="rdn-metrics-push-label"',
                'toggleRdnMetricsPush(false)',
            ]);
    }

    #[Group('pages')]
    #[Group('subscription')]
    #[Group('rdn-metrics')]
    public function test_pro_plus_admin_can_toggle_rdn_metrics_push_on(): void
    {
        Analytics::partialMock();

        $user = $this->createUser(role: RoleEnum::ADMIN->value, pro: true);
        $user->org->meta->updateProperties([
            'platform_repo_pro_tier' => ProTierEnum::CLARITY_PRO_PLUS->value,
        ]);
        $this->assertSame(ProTierEnum::CLARITY_PRO_PLUS, $user->org->refresh()->proTier());

        $this->actingAs($user);

        Livewire::test(Subscription::class)
            ->assertSet('rdnMetricsPushEnabled', false)
            ->call('toggleRdnMetricsPush', true)
            ->assertSet('rdnMetricsPushEnabled', true)
            ->assertNotified('RDN Statistics Push enabled');

        $user->org->meta->refresh();
        $this->assertTrue($user->org->meta->properties['platform_repo_pro_rdn_metrics_push']);

        $log = Activity::query()
            ->where('log_name', 'rdn_metrics_toggle')
            ->latest('id')
            ->first();

        $this->assertNotNull($log);
        $this->assertSame('enabled', $log->description);
        $this->assertSame($user->id, $log->causer_id);
        $this->assertSame($user->org_id, $log->properties['org_id']);
        $this->assertFalse($log->properties['old']);
        $this->assertTrue($log->properties['new']);
    }

    #[Group('pages')]
    #[Group('subscription')]
    #[Group('rdn-metrics')]
    public function test_pro_plus_admin_can_toggle_rdn_metrics_push_off(): void
    {
        Analytics::partialMock();

        $user = $this->createUser(role: RoleEnum::ADMIN->value, pro: true);
        $user->org->meta->updateProperties([
            'platform_repo_pro_tier' => ProTierEnum::CLARITY_PRO_PLUS->value,
            'platform_repo_pro_rdn_metrics_push' => true,
        ]);
        $this->assertSame(ProTierEnum::CLARITY_PRO_PLUS, $user->org->refresh()->proTier());

        $this->actingAs($user);

        Livewire::test(Subscription::class)
            ->assertSet('rdnMetricsPushEnabled', true)
            ->call('toggleRdnMetricsPush', false)
            ->assertSet('rdnMetricsPushEnabled', false)
            ->assertNotified('RDN Statistics Push disabled');

        $user->org->meta->refresh();
        $this->assertFalse($user->org->meta->properties['platform_repo_pro_rdn_metrics_push']);

        $log = Activity::query()
            ->where('log_name', 'rdn_metrics_toggle')
            ->latest('id')
            ->first();

        $this->assertSame('disabled', $log->description);
        $this->assertTrue($log->properties['old']);
        $this->assertFalse($log->properties['new']);
    }

    #[Group('pages')]
    #[Group('subscription')]
    #[Group('rdn-metrics')]
    public function test_toggle_rdn_metrics_push_on_sends_success_notification(): void
    {
        Analytics::partialMock();

        $user = $this->createUser(role: RoleEnum::ADMIN->value, pro: true);
        $this->actingAs($user);

        Livewire::test(Subscription::class)
            ->call('toggleRdnMetricsPush', true)
            ->assertNotified('RDN Statistics Push enabled');
    }

    #[Group('pages')]
    #[Group('subscription')]
    #[Group('rdn-metrics')]
    public function test_non_admin_toggle_rdn_metrics_push_sends_danger_notification(): void
    {
        Analytics::partialMock();

        $user = $this->createUser(pro: true);
        $this->actingAs($user);

        Livewire::test(Subscription::class)
            ->call('toggleRdnMetricsPush', true)
            ->assertNotified(
                Notification::make()
                    ->title('Unable to update RDN Statistics Push')
                    ->body('You do not have permission to change this setting.')
                    ->danger(),
            );

        $user->org->meta->refresh();
        $this->assertNull($user->org->meta->properties['platform_repo_pro_rdn_metrics_push'] ?? null);
        $this->assertSame(0, Activity::query()->where('log_name', 'rdn_metrics_toggle')->count());
    }

    #[Group('pages')]
    #[Group('subscription')]
    public function test_downgrade_effective_date_with_leap_year_anchor_day_29_in_non_leap_year(): void
    {
        Mail::fake();
        Analytics::partialMock();

        // 2027 is not a leap year — anchor on 29th means Feb has no 29th
        Carbon::setTestNow('2027-01-15');

        $user = $this->createUser(role: RoleEnum::ADMIN->value, pro: true);

        $user->org->meta->updateProperties([
            'platform_repo_pro_billing' => '2026-03-29',
        ]);

        $this->actingAs($user);

        Livewire::test(Subscription::class)
            ->call('requestDowngrade', ProTierEnum::BASIC->value);

        $user->org->meta->refresh();
        $effectiveAt = Carbon::parse($user->org->meta->properties['platform_repo_pro_downgrade_effective_at']);

        // Anchor 29 in January: Jan 29 > Jan 15, so cycleStart = Dec 29, +1 month = Jan 29
        $this->assertTrue($effectiveAt->isSameDay(Carbon::parse('2027-01-29')));

        // Now test Feb specifically: anchor 29 clamped to 28 in a non-leap year
        Carbon::setTestNow('2027-02-15');

        Livewire::test(Subscription::class)
            ->call('requestDowngrade', ProTierEnum::BASIC->value);

        $user->org->meta->refresh();
        $effectiveAt = Carbon::parse($user->org->meta->properties['platform_repo_pro_downgrade_effective_at']);

        // Anchor day 29 clamped to Feb 28, so effective date is Feb 28
        $this->assertTrue($effectiveAt->isSameDay(Carbon::parse('2027-02-28')));

        Carbon::setTestNow();
    }

    // ── 17. Clarity Pro Plus (Admin-Managed Only) ─────────────────────

    #[Group('pages')]
    #[Group('subscription')]
    public function test_mount_sets_clarity_pro_plus_tier_from_metadata(): void
    {
        $user = $this->createUser(role: RoleEnum::ADMIN->value, pro: true);

        $user->org->meta->updateProperties([
            'platform_repo_pro_tier' => 'clarity_pro_plus',
        ]);

        $this->actingAs($user);

        Livewire::test(Subscription::class)
            ->assertSet('currentTier', ProTierEnum::CLARITY_PRO_PLUS);
    }

    #[Group('pages')]
    #[Group('subscription')]
    public function test_pro_plus_org_cannot_self_downgrade(): void
    {
        Mail::fake();
        Analytics::partialMock();

        $user = $this->createUser(role: RoleEnum::ADMIN->value, pro: true);

        $user->org->meta->updateProperties([
            'platform_repo_pro_tier' => 'clarity_pro_plus',
        ]);

        $this->actingAs($user);

        Livewire::test(Subscription::class)
            ->call('requestDowngrade', ProTierEnum::CLARITY_PRO->value)
            ->assertNotDispatched('pro-downgrade-success');

        $user->org->meta->refresh();
        $this->assertNull($user->org->meta->properties['platform_repo_pro_downgrade_target_tier'] ?? null);
        $this->assertNull($user->org->meta->properties['platform_repo_pro_downgrade_effective_at'] ?? null);

        Mail::assertNothingSent();
    }

    #[Group('pages')]
    #[Group('subscription')]
    public function test_pro_org_cannot_self_upgrade_to_pro_plus(): void
    {
        Mail::fake();
        Analytics::partialMock();

        $user = $this->createUser(role: RoleEnum::ADMIN->value, pro: true);
        $this->actingAs($user);

        Livewire::test(Subscription::class)
            ->call('requestUpgrade', ProTierEnum::CLARITY_PRO_PLUS->value)
            ->assertNotDispatched('pro-upgrade-success');

        $user->org->meta->refresh();
        $this->assertEquals('clarity_pro', $user->org->meta->properties['platform_repo_pro_tier'] ?? 'clarity_pro');

        Mail::assertNothingSent();
    }

    // ── 18. Non-admin viewing (DEV-2042) ──────────────────────────────

    #[Group('pages')]
    #[Group('subscription')]
    public function test_non_admin_repo_user_can_view_subscription_page(): void
    {
        $this->actingAs($this->createUser());

        Livewire::test(Subscription::class)
            ->assertSuccessful();
    }

    // ── 19. Tier feature-list copy (DEV-2207) ─────────────────────────

    #[Group('pages')]
    #[Group('subscription')]
    public function test_pro_lite_promotes_shipped_features_and_drops_coming_soon(): void
    {
        $this->actingAs($this->createUser(role: RoleEnum::ADMIN->value, pro: true));

        Livewire::test(Subscription::class)
            ->assertSuccessful()
            ->assertSee('Advanced Plate State recognition')
            ->assertSee('Advanced Kit User Path')
            ->assertDontSee('Advanced Gridding Analytics');
    }

    #[Group('pages')]
    #[Group('subscription')]
    public function test_clarity_pro_promotes_rdn_reporting_and_lists_optimized_gridding(): void
    {
        $this->actingAs($this->createUser(role: RoleEnum::ADMIN->value, pro: true));

        Livewire::test(Subscription::class)
            ->assertSuccessful()
            ->assertSee('RDN Reporting Integration')
            ->assertSee('Optimized Gridding (Scout)')
            ->assertDontSee('RDN custom reports');
    }
}
