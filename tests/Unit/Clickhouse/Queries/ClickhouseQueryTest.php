<?php

declare(strict_types=1);

namespace Tests\Unit\Clickhouse\Queries;

use App\Clickhouse\Queries\ClickhouseQuery;
use App\Clickhouse\Queries\HotlistAddressGeohashQuery;
use PHPUnit\Framework\Attributes\Group;
use ReflectionMethod;
use Tests\TestCase;

class ClickhouseQueryTest extends TestCase
{
    #[Group('clickhouse')]
    public function test_clarity_db_returns_literal_schema_under_the_pgsql_bridge(): void
    {
        config()->set('database.connections.clarity_clickhouse.driver', 'pgsql');

        $this->assertSame('clarity', self::callDbResolver('clarityDb'));
    }

    #[Group('clickhouse')]
    public function test_clarity_db_returns_the_configured_database_under_clickhouse(): void
    {
        config()->set('database.connections.clarity_clickhouse.driver', 'clickhouse');
        config()->set('database.connections.clarity_clickhouse.database', 'clarity_wt99');

        $this->assertSame('clarity_wt99', self::callDbResolver('clarityDb'));
    }

    #[Group('clickhouse')]
    public function test_zod_db_returns_literal_schema_under_the_pgsql_bridge(): void
    {
        config()->set('database.connections.clarity_clickhouse.driver', 'pgsql');

        $this->assertSame('zod', self::callDbResolver('zodDb'));
    }

    #[Group('clickhouse')]
    public function test_zod_db_returns_the_configured_database_under_clickhouse(): void
    {
        config()->set('database.connections.clarity_clickhouse.driver', 'clickhouse');
        config()->set('database.connections.public_clickhouse.database', 'zod_wt99');

        $this->assertSame('zod_wt99', self::callDbResolver('zodDb'));
    }

    private static function callDbResolver(string $method): string
    {
        $reflection = new ReflectionMethod(ClickhouseQuery::class, $method);
        $reflection->setAccessible(true);

        return $reflection->invoke(null);
    }

    #[Group('clickhouse')]
    #[Group('run-area')]
    public function test_polygon_literal_emits_sanitized_clickhouse_array_of_tuples(): void
    {
        $literal = self::callPolygonLiteral([
            [-97.5, 32.5],
            [-96.5, 32.5],
            [-96.5, 33.5],
        ]);

        $this->assertSame('[(-97.5,32.5),(-96.5,32.5),(-96.5,33.5)]', $literal);
    }

    #[Group('clickhouse')]
    #[Group('run-area')]
    public function test_polygon_literal_casts_strings_to_floats_so_injection_attempts_collapse_to_zero(): void
    {
        $literal = self::callPolygonLiteral([
            ['1); DROP TABLE foo; --', 'evil'],
            [42, 'also evil'],
        ]);

        $this->assertSame('[(0,0),(42,0)]', $literal);
    }

    #[Group('clickhouse')]
    #[Group('run-area')]
    public function test_polygon_literal_returns_empty_array_literal_for_empty_ring(): void
    {
        $this->assertSame('[]', self::callPolygonLiteral([]));
    }

    /**
     * @param  list<array{0: int|float|string, 1: int|float|string}>  $ring
     */
    private static function callPolygonLiteral(array $ring): string
    {
        $method = new ReflectionMethod(HotlistAddressGeohashQuery::class, 'polygonLiteral');
        $method->setAccessible(true);

        return $method->invoke(null, $ring);
    }
}
