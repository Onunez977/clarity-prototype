<?php

namespace App\Console\Commands\Dev;

use App\Support\Geocoding\TigerStateColumns;
use Database\Seeders\CloudClickhouseSeeder;
use Database\Seeders\CloudSmokeSeeder;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * One-shot bootstrap for isolated environments (cloud agent VMs, the QA runner):
 * schema, tiger.state patch, fixed personas, and a local ClickHouse populated
 * from the seeded Postgres rows. Refuses anything that is not a local host.
 */
#[AsCommand(name: 'app:preview-bootstrap')]
class PreviewBootstrap extends Command
{
    /**
     * @var list<string>
     */
    private const POSTGRES_CONNECTIONS = ['zod', 'platform', 'platform_read', 'graphql', 'pg'];

    /**
     * @var list<string>
     */
    private const CLICKHOUSE_CONNECTIONS = ['clarity_clickhouse', 'clarity_clickhouse_write', 'public_clickhouse'];

    /**
     * @var array<string, string> connection => table
     */
    private const REQUIRED_POSTGRES_TABLES = ['zod' => 'orgs', 'platform' => 'users'];

    /**
     * @var list<string>
     */
    private const VERIFIED_CLICKHOUSE_TABLES = ['zod.public_orgs', 'clarity.hotlist', 'clarity.scans_cold', 'clarity.scans_hot', 'clarity.hits_hot', 'clarity.sc_positions'];

    protected $signature = 'app:preview-bootstrap
                            {--fresh : Drop and rebuild the public and platform schemas first (destructive)}
                            {--schema-only : Migrate and load the ClickHouse schema without seeding}
                            {--skip-clickhouse : Skip the ClickHouse schema and seed}';

    protected $description = 'Bootstrap an isolated local environment: schema, seed personas, and a local ClickHouse copy of the seed.';

    public function handle(): int
    {
        if (! app()->environment(['local', 'testing'])) {
            $this->error('app:preview-bootstrap only runs when APP_ENV is local or testing.');

            return self::FAILURE;
        }

        if (($refusal = $this->hostRefusal()) !== null) {
            $this->error($refusal);

            return self::FAILURE;
        }

        if (! $this->migrate()) {
            return self::FAILURE;
        }

        foreach (TigerStateColumns::ensure() as $column) {
            $this->line("Added tiger.state.{$column}");
        }

        $withClickhouse = ! $this->option('skip-clickhouse')
            && config('database.connections.clarity_clickhouse.driver') === 'clickhouse';

        if (! $withClickhouse && ! $this->option('skip-clickhouse')) {
            $this->warn('CLICKHOUSE_DRIVER is not clickhouse; skipping the ClickHouse schema and seed.');
        }

        // Before the Postgres seed: some seeders (OttoSeeder) write to ClickHouse directly.
        if ($withClickhouse) {
            $this->loadClickhouseSchema();
        }

        if ($this->option('schema-only')) {
            return self::SUCCESS;
        }

        $this->seedPostgres();

        if (! $withClickhouse) {
            return self::SUCCESS;
        }

        $this->info('Syncing the seed into ClickHouse...');
        $this->call('db:seed', ['--class' => CloudClickhouseSeeder::class, '--force' => true]);

        return $this->verifyClickhouse() ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Only loopback hosts and single-label Docker service names are isolated.
     * Anything with a dot (RDS, ClickHouse Cloud, *.insightlpr.io, VPC IPs) is refused.
     */
    public static function isIsolatedHost(string $host): bool
    {
        $host = strtolower(trim($host, '[] '));

        if (in_array($host, ['', 'localhost', '::1'], true) || str_starts_with($host, '127.')) {
            return true;
        }

        return (bool) preg_match('/^[a-z0-9][a-z0-9_-]*$/', $host);
    }

    private function hostRefusal(): ?string
    {
        $connections = self::POSTGRES_CONNECTIONS;

        if (! $this->option('skip-clickhouse')) {
            $connections = [...$connections, ...self::CLICKHOUSE_CONNECTIONS];
        }

        foreach ($connections as $connection) {
            foreach ($this->hostsFor($connection) as $host) {
                if (! self::isIsolatedHost($host)) {
                    return "Refusing to bootstrap: the {$connection} connection points at '{$host}', which is not a local host.";
                }
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function hostsFor(string $connection): array
    {
        $config = config("database.connections.{$connection}", []);

        return collect([$config['host'] ?? null, $config['read']['host'] ?? null, $config['write']['host'] ?? null])
            ->flatten()
            ->filter(fn (mixed $host): bool => $host !== null)
            ->map(fn (mixed $host): string => (string) $host)
            ->values()
            ->all();
    }

    private function migrate(): bool
    {
        if ($this->option('fresh') || ! Schema::connection('zod')->hasTable('orgs')) {
            $this->info('Loading the public schema dump and migrating platform...');
            $this->call('dev:migrate');
        } else {
            $this->info('Public schema present; running pending platform migrations...');
            $this->call('migrate', ['--force' => true]);
        }

        foreach (self::REQUIRED_POSTGRES_TABLES as $connection => $table) {
            if (! Schema::connection($connection)->hasTable($table)) {
                $this->error("Schema load incomplete: {$connection}.{$table} is missing.");

                return false;
            }
        }

        return true;
    }

    private function seedPostgres(): void
    {
        if (DB::connection('zod')->table('orgs')->where('name', CloudSmokeSeeder::PRIMARY_ORG_NAME)->exists()) {
            $this->info('Seed personas already present; skipping the Postgres seed (use --fresh to rebuild).');
        } else {
            $this->info('Seeding Postgres...');
            $this->call('db:seed', ['--class' => DatabaseSeeder::class, '--force' => true]);
        }

        $this->call('db:seed', ['--class' => CloudSmokeSeeder::class, '--force' => true]);
    }

    private function loadClickhouseSchema(): void
    {
        $this->info('Loading the ClickHouse schema...');

        // The HTTP client sends the connection's database with every request, and
        // clarity/zod may not exist yet — so load through `default`. Statements are fully qualified.
        config(['database.connections.clickhouse_bootstrap' => [
            ...config('database.connections.clarity_clickhouse_write'),
            'database' => 'default',
        ]]);
        $clickhouse = DB::connection('clickhouse_bootstrap');

        foreach ($this->clickhouseStatements(database_path('schema/clickhouse-schema.sql')) as $statement) {
            $clickhouse->statement(preg_replace(
                '/^CREATE (TABLE|VIEW|MATERIALIZED VIEW|DATABASE) (?!IF NOT EXISTS)/',
                'CREATE $1 IF NOT EXISTS ',
                $statement,
            ));
        }

        foreach (glob(database_path('schema/clickhouse-functions/*.sql')) ?: [] as $file) {
            foreach ($this->clickhouseStatements($file) as $statement) {
                $clickhouse->statement(preg_replace('/^CREATE FUNCTION /', 'CREATE OR REPLACE FUNCTION ', $statement));
            }
        }

        // Refreshable views would otherwise append into the seeded tables on a timer.
        $clickhouse->statement('SYSTEM STOP VIEWS');
    }

    /**
     * @return list<string>
     */
    private function clickhouseStatements(string $path): array
    {
        $sql = preg_replace('/^--.*$/m', '', (string) file_get_contents($path));

        return collect(preg_split('/;\s*$/m', $sql))
            ->map(fn (string $statement): string => trim($statement))
            ->filter()
            ->values()
            ->all();
    }

    private function verifyClickhouse(): bool
    {
        $clickhouse = DB::connection('clarity_clickhouse');
        $rows = [];

        foreach (self::VERIFIED_CLICKHOUSE_TABLES as $table) {
            $rows[] = [$table, (int) ($clickhouse->select("SELECT count() AS c FROM {$table}")[0]['c'] ?? 0)];
        }

        $this->table(['ClickHouse table', 'Rows'], $rows);

        if (collect($rows)->contains(fn (array $row): bool => $row[1] === 0)) {
            $this->error('ClickHouse seed verification failed: a table above is empty.');

            return false;
        }

        $this->info('Preview bootstrap complete. Log in as '.CloudSmokeSeeder::PRIMARY_EMAIL.' (admin), '.CloudSmokeSeeder::PEER_ADMIN_EMAIL.' (peer org) or '.CloudSmokeSeeder::CLIENT_EMAIL.' (client portal).');

        return true;
    }
}
