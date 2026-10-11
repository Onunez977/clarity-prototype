<?php

declare(strict_types=1);

namespace Tests\Feature\RdnMetrics;

use App\Enums\RdnMetricType;
use App\Jobs\Rdn\PublishRdnMetric;
use App\Services\Aws\Sqs;
use App\Services\Rdn\RdnMetricsPublisher;
use Aws\Result;
use Carbon\CarbonImmutable;
use Mockery;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('rdn-metrics')]
class RdnMetricsPublisherTest extends TestCase
{
    /** @var array<int, array<string, mixed>> */
    protected array $captured = [];

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.rdn_metrics.queue_url', 'https://sqs.test/rdn-metrics');

        $this->captured = [];
        $captured = &$this->captured;

        $fake = Mockery::mock(Sqs::class);
        $fake->shouldReceive('sendRdnMetricsMessage')
            ->andReturnUsing(function (string $body) use (&$captured) {
                $captured[] = json_decode($body, true);

                return new Result;
            });

        $this->app->instance(Sqs::class, $fake);

        // RdnMetricsPublisher constructs its own Sqs. Inject our fake by overriding
        // the publisher's resolution so it uses our mocked instance.
        $this->app->bind(RdnMetricsPublisher::class, function () use ($fake) {
            $publisher = new class extends RdnMetricsPublisher
            {
                public function __construct() {}

                public function setSqs(Sqs $sqs): void
                {
                    $this->sqs = $sqs;
                }
            };
            $publisher->setSqs($fake);

            return $publisher;
        });
    }

    public function test_envelope_shape_for_scan_metric(): void
    {
        $publisher = app(RdnMetricsPublisher::class);
        $rangeEnd = CarbonImmutable::parse('2026-05-01T01:00:00Z');
        $rangeStart = $rangeEnd->subHour();

        $publisher->publish(
            orgId: 42,
            rdnOrgIdentifier: 'AZ5209991234',
            metric: RdnMetricType::SCANS,
            rangeStart: $rangeStart,
            rangeEnd: $rangeEnd,
            precomputedCount: 12871,
        );

        $this->assertCount(1, $this->captured);
        $envelope = $this->captured[0];

        $this->assertSame('scans_by_org_by_hour', $envelope['metric_type']);
        $this->assertSame('2026-05-01T00:00:00Z', $envelope['metric_range_start']);
        $this->assertSame('2026-05-01T01:00:00Z', $envelope['metric_range_end']);
        $this->assertSame('AZ5209991234', $envelope['rdn_org_identifier']);
        $this->assertSame([['count' => 12871]], $envelope['data']);
        $this->assertSame(
            sha1('AZ5209991234|scans_by_org_by_hour|2026-05-01T00:00:00Z|2026-05-01T01:00:00Z'),
            $envelope['idempotency_key'],
        );
    }

    public function test_idempotency_key_is_stable_across_republish(): void
    {
        $publisher = app(RdnMetricsPublisher::class);
        $rangeEnd = CarbonImmutable::parse('2026-05-01T01:00:00Z');
        $rangeStart = $rangeEnd->subHour();

        $publisher->publish(orgId: 1, rdnOrgIdentifier: 'AZ123', metric: RdnMetricType::SCANS,
            rangeStart: $rangeStart, rangeEnd: $rangeEnd, precomputedCount: 100);
        $publisher->publish(orgId: 1, rdnOrgIdentifier: 'AZ123', metric: RdnMetricType::SCANS,
            rangeStart: $rangeStart, rangeEnd: $rangeEnd, precomputedCount: 100);

        $this->assertCount(2, $this->captured);
        $this->assertSame($this->captured[0]['idempotency_key'], $this->captured[1]['idempotency_key']);
    }

    public function test_idempotency_key_differs_per_metric_type(): void
    {
        $publisher = app(RdnMetricsPublisher::class);
        $rangeEnd = CarbonImmutable::parse('2026-05-01T01:00:00Z');
        $rangeStart = $rangeEnd->subHour();

        foreach (RdnMetricType::cases() as $metric) {
            $publisher->publish(orgId: 1, rdnOrgIdentifier: 'AZ123', metric: $metric,
                rangeStart: $rangeStart, rangeEnd: $rangeEnd, precomputedCount: 0);
        }

        $keys = collect($this->captured)->pluck('idempotency_key');
        $this->assertCount(5, $keys->unique());
    }

    public function test_envelope_for_each_hit_metric_uses_correct_count(): void
    {
        $publisher = app(RdnMetricsPublisher::class);
        $rangeEnd = CarbonImmutable::parse('2026-05-01T01:00:00Z');
        $rangeStart = $rangeEnd->subHour();

        $expected = [
            RdnMetricType::LIVE_HITS->value => 47,
            RdnMetricType::HISTORICAL_HITS->value => 12,
            RdnMetricType::STAGING_LIVE->value => 3,
            RdnMetricType::STAGING_HISTORICAL->value => 1,
        ];

        foreach ($expected as $metricValue => $count) {
            $metric = RdnMetricType::from($metricValue);

            $publisher->publish(
                orgId: 42,
                rdnOrgIdentifier: 'AZ5209991234',
                metric: $metric,
                rangeStart: $rangeStart,
                rangeEnd: $rangeEnd,
                precomputedCount: $count,
            );
        }

        $countsByType = collect($this->captured)->mapWithKeys(
            fn (array $e) => [$e['metric_type'] => $e['data'][0]['count']],
        );

        $this->assertSame(47, $countsByType['lives_by_org_by_hour']);
        $this->assertSame(12, $countsByType['hh_by_org_by_hour']);
        $this->assertSame(3, $countsByType['staging_lives_by_org_by_hour']);
        $this->assertSame(1, $countsByType['aa_by_org_by_hour']);
    }

    public function test_publish_job_is_at_most_once(): void
    {
        // Design contract: at-most-once delivery. PublishRdnMetric must not be
        // configured to retry — combined with `retries=0` on the SqsClient,
        // this guarantees a single SendMessage attempt per envelope.
        $job = new PublishRdnMetric(
            orgId: 1,
            rdnOrgIdentifier: 'AZ123',
            metric: RdnMetricType::SCANS,
            rangeStartIso: '2026-05-01T00:00:00Z',
            rangeEndIso: '2026-05-01T01:00:00Z',
        );

        $this->assertSame(1, $job->tries);
    }

    public function test_sqs_send_failure_propagates_from_publish(): void
    {
        // If SQS fails, publish() must throw so Horizon marks the job failed.
        // Combined with tries=1, this gives the "drop the metric, never duplicate"
        // contract. Critical: the success-side Datadog counter is OUTSIDE the
        // try/catch, so a counter exception couldn't trigger this either.
        $failingFake = Mockery::mock(Sqs::class);
        $failingFake->shouldReceive('sendRdnMetricsMessage')
            ->andThrow(new \RuntimeException('simulated SQS outage'));

        $this->app->bind(RdnMetricsPublisher::class, function () use ($failingFake) {
            $publisher = new class extends RdnMetricsPublisher
            {
                public function __construct() {}

                public function setSqs(Sqs $sqs): void
                {
                    $this->sqs = $sqs;
                }
            };
            $publisher->setSqs($failingFake);

            return $publisher;
        });

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('simulated SQS outage');

        app(RdnMetricsPublisher::class)->publish(
            orgId: 1,
            rdnOrgIdentifier: 'AZ123',
            metric: RdnMetricType::SCANS,
            rangeStart: CarbonImmutable::parse('2026-05-01T00:00:00Z'),
            rangeEnd: CarbonImmutable::parse('2026-05-01T01:00:00Z'),
            precomputedCount: 100,
        );
    }
}
