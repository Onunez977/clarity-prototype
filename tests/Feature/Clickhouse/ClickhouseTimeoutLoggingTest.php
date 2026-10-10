<?php

declare(strict_types=1);

namespace Tests\Feature\Clickhouse;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use LaravelClickhouseEloquent\Exceptions\ClickhouseException;
use Mockery;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\RequiresClickhouse;
use Tests\TestCase;

#[Group('clickhouse')]
class ClickhouseTimeoutLoggingTest extends TestCase
{
    use RequiresClickhouse;

    public function test_forced_server_timeout_logs_code_159_without_an_app_caller(): void
    {
        $host = (string) config('database.connections.clarity_clickhouse.host');
        $this->assertContains($host, ['127.0.0.1', 'localhost']);

        $this->setupRequiresClickhouse();

        config([
            'database.connections.clarity_clickhouse.timeout_query' => 2,
            'database.connections.clarity_clickhouse.timeout_connect' => 5,
            'database.connections.clarity_clickhouse.retries' => 0,
            'database.connections.clarity_clickhouse.https' => false,
            'database.connections.clarity_clickhouse.settings' => array_merge(
                config('database.connections.clarity_clickhouse.settings', []),
                [
                    'timeout_before_checking_execution_speed' => 0,
                    'max_block_size' => 1,
                    // CH 26.2 writes the 159 trailer after a partial HTTP 200 body and
                    // closes the socket. The package client keeps the curl error and
                    // drops that body. Waiting for the query end returns HTTP 408
                    // with Code 159 before any rows, which this client can parse.
                    'wait_end_of_query' => 1,
                ],
            ),
        ]);
        DB::purge('clarity_clickhouse');

        $logged = new \stdClass;
        $logged->rows = [];
        Log::spy();
        Log::shouldReceive('channel')->andReturnUsing(function () use ($logged) {
            $channel = Mockery::mock();
            $channel->shouldReceive('error')->andReturnUsing(function (string $message, array $context = []) use ($logged): void {
                $logged->rows[] = [$message, $context];
            });
            $channel->shouldIgnoreMissing();

            return $channel;
        });

        $thrown = null;

        try {
            DB::connection('clarity_clickhouse')->select('SELECT * FROM numbers(100000000)');
        } catch (ClickhouseException $exception) {
            $thrown = $exception;
        }

        $this->assertInstanceOf(ClickhouseException::class, $thrown);
        $this->assertSame(0, $thrown->getCode());
        $this->assertStringContainsString('TIMEOUT_EXCEEDED', $thrown->getMessage());

        $this->assertCount(1, $logged->rows);
        $context = $logged->rows[0][1];
        $this->assertSame('ClickHouse HTTP exception', $logged->rows[0][0]);
        $this->assertSame(159, $context['ch_code']);
        $this->assertStringContainsString('TIMEOUT_EXCEEDED', $context['exception_message']);
        $this->assertStringContainsString('numbers(', $context['sql']);
        $this->assertSame('select', $context['statement']);
        $this->assertSame('clarity_clickhouse', $context['connection']);
        $this->assertNull($context['caller_class']);
        $this->assertNull($context['caller_function']);
        $this->assertNull($context['caller_file']);
        $this->assertNull($context['caller_line']);
        $this->assertTrue($context['console']);
        $this->assertSame(2, $context['timeout_query']);
        $this->assertSame(5, $context['timeout_connect']);
        $this->assertSame(0, $context['retries']);
        $this->assertSame(7, $context['curl_timeout']);
        $this->assertSame(2, $context['max_execution_time']);
        $this->assertArrayNotHasKey('query_id', $context);
        $this->assertArrayNotHasKey('bindings', $context);
        $this->assertTrue(
            $context['server_elapsed_ms'] === null || is_float($context['server_elapsed_ms']) || is_int($context['server_elapsed_ms']),
        );
    }
}
