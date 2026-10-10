<?php

declare(strict_types=1);

namespace Tests\Unit\Clickhouse\Queries;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\RequiresClickhouse;
use Tests\TestCase;

/**
 * DEV-2381: GitHub Actions loads `database/schema/clickhouse-schema.sql` into
 * Docker ClickHouse before PHPUnit. Pin that dump's `clarity.sc_positions`
 * includes `proj_kit_mapping_lc` so Scout `lower(kit_mapping)` tests run
 * against the same projection Cloud will have after the chlocal apply. This
 * does not prove Cloud granule prune (fixtures are tiny; EXPLAIN is the
 * operator runbook).
 */
class ScPositionsLowercaseProjectionTest extends TestCase
{
    use RequiresClickhouse;

    #[Group('clickhouse')]
    #[Group('clarity-scout')]
    public function test_ci_schema_defines_proj_kit_mapping_lc_on_sc_positions(): void
    {
        $ddl = DB::connection('clarity_clickhouse')->selectOne(
            "SELECT create_table_query FROM system.tables WHERE database = 'clarity' AND name = 'sc_positions'"
        );

        $this->assertNotNull($ddl, 'CI dump must create clarity.sc_positions.');

        $createTableQuery = (string) ($ddl['create_table_query'] ?? '');
        $this->assertStringContainsString('proj_kit_mapping_lc', $createTableQuery);
        $this->assertStringContainsString('lower(kit_mapping)', $createTableQuery);
        $this->assertStringContainsString('timestamp_utc', $createTableQuery);

        $projection = DB::connection('clarity_clickhouse')->selectOne(
            "SELECT name, sorting_key FROM system.projections WHERE database = 'clarity' AND `table` = 'sc_positions' AND name = 'proj_kit_mapping_lc'"
        );

        $this->assertNotNull($projection, 'CI dump must define projection proj_kit_mapping_lc on clarity.sc_positions.');
        $this->assertSame('proj_kit_mapping_lc', $projection['name'] ?? null);

        $sortingKey = $this->clickhouseExpressionList($projection['sorting_key'] ?? []);
        $this->assertStringContainsString('lower(kit_mapping)', $sortingKey);
        $this->assertStringContainsString('timestamp_utc', $sortingKey);
    }

    /**
     * ClickHouse `system.projections.sorting_key` is Array(String) over HTTP.
     */
    private function clickhouseExpressionList(mixed $value): string
    {
        if (is_array($value)) {
            $parts = [];

            foreach ($value as $part) {
                $parts[] = $this->clickhouseExpressionList($part);
            }

            return implode(', ', $parts);
        }

        if (is_scalar($value)) {
            return (string) $value;
        }

        return '';
    }
}
