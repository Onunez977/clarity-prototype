<?php

declare(strict_types=1);

namespace App\Jobs\Rdn;

use App\Clickhouse\Queries\RdnHitHourCountQuery;
use App\Enums\ProTierEnum;
use App\Enums\RdnMetricType;
use App\Models\Org;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Batch;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;

class DispatchRdnMetricsHourly implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $timeout = 120;

    public int $tries = 1;

    public function __construct()
    {
        $this->onQueue('{low}');
    }

    public function handle(): void
    {
        if (blank(config('services.rdn_metrics.queue_url'))) {
            return;
        }

        $rangeEnd = CarbonImmutable::now('UTC')->startOfHour();
        $rangeStart = $rangeEnd->subHour();

        $orgs = $this->eligibleOrgs();
        $dispatched = 0;

        foreach ($orgs as $org) {
            try {
                $this->dispatchBatchForOrg($org, $rangeStart, $rangeEnd);
                $dispatched++;
            } catch (\Throwable $e) {
                // Skip the entire org for this hour. Dispatching the scan job
                // alone would give RDN partial data for this window, and the
                // envelope's idempotency_key would lock it in — so a corrected
                // re-run would be deduped away. Better to drop the hour cleanly
                // and alert via Datadog than to ship half a payload.
                Log::error('RDN metrics dispatch skipped org', [
                    'org_id' => $org->id,
                    'range_start' => $rangeStart->toIso8601String(),
                    'message' => $e->getMessage(),
                ]);

                logDatadogCount('rdn_metrics.org_dispatch_failed', 1, [
                    'category' => 'rdn_metrics',
                    'item' => 'org_'.$org->id,
                ]);
            }
        }

        logDatadogCount('rdn_metrics.dispatch', $dispatched, [
            'category' => 'rdn_metrics',
            'item' => 'dispatch_tick',
        ]);

        // If we had eligible orgs but every single one failed to dispatch,
        // promote that to a job failure so it shows up in Horizon's failed
        // jobs tab and pages whoever is on-call. Otherwise the per-org
        // catch/log path silently absorbs a total outage.
        if ($orgs->isNotEmpty() && $dispatched === 0) {
            throw new \RuntimeException(
                "RDN metrics dispatch failed for all {$orgs->count()} eligible orgs",
            );
        }
    }

    /**
     * @return Collection<int, Org>
     */
    protected function eligibleOrgs(): Collection
    {
        return Org::query()
            ->whereNotNull('rdn_id')
            ->where('rdn_id', '!=', '')
            ->whereHas('meta', fn ($q) => $q
                ->where('properties->platform_repo_pro', true)
                ->where('properties->platform_repo_pro_rdn_metrics_push', true))
            ->with('meta')
            ->get()
            // PHP-side filters that can't be expressed in SQL cheaply:
            //   1. Trim rdn_id so whitespace-only values are dropped.
            //   2. proTier() resolves pending downgrades against the current date — must run after fetch.
            ->filter(fn (Org $org): bool => trim((string) $org->rdn_id) !== '' && in_array(
                $org->proTier(),
                [ProTierEnum::PRO_LITE, ProTierEnum::CLARITY_PRO, ProTierEnum::CLARITY_PRO_PLUS],
                true,
            ))
            ->values();
    }

    protected function dispatchBatchForOrg(Org $org, CarbonImmutable $rangeStart, CarbonImmutable $rangeEnd): void
    {
        $orgId = $org->id;
        $rdnOrgIdentifier = (string) $org->rdn_id;
        $rangeEndIso = $rangeEnd->toIso8601ZuluString();
        $rangeStartIso = $rangeStart->toIso8601ZuluString();

        // Resolve all 4 hit-type counts in one ClickHouse round-trip so the 4
        // hit jobs don't each re-run the same `hits_hot FINAL` query and
        // discard 3 of 4 columns. Scan stays in its own job — only 1 query.
        $hitCounts = $this->resolveHitCounts($orgId, $rangeStart, $rangeEnd);

        $jobs = collect(RdnMetricType::cases())
            ->map(fn (RdnMetricType $metric) => new PublishRdnMetric(
                orgId: $orgId,
                rdnOrgIdentifier: $rdnOrgIdentifier,
                metric: $metric,
                rangeStartIso: $rangeStartIso,
                rangeEndIso: $rangeEndIso,
                precomputedCount: $metric->isScan() ? null : ($hitCounts[$metric->hitColumn()] ?? 0),
            ))
            ->all();

        Bus::batch($jobs)
            ->name("rdn_metrics:{$orgId}:{$rangeEndIso}")
            ->onQueue('{low}')
            ->allowFailures()
            ->then(function (Batch $batch) use ($orgId) {
                logDatadogCount('rdn_metrics.org_batch_completed', 1, [
                    'category' => 'rdn_metrics',
                    'item' => 'org_'.$orgId,
                ]);
            })
            ->catch(function (Batch $batch, \Throwable $e) use ($orgId) {
                Log::error('RDN metrics batch failed', [
                    'org_id' => $orgId,
                    'batch_id' => $batch->id,
                    'message' => $e->getMessage(),
                ]);

                logDatadogCount('rdn_metrics.org_batch_failed', 1, [
                    'category' => 'rdn_metrics',
                    'item' => 'org_'.$orgId,
                ]);
            })
            ->dispatch();
    }

    /**
     * Resolve all 4 hit-type counts in a single ClickHouse round-trip.
     * Throws on failure — the caller (handle()) skips the entire org for
     * the hour so we never publish a partial / zeroed envelope under a
     * stable idempotency_key.
     *
     * @return array{lives:int,hh:int,staging_lives:int,aa:int}
     */
    protected function resolveHitCounts(int $orgId, CarbonImmutable $rangeStart, CarbonImmutable $rangeEnd): array
    {
        return RdnHitHourCountQuery::handle([
            'org_id' => $orgId,
            'range_start' => $rangeStart->utc()->format('Y-m-d H:i:s'),
            'range_end' => $rangeEnd->utc()->format('Y-m-d H:i:s'),
        ]);
    }
}
