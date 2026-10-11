<?php

namespace App\Console\Commands\Dev;

use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * @codeCoverageIgnore
 */
#[AsCommand(name: 'dev:migrate')]
class DevMigrate extends Command
{
    protected $signature = 'dev:migrate
                            {--schema-path= : Path to the public schema SQL dump}
                            {--seed : Seed the database after migrating}
                            {--seeder= : The class name of the root seeder}';

    protected $description = 'Run migrate:fresh for the public and platform database schemas.';

    public function handle(): int
    {
        $this->line('Migrating public and platform schemas...');

        return $this->call('migrate:fresh', array_filter([
            '--schema-path' => $this->option('schema-path'),
            '--seed' => $this->option('seed'),
            '--seeder' => $this->option('seeder'),
            '--force' => true,
        ]));
    }
}
