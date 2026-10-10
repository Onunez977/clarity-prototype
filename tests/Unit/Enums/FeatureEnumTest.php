<?php

declare(strict_types=1);

namespace Tests\Unit\Enums;

use App\Enums\FeatureEnum;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

class FeatureEnumTest extends TestCase
{
    #[Group('enums')]
    #[Group('features')]
    public function test_has_correct_cases(): void
    {
        $expected = [
            'SHARED_KITS' => 'platform_repo_pro_shared_kits',
            'INVITES' => 'platform_repo_pro_invites',
            'SCAN_CLUSTERING' => 'platform_repo_pro_scan_clustering',
            'MISSED_OPPORTUNITY' => 'platform_repo_pro_missed_opportunity',
            'FLEX_SEARCH' => 'platform_repo_pro_fuzzy_search',
            'ALERT_SETTINGS' => 'platform_repo_pro_alert_settings',
            'CLIENT_ANALYTICS' => 'platform_repo_pro_client_dashboard',
            'REPO_HEATMAP' => 'platform_repo_pro_repo_heatmap',
            'CLICKHOUSE_SCANS' => 'platform_repo_pro_clickhouse_scans',
            'CLICKHOUSE_HITS' => 'platform_repo_pro_clickhouse_hits',
            'AI_NOTIFICATIONS' => 'platform_repo_pro_ai_notifications',
            'LPR_VISITS' => 'platform_repo_pro_lpr_visits',
            'CLEARPLAN_RESEND' => 'platform_repo_pro_clearplan',
            'HIT_ADDRESS_DISTANCE' => 'platform_repo_pro_hit_address_distance',
            'CLARITY_SCOUT' => 'platform_repo_pro_clarity_scout',
            'CLARITY_SCOUT_TRIPS' => 'platform_repo_pro_clarity_scout_trips',
            'HIT_WEBHOOK' => 'platform_repo_pro_hit_webhook',
        ];

        foreach ($expected as $caseName => $value) {
            $this->assertTrue(
                defined(FeatureEnum::class.'::'.$caseName),
                "Case {$caseName} should exist"
            );
            $this->assertEquals($value, constant(FeatureEnum::class.'::'.$caseName)->value);
        }
    }

    #[Group('enums')]
    #[Group('features')]
    public function test_values_method_returns_all_values(): void
    {
        $values = FeatureEnum::values();

        $expected = [
            'platform_repo_pro_shared_kits',
            'platform_repo_pro_invites',
            'platform_repo_pro_scan_clustering',
            'platform_repo_pro_missed_opportunity',
            'platform_repo_pro_fuzzy_search',
            'platform_repo_pro_alert_settings',
            'platform_repo_pro_client_dashboard',
            'platform_repo_pro_repo_heatmap',
            'platform_repo_pro_clickhouse_scans',
            'platform_repo_pro_clickhouse_hits',
            'platform_repo_pro_ai_notifications',
            'platform_repo_pro_lpr_visits',
            'platform_repo_pro_clearplan',
            'platform_repo_pro_hit_address_distance',
            'platform_repo_pro_clarity_scout',
            'platform_repo_pro_clarity_scout_trips',
            'platform_repo_pro_hit_webhook',
        ];

        $this->assertCount(17, $values);
        foreach ($expected as $value) {
            $this->assertContains($value, $values);
        }
    }

    #[Group('enums')]
    #[Group('features')]
    public function test_title_returns_correct_title_for_each_case(): void
    {
        $this->assertEquals('Shared Kits', FeatureEnum::SHARED_KITS->title());
        $this->assertEquals('Invites', FeatureEnum::INVITES->title());
        $this->assertEquals('Scan Clustering', FeatureEnum::SCAN_CLUSTERING->title());
        $this->assertEquals('Missed Opportunity', FeatureEnum::MISSED_OPPORTUNITY->title());
        $this->assertEquals('Flex Search', FeatureEnum::FLEX_SEARCH->title());
        $this->assertEquals('Alert Settings', FeatureEnum::ALERT_SETTINGS->title());
        $this->assertEquals('Client Analytics', FeatureEnum::CLIENT_ANALYTICS->title());
        $this->assertEquals('Repo Heatmap', FeatureEnum::REPO_HEATMAP->title());
        $this->assertEquals('Clickhouse Scans', FeatureEnum::CLICKHOUSE_SCANS->title());
        $this->assertEquals('Clickhouse Hits', FeatureEnum::CLICKHOUSE_HITS->title());
        $this->assertEquals('AI Notifications', FeatureEnum::AI_NOTIFICATIONS->title());
        $this->assertEquals('LPR Visits', FeatureEnum::LPR_VISITS->title());
        $this->assertEquals('Resend to Clearplan', FeatureEnum::CLEARPLAN_RESEND->title());
        $this->assertEquals('Distance from Given', FeatureEnum::HIT_ADDRESS_DISTANCE->title());
        $this->assertEquals('Clarity Scout', FeatureEnum::CLARITY_SCOUT->title());
        $this->assertEquals('Clarity Scout Trips', FeatureEnum::CLARITY_SCOUT_TRIPS->title());
        $this->assertEquals('Hit Webhooks', FeatureEnum::HIT_WEBHOOK->title());

        // The Title drives the Pennant key: FeatureEnum::slug() is Str::slug(title()), and
        // PennantServiceProvider defines features by ->slug(). Renaming this Title silently
        // changes the feature key and un-gates (or hard-locks) the Company Metrics cards.
    }
}
