<?php

declare(strict_types=1);

namespace Tests\Feature\Otto;

use App\Ai\Agents\Otto;
use App\Ai\PageContext;
use App\Ai\Surface;
use App\Ai\Tools\Metrics\ClientMissedOpportunitiesTool;
use App\Ai\Tools\Metrics\CompanyMissedOpportunitiesTool;
use App\Ai\Tools\Metrics\CurrentOpenAssignmentsTool;
use App\Ai\Tools\Metrics\HotlistCountTool;
use App\Ai\Tools\Metrics\RecoveryStatsTool;
use App\Ai\Tools\Metrics\TotalAssignmentsTool;
use App\Ai\Tools\Metrics\UniqueScanCountsTool;
use App\Ai\Tools\Metrics\WidgetMetricTool;
use App\Ai\Tools\ToolRegistry;
use App\Data\AgentInvestigation\AgentInvestigationProperties;
use App\Enums\FeatureEnum;
use App\Models\Hotlist;
use App\Models\Investigation;
use App\Models\Org;
use App\Support\Otto\WidgetMetric;
use InsightLPR\BlackAdam\Enum\PermissionEnum;
use InsightLPR\BlackAdam\Enum\RoleEnum;
use Laravel\Ai\Tools\Request;
use Laravel\Pennant\Feature;
use Tests\TestCase;

class PageMetricsToolTest extends TestCase
{
    public function test_each_widget_metric_tool_is_registered_for_every_otto_user(): void
    {
        $registered = app(ToolRegistry::class)->registered();

        foreach (WidgetMetricTool::registered() as $toolClass) {
            $this->assertContains($toolClass, $registered);
        }

        $user = $this->createUser();
        $tool = new UniqueScanCountsTool($user, new Investigation);

        $this->assertTrue($tool->authorizedFor($user));
        $this->assertTrue($tool->availableOnPage(null));
        $this->assertTrue($tool->availableOnPage(new PageContext('scan-search', 'Scans', [])));
        $this->assertStringNotContainsString('current page', strtolower((string) $tool->description()));
        $this->assertStringNotContainsString('page', strtolower((string) (new HotlistCountTool($user, new Investigation))->description()));
    }

    public function test_otto_can_call_one_widget_and_several_in_one_turn_without_a_page(): void
    {
        $admin = $this->createUser(role: RoleEnum::ADMIN->value);
        $this->makeOrgPro($admin->org, FeatureEnum::CLIENT_ANALYTICS->value);
        Feature::flushCache();

        $client = Org::factory()->create(['name' => 'Northwind Recovery']);
        Hotlist::factory()->forOrg($admin->org)->create(['client_org_id' => $client->id]);

        $investigation = new Investigation;
        $investigation->data = new AgentInvestigationProperties;
        $otto = new Otto($admin, $investigation, Surface::Chat);

        $classes = array_map(fn (object $tool): string => $tool::class, iterator_to_array($otto->tools()));
        $this->assertContains(CompanyMissedOpportunitiesTool::class, $classes);
        $this->assertContains(HotlistCountTool::class, $classes);
        $this->assertContains(TotalAssignmentsTool::class, $classes);
        $this->assertContains(CurrentOpenAssignmentsTool::class, $classes);
        $this->assertContains(RecoveryStatsTool::class, $classes);
        $this->assertNotContains('App\\Ai\\Tools\\PageMetricsTool', $classes);

        $company = $this->metric(new CompanyMissedOpportunitiesTool($admin, $investigation), []);
        $this->assertStringContainsString('Missed Opportunities:', $company);
        $this->assertStringNotContainsString('not available for this account', $company);

        $open = $this->metric(new HotlistCountTool($admin, $investigation), []);
        $this->assertStringContainsString('Total Open Assignments:', $open);

        $assignments = $this->metric(new TotalAssignmentsTool($admin, $investigation), []);
        $this->assertStringContainsString('Total Assignments:', $assignments);
        $this->assertStringNotContainsString('account manager', strtolower($assignments));

        $oneClient = $this->metric(new CurrentOpenAssignmentsTool($admin, $investigation), [
            'client_name' => 'Northwind',
        ]);
        $this->assertStringContainsString('Northwind Recovery', $oneClient);
        $this->assertStringContainsString('Current Open Assignments:', $oneClient);

        $recoveries = $this->metric(new RecoveryStatsTool($admin, $investigation), []);
        $this->assertStringContainsString('Total Recoveries:', $recoveries);

        $this->assertFalse($investigation->toolCallLimitReached(6));

        $prompt = (string) $otto->instructions();
        $this->assertStringContainsString('call each of those tools in this turn', $prompt);
        $this->assertStringNotContainsString('current page', strtolower($prompt));
    }

    public function test_a_forbidden_or_locked_widget_does_not_return_the_number(): void
    {
        $denied = $this->createUser();
        $investigation = new Investigation;
        $investigation->data = new AgentInvestigationProperties;

        $company = $this->metric(new UniqueScanCountsTool($denied, $investigation), [
            'period' => 'this month',
        ]);
        $this->assertSame(WidgetMetric::COMPANY_METRICS_DENIED, $company);
        $this->assertStringNotContainsString('Scan Counts:', $company);
        $this->assertStringNotContainsString('account manager', strtolower($company));

        $open = $this->metric(new HotlistCountTool($denied, $investigation), []);
        $this->assertSame(WidgetMetric::OPEN_ASSIGNMENTS_DENIED, $open);
        $this->assertStringNotContainsString('Total Open Assignments:', $open);
        $this->assertStringNotContainsString('Clarity Pro', $open);

        $kit = $this->metric(new RecoveryStatsTool($denied, $investigation), []);
        $this->assertSame(WidgetMetric::KIT_RECOVERIES_DENIED, $kit);
        $this->assertStringNotContainsString('Total Recoveries:', $kit);
        $this->assertStringNotContainsString('Clarity Pro', $kit);

        $admin = $this->createUser(role: RoleEnum::ADMIN->value);
        $upsell = $this->metric(new TotalAssignmentsTool($admin, new Investigation), []);
        $this->assertSame(WidgetMetric::CLIENT_ANALYTICS_UPSELL, $upsell);
        $this->assertStringNotContainsString('Total Assignments:', $upsell);

        $oneClientUpsell = $this->metric(new CurrentOpenAssignmentsTool($admin, new Investigation), [
            'client_name' => 'Northwind',
        ]);
        $this->assertSame(WidgetMetric::CLIENT_ANALYTICS_UPSELL, $oneClientUpsell);
        $this->assertStringNotContainsString('Current Open Assignments:', $oneClientUpsell);

        $member = $this->createUser(role: RoleEnum::USER->value);
        $this->makeOrgPro($member->org, FeatureEnum::CLIENT_ANALYTICS->value);
        Feature::flushCache();

        $roleDenial = $this->metric(new TotalAssignmentsTool($member, new Investigation), []);
        $this->assertSame(WidgetMetric::CLIENT_ANALYTICS_ROLE, $roleDenial);
        $this->assertStringNotContainsString('account manager', strtolower($roleDenial));
        $this->assertStringNotContainsString('Total Assignments:', $roleDenial);
    }

    public function test_missed_opportunities_upsells_when_that_premium_feature_is_off(): void
    {
        $user = $this->createUser(role: RoleEnum::ADMIN->value);
        $this->makeOrgPro($user->org, FeatureEnum::CLIENT_ANALYTICS->value);
        $meta = $user->org->refresh()->meta;
        $properties = $meta->properties;
        $properties[FeatureEnum::MISSED_OPPORTUNITY->value] = false;
        $meta->update(['properties' => $properties]);
        Feature::flushCache();
        config(['app.env' => 'production']);

        $result = $this->metric(new ClientMissedOpportunitiesTool($user, new Investigation), []);

        $this->assertSame(WidgetMetric::MISSED_OPPORTUNITY_UPSELL, $result);
        $this->assertStringNotContainsString('Missed Opportunities:', $result);
    }

    public function test_impersonation_can_read_client_analytics_without_the_feature(): void
    {
        $user = $this->createUser(role: RoleEnum::ADMIN->value);
        session(['admin_impersonator' => ['id' => 1, 'name' => 'Staff']]);

        $result = $this->metric(new TotalAssignmentsTool($user, new Investigation), []);

        $this->assertStringNotContainsString('account manager', strtolower($result));
        $this->assertStringContainsString('Total Assignments:', $result);
    }

    public function test_company_metric_rejects_a_period_the_widget_does_not_use(): void
    {
        $user = $this->createUser(role: RoleEnum::ADMIN->value);

        $result = $this->metric(new UniqueScanCountsTool($user, new Investigation), [
            'period' => 'yesterday',
        ]);

        $this->assertStringContainsString('does not include yesterday', strtolower($result));
        $this->assertStringContainsString('This month', $result);
        $this->assertStringNotContainsString('Scan Counts:', $result);
    }

    public function test_refuses_a_client_that_is_not_this_organizations(): void
    {
        $user = $this->createUser(role: RoleEnum::ADMIN->value);
        $this->makeOrgPro($user->org, FeatureEnum::CLIENT_ANALYTICS->value);
        Feature::flushCache();

        $result = $this->metric(new CurrentOpenAssignmentsTool($user, new Investigation), [
            'client_id' => 999999,
        ]);

        $this->assertStringContainsString('not one of this organization', strtolower($result));
        $this->assertStringNotContainsString('Current Open Assignments:', $result);
    }

    public function test_kit_user_recoveries_follow_the_report_permission(): void
    {
        $allowed = $this->createUser(permissions: PermissionEnum::Reports_View_Kit_User_Recoveries_Report->value);

        $result = $this->metric(new RecoveryStatsTool($allowed, new Investigation), [
            'period' => 'this month',
        ]);

        $this->assertStringContainsString('Total Recoveries:', $result);
        $this->assertStringNotContainsString(WidgetMetric::KIT_RECOVERIES_DENIED, $result);
    }

    public function test_an_exact_client_name_wins_over_names_that_contain_it(): void
    {
        $user = $this->createUser(role: RoleEnum::ADMIN->value);
        $this->makeOrgPro($user->org, FeatureEnum::CLIENT_ANALYTICS->value);
        Feature::flushCache();

        $ally = Org::factory()->create(['name' => 'Ally']);
        $allyFinancial = Org::factory()->create(['name' => 'Ally Financial']);
        Hotlist::factory()->forOrg($user->org)->create(['client_org_id' => $ally->id]);
        Hotlist::factory()->forOrg($user->org)->create(['client_org_id' => $allyFinancial->id]);

        $exact = $this->metric(new CurrentOpenAssignmentsTool($user, new Investigation), [
            'client_name' => 'ally',
        ]);
        $this->assertStringStartsWith('Ally, ', $exact);
        $this->assertStringNotContainsString('Ally Financial', $exact);
        $this->assertStringNotContainsString('Several clients matched', $exact);

        $partial = $this->metric(new CurrentOpenAssignmentsTool($user, new Investigation), [
            'client_name' => 'All',
        ]);
        $this->assertStringContainsString('Several clients matched', $partial);
    }

    public function test_reading_a_metric_does_not_cycle_the_users_remember_token(): void
    {
        $user = $this->createUser(role: RoleEnum::ADMIN->value);
        $user->forceFill(['remember_token' => 'kept-remember-token'])->save();
        auth()->forgetUser();

        $this->metric(new HotlistCountTool($user, new Investigation), []);

        $this->assertSame('kept-remember-token', $user->fresh()->remember_token);
        $this->assertFalse(auth()->hasUser());
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function metric(WidgetMetricTool $tool, array $payload): string
    {
        return (string) $tool->handle(new Request($payload));
    }
}
