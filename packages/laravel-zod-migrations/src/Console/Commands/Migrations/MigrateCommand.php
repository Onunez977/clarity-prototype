<?php

namespace InsightLPR\ZodMigrations\Console\Commands\Migrations;

use Illuminate\Database\Console\Migrations\MigrateCommand as BaseMigrateCommand;
use InsightLPR\ZodMigrations\Database\ZodSchemaMigrator;

class MigrateCommand extends BaseMigrateCommand
{
    public function handle()
    {
        $database = $this->input->getOption('database') ?? config('database.default');

        if (app(ZodSchemaMigrator::class)->shouldEnsureAppSchema($database)) {
            app(ZodSchemaMigrator::class)->ensureAppSchemaExists();
        }

        return parent::handle();
    }
}
