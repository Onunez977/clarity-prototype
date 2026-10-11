<?php

namespace Tests;

use LaravelClickhouseEloquent\Builder;
use LaravelClickhouseEloquent\RawColumn;
use Tests\Models\Example;

class CteTest extends TestCase
{
    /**
     * Test withCte() with a closure generates correct SQL.
     */
    public function testWithCteClosure(): void
    {
        $query = Example::select()
            ->withCte('active', function (Builder $q) {
                $q->select('*')->from('users')->where('active', '=', 1);
            })
            ->from('active');

        $sql = $query->toSql();

        $this->assertStringContainsString('WITH', $sql);
        $this->assertStringContainsString('active AS (select', $sql);
        $this->assertStringContainsString('from `active`', $sql);
    }

    /**
     * Test withCte() with raw SQL string generates correct SQL.
     */
    public function testWithCteRawString(): void
    {
        $query = Example::select()
            ->withCte('filtered', 'SELECT * FROM examples WHERE f_int > 10')
            ->from('filtered');

        $sql = $query->toSql();

        $this->assertStringContainsString('WITH', $sql);
        $this->assertStringContainsString('filtered AS (SELECT * FROM examples WHERE f_int > 10)', $sql);
    }

    /**
     * Test withCte() with existing Builder instance generates correct SQL.
     */
    public function testWithCteBuilderInstance(): void
    {
        $subquery = Example::select('f_int', 'f_string')->where('f_int', '>', 5);

        $query = Example::select()
            ->withCte('sub', $subquery)
            ->from('sub');

        $sql = $query->toSql();

        $this->assertStringContainsString('WITH', $sql);
        $this->assertStringContainsString('sub AS (select', $sql);
    }

    /**
     * Test withCteExpression() with integer generates correct SQL.
     */
    public function testWithCteExpressionInteger(): void
    {
        $query = Example::select()
            ->withCteExpression('threshold', 100)
            ->where('f_int', '>', new RawColumn('threshold'));

        $sql = $query->toSql();

        $this->assertStringContainsString('WITH', $sql);
        $this->assertStringContainsString('100 AS threshold', $sql);
    }

    /**
     * Test withCteExpression() with string generates quoted SQL.
     */
    public function testWithCteExpressionString(): void
    {
        $query = Example::select()
            ->withCteExpression('prefix', 'user_');

        $sql = $query->toSql();

        $this->assertStringContainsString("'user_' AS prefix", $sql);
    }

    /**
     * Test withCteExpression() with boolean converts to 1/0.
     */
    public function testWithCteExpressionBoolean(): void
    {
        $queryTrue = Example::select()->withCteExpression('flag', true);
        $queryFalse = Example::select()->withCteExpression('flag', false);

        $this->assertStringContainsString('1 AS flag', $queryTrue->toSql());
        $this->assertStringContainsString('0 AS flag', $queryFalse->toSql());
    }

    /**
     * Test withCteExpression() with RawColumn generates unquoted SQL.
     */
    public function testWithCteExpressionRawColumn(): void
    {
        $query = Example::select()
            ->withCteExpression('today', new RawColumn('today()'));

        $sql = $query->toSql();

        $this->assertStringContainsString('today() AS today', $sql);
    }

    /**
     * Test withCteExpression() with closure generates subquery SQL.
     */
    public function testWithCteExpressionClosure(): void
    {
        $query = Example::select()
            ->withCteExpression('max_val', function (Builder $q) {
                $q->selectRaw('max(f_int)')->from('examples');
            });

        $sql = $query->toSql();

        $this->assertStringContainsString('(select max(f_int) from `examples`) AS max_val', $sql);
    }

    /**
     * Test multiple CTEs chain correctly.
     */
    public function testMultipleCtes(): void
    {
        $query = Example::select()
            ->withCteExpression('threshold', 50)
            ->withCte('filtered', function (Builder $q) {
                $q->select('*')->from('examples')->where('f_int', '>', new RawColumn('threshold'));
            })
            ->from('filtered');

        $sql = $query->toSql();

        $this->assertStringContainsString('WITH', $sql);
        $this->assertStringContainsString('50 AS threshold', $sql);
        $this->assertStringContainsString('filtered AS (select', $sql);
        // Verify order: expressions before subqueries in SQL
        $thresholdPos = strpos($sql, '50 AS threshold');
        $filteredPos = strpos($sql, 'filtered AS (select');
        $this->assertLessThan($filteredPos, $thresholdPos);
    }

    /**
     * Test CTE with SETTINGS clause - CTE should come before SELECT, SETTINGS after.
     */
    public function testCteWithSettings(): void
    {
        $query = Example::select()
            ->withCteExpression('n', 10)
            ->settings(['max_threads' => 2]);

        $sql = $query->toSql();

        // WITH should be at the beginning
        $this->assertStringStartsWith('WITH', trim($sql));
        // SETTINGS should be at the end
        $this->assertStringContainsString('SETTINGS max_threads=2', $sql);
    }

    /**
     * Integration test: execute CTE query against ClickHouse.
     */
    public function testCteExecutesAgainstClickhouse(): void
    {
        // Ensure we have test data
        Example::truncate();
        usleep(1e4);
        Example::insertAssoc([
            ['f_int' => 10, 'f_string' => 'a', 'f_int2' => 1, 'created_at' => date('Y-m-d H:i:s')],
            ['f_int' => 20, 'f_string' => 'b', 'f_int2' => 2, 'created_at' => date('Y-m-d H:i:s')],
            ['f_int' => 30, 'f_string' => 'c', 'f_int2' => 3, 'created_at' => date('Y-m-d H:i:s')],
        ]);
        usleep(1e4);

        // Query using CTE
        $rows = Example::select()
            ->withCteExpression('threshold', 15)
            ->withCte('filtered', function (Builder $q) {
                $q->select('f_int', 'f_string')
                  ->from('examples')
                  ->where('f_int', '>', new RawColumn('threshold'));
            })
            ->from('filtered')
            ->getRows();

        $this->assertCount(2, $rows);
        $this->assertEquals(20, $rows[0]['f_int']);
        $this->assertEquals(30, $rows[1]['f_int']);
    }
}
