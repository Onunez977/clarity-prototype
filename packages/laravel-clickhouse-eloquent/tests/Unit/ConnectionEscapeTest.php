<?php

namespace Tests\Unit;

use LaravelClickhouseEloquent\Connection;
use PHPUnit\Framework\TestCase;

class ConnectionEscapeTest extends TestCase
{
    public function testLaravelQueryBuilderToRawSqlEscapesStrings()
    {
        $connection = new Connection(null, 'clickhouse', '', []);
        $builder = $connection->table('examples');

        $raw = $builder->where('name', "O'Reilly")->toRawSql();

        $this->assertStringContainsString("'O''Reilly'", $raw);
    }
}
