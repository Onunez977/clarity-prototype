<?php

declare(strict_types=1);

namespace Tests\Unit\Clickhouse\Queries;

use App\Clickhouse\Queries\HotlistAddressGeohashQuery;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

class HotlistAddressGeohashQueryTest extends TestCase
{
    #[Group('clickhouse')]
    #[Group('kit-user-path')]
    public function test_repo_and_staging_color_ramps_differ_per_tier(): void
    {
        $counts = [1, 3, 7, 15, 50];

        foreach ($counts as $count) {
            $repo = HotlistAddressGeohashQuery::getTierColor($count, staging: false);
            $staging = HotlistAddressGeohashQuery::getTierColor($count, staging: true);

            $this->assertNotSame(
                $repo,
                $staging,
                "Repo and staging ramps must produce different colors for count={$count}",
            );
        }
    }

    #[Group('clickhouse')]
    #[Group('kit-user-path')]
    public function test_combined_tier_buckets_split_at_ten_and_twenty_five(): void
    {
        $this->assertSame('tier_1', HotlistAddressGeohashQuery::getCombinedTier(1));
        $this->assertSame('tier_2_4', HotlistAddressGeohashQuery::getCombinedTier(2));
        $this->assertSame('tier_2_4', HotlistAddressGeohashQuery::getCombinedTier(4));
        $this->assertSame('tier_5_9', HotlistAddressGeohashQuery::getCombinedTier(5));
        $this->assertSame('tier_5_9', HotlistAddressGeohashQuery::getCombinedTier(9));
        $this->assertSame('tier_10_24', HotlistAddressGeohashQuery::getCombinedTier(10));
        $this->assertSame('tier_10_24', HotlistAddressGeohashQuery::getCombinedTier(24));
        $this->assertSame('tier_25_plus', HotlistAddressGeohashQuery::getCombinedTier(25));
        $this->assertSame('tier_25_plus', HotlistAddressGeohashQuery::getCombinedTier(30));
    }

    #[Group('clickhouse')]
    #[Group('kit-user-path')]
    public function test_combined_color_ramp_is_stepped_across_five_bands(): void
    {
        $one = HotlistAddressGeohashQuery::getCombinedTierColor(1);
        $low = HotlistAddressGeohashQuery::getCombinedTierColor(3);
        $mid = HotlistAddressGeohashQuery::getCombinedTierColor(7);
        $high = HotlistAddressGeohashQuery::getCombinedTierColor(12);
        $top = HotlistAddressGeohashQuery::getCombinedTierColor(40);

        $this->assertCount(5, array_unique([$one, $low, $mid, $high, $top]));
        $this->assertSame('#7B1FA2', HotlistAddressGeohashQuery::getCombinedTierColor(10));
        $this->assertSame('#4A148C', HotlistAddressGeohashQuery::getCombinedTierColor(25));
        $this->assertNotSame(
            HotlistAddressGeohashQuery::getCombinedTierColor(10),
            HotlistAddressGeohashQuery::getCombinedTierColor(25),
        );
    }

    #[Group('clickhouse')]
    #[Group('kit-user-path')]
    public function test_combined_tier_labels_read_address_counts(): void
    {
        $this->assertSame('1 address', HotlistAddressGeohashQuery::getCombinedTierLabel(1));
        $this->assertSame('2-4 addresses', HotlistAddressGeohashQuery::getCombinedTierLabel(3));
        $this->assertSame('5-9 addresses', HotlistAddressGeohashQuery::getCombinedTierLabel(6));
        $this->assertSame('10-24 addresses', HotlistAddressGeohashQuery::getCombinedTierLabel(12));
        $this->assertSame('25+ addresses', HotlistAddressGeohashQuery::getCombinedTierLabel(40));
    }
}
