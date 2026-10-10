<?php

declare(strict_types=1);

namespace App\Enums;

enum ProTierEnum: string
{
    case BASIC = 'basic';
    case PRO_LITE = 'pro_lite';
    case CLARITY_PRO = 'clarity_pro';
    case CLARITY_PRO_PLUS = 'clarity_pro_plus';

    public function label(): string
    {
        return match ($this) {
            self::BASIC => 'Basic',
            self::PRO_LITE => 'Pro Lite',
            self::CLARITY_PRO => 'Clarity Pro',
            self::CLARITY_PRO_PLUS => 'Clarity Pro Plus',
        };
    }

    public function price(): int
    {
        return match ($this) {
            self::BASIC => 0,
            self::PRO_LITE => 249,
            self::CLARITY_PRO => 599,
            self::CLARITY_PRO_PLUS => 1199,
        };
    }

    /**
     * @return array<FeatureEnum>
     */
    public function features(): array
    {
        return match ($this) {
            self::BASIC => [],
            self::PRO_LITE => [
                FeatureEnum::FLEX_SEARCH,
                FeatureEnum::SCAN_CLUSTERING,
                FeatureEnum::REPO_HEATMAP,
                FeatureEnum::HIT_ADDRESS_DISTANCE,
                FeatureEnum::LPR_VISITS,
            ],
            self::CLARITY_PRO => [
                FeatureEnum::FLEX_SEARCH,
                FeatureEnum::SCAN_CLUSTERING,
                FeatureEnum::REPO_HEATMAP,
                FeatureEnum::CLIENT_ANALYTICS,
                FeatureEnum::MISSED_OPPORTUNITY,
                FeatureEnum::ALERT_SETTINGS,
                FeatureEnum::SHARED_KITS,
                FeatureEnum::INVITES,
                FeatureEnum::LPR_VISITS,
                FeatureEnum::CLICKHOUSE_SCANS,
                FeatureEnum::CLICKHOUSE_HITS,
                FeatureEnum::CLEARPLAN_RESEND,
                FeatureEnum::HIT_ADDRESS_DISTANCE,
            ],
            self::CLARITY_PRO_PLUS => [
                FeatureEnum::FLEX_SEARCH,
                FeatureEnum::SCAN_CLUSTERING,
                FeatureEnum::REPO_HEATMAP,
                FeatureEnum::CLIENT_ANALYTICS,
                FeatureEnum::MISSED_OPPORTUNITY,
                FeatureEnum::ALERT_SETTINGS,
                FeatureEnum::SHARED_KITS,
                FeatureEnum::INVITES,
                FeatureEnum::LPR_VISITS,
                FeatureEnum::CLICKHOUSE_SCANS,
                FeatureEnum::CLICKHOUSE_HITS,
                FeatureEnum::CLEARPLAN_RESEND,
                FeatureEnum::HIT_ADDRESS_DISTANCE,
                FeatureEnum::HIT_WEBHOOK,
            ],
        };
    }

    public function isSelfSubscribable(): bool
    {
        return match ($this) {
            self::BASIC, self::PRO_LITE, self::CLARITY_PRO => true,
            self::CLARITY_PRO_PLUS => false,
        };
    }
}
