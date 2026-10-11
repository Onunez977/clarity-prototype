<?php

declare(strict_types=1);

namespace Tests\Unit\Clickhouse\Queries;

use App\Clickhouse\Models\LprAssist;
use App\Clickhouse\Queries\Client\ClientRankedMetricsBatch;
use PHPUnit\Framework\Attributes\Group;
use ReflectionMethod;
use Tests\TestCase;

class ClientRankedMetricsBatchTimezoneTest extends TestCase
{
    #[Group('clickhouse')]
    #[Group('client-analytics')]
    public function test_lpr_assist_window_keeps_original_caller_timezone_bounds(): void
    {
        $window = ClientRankedMetricsBatch::lprAssistWindow([
            'start' => '2026-08-01 00:00:00',
            'end' => '2026-08-20 23:59:59',
            'timezone' => 'America/Chicago',
        ]);

        $this->assertSame('2026-08-01 00:00:00', $window['start']);
        $this->assertSame('2026-08-20 23:59:59', $window['end']);
        $this->assertSame('America/Chicago', $window['timezone']);

        $sql = self::buildWhereClause(
            orgId: 1,
            start: $window['start'],
            end: $window['end'],
            timezone: $window['timezone'],
        );

        $this->assertStringContainsString("r.repo_date <= '2026-08-20'", $sql);
        $this->assertStringNotContainsString("r.repo_date <= '2026-08-21'", $sql);
    }

    private static function buildWhereClause(int $orgId, string $start, string $end, string $timezone): string
    {
        $reflection = new ReflectionMethod(LprAssist::class, 'buildWhereClause');

        return $reflection->invoke(null, $orgId, $start, $end, $timezone, null);
    }
}
