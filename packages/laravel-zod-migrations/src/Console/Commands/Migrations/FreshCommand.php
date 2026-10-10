<?php

namespace InsightLPR\ZodMigrations\Console\Commands\Migrations;

use Illuminate\Console\Command;
use Illuminate\Database\Console\Migrations\FreshCommand as BaseFreshCommand;
use InsightLPR\ZodMigrations\Database\ZodSchemaMigrator;

class FreshCommand extends BaseFreshCommand
{
    public function handle()
    {
        $database = $this->input->getOption('database');
        $migrator = app(ZodSchemaMigrator::class);

        if ($migrator->shouldManageAllSchemas($database, $this->input)) {
            if ($this->isProhibited() ||
                ! $this->confirmToProceed()) {
                return Command::FAILURE;
            }

            return $migrator->freshAllSchemas(
                $this,
                $migrator->optionsFromCommand($this),
            );
        }

        if ($migrator->shouldEnsureAppSchema($database)) {
            $migrator->ensureAppSchemaExists();
        }

        return parent::handle();
    }
}
