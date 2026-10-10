<?php

declare(strict_types=1);

namespace App\Enums;

use ArchTech\Enums\Meta\Meta;
use ArchTech\Enums\Metadata;
use Illuminate\Support\Str;
use InsightLPR\BlackAdam\Enum\Concern\EnumHasValues;
use InsightLPR\BlackAdam\Enum\Concern\IsDataType;
use InsightLPR\BlackAdam\Enum\MetaDataProperties\Description;
use InsightLPR\BlackAdam\Enum\MetaDataProperties\Title;

#[Meta(Title::class, Description::class)]
enum FeatureEnum: string
{
    use EnumHasValues;
    use IsDataType;
    use Metadata;

    #[Title('Shared Kits')]
    #[Description('Share kits across organizations to collaborate on investigations and recovery operations.')]
    case SHARED_KITS = 'platform_repo_pro_shared_kits';

    #[Title('Invites')]
    #[Description('Invite new users to your organization and manage team access with custom permissions.')]
    case INVITES = 'platform_repo_pro_invites';

    #[Title('Scan Clustering')]
    #[Description('Group nearby scans into location clusters to identify high-activity areas and optimize routes.')]
    case SCAN_CLUSTERING = 'platform_repo_pro_scan_clustering';

    #[Title('Missed Opportunity')]
    #[Description('Track vehicles scanned near hotlist addresses that weren\'t on your hotlist at the time — opportunities you could have captured.')]
    case MISSED_OPPORTUNITY = 'platform_repo_pro_missed_opportunity';

    #[Title('Flex Search')]
    #[Description('Automatically generate multiple plate variations to eliminate potential OCR errors and find more matches.')]
    case FLEX_SEARCH = 'platform_repo_pro_fuzzy_search';

    #[Title('Alert Settings')]
    #[Description('Customize alert rules and notification preferences to control when and how your team gets notified about hits.')]
    case ALERT_SETTINGS = 'platform_repo_pro_alert_settings';

    #[Title('Client Analytics')]
    #[Description('View detailed analytics dashboards showing scan volume, hit rates, and recovery performance for your clients.')]
    case CLIENT_ANALYTICS = 'platform_repo_pro_client_dashboard';

    #[Title('Repo Heatmap')]
    #[Description('Visualize address density from your hotlists on a map to identify concentration areas and plan routes.')]
    case REPO_HEATMAP = 'platform_repo_pro_repo_heatmap';

    #[Title('Clickhouse Scans')]
    #[Description('Access advanced scan history and analytics powered by high-performance data processing.')]
    case CLICKHOUSE_SCANS = 'platform_repo_pro_clickhouse_scans';

    #[Title('Clickhouse Hits')]
    #[Description('Access advanced hit history and analytics powered by high-performance data processing.')]
    case CLICKHOUSE_HITS = 'platform_repo_pro_clickhouse_hits';

    #[Title('AI Notifications')]
    case AI_NOTIFICATIONS = 'platform_repo_pro_ai_notifications';

    #[Title('LPR Visits')]
    #[Description('LPR Visits is a count of how many times one of the LPR systems drove by a particular address, regardless of how many scans it may have captured.')]
    case LPR_VISITS = 'platform_repo_pro_lpr_visits';

    #[Title('Resend to Clearplan')]
    #[Description('Resend hit notifications to ClearPlan for cases that need to be reprocessed.')]
    case CLEARPLAN_RESEND = 'platform_repo_pro_clearplan';

    #[Title('Distance from Given')]
    #[Description('See how far each hit was from the nearest given address on the order.')]
    case HIT_ADDRESS_DISTANCE = 'platform_repo_pro_hit_address_distance';

    #[Title('Clarity Scout')]
    #[Description('A dedicated mobile portal for scanner operators to view trips, dashboard stats, and live map data from the field.')]
    case CLARITY_SCOUT = 'platform_repo_pro_clarity_scout';

    #[Title('Clarity Scout Trips')]
    #[Description('The Trips section of the Clarity Scout portal. Hidden for the initial rollout while trip calculation is finalized.')]
    case CLARITY_SCOUT_TRIPS = 'platform_repo_pro_clarity_scout_trips';

    #[Title('Hit Webhooks')]
    #[Description('Receive a real-time HTTP POST to your own endpoint whenever a hit fires, with the full vehicle, scan, and order details.')]
    case HIT_WEBHOOK = 'platform_repo_pro_hit_webhook';

    public function slug(): string
    {
        return Str::slug($this->title());
    }

    public static function slugs(): array
    {
        return array_map(fn (self $case) => $case->slug(), self::cases());
    }

    public static function fromSlug(string $slug): ?self
    {
        foreach (self::cases() as $case) {
            if ($case->slug() === $slug) {
                return $case;
            }
        }

        return null;
    }

    public function minimumTier(): ?ProTierEnum
    {
        foreach (ProTierEnum::cases() as $tier) {
            if (in_array($this, $tier->features(), true)) {
                return $tier;
            }
        }

        return null;
    }
}
