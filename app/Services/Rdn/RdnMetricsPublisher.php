<?php

declare(strict_types=1);

namespace App\Services\Rdn;

use App\Clickhouse\Queries\RdnHitHourCountQuery;
use App\Clickhouse\Queries\RdnScanHourCountQuery;
use App\Enums\RdnMetricType;
use App\Services\Aws\Sqs;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;

class RdnMetricsPublisher
{
    protected Sqs $sqs;

    public function __construct()
    {
        $this->sqs = new Sqs(
            config: [
                'profile' => config('services.rdn_metrics.aws_profile'),
                'region' => config('services.rdn_metrics.aws_region'),
                // Disable the AWS SDK's default 3-retry policy. Combined with
                // PublishRdnMetric::$tries = 1, this gives true at-most-once
                // semantics — the SDK won't silently duplicate a SendMessage
                // on transient errors against a non-FIFO queue. Idempotency
                // key in the envelope remains the belt-and-suspenders path
                // if RDN ever dedups on receipt.
                'retries' => 0,
            ],
            queueUrl: config('services.rdn_metrics.queue_url'),
        );
    }

    public function publish(
        int $orgId,
        string $rdnOrgIdentifier,
        RdnMetricType $metric,
        CarbonImmutable $rangeStart,
        CarbonImmutable $rangeEnd,
        ?int $precomputedCount = null,
    ): void {
        try {
            $count = $precomputedCount ?? $this->resolveCount($orgId, $metric, $rangeStart, $rangeEnd);

            $rangeStartIso = $rangeStart->utc()->toIso8601ZuluString();
            $rangeEndIso = $rangeEnd->utc()->toIso8601ZuluString();

            $envelope = [
                'metric_type' => $metric->value,
                'metric_range_start' => $rangeStartIso,
                'metric_range_end' => $rangeEndIso,
                'rdn_org_identifier' => $rdnOrgIdentifier,
                // Stable across resends of the same (org, metric, window). RDN can
                // dedup on this to guard against the at-least-once SQS contract.
                'idempotency_key' => sha1(implode('|', [
                    $rdnOrgIdentifier,
                    $metric->value,
                    $rangeStartIso,
                    $rangeEndIso,
                ])),
                'data' => [['count' => $count]],
            ];

            $this->sqs->sendRdnMetricsMessage(
                json_encode($envelope, JSON_THROW_ON_ERROR),
            );
        } catch (\Throwable $e) {
            Log::error('RDN metrics SQS error', [
                'org_id' => $orgId,
                'metric' => $metric->value,
                'range_start' => $rangeStart->toIso8601String(),
                'range_end' => $rangeEnd->toIso8601String(),
                'message' => $e->getMessage(),
            ]);

            logDatadogCount('rdn_metrics.publish_failed', 1, [
                'category' => 'rdn_metrics',
                'item' => $metric->value,
            ]);

            throw $e;
        }

        // Outside the try/catch: a thrown Datadog counter must not trigger
        // a Horizon retry, since that would re-send a duplicate envelope.
        try {
            logDatadogCount('rdn_metrics.published', 1, [
                'category' => 'rdn_metrics',
                'item' => $metric->value,
            ]);
        } catch (\Throwable) {
            // Swallow — observability failure must not affect delivery.
        }
    }

    protected function resolveCount(
        int $orgId,
        RdnMetricType $metric,
        CarbonImmutable $rangeStart,
        CarbonImmutable $rangeEnd,
    ): int {
        if ($metric->isScan()) {
            return RdnScanHourCountQuery::handle([
                'org_id' => $orgId,
                'hour' => $rangeStart->utc()->format('Y-m-d H:i:s'),
            ]);
        }

        $hits = RdnHitHourCountQuery::handle([
            'org_id' => $orgId,
            'range_start' => $rangeStart->utc()->format('Y-m-d H:i:s'),
            'range_end' => $rangeEnd->utc()->format('Y-m-d H:i:s'),
        ]);

        return (int) ($hits[$metric->hitColumn()] ?? 0);
    }
}
