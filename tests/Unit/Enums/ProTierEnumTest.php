<?php

declare(strict_types=1);

namespace Tests\Unit\Enums;

use App\Enums\FeatureEnum;
use App\Enums\ProTierEnum;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

class ProTierEnumTest extends TestCase
{
    #[Group('enums')]
    #[Group('pro-tiers')]
    public function test_clarity_pro_plus_label(): void
    {
        $this->assertSame('Clarity Pro Plus', ProTierEnum::CLARITY_PRO_PLUS->label());
    }

    #[Group('enums')]
    #[Group('pro-tiers')]
    public function test_clarity_pro_plus_price(): void
    {
        $this->assertSame(1199, ProTierEnum::CLARITY_PRO_PLUS->price());
    }

    #[Group('enums')]
    #[Group('pro-tiers')]
    public function test_clarity_pro_plus_is_a_superset_of_clarity_pro(): void
    {
        $proFeatures = ProTierEnum::CLARITY_PRO->features();
        $proPlusFeatures = ProTierEnum::CLARITY_PRO_PLUS->features();

        foreach ($proFeatures as $feature) {
            $this->assertContains($feature, $proPlusFeatures);
        }
    }

    #[Group('enums')]
    #[Group('pro-tiers')]
    public function test_hit_webhook_is_clarity_pro_plus_only(): void
    {
        $this->assertContains(FeatureEnum::HIT_WEBHOOK, ProTierEnum::CLARITY_PRO_PLUS->features());
        $this->assertNotContains(FeatureEnum::HIT_WEBHOOK, ProTierEnum::CLARITY_PRO->features());
    }

    #[Group('enums')]
    #[Group('pro-tiers')]
    public function test_clarity_scout_is_not_bundled_into_any_pro_tier(): void
    {
        // DEV-2115: Scout requires an explicit per-org grant; tier membership alone
        // must NOT grant it. It therefore belongs to no tier and has no minimum tier.
        $this->assertNotContains(FeatureEnum::CLARITY_SCOUT, ProTierEnum::CLARITY_PRO->features());
        $this->assertNotContains(FeatureEnum::CLARITY_SCOUT, ProTierEnum::CLARITY_PRO_PLUS->features());
        $this->assertNull(FeatureEnum::CLARITY_SCOUT->minimumTier());
    }

    #[Group('enums')]
    #[Group('pro-tiers')]
    public function test_is_self_subscribable(): void
    {
        $this->assertTrue(ProTierEnum::BASIC->isSelfSubscribable());
        $this->assertTrue(ProTierEnum::PRO_LITE->isSelfSubscribable());
        $this->assertTrue(ProTierEnum::CLARITY_PRO->isSelfSubscribable());
        $this->assertFalse(ProTierEnum::CLARITY_PRO_PLUS->isSelfSubscribable());
    }
}
