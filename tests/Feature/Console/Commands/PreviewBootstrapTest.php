<?php

namespace Tests\Feature\Console\Commands;

use App\Console\Commands\Dev\PreviewBootstrap;
use Database\Seeders\CloudClickhouseSeeder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

class PreviewBootstrapTest extends TestCase
{
    #[Group('commands')]
    public function test_refuses_to_run_outside_local_and_testing(): void
    {
        $this->app['env'] = 'production';

        $this->artisan('app:preview-bootstrap')
            ->expectsOutputToContain('only runs when APP_ENV is local or testing')
            ->assertFailed();
    }

    #[Group('commands')]
    public function test_refuses_a_remote_postgres_host(): void
    {
        config(['database.connections.pg.host' => 'zod.cluster-abc.us-east-2.rds.amazonaws.com']);

        $this->artisan('app:preview-bootstrap')
            ->expectsOutputToContain("the pg connection points at 'zod.cluster-abc.us-east-2.rds.amazonaws.com'")
            ->assertFailed();
    }

    #[Group('commands')]
    public function test_refuses_a_remote_postgres_write_host(): void
    {
        config(['database.connections.zod.write.host' => ['10.100.3.13']]);

        $this->artisan('app:preview-bootstrap')
            ->expectsOutputToContain("the zod connection points at '10.100.3.13'")
            ->assertFailed();
    }

    #[Group('commands')]
    public function test_refuses_clickhouse_cloud(): void
    {
        config(['database.connections.clarity_clickhouse_write.host' => 'zd69ju2h8d.us-east-2.aws.clickhouse.cloud']);

        $this->artisan('app:preview-bootstrap')
            ->expectsOutputToContain("the clarity_clickhouse_write connection points at 'zd69ju2h8d.us-east-2.aws.clickhouse.cloud'")
            ->assertFailed();
    }

    #[DataProvider('hosts')]
    public function test_only_loopback_and_docker_service_hosts_are_isolated(string $host, bool $isolated): void
    {
        $this->assertSame($isolated, PreviewBootstrap::isIsolatedHost($host));
    }

    public static function hosts(): iterable
    {
        yield 'loopback' => ['127.0.0.1', true];
        yield 'localhost' => ['localhost', true];
        yield 'ipv6 loopback' => ['[::1]', true];
        yield 'unix socket' => ['', true];
        yield 'docker service' => ['clarity-qa-runner-postgres', true];
        yield 'rds' => ['zod.cluster-abc.us-east-2.rds.amazonaws.com', false];
        yield 'clickhouse cloud' => ['zd69ju2h8d.us-east-2.aws.clickhouse.cloud', false];
        yield 'insightlpr' => ['pr-12-platform.internal.insightlpr.io', false];
        yield 'vpc ip' => ['10.100.3.13', false];
    }

    #[DataProvider('coercions')]
    public function test_coerces_postgres_values_to_clickhouse_types(string $type, mixed $value, mixed $expected): void
    {
        $this->assertSame($expected, CloudClickhouseSeeder::coerce($type, $value));
    }

    public static function coercions(): iterable
    {
        yield 'ewkt to point' => ['Point', 'SRID=4326;POINT(-86.15 39.76)', '(-86.15, 39.76)'];
        yield 'ewkt stays a string' => ['Nullable(String)', 'SRID=4326;POINT(-86.15 39.76)', 'SRID=4326;POINT(-86.15 39.76)'];
        yield 'timestamptz to datetime64' => ['DateTime64(6)', '2026-09-01 10:00:00.123456+00', '2026-09-01 10:00:00.123456'];
        yield 'timestamp to datetime' => ['DateTime', '2026-09-01 10:00:00.5', '2026-09-01 10:00:00'];
        yield 'date32' => ['Nullable(Date32)', '2026-09-01', '2026-09-01'];
        yield 'bool' => ['Bool', true, 1];
        yield 'low cardinality string' => ['LowCardinality(String)', 'L', 'L'];
        yield 'nullable keeps null' => ['Nullable(Int32)', null, null];
        yield 'non-nullable null is zero' => ['Int32', null, 0];
        yield 'non-nullable null point' => ['Point', null, '(0, 0)'];
        yield 'decimal' => ['Nullable(Decimal(10, 2))', '12.50', 12.5];
    }
}
