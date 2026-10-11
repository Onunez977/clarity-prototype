<?php

declare(strict_types=1);

namespace Tests\Unit\Clickhouse;

use App\Clickhouse\Queries\ScanPlateStatsBatchQuery;
use App\Clickhouse\Queries\StagingLiveHitLeaderQuery;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\RequiresClickhouse;
use Tests\TestCase;

/**
 * SQL, cache-key, and zone guards for the staging-live hit rollup. These stay off
 * {@see RequiresClickhouse}: Laravel calls setUpRequiresClickhouse()
 * for every method of a class that uses that trait, which would hide these guards
 * whenever CLICKHOUSE_DRIVER is not clickhouse.
 */
#[Group('clickhouse')]
class StagingLiveHitLeaderQueryContractTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_handle_range_sql_keeps_the_staging_live_shape(): void
    {
        $sql = StagingLiveHitLeaderQuery::handleRangeSql([
            'org_id' => 742,
            'start' => '2026-06-01 00:00:00',
            'end' => '2026-06-15 23:59:59',
            'timezone' => 'UTC',
        ]);

        $this->assertStringContainsString("uniqExactIf(ocr, hit_type = 'S')", $sql);
        $this->assertStringContainsString('kit_org_id', $sql);
        $this->assertStringContainsString('kit_id', $sql);
        $this->assertStringContainsString('WITH ROLLUP', $sql);
        $this->assertStringContainsString('GROUPING(kit_id)', $sql);
        $this->assertStringNotContainsString('hotlist_org_id', $sql);
        $this->assertStringNotContainsString('count(', $sql);
        $this->assertStringNotContainsString('safeTimezone', $sql);
    }

    public function test_handle_range_sql_converts_a_non_utc_bound_before_interpolation(): void
    {
        $sql = StagingLiveHitLeaderQuery::handleRangeSql([
            'org_id' => 742,
            'start' => '2026-08-15 00:00:00',
            'end' => '2026-08-15 23:59:59',
            'timezone' => 'America/Chicago',
        ]);

        $this->assertStringContainsString("toDateTime('2026-08-15 05:00:00')", $sql);
        $this->assertStringContainsString("toDateTime('2026-08-16 04:59:59')", $sql);
        $this->assertStringNotContainsString('America/Chicago', $sql);
        $this->assertStringNotContainsString("toDateTime('2026-08-15 00:00:00')", $sql);
    }

    public function test_handle_range_sql_does_not_sanitise_an_unlisted_zone(): void
    {
        $sql = StagingLiveHitLeaderQuery::handleRangeSql([
            'org_id' => 742,
            'start' => '2026-08-15 00:00:00',
            'end' => '2026-08-15 23:59:59',
            'timezone' => 'CST',
        ]);

        $this->assertSame('UTC', ScanPlateStatsBatchQuery::safeTimezone('CST'));
        $this->assertStringContainsString("toDateTime('2026-08-15 06:00:00')", $sql);
        $this->assertStringNotContainsString("toDateTime('2026-08-15 00:00:00')", $sql);
        $this->assertStringNotContainsString('CST', $sql);
    }

    public function test_handle_range_throws_on_malformed_start_datetime(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        StagingLiveHitLeaderQuery::handleRange([
            'org_id' => 742,
            'start' => 'not-a-date',
            'end' => '2026-06-30 23:59:59',
            'timezone' => 'UTC',
        ]);
    }

    public function test_shared_range_cache_key_is_class_neutral(): void
    {
        $key = StagingLiveHitLeaderQuery::sharedRangeCacheKey(
            742,
            '2026-06-01 00:00:00',
            '2026-06-15 23:59:59',
            'America/Chicago',
        );

        $this->assertStringContainsString('-v1', $key);
        $this->assertStringContainsString(':tz=', $key);
        $this->assertStringNotContainsString(' ', $key);
        $this->assertStringNotContainsString('StagingLiveHitLeader', $key);
        $this->assertStringNotContainsString('staging-live-hit-leader-query', $key);
        $this->assertStringNotContainsString('StagingLiveHits', $key);
        $this->assertSame(
            'staging-live-hits:range-v1:org=742:2026-06-0100:00:00:2026-06-1523:59:59:tz=America/Chicago',
            $key,
        );
    }

    public function test_shared_range_cache_key_rejects_a_malformed_datetime(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        StagingLiveHitLeaderQuery::sharedRangeCacheKey(742, 'not-a-date', '2026-06-15 23:59:59', 'UTC');
    }

    public function test_month_arms_use_the_bucket_zone_and_day_arms_keep_the_raw_zone(): void
    {
        Carbon::setTestNow('2026-08-16 02:00:00');

        [$start, $end, $timezone] = StagingLiveHitLeaderQuery::boundsForCardDate(
            'this_month',
            'CST',
            'ignored',
            'ignored',
        );

        $this->assertSame('UTC', $timezone);
        $this->assertSame(now(tz: 'UTC')->startOfMonth()->startOfDay()->toDateTimeString(), $start);
        $this->assertSame(now(tz: 'UTC')->endOfDay()->toDateTimeString(), $end);

        [$rawStart, $rawEnd, $rawZone] = StagingLiveHitLeaderQuery::boundsForCardDate(
            'today',
            'CST',
            '2026-08-15 00:00:00',
            '2026-08-15 23:59:59',
        );

        $this->assertSame('CST', $rawZone);
        $this->assertSame('2026-08-15 00:00:00', $rawStart);
        $this->assertSame('2026-08-15 23:59:59', $rawEnd);
    }
}
