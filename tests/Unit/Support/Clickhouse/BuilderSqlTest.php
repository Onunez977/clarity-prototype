<?php

namespace Tests\Unit\Support\Clickhouse;

use App\Clickhouse\Models\Recovery;
use App\Support\Clickhouse\BuilderSql;
use PHPUnit\Framework\Attributes\Group;
use ReflectionMethod;
use Tests\TestCase;

class BuilderSqlTest extends TestCase
{
    #[Group('clickhouse')]
    public function test_count_of_emits_what_count_actually_compiles(): void
    {
        $query = fn () => Recovery::query()->forOrg(42)->between('2026-08-01', '2026-08-31');

        // The reference string: what Illuminate\Database\Query\Builder::aggregate() builds
        // before handing it to the connection. setAggregate() is protected, hence reflection --
        // comparing against anything looser would let countOf() drift undetected.
        $base = $query()->toBase();
        $clone = $base->cloneWithout($base->unions || $base->havings ? [] : ['columns'])
            ->cloneWithoutBindings($base->unions || $base->havings ? [] : ['select']);

        $setAggregate = new ReflectionMethod($clone, 'setAggregate');
        $setAggregate->setAccessible(true);
        $setAggregate->invoke($clone, 'count', ['*']);

        // Both sides interpolate through BuilderSql::of(), so this compares the clone +
        // aggregate steps, not the binding substitution (covered separately below).
        $this->assertSame(
            $this->normalize(BuilderSql::of($clone)),
            $this->normalize(BuilderSql::countOf($query())),
            'countOf() drifted from Builder::aggregate()',
        );
    }

    #[Group('clickhouse')]
    public function test_global_scopes_reach_the_emitted_sql(): void
    {
        // toBase() rather than getQuery(): FiltersCdcDeletes / ApplyFinal are global scopes,
        // and getQuery() would emit SQL the app never actually runs.
        $sql = BuilderSql::countOf(Recovery::query()->forOrg(42)->between('2026-08-01', '2026-08-31'));

        $this->assertStringContainsString('_peerdb_is_deleted', $sql);
    }

    #[Group('clickhouse')]
    public function test_no_placeholders_survive_interpolation(): void
    {
        $sql = BuilderSql::countOf(Recovery::query()->forOrg(42)->between('2026-08-01', '2026-08-31'));

        $this->assertStringNotContainsString('?', $sql);
        $this->assertStringContainsString('42', $sql);
        $this->assertStringContainsString('2026-08-01', $sql);
    }

    private function normalize(string $sql): string
    {
        return trim(preg_replace('/\s+/', ' ', $sql));
    }
}
