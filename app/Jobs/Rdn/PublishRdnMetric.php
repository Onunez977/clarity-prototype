<?php

declare(strict_types=1);

namespace App\Jobs\Rdn;

use App\Enums\RdnMetricType;
use App\Services\Rdn\RdnMetricsPublisher;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class PublishRdnMetric implements ShouldQueue
{
    use Batchable;
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $timeout = 60;

    // Intentionally at-most-once. The non-FIFO queue can't dedup and the envelope
    // lacks a server-side idempotency contract with RDN today, so a Horizon retry
    // after a worker death post-`sendMessage` 200 would silently inflate RDN's
    // counts. We accept the risk of dropping a metric over double-publishing it.
    // Defense-in-depth: the envelope still carries an `idempotency_key` so RDN
    // can dedup on receipt if they choose to.
    public int $tries = 1;

    public function __construct(
        public int $orgId,
        public string $rdnOrgIdentifier,
        public RdnMetricType $metric,
        public string $rangeStartIso,
        public string $rangeEndIso,
        public ?int $precomputedCount = null,
    ) {
        $this->onQueue('{low}');
    }

    public function handle(RdnMetricsPublisher $publisher): void
    {
        if ($this->batch()?->cancelled()) {
            return;
        }

        $publisher->publish(
            orgId: $this->orgId,
            rdnOrgIdentifier: $this->rdnOrgIdentifier,
            metric: $this->metric,
            rangeStart: CarbonImmutable::parse($this->rangeStartIso),
            rangeEnd: CarbonImmutable::parse($this->rangeEndIso),
            precomputedCount: $this->precomputedCount,
        );
    }
}
