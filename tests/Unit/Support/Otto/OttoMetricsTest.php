<?php

namespace Tests\Unit\Support\Otto;

use App\Ai\Surface;
use App\Models\User;
use App\Support\Otto\OttoMetrics;
use DataDog\DogStatsd;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Responses\Data\Usage;
use Mockery;
use Tests\TestCase;

class OttoMetricsTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function metricsUser(int $id = 42, ?string $email = 'Alice@Example.com', ?string $name = 'Alice'): User
    {
        $user = new User;
        $user->id = $id;
        if ($email !== null) {
            $user->email = $email;
        }
        if ($name !== null) {
            $user->name = $name;
        }

        return $user;
    }

    public function test_enabled_requires_production_and_forwarding(): void
    {
        $this->app->detectEnvironment(fn () => 'local');
        config(['services.datadog_forwarding' => true]);
        $this->assertFalse(OttoMetrics::enabled());

        $this->app->detectEnvironment(fn () => 'production');
        config(['services.datadog_forwarding' => false]);
        $this->assertFalse(OttoMetrics::enabled());

        $this->app->detectEnvironment(fn () => 'preprod');
        config(['services.datadog_forwarding' => true]);
        $this->assertFalse(OttoMetrics::enabled());

        $this->app->detectEnvironment(fn () => 'production');
        config(['services.datadog_forwarding' => true]);
        $this->assertTrue(OttoMetrics::enabled());
    }

    public function test_record_turn_noops_when_disabled(): void
    {
        $this->app->detectEnvironment(fn () => 'local');
        config(['services.datadog_forwarding' => true]);

        $statsd = Mockery::mock(DogStatsd::class);
        $statsd->shouldNotReceive('increment');
        $statsd->shouldNotReceive('gauge');
        $this->app->instance(DogStatsd::class, $statsd);

        OttoMetrics::recordTurn(
            Surface::Chat,
            $this->metricsUser(),
            new Usage(100, 50),
            [new ToolCall('t1', 'ScanSearchTool', [])],
        );

        $this->addToAssertionCount(1);
    }

    public function test_record_turn_emits_message_tokens_cost_and_tool_calls(): void
    {
        $this->app->detectEnvironment(fn () => 'production');
        config([
            'services.datadog_forwarding' => true,
            'otto.pricing.prompt_usd_per_million' => 2.20,
            'otto.pricing.completion_usd_per_million' => 6.60,
            'otto.pricing.cache_read_usd_per_million' => 0.55,
        ]);

        $increments = [];
        $statsd = Mockery::mock(DogStatsd::class);
        $statsd->shouldReceive('increment')
            ->andReturnUsing(function (string $metric, $sampleRate = 1.0, $tags = null, $value = 1) use (&$increments) {
                $increments[] = [
                    'metric' => $metric,
                    'value' => $value,
                    'tags' => $tags,
                ];
            });
        $this->app->instance(DogStatsd::class, $statsd);

        // 1M prompt + 1M completion + 1M cache read → $2.20 + $6.60 + $0.55 = $9.35
        OttoMetrics::recordTurn(
            Surface::Chat,
            $this->metricsUser(7, 'bob@example.com', 'Bob'),
            new Usage(1_000_000, 1_000_000, 0, 1_000_000),
            [
                new ToolCall('t1', 'ScanSearchTool', []),
                new ToolCall('t2', 'HitSearchTool', []),
            ],
        );

        $byMetric = collect($increments)->groupBy('metric');

        $expectedUserTags = [
            'surface' => 'chat',
            'user_id' => '7',
            'user' => 'bob_example.com',
        ];

        $this->assertCount(1, $byMetric['clarity.otto_message']);
        $this->assertSame($expectedUserTags, $byMetric['clarity.otto_message'][0]['tags']);

        $this->assertSame(1_000_000.0, $byMetric['clarity.otto_tokens_prompt'][0]['value']);
        $this->assertSame($expectedUserTags, $byMetric['clarity.otto_tokens_prompt'][0]['tags']);
        $this->assertSame(1_000_000.0, $byMetric['clarity.otto_tokens_completion'][0]['value']);
        $this->assertSame($expectedUserTags, $byMetric['clarity.otto_tokens_completion'][0]['tags']);
        $this->assertEqualsWithDelta(9.35, $byMetric['clarity.otto_estimated_cost_usd'][0]['value'], 0.0001);
        $this->assertSame($expectedUserTags, $byMetric['clarity.otto_estimated_cost_usd'][0]['tags']);

        $toolNames = collect($byMetric['clarity.otto_tool_call'])->pluck('tags.tool')->all();
        $this->assertSame(['ScanSearchTool', 'HitSearchTool'], $toolNames);
        $this->assertSame(
            ['surface' => 'chat', 'tool' => 'ScanSearchTool'],
            $byMetric['clarity.otto_tool_call'][0]['tags'],
        );
    }

    public function test_record_turn_with_zero_usage_skips_tokens_and_cost(): void
    {
        $this->app->detectEnvironment(fn () => 'production');
        config(['services.datadog_forwarding' => true]);

        $increments = [];
        $statsd = Mockery::mock(DogStatsd::class);
        $statsd->shouldReceive('increment')
            ->andReturnUsing(function (string $metric, $sampleRate = 1.0, $tags = null, $value = 1) use (&$increments) {
                $increments[] = $metric;
            });
        $this->app->instance(DogStatsd::class, $statsd);

        OttoMetrics::recordTurn(Surface::Chat, $this->metricsUser(), new Usage(0, 0), ['ScanSearchTool']);

        $this->assertSame(
            ['clarity.otto_message', 'clarity.otto_tool_call'],
            $increments,
        );
    }

    public function test_user_tags_prefer_email_then_name_then_id_fallback(): void
    {
        $this->assertSame(
            ['user_id' => '9', 'user' => 'sam_example.com'],
            OttoMetrics::userTags($this->metricsUser(9, 'Sam@Example.com', 'Sam')),
        );

        $this->assertSame(
            ['user_id' => '9', 'user' => 'sam_smith'],
            OttoMetrics::userTags($this->metricsUser(9, null, 'Sam Smith')),
        );

        $this->assertSame(
            ['user_id' => '9', 'user' => 'user_9'],
            OttoMetrics::userTags($this->metricsUser(9, null, null)),
        );
    }

    public function test_sanitize_tag_value_lowercases_and_replaces_invalid_chars(): void
    {
        $this->assertSame(
            'alice_example.com',
            OttoMetrics::sanitizeTagValue('Alice Example.com'),
        );
        $this->assertSame(
            'a_b-c:d.e/f',
            OttoMetrics::sanitizeTagValue('A!B-C:D.E/F'),
        );
        $this->assertSame('unknown', OttoMetrics::sanitizeTagValue('!!!'));
        $this->assertSame(100, strlen(OttoMetrics::sanitizeTagValue(str_repeat('a', 150))));
    }

    public function test_record_feedback_emits_rating_tag(): void
    {
        $this->app->detectEnvironment(fn () => 'production');
        config(['services.datadog_forwarding' => true]);

        $tagsSeen = [];
        $statsd = Mockery::mock(DogStatsd::class);
        $statsd->shouldReceive('increment')
            ->twice()
            ->andReturnUsing(function (string $metric, $sampleRate = 1.0, $tags = null) use (&$tagsSeen) {
                $this->assertSame('clarity.otto_feedback', $metric);
                $tagsSeen[] = $tags['rating'] ?? null;
            });
        $this->app->instance(DogStatsd::class, $statsd);

        OttoMetrics::recordFeedback(true);
        OttoMetrics::recordFeedback(false);

        $this->assertSame(['positive', 'negative'], $tagsSeen);
    }

    public function test_sub_cent_cost_is_a_count_sample_not_zero_on_the_wire(): void
    {
        $this->app->detectEnvironment(fn () => 'production');
        config([
            'services.datadog_forwarding' => true,
            'otto.pricing.prompt_usd_per_million' => 2.20,
            'otto.pricing.completion_usd_per_million' => 6.60,
            'otto.pricing.cache_read_usd_per_million' => 0.55,
        ]);

        $path = sys_get_temp_dir().'/otto-dd-'.getmypid().'-'.uniqid().'.sock';
        if (file_exists($path)) {
            unlink($path);
        }

        $socket = socket_create(AF_UNIX, SOCK_DGRAM, 0);
        $this->assertNotFalse($socket);
        $this->assertTrue(socket_bind($socket, $path));
        socket_set_nonblock($socket);

        $previousUrl = getenv('DD_DOGSTATSD_URL');
        putenv('DD_DOGSTATSD_URL=unix://'.$path);
        $this->app->forgetInstance(DogStatsd::class);

        try {
            // 500 prompt + 200 completion = $0.0011 + $0.00132 = $0.00242.
            // Precision 2 submits that as 0|c. Precision 8 keeps 0.00242|c.
            OttoMetrics::recordTurn(
                Surface::Chat,
                $this->metricsUser(3, 'brad.webb@premieradjusters.com'),
                new Usage(500, 200),
            );

            $packets = $this->readDatagrams($socket);
        } finally {
            socket_close($socket);
            if (file_exists($path)) {
                unlink($path);
            }
            if ($previousUrl === false) {
                putenv('DD_DOGSTATSD_URL');
            } else {
                putenv('DD_DOGSTATSD_URL='.$previousUrl);
            }
            $this->app->forgetInstance(DogStatsd::class);
        }

        $this->assertStringContainsString('clarity.otto_message:1|c', $packets);
        $this->assertStringContainsString('clarity.otto_tokens_prompt:500|c', $packets);
        $this->assertStringContainsString('clarity.otto_tokens_completion:200|c', $packets);
        $this->assertStringContainsString('clarity.otto_estimated_cost_usd:0.00242|c', $packets);
        $this->assertStringNotContainsString('clarity.otto_estimated_cost_usd:0|c', $packets);
        $this->assertStringContainsString('user:brad.webb_premieradjusters.com', $packets);
        $this->assertStringNotContainsString('|g', $packets);
    }

    private function readDatagrams(\Socket $socket): string
    {
        $packets = '';
        $buffer = '';
        $from = '';

        while (@socket_recvfrom($socket, $buffer, 8192, 0, $from) !== false) {
            $packets .= $buffer."\n";
            $buffer = '';
        }

        return $packets;
    }

    public function test_estimate_cost_usd_uses_config_rates(): void
    {
        config([
            'otto.pricing.prompt_usd_per_million' => 1.0,
            'otto.pricing.completion_usd_per_million' => 2.0,
            'otto.pricing.cache_read_usd_per_million' => 0.5,
        ]);

        $this->assertEqualsWithDelta(
            0.0035,
            OttoMetrics::estimateCostUsd(1000, 1000, 1000),
            0.0000001,
        );
    }
}
