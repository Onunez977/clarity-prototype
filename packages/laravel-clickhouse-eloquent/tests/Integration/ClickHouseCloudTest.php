<?php

namespace Tests\Integration;

use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use LaravelClickhouseEloquent\Builder;
use LaravelClickhouseEloquent\RawColumn;
use Tests\Models\Camera;
use Tests\TestCase;

class ClickHouseCloudTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Skip if cloud connection is not configured
        if (!env('CLICKHOUSE_CLOUD_HOST')) {
            $this->markTestSkipped('CLICKHOUSE_CLOUD_HOST not configured — copy .env.example to .env and fill in credentials');
        }
    }

    // ---------------------------------------------------------------
    // Path 1: Laravel Query Builder (Connection + QueryGrammar)
    // ---------------------------------------------------------------

    public function testLaravelSelectWithWhereBindings(): void
    {
        $rows = DB::connection('clickhouse-cloud')
            ->table('guardian.cameras')
            ->where('org_id', 2568)
            ->limit(5)
            ->get();

        $this->assertNotEmpty($rows);
        foreach ($rows as $row) {
            $this->assertEquals(2568, $row['org_id']);
        }
    }

    public function testLaravelToRawSqlEscapesStrings(): void
    {
        $builder = DB::connection('clickhouse-cloud')
            ->table('guardian.cameras')
            ->where('name', "O'Reilly");

        $rawSql = $builder->toRawSql();

        // The escapeString method should double the single quote
        $this->assertStringContainsString("'O''Reilly'", $rawSql);
        $this->assertStringNotContainsString('?', $rawSql);
        $this->assertStringNotContainsString(':0', $rawSql);
    }

    public function testLaravelToRawSqlWithMultipleBindings(): void
    {
        $builder = DB::connection('clickhouse-cloud')
            ->table('guardian.cameras')
            ->where('org_id', 2568)
            ->where('status', 1);

        $rawSql = $builder->toRawSql();

        $this->assertStringContainsString('2568', $rawSql);
        $this->assertStringContainsString('1', $rawSql);
        $this->assertStringNotContainsString('?', $rawSql);
        $this->assertStringNotContainsString(':0', $rawSql);
        $this->assertStringNotContainsString(':1', $rawSql);
    }

    public function testLaravelCteViaQueryBuilder(): void
    {
        $builder = DB::connection('clickhouse-cloud')
            ->table('guardian.cameras');

        // Add CTEs via the clickhouseCtes property
        $builder->clickhouseCtes = [
            [
                'type' => 'expression',
                'name' => 'target_org',
                'sql' => '2568',
            ],
        ];

        $sql = $builder->where('org_id', new \Illuminate\Database\Query\Expression('target_org'))
            ->limit(5)
            ->toSql();

        $this->assertStringContainsString('WITH', $sql);
        $this->assertStringContainsString('2568 AS target_org', $sql);
    }

    // ---------------------------------------------------------------
    // Path 2: Builder (via BaseModel)
    // ---------------------------------------------------------------

    public function testBaseModelSelectWithWhere(): void
    {
        $rows = Camera::where('org_id', 2568)->getRows();

        $this->assertNotEmpty($rows);
        foreach ($rows as $row) {
            $this->assertEquals(2568, $row['org_id']);
        }
    }

    public function testPaginateReturnsLengthAwarePaginator(): void
    {
        // Get total count first to pass as known total
        $total = count(Camera::select()->getRows());
        $this->assertGreaterThan(0, $total);

        $paginator = Camera::select()->paginate(5, ['*'], 'page', 1, $total);

        $this->assertInstanceOf(LengthAwarePaginator::class, $paginator);
        $this->assertEquals(5, $paginator->perPage());
        $this->assertEquals(1, $paginator->currentPage());
        $this->assertEquals($total, $paginator->total());
        $this->assertCount(5, $paginator->items());
    }

    public function testPaginateWithCallablePerPage(): void
    {
        $total = 20;

        $paginator = Camera::select()->paginate(
            fn ($total) => min(10, $total),
            ['*'],
            'page',
            1,
            $total
        );

        $this->assertInstanceOf(LengthAwarePaginator::class, $paginator);
        $this->assertEquals(10, $paginator->perPage());
        $this->assertCount(10, $paginator->items());
    }

    public function testPaginateSecondPage(): void
    {
        $perPage = 5;
        $total = 20;

        $page1 = Camera::select()->paginate($perPage, ['*'], 'page', 1, $total);
        $page2 = Camera::select()->paginate($perPage, ['*'], 'page', 2, $total);

        $this->assertCount($perPage, $page1->items());
        $this->assertCount($perPage, $page2->items());

        // Pages should have different data (first row IDs should differ)
        $page1Items = $page1->items() instanceof \Illuminate\Support\Collection
            ? $page1->items()->all()
            : $page1->items();
        $page2Items = $page2->items() instanceof \Illuminate\Support\Collection
            ? $page2->items()->all()
            : $page2->items();
        $page1Ids = array_column($page1Items, 'id');
        $page2Ids = array_column($page2Items, 'id');
        $this->assertNotEquals($page1Ids, $page2Ids);
    }

    public function testBuilderToRawSqlSubstitutesBindings(): void
    {
        $query = Camera::select()
            ->where('org_id', 2568)
            ->where('status', 1);

        $rawSql = $query->toRawSql();
        $this->assertStringContainsString('2568', $rawSql);
        $this->assertStringNotContainsString('?', $rawSql);
    }

    public function testWithCteExpressionExecutes(): void
    {
        $rows = Camera::select()
            ->withCteExpression('target_org', 2568)
            ->where('org_id', '=', new RawColumn('target_org'))
            ->getRows();

        $this->assertNotEmpty($rows);
        foreach ($rows as $row) {
            $this->assertEquals(2568, $row['org_id']);
        }
    }

    public function testWithCteSubqueryGeneratesSql(): void
    {
        $query = Camera::select()
            ->withCte('active_cameras', function (Builder $q) {
                $q->select('id', 'name', 'org_id', 'status')
                  ->from('guardian.cameras')
                  ->where('status', '=', 1)
                  ->where('org_id', '=', 2568);
            });

        $sql = $query->toSql();

        // WITH should come before select
        $this->assertMatchesRegularExpression('/^WITH\s+active_cameras\s+AS\s+\(select/', $sql);
        $this->assertStringContainsString('from `guardian`.`cameras`', $sql);
    }

    public function testWithCteSubqueryExecutes(): void
    {
        // Use the ClickHouse client directly to execute a CTE query
        $client = Camera::getClient();
        $sql = "WITH active_cameras AS (
                    SELECT id, name, org_id, status
                    FROM guardian.cameras
                    WHERE status = 1 AND org_id = 2568
                )
                SELECT * FROM active_cameras LIMIT 5";

        $rows = $client->select($sql);

        $this->assertNotEmpty($rows);
        foreach ($rows as $row) {
            $this->assertEquals(1, $row['status']);
            $this->assertEquals(2568, $row['org_id']);
        }
    }
}
