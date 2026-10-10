<?php

namespace Tests\Unit\Database;

use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Support\Facades\Config;
use InsightLPR\ZodMigrations\Database\ZodSchemaMigrator;
use Symfony\Component\Console\Input\ArrayInput;

class ZodSchemaMigratorTest extends TestCase
{
    public function test_it_detects_zod_database_connections(): void
    {
        Config::set('database.connections.platform.driver', 'pgsql');
        Config::set('database.connections.platform.database', 'zod');
        Config::set('database.connections.zod.driver', 'pgsql');
        Config::set('database.connections.zod.database', 'zod');
        Config::set('database.connections.sqlite.driver', 'sqlite');
        Config::set('database.connections.sqlite.database', 'database.sqlite');

        $migrator = app(ZodSchemaMigrator::class);

        $this->assertTrue($migrator->usesMultiSchemaDatabase('platform'));
        $this->assertTrue($migrator->usesMultiSchemaDatabase('zod'));
        $this->assertFalse($migrator->usesMultiSchemaDatabase('sqlite'));
    }

    public function test_it_detects_non_zod_prefixed_testing_databases(): void
    {
        Config::set('database.connections.platform.driver', 'pgsql');
        Config::set('database.connections.platform.database', 'cl_testing');

        $this->assertTrue(app(ZodSchemaMigrator::class)->usesMultiSchemaDatabase('platform'));
    }

    public function test_it_detects_zod_testing_database(): void
    {
        Config::set('database.connections.platform.driver', 'pgsql');
        Config::set('database.connections.platform.database', 'zod_testing');

        $this->assertTrue(app(ZodSchemaMigrator::class)->usesMultiSchemaDatabase('platform'));
    }

    public function test_it_manages_all_schemas_when_no_connection_is_specified(): void
    {
        Config::set('database.default', 'platform');
        Config::set('database.connections.platform.driver', 'pgsql');
        Config::set('database.connections.platform.database', 'zod');

        $migrator = app(ZodSchemaMigrator::class);
        $input = new ArrayInput([]);

        $this->assertTrue($migrator->shouldManageAllSchemas(null, $input));
    }

    public function test_it_does_not_manage_all_schemas_for_explicit_connections(): void
    {
        Config::set('database.default', 'platform');
        Config::set('database.connections.platform.driver', 'pgsql');
        Config::set('database.connections.platform.database', 'zod');

        $migrator = app(ZodSchemaMigrator::class);

        $this->assertFalse($migrator->shouldManageAllSchemas('platform', new ArrayInput([])));
        $this->assertFalse($migrator->shouldManageAllSchemas('zod', new ArrayInput([])));
    }

    public function test_it_uses_testing_schema_dump_in_testing_environment(): void
    {
        $this->assertStringEndsWith(
            'testing-schema.sql',
            app(ZodSchemaMigrator::class)->publicSchemaPath(),
        );
    }

    public function test_it_allows_schema_path_override(): void
    {
        $path = database_path('schema/custom-schema.sql');

        $this->assertSame($path, app(ZodSchemaMigrator::class)->publicSchemaPath($path));
    }
}
