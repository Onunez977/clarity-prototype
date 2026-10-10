<?php

namespace Tests\Feature\Jobs;

use App\Dto\AnalyticsDataDto;
use App\Jobs\TrackAnalytics;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LaravelClickhouseEloquent\Exceptions\ClickhouseException;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

class TrackAnalyticsTest extends TestCase
{
    private function makeJob(): TrackAnalytics
    {
        return new TrackAnalytics(
            feature: 'page-view',
            userId: 1,
            orgId: 1,
            data: AnalyticsDataDto::fromArray(['action' => 'view']),
            page: 'dashboard',
        );
    }

    #[Group('jobs')]
    public function test_generates_a_unique_id_at_dispatch_time(): void
    {
        $job = $this->makeJob();

        $this->assertTrue(Str::isUuid($job->id));

        // Identical events must never share an id — ClickHouse dedups
        // byte-identical insert blocks, so a shared id means lost rows.
        $this->assertNotSame($job->id, $this->makeJob()->id);
    }

    #[Group('jobs')]
    public function test_captures_event_time_at_dispatch_time(): void
    {
        $this->travelTo(now()->startOfMinute());

        $job = $this->makeJob();

        $this->travel(10)->minutes();

        // created_at must reflect when the event happened, not when a queue
        // worker eventually processed the job.
        $this->assertSame(now()->subMinutes(10)->format('Y-m-d H:i:s'), $job->createdAt);
    }

    #[Group('jobs')]
    public function test_id_and_created_at_survive_serialization(): void
    {
        $job = $this->makeJob();

        $restored = unserialize(serialize($job));

        $this->assertSame($job->id, $restored->id);
        $this->assertSame($job->createdAt, $restored->createdAt);
    }

    #[Group('jobs')]
    public function test_retries_transient_failures_with_backoff(): void
    {
        $job = $this->makeJob();

        // The {low} supervisor runs tries=1, so the job must declare its own
        // retries — otherwise a transient ClickHouse connect timeout drops the
        // event. Dedup on the dispatch-time id keeps retries exactly-once.
        $this->assertGreaterThanOrEqual(2, $job->tries);
        $this->assertGreaterThan(0, $job->backoff);
    }

    #[Group('jobs')]
    public function test_write_connection_uses_a_resilient_connect_budget(): void
    {
        // DEV-2123: a 2s connect timeout with 0 retries on the write path was timing out
        // analytics inserts on ClickHouse Cloud TLS/idle-scaling waits. The write connection
        // must keep a longer connect budget and at least one in-client retry so a transient
        // connect blip is re-attempted before the job's slower 10s backoff. Pins the defaults
        // against a silent reset; read paths intentionally stay on the 2s fast-fail.
        $this->assertGreaterThanOrEqual(5, config('database.connections.clarity_clickhouse_write.timeout_connect'));
        $this->assertGreaterThanOrEqual(1, config('database.connections.clarity_clickhouse_write.retries'));
    }

    #[Group('jobs')]
    public function test_propagates_clickhouse_connect_timeout_instead_of_swallowing(): void
    {
        // Mirrors the production incident (6× on 2026-06-24, e.g. 07:13 / 12:52 / 18:13):
        //   LaravelClickhouseEloquent\Exceptions\ClickhouseException:
        //   cURL error: Operation timed out after 2001 milliseconds with 0 out of 0 bytes received
        //   (ClickhouseHttpClient.php:438, thrown from TrackAnalytics::handle)
        // The old catch/report() swallowed exactly this, so the event was dropped and never
        // retried. Point the write connection at a black-holed address so the insert hits a real
        // connect timeout (not just a refused port); handle() must let it propagate so the queue retries.
        config([
            'database.connections.clarity_clickhouse_write.driver' => 'clickhouse',
            'database.connections.clarity_clickhouse_write.host' => '192.0.2.1', // TEST-NET-1 (RFC 5737), unroutable
            'database.connections.clarity_clickhouse_write.port' => '8123',
            'database.connections.clarity_clickhouse_write.https' => false,
            'database.connections.clarity_clickhouse_write.options' => ['protocol' => 'http'],
            'database.connections.clarity_clickhouse_write.timeout_connect' => 1,
            'database.connections.clarity_clickhouse_write.timeout_query' => 1,
            'database.connections.clarity_clickhouse_write.retries' => 0,
        ]);
        DB::purge('clarity_clickhouse_write');

        // The exact cURL wording ("Operation" vs "Connection" timed out) is libcurl/platform
        // dependent, so assert the prod exception class propagates — not the message string.
        $this->expectException(ClickhouseException::class);

        $this->makeJob()->handle();
    }
}
