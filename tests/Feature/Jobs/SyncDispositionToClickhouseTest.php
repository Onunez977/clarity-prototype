<?php

namespace Tests\Feature\Jobs;

use App\Jobs\SyncDispositionToClickhouse;
use Illuminate\Support\Facades\DB;
use LaravelClickhouseEloquent\Exceptions\ClickhouseException;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

class SyncDispositionToClickhouseTest extends TestCase
{
    private function makeJob(): SyncDispositionToClickhouse
    {
        return new SyncDispositionToClickhouse(
            scanId: 1,
            disposition: 'recovered',
        );
    }

    #[Group('jobs')]
    public function test_retries_transient_failures_with_backoff(): void
    {
        $job = $this->makeJob();

        // The {low} supervisor runs tries=1, so the job must declare its own
        // retries — otherwise a transient ClickHouse connect timeout drops the
        // disposition sync. The ALTER…UPDATE is idempotent, so retries are safe.
        $this->assertGreaterThanOrEqual(2, $job->tries);
        $this->assertGreaterThan(0, $job->backoff);
    }

    #[Group('jobs')]
    public function test_handle_does_not_swallow_clickhouse_failures(): void
    {
        // Point the write connection at a dead host so the ALTER…UPDATE fails on
        // connect. handle() must let that propagate so the queue can retry — the
        // old try/catch logged it and completed, silently dropping the sync.
        config([
            'database.connections.clarity_clickhouse_write.driver' => 'clickhouse',
            'database.connections.clarity_clickhouse_write.host' => '127.0.0.1',
            'database.connections.clarity_clickhouse_write.port' => '1',
            'database.connections.clarity_clickhouse_write.https' => false,
            'database.connections.clarity_clickhouse_write.options' => ['protocol' => 'http'],
            'database.connections.clarity_clickhouse_write.timeout_connect' => 1,
            'database.connections.clarity_clickhouse_write.timeout_query' => 1,
            'database.connections.clarity_clickhouse_write.retries' => 0,
        ]);
        DB::purge('clarity_clickhouse_write');

        $this->expectException(ClickhouseException::class);

        $this->makeJob()->handle();
    }
}
