<?php

declare(strict_types=1);

namespace Tests\Unit\Clickhouse;

use App\Clickhouse\Models\LprAssist;
use PHPUnit\Framework\Attributes\Group;
use ReflectionMethod;
use Tests\TestCase;

class LprAssistTimezoneTest extends TestCase
{
    #[Group('clickhouse')]
    #[Group('client-analytics')]
    public function test_date_bounds_use_caller_timezone_calendar_day_not_utc_shift(): void
    {
        $sql = self::buildWhereClause(
            orgId: 1,
            start: '2026-08-01 00:00:00',
            end: '2026-08-20 23:59:59',
            timezone: 'America/Chicago',
        );

        $this->assertStringContainsString("r.repo_date >= '2026-08-01'", $sql);
        $this->assertStringContainsString("r.repo_date <= '2026-08-20'", $sql);
        $this->assertStringNotContainsString("r.repo_date >= '2026-08-21'", $sql);
        $this->assertStringNotContainsString("r.repo_date < '2026-08-21'", $sql);
    }

    #[Group('clickhouse')]
    #[Group('client-analytics')]
    public function test_end_bound_is_inclusive_on_repo_date(): void
    {
        $sql = self::buildWhereClause(
            orgId: 1,
            start: '2026-08-01 00:00:00',
            end: '2026-08-20 14:15:00',
            timezone: 'UTC',
        );

        $this->assertStringContainsString("r.repo_date <= '2026-08-20'", $sql);
        $this->assertStringNotContainsString("r.repo_date < '2026-08-20'", $sql);
    }

    private static function buildWhereClause(
        int $orgId,
        string $start,
        string $end,
        string $timezone = 'UTC',
        ?int $branchId = null,
    ): string {
        $reflection = new ReflectionMethod(LprAssist::class, 'buildWhereClause');

        return $reflection->invoke(null, $orgId, $start, $end, $timezone, $branchId);
    }
}
