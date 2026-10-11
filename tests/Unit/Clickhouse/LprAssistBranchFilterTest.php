<?php

declare(strict_types=1);

namespace Tests\Unit\Clickhouse;

use App\Clickhouse\Models\LprAssist;
use PHPUnit\Framework\Attributes\Group;
use ReflectionMethod;
use Tests\TestCase;

class LprAssistBranchFilterTest extends TestCase
{
    #[Group('clickhouse')]
    #[Group('client-analytics')]
    public function test_branch_filter_uses_the_hotlists_current_branch(): void
    {
        $sql = self::buildWhereClause(orgId: 1, start: '2026-08-01 00:00:00', end: '2026-08-20 14:15:00', branchId: 42);

        $this->assertStringContainsString('la.recovery_id IN (', $sql);
        $this->assertStringContainsString('FROM clarity.hotlist FINAL', $sql);
        $this->assertStringContainsString('org_id = 1', $sql);
        $this->assertStringContainsString('_peerdb_is_deleted = 0', $sql);
        $this->assertStringContainsString('ifNull(branch_id, 0) = 42', $sql);
        $this->assertStringNotContainsString('r.branch_id', $sql);
        $this->assertStringNotContainsString('repo_branch_id', $sql);
    }

    #[Group('clickhouse')]
    #[Group('client-analytics')]
    public function test_null_branch_id_omits_the_branch_predicate(): void
    {
        $sql = self::buildWhereClause(orgId: 1, start: '2026-08-01 00:00:00', end: '2026-08-20 14:15:00', branchId: null);

        $this->assertStringNotContainsString('branch_id', $sql);
        $this->assertStringNotContainsString('repo_branch_id', $sql);
    }

    private static function buildWhereClause(
        int $orgId,
        string $start,
        string $end,
        ?int $branchId,
        string $timezone = 'UTC',
    ): string {
        $reflection = new ReflectionMethod(LprAssist::class, 'buildWhereClause');

        return $reflection->invoke(null, $orgId, $start, $end, $timezone, $branchId);
    }
}
