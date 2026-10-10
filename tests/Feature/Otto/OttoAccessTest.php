<?php

namespace Tests\Feature\Otto;

use App\Enums\FeatureEnum;
use App\Enums\ProTierEnum;
use App\Jobs\RunOttoChat;
use App\Models\Org;
use App\Models\User;
use App\Support\Otto\OttoAccess;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Queue;
use InsightLPR\BlackAdam\Enum\RoleEnum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class OttoAccessTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Permission::findOrCreate('use-otto', 'web');
        Permission::findOrCreate('clarity-scout', 'web');
        config()->set('app.experimental.clarity_scout', true);
    }

    public function test_user_cannot_use_otto_when_org_is_below_pro_lite(): void
    {
        $user = $this->createUser();
        setPermissionsTeamId($user->org_id);
        $user->givePermissionTo('use-otto');

        $this->assertFalse(OttoAccess::userCanUseOtto($user));

        $this->setOrgProTier($user->org, ProTierEnum::BASIC);

        $this->assertFalse(OttoAccess::userCanUseOtto($user->fresh()));
    }

    public function test_user_can_use_otto_on_pro_lite_or_higher_when_permitted(): void
    {
        $user = $this->createUser();
        setPermissionsTeamId($user->org_id);
        $user->givePermissionTo('use-otto');

        foreach ([ProTierEnum::PRO_LITE, ProTierEnum::CLARITY_PRO, ProTierEnum::CLARITY_PRO_PLUS] as $tier) {
            $this->setOrgProTier($user->org, $tier);

            $this->assertTrue(OttoAccess::userCanUseOtto($user->fresh()), $tier->value);
        }
    }

    public function test_pro_lite_still_requires_the_use_otto_permission(): void
    {
        $user = $this->createUser();
        $this->setOrgProTier($user->org, ProTierEnum::PRO_LITE);

        $this->assertFalse(OttoAccess::userCanUseOtto($user->fresh()));
    }

    public function test_legacy_company_toggle_does_not_grant_otto(): void
    {
        $user = $this->createUser();
        $user->org->meta()->create([
            'properties' => ['otto_enabled' => true],
        ]);
        setPermissionsTeamId($user->org_id);
        $user->givePermissionTo('use-otto');

        $this->assertFalse($user->org->fresh()->ottoEnabled());
        $this->assertFalse(OttoAccess::userCanUseOtto($user->fresh()));
    }

    public function test_legacy_pro_org_without_an_explicit_tier_can_use_otto(): void
    {
        $user = $this->createUser();
        $user->org->meta()->create([
            'properties' => ['platform_repo_pro' => true],
        ]);
        cache()->forget('org_pro_enabled_'.$user->org_id);
        setPermissionsTeamId($user->org_id);
        $user->givePermissionTo('use-otto');

        $org = $user->org->fresh();

        $this->assertSame(ProTierEnum::CLARITY_PRO, $org->proTier());
        $this->assertTrue(OttoAccess::userCanUseOtto($user->fresh()));
    }

    public function test_expired_pro_and_completed_downgrade_to_basic_remove_otto(): void
    {
        $expired = $this->createUser();
        $this->setOrgProTier($expired->org, ProTierEnum::CLARITY_PRO);
        $expired->org->meta()->firstOrFail()->updateProperties([
            'platform_repo_pro_end' => now()->subDay()->toIso8601String(),
        ]);
        cache()->forget('org_pro_enabled_'.$expired->org_id);
        $expired->org->unsetRelation('meta');
        setPermissionsTeamId($expired->org_id);
        $expired->givePermissionTo('use-otto');

        $this->assertFalse(OttoAccess::userCanUseOtto($expired->fresh()));

        $downgraded = $this->createUser();
        $this->setOrgProTier($downgraded->org, ProTierEnum::CLARITY_PRO);
        $downgraded->org->meta()->firstOrFail()->updateProperties([
            'platform_repo_pro_downgrade_effective_at' => now()->subDay()->toIso8601String(),
            'platform_repo_pro_downgrade_target_tier' => ProTierEnum::BASIC->value,
        ]);
        cache()->forget('org_pro_enabled_'.$downgraded->org_id);
        $downgraded->org->unsetRelation('meta');
        setPermissionsTeamId($downgraded->org_id);
        $downgraded->givePermissionTo('use-otto');

        $this->assertFalse(OttoAccess::userCanUseOtto($downgraded->fresh()));

        $toLite = $this->createUser();
        $this->setOrgProTier($toLite->org, ProTierEnum::CLARITY_PRO);
        $toLite->org->meta()->firstOrFail()->updateProperties([
            'platform_repo_pro_downgrade_effective_at' => now()->subDay()->toIso8601String(),
            'platform_repo_pro_downgrade_target_tier' => ProTierEnum::PRO_LITE->value,
        ]);
        cache()->forget('org_pro_enabled_'.$toLite->org_id);
        $toLite->org->unsetRelation('meta');
        setPermissionsTeamId($toLite->org_id);
        $toLite->givePermissionTo('use-otto');

        $this->assertTrue(OttoAccess::userCanUseOtto($toLite->fresh()));
    }

    public function test_popout_mount_renders_vue_island_for_permitted_user(): void
    {
        $user = $this->createUser();
        $this->setOrgProTier($user->org);
        setPermissionsTeamId($user->org_id);
        $user->givePermissionTo('use-otto');

        $this->actingAs($user);

        $html = Blade::render('components.otto.popout-mount');

        $this->assertStringContainsString('id="otto-root"', $html);
        $this->assertStringContainsString('__OTTO_CONFIG__', $html);
        $this->assertStringContainsString('otto.jpg', $html);
        $this->assertStringContainsString('"userId":'.$user->id, $html);
        $this->assertStringContainsString('"showDisclaimer":true', $html);
        $this->assertStringNotContainsString('ottoPopout()', $html);

        $blade = (string) file_get_contents(resource_path('views/components/otto/popout-mount.blade.php'));
        $this->assertStringContainsString("@vite('resources/js/otto/filament.js')", $blade);
    }

    public function test_popout_mount_hidden_when_org_is_below_pro_lite(): void
    {
        $user = $this->createUser();
        $this->setOrgProTier($user->org, ProTierEnum::BASIC);
        setPermissionsTeamId($user->org_id);
        $user->givePermissionTo('use-otto');

        $this->actingAs($user);

        $html = Blade::render('components.otto.popout-mount');

        $this->assertStringNotContainsString('id="otto-root"', $html);
        $this->assertStringNotContainsString('__OTTO_CONFIG__', $html);
    }

    public function test_org_map_mounts_otto_island_when_permitted(): void
    {
        [$admin] = $this->createScoutAdmin();
        $this->setOrgProTier($admin->org);
        setPermissionsTeamId($admin->org_id);
        $admin->givePermissionTo('use-otto');

        $this->actingAs($admin->fresh())
            ->get(route('clarity-scout.org-map'))
            ->assertOk()
            ->assertSee('id="otto-root"', false)
            ->assertSee('__OTTO_CONFIG__', false)
            ->assertSee('otto.jpg', false);
    }

    public function test_org_map_omits_otto_island_when_org_is_below_pro_lite(): void
    {
        [$admin] = $this->createScoutAdmin();
        $this->setOrgProTier($admin->org, ProTierEnum::BASIC);
        setPermissionsTeamId($admin->org_id);
        $admin->givePermissionTo('use-otto');

        $this->actingAs($admin->fresh())
            ->get(route('clarity-scout.org-map'))
            ->assertOk()
            ->assertSee('clarity-scout-app', false)
            ->assertDontSee('id="otto-root"', false)
            ->assertDontSee('__OTTO_CONFIG__', false);
    }

    public function test_impersonator_can_use_otto_when_org_enabled_without_permission(): void
    {
        $user = $this->createUser();
        $this->setOrgProTier($user->org);
        $other = $this->createUser();

        $this->assertFalse(OttoAccess::userCanUseOtto($user));

        session(['admin_impersonator' => ['id' => 1, 'name' => 'Staff']]);
        setPermissionsTeamId($other->org_id);

        $this->assertTrue(OttoAccess::userCanUseOtto($user->fresh()));
        $this->assertEquals($user->org_id, getPermissionsTeamId());
    }

    public function test_impersonator_cannot_use_otto_when_org_is_below_pro_lite(): void
    {
        $user = $this->createUser();
        $this->setOrgProTier($user->org, ProTierEnum::BASIC);
        session(['admin_impersonator' => ['id' => 1, 'name' => 'Staff']]);

        $this->assertFalse(OttoAccess::userCanUseOtto($user->fresh()));
    }

    public function test_http_chat_forbidden_when_org_is_below_pro_lite(): void
    {
        $user = $this->createUser();
        setPermissionsTeamId($user->org_id);
        $user->givePermissionTo('use-otto');
        $this->actingAs($user);

        $this->getJson(route('otto.conversations.index'))->assertForbidden();
        $this->postJson(route('otto.conversations.store'), ['message' => 'hello'])->assertForbidden();
    }

    public function test_http_send_dispatches_run_otto_chat(): void
    {
        Queue::fake();

        $user = $this->createUser();
        $this->setOrgProTier($user->org);
        setPermissionsTeamId($user->org_id);
        $user->givePermissionTo('use-otto');

        $this->actingAs($user);

        $this->postJson(route('otto.disclaimer'))->assertSuccessful();
        $this->postJson(route('otto.conversations.store'), [
            'message' => 'Find scans for ABC1234',
        ])->assertSuccessful();

        Queue::assertPushed(RunOttoChat::class, function (RunOttoChat $job) use ($user) {
            return $job->user->is($user)
                && $job->prompt === 'Find scans for ABC1234'
                && $job->queue === '{otto}'
                && $job->adminImpersonator === null;
        });
    }

    public function test_http_send_copies_admin_impersonator_onto_job(): void
    {
        Queue::fake();

        $user = $this->createUser();
        $this->setOrgProTier($user->org);

        $impersonator = ['id' => 42, 'name' => 'Staff'];
        session(['admin_impersonator' => $impersonator]);

        $this->actingAs($user);

        $this->postJson(route('otto.disclaimer'))->assertSuccessful();
        $this->postJson(route('otto.conversations.store'), [
            'message' => 'Where are my drivers?',
        ])->assertSuccessful();

        Queue::assertPushed(RunOttoChat::class, function (RunOttoChat $job) use ($user, $impersonator) {
            return $job->user->is($user)
                && $job->prompt === 'Where are my drivers?'
                && $job->adminImpersonator === $impersonator;
        });
    }

    public function test_fullscreen_results_pane_lives_in_the_vue_island(): void
    {
        $chat = (string) file_get_contents(resource_path('js/otto/OttoChat.vue'));
        $pane = (string) file_get_contents(resource_path('js/otto/OttoResultsPane.vue'));
        $popout = (string) file_get_contents(resource_path('js/otto/OttoPopout.vue'));
        $driverMap = (string) file_get_contents(resource_path('js/otto/components/widgets/DriverMapWidget.vue'));

        $this->assertStringContainsString('data-otto-results-pane', $chat);
        $this->assertStringContainsString('v-if="conversationId && fullscreen"', $chat);
        $this->assertStringContainsString('data-otto-results-root', $pane);
        $this->assertStringContainsString('Investigation Results', $pane);
        $this->assertStringContainsString("toggleType('scout_driver')", $pane);
        $this->assertStringContainsString('Drivers', $pane);
        $this->assertStringContainsString('ref="mapContainer"', $pane);
        $this->assertStringContainsString('otto-open-fullscreen', $popout);
        $this->assertStringContainsString('data-otto-widget="driver-map"', $driverMap);
    }

    public function test_fullscreen_history_sidebar_collapses_and_relocates_new_button(): void
    {
        $chat = (string) file_get_contents(resource_path('js/otto/OttoChat.vue'));

        $this->assertStringContainsString('v-if="fullscreen && !historyCollapsed"', $chat);
        $this->assertStringContainsString('data-otto-history-sidebar', $chat);
        $this->assertStringContainsString('aria-label="Collapse history"', $chat);
        $this->assertStringContainsString('data-otto-history-new="sidebar"', $chat);
        $this->assertStringContainsString('v-if="fullscreen && historyCollapsed"', $chat);
        $this->assertStringContainsString('data-otto-history-collapsed-bar', $chat);
        $this->assertStringContainsString('aria-label="Expand history"', $chat);
        $this->assertStringContainsString('data-otto-history-new="chat"', $chat);
        $this->assertStringContainsString("localStorage.getItem(HISTORY_COLLAPSED_KEY) === '1'", $chat);
        $this->assertStringContainsString('v-if="conversationId && fullscreen"', $chat);

        $sidebar = substr($chat, (int) strpos($chat, 'data-otto-history-sidebar'), 1800);
        $collapsedBar = substr($chat, (int) strpos($chat, 'data-otto-history-collapsed-bar'), 1600);

        $this->assertStringContainsString('data-otto-history-new="sidebar"', $sidebar);
        $this->assertStringNotContainsString('data-otto-history-new="chat"', $sidebar);
        $this->assertStringContainsString('data-otto-history-new="chat"', $collapsedBar);
        $this->assertStringContainsString('@click="startNewConversation"', $collapsedBar);
        $this->assertStringNotContainsString('data-otto-history-sidebar', $collapsedBar);
    }

    /**
     * @return array{0: User}
     */
    private function createScoutAdmin(): array
    {
        $org = Org::factory()->create();
        $this->makeOrgPro($org, FeatureEnum::CLARITY_SCOUT->value);

        $admin = User::factory()->forOrg($org)->create();
        setPermissionsTeamId($org->id);
        $admin->assignRole(RoleEnum::ADMIN->value);

        return [$admin->fresh()];
    }
}
