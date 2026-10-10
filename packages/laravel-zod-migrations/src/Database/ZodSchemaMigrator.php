<?php

namespace InsightLPR\ZodMigrations\Database;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Console\Input\InputInterface;

class ZodSchemaMigrator
{
    public function usesZodDatabase(?string $connection = null): bool
    {
        return $this->usesMultiSchemaDatabase($connection);
    }

    public function usesMultiSchemaDatabase(?string $connection = null): bool
    {
        $connection ??= config('database.default');

        if (! in_array($connection, $this->managedConnections(), true)) {
            return false;
        }

        return config("database.connections.{$connection}.driver") === 'pgsql';
    }

    public function shouldEnsureAppSchema(?string $connection = null): bool
    {
        return $this->usesMultiSchemaDatabase($connection);
    }

    public function ensureAppSchemaExists(): void
    {
        $connection = $this->resolvePublicConnectionName();

        DB::connection($connection)->unprepared(
            'CREATE SCHEMA IF NOT EXISTS '.config('zod-migrations.app_schema', 'platform')
        );
    }

    public function preparePublicSchema(): void
    {
        DB::connection($this->publicConnectionName())->unprepared(
            'DROP EXTENSION IF EXISTS address_standardizer_data_us CASCADE'
        );
    }

    public function publicSchemaPath(?string $override = null): string
    {
        if ($override !== null && $override !== '') {
            return $override;
        }

        if (app()->environment('testing')) {
            return config('zod-migrations.testing_schema_path')
                ?: $this->packageSchemaPath('testing-schema.sql');
        }

        return config('zod-migrations.zod_schema_path')
            ?: $this->packageSchemaPath('zod-schema.sql');
    }

    public function publicMigrationsPath(): string
    {
        return config('zod-migrations.public_migrations_path')
            ?: $this->packageMigrationsPath();
    }

    /**
     * @param  array<string, mixed>  $options
     */
    public function freshPublicSchema(?Command $command = null, array $options = []): int
    {
        $this->ensureAppSchemaExists();
        $this->preparePublicSchema();

        return $this->runArtisan($command, 'migrate:fresh', array_filter([
            '--database' => $this->publicConnectionName(),
            '--path' => $this->publicMigrationsPath(),
            '--schema-path' => $options['schema-path'] ?? $this->publicSchemaPath(),
            '--force' => $options['force'] ?? true,
            '--drop-views' => $options['drop-views'] ?? null,
        ]));
    }

    /**
     * @param  array<string, mixed>  $options
     */
    public function freshAppSchema(?Command $command = null, array $options = []): int
    {
        $this->ensureAppSchemaExists();

        return $this->runArtisan($command, 'migrate:fresh', array_filter([
            '--database' => $this->appConnectionName(),
            '--force' => $options['force'] ?? true,
            '--path' => $options['path'] ?? null,
            '--seed' => $options['seed'] ?? null,
            '--seeder' => $options['seeder'] ?? null,
            '--step' => $options['step'] ?? null,
        ]));
    }

    /**
     * @param  array<string, mixed>  $options
     */
    public function freshAllSchemas(?Command $command = null, array $options = []): int
    {
        if ($command !== null) {
            $command->line('Migrating public schema...');
        }

        $publicStatus = $this->freshPublicSchema($command, $options);

        if ($publicStatus !== 0) {
            return $publicStatus;
        }

        $this->runPostPublicMigrateSql();

        if ($command !== null) {
            $command->info('Finished migrating public schema.');
            $command->line('Migrating application schema...');
        }

        $appOptions = $options;
        unset($appOptions['schema-path'], $appOptions['drop-views']);

        $appStatus = $this->usesIncrementalAppSchema()
            ? $this->migrateAppSchema($command, $appOptions)
            : $this->freshAppSchema($command, $appOptions);

        if ($command !== null && $appStatus === 0) {
            $command->info('Finished migrating application schema.');
        }

        return $appStatus;
    }

    /**
     * Whether the application schema ships inside the public dump and should
     * only have pending migrations applied on top of it (squash-dump + delta
     * apps such as Guardian/Overwatch), rather than being wiped and rebuilt
     * from a full migration set (the default, which suits Platform).
     */
    public function usesIncrementalAppSchema(): bool
    {
        return config('zod-migrations.app_strategy', 'fresh') === 'migrate';
    }

    /**
     * @param  array<string, mixed>  $options
     */
    public function refreshAllSchemas(?Command $command = null, array $options = []): int
    {
        if ($command !== null) {
            $command->line('Reloading public schema...');
        }

        $publicStatus = $this->freshPublicSchema($command, $options);

        if ($publicStatus !== 0) {
            return $publicStatus;
        }

        $this->runPostPublicMigrateSql();

        if ($command !== null) {
            $command->info('Finished reloading public schema.');
            $command->line('Refreshing application schema...');
        }

        $this->ensureAppSchemaExists();

        $appStatus = $this->runArtisan($command, 'migrate:refresh', array_filter([
            '--database' => $this->appConnectionName(),
            '--force' => $options['force'] ?? true,
            '--path' => $options['path'] ?? null,
            '--seed' => $options['seed'] ?? null,
            '--seeder' => $options['seeder'] ?? null,
            '--step' => $options['step'] ?? null,
        ]));

        if ($command !== null && $appStatus === 0) {
            $command->info('Finished refreshing application schema.');
        }

        return $appStatus;
    }

    /**
     * @param  array<string, mixed>  $options
     */
    public function migrateAppSchema(?Command $command = null, array $options = []): int
    {
        $this->ensureAppSchemaExists();

        return $this->runArtisan($command, 'migrate', array_filter([
            '--database' => $this->appConnectionName(),
            '--force' => $options['force'] ?? null,
            '--path' => $options['path'] ?? null,
            '--schema-path' => $options['schema-path'] ?? null,
            '--pretend' => $options['pretend'] ?? null,
            '--seed' => $options['seed'] ?? null,
            '--seeder' => $options['seeder'] ?? null,
            '--step' => $options['step'] ?? null,
            '--graceful' => $options['graceful'] ?? null,
        ]));
    }

    public function runPostPublicMigrateSql(): void
    {
        $statements = config('zod-migrations.post_public_migrate_sql', []);

        if (! is_array($statements) || $statements === []) {
            return;
        }

        $connection = $this->publicConnectionName();

        foreach ($statements as $sql) {
            if (! is_string($sql) || trim($sql) === '') {
                continue;
            }

            try {
                DB::connection($connection)->unprepared($sql);
            } catch (\Throwable) {
                //
            }
        }
    }

    /**
     * @param  array<string, mixed>  $options
     */
    protected function runArtisan(?Command $command, string $artisanCommand, array $options): int
    {
        if ($command !== null) {
            return $command->call($artisanCommand, $options);
        }

        return Artisan::call($artisanCommand, $options);
    }

    /**
     * @return array<string, mixed>
     */
    public function optionsFromCommand(Command $command): array
    {
        $definition = $command->getDefinition();
        $options = [];

        foreach ([
            'force',
            'path',
            'schema-path',
            'seed',
            'seeder',
            'step',
            'drop-views',
            'pretend',
            'graceful',
        ] as $name) {
            if (! $definition->hasOption($name)) {
                continue;
            }

            $value = $command->option($name);

            if ($value !== null && $value !== false && $value !== []) {
                $options[$name] = $value;
            }
        }

        return $options;
    }

    public function shouldManageAllSchemas(?string $database, InputInterface $input): bool
    {
        if ($database !== null && in_array($database, $this->managedConnections(), true)) {
            return false;
        }

        return $this->usesMultiSchemaDatabase($database ?? config('database.default'));
    }

    /**
     * @return list<string>
     */
    public function managedConnections(): array
    {
        return array_values(array_unique([
            $this->publicConnectionName(),
            $this->appConnectionName(),
        ]));
    }

    public function publicConnectionName(): string
    {
        return (string) config('zod-migrations.public_connection', 'zod');
    }

    public function appConnectionName(): string
    {
        return (string) config('zod-migrations.app_connection', 'platform');
    }

    protected function resolvePublicConnectionName(): string
    {
        $configuredPublic = $this->publicConnectionName();

        if (config("database.connections.{$configuredPublic}.driver") === 'pgsql') {
            return $configuredPublic;
        }

        return $this->appConnectionName();
    }

    protected function packageSchemaPath(string $filename): string
    {
        return dirname(__DIR__, 2).'/database/schema/'.$filename;
    }

    protected function packageMigrationsPath(): string
    {
        return dirname(__DIR__, 2).'/database/migrations/public';
    }
}
