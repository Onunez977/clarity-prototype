<?php

declare(strict_types=1);

namespace App\Clickhouse;

use Illuminate\Support\Facades\DB;

/**
 * App-namespace invoker so a curl-error select has a caller frame outside Logging.
 */
final class FailureLogSelectProbe
{
    /**
     * @param  array<int, mixed>  $bindings
     * @return array<int, mixed>
     */
    public static function select(string $sql, array $bindings = []): array
    {
        return DB::connection('clarity_clickhouse')->select($sql, $bindings);
    }
}

namespace Tests\Feature\Clickhouse;

use App\Clickhouse\FailureLogSelectProbe;
use App\Clickhouse\Logging\LoggingClickhouseConnection;
use App\Clickhouse\Logging\LoggingClickhouseHttpClient;
use App\Dto\AnalyticsDataDto;
use App\Jobs\TrackAnalytics;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use LaravelClickhouseEloquent\Exceptions\ClickhouseException;
use Mockery;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('clickhouse')]
class ClickhouseFailureLoggingTest extends TestCase
{
    /**
     * @var list<array{0: string, 1: array<string, mixed>}>
     */
    private array $dbErrors = [];

    public function test_clickhouse_driver_resolves_the_logging_connection(): void
    {
        config(['database.connections.clarity_clickhouse.driver' => 'clickhouse']);
        DB::purge('clarity_clickhouse');

        $connection = DB::connection('clarity_clickhouse');

        $this->assertInstanceOf(LoggingClickhouseConnection::class, $connection);
        $this->assertInstanceOf(LoggingClickhouseHttpClient::class, $connection->getClient());
    }

    public function test_curl_error_select_logs_sql_and_the_app_caller(): void
    {
        config([
            'database.connections.clarity_clickhouse.driver' => 'clickhouse',
            'database.connections.clarity_clickhouse.host' => '192.0.2.1',
            'database.connections.clarity_clickhouse.port' => '8123',
            'database.connections.clarity_clickhouse.https' => false,
            'database.connections.clarity_clickhouse.username' => 'ch-user-sentinel-2923',
            'database.connections.clarity_clickhouse.password' => 'ch-pass-sentinel-2923',
            'database.connections.clarity_clickhouse.timeout_connect' => 1,
            'database.connections.clarity_clickhouse.timeout_query' => 1,
            'database.connections.clarity_clickhouse.retries' => 0,
        ]);
        DB::purge('clarity_clickhouse');

        $this->captureDbError();

        $thrown = null;

        try {
            FailureLogSelectProbe::select("SELECT plate FROM t WHERE plate = 'ABC123'");
        } catch (ClickhouseException $exception) {
            $thrown = $exception;
        }

        $this->assertInstanceOf(ClickhouseException::class, $thrown);
        $this->assertSame(0, $thrown->getCode());
        $this->assertStringContainsString('ClickhouseHttpClient.php', $thrown->getFile());

        $this->assertCount(1, $this->dbErrors);
        $context = $this->dbErrors[0][1];
        $this->assertSame('ClickHouse HTTP exception', $this->dbErrors[0][0]);
        $this->assertStringContainsString('ABC123', $context['sql']);
        $this->assertSame('select', $context['statement']);
        $this->assertNull($context['ch_code']);
        $this->assertSame(FailureLogSelectProbe::class, $context['caller_class']);
        $this->assertSame('select', $context['caller_function']);
        $this->assertSame('tests/Feature/Clickhouse/ClickhouseFailureLoggingTest.php', $context['caller_file']);
        $this->assertIsInt($context['caller_line']);
        $this->assertSame('clarity_clickhouse', $context['connection']);
        $this->assertSame(1, $context['timeout_query']);
        $this->assertSame(1, $context['timeout_connect']);
        $this->assertSame(0, $context['retries']);
        $this->assertSame(2, $context['curl_timeout']);
        $this->assertStringContainsString('192.0.2.1', $thrown->getMessage());
        $this->assertStringNotContainsString('192.0.2.1', $context['exception_message']);
        $this->assertStringContainsString('cURL error:', $context['exception_message']);
        $this->assertArrayNotHasKey('query_id', $context);
        $this->assertArrayNotHasKey('bindings', $context);
        $this->assertArrayHasKey('bindings_redacted', $context);

        $serialized = json_encode($context);
        $this->assertIsString($serialized);
        $this->assertStringNotContainsString('ch-user-sentinel-2923', $serialized);
        $this->assertStringNotContainsString('ch-pass-sentinel-2923', $serialized);
        $this->assertStringNotContainsString('192.0.2.1', $serialized);
    }

    public function test_curl_error_write_logs_track_analytics_as_the_caller(): void
    {
        config([
            'database.connections.clarity_clickhouse_write.driver' => 'clickhouse',
            'database.connections.clarity_clickhouse_write.host' => '192.0.2.1',
            'database.connections.clarity_clickhouse_write.port' => '8123',
            'database.connections.clarity_clickhouse_write.https' => false,
            'database.connections.clarity_clickhouse_write.username' => 'ch-user-sentinel-2923',
            'database.connections.clarity_clickhouse_write.password' => 'ch-pass-sentinel-2923',
            'database.connections.clarity_clickhouse_write.timeout_connect' => 1,
            'database.connections.clarity_clickhouse_write.timeout_query' => 1,
            'database.connections.clarity_clickhouse_write.retries' => 0,
        ]);
        DB::purge('clarity_clickhouse_write');

        $this->captureDbError();
        $thrown = null;

        try {
            (new TrackAnalytics(
                feature: 'page-view',
                userId: 1,
                orgId: 1,
                data: AnalyticsDataDto::fromArray(['action' => 'view']),
                page: 'dashboard',
            ))->handle();
        } catch (ClickhouseException $exception) {
            $thrown = $exception;
        }

        $this->assertInstanceOf(ClickhouseException::class, $thrown);
        $this->assertCount(1, $this->dbErrors);
        $context = $this->dbErrors[0][1];
        $this->assertSame('write', $context['statement']);
        $this->assertSame(TrackAnalytics::class, $context['caller_class']);
        $this->assertSame('handle', $context['caller_function']);
        $this->assertSame('tests/Feature/Clickhouse/ClickhouseFailureLoggingTest.php', $context['caller_file']);
        $this->assertSame('clarity_clickhouse_write', $context['connection']);
        $this->assertNull($context['ch_code']);
        $this->assertStringContainsString('192.0.2.1', $thrown->getMessage());
        $this->assertStringNotContainsString('192.0.2.1', $context['exception_message']);

        $serialized = (string) json_encode($context);
        $this->assertStringNotContainsString('ch-pass-sentinel-2923', $serialized);
        $this->assertStringNotContainsString('192.0.2.1', $serialized);
    }

    public function test_successful_select_and_statement_do_not_log(): void
    {
        $host = (string) config('database.connections.clarity_clickhouse.host');
        $this->assertContains($host, ['127.0.0.1', 'localhost']);

        $this->captureDbError();

        $rows = DB::connection('clarity_clickhouse')->select('SELECT 1');
        $wrote = DB::connection('clarity_clickhouse')->statement('SELECT 1');

        $this->assertNotEmpty($rows);
        $this->assertTrue($wrote);
        $this->assertSame([], $this->dbErrors);
    }

    private function captureDbError(): void
    {
        $this->dbErrors = [];

        Log::spy();
        Log::shouldReceive('channel')->andReturnUsing(function () {
            $channel = Mockery::mock();
            $channel->shouldReceive('error')->andReturnUsing(function (string $message, array $context = []): void {
                $this->dbErrors[] = [$message, $context];
            });
            $channel->shouldIgnoreMissing();

            return $channel;
        });
    }
}
