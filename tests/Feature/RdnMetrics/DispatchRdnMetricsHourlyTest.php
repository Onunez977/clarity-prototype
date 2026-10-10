<?php

declare(strict_types=1);

namespace Tests\Feature\RdnMetrics;

use App\Enums\ProTierEnum;
use App\Enums\RdnMetricType;
use App\Jobs\Rdn\DispatchRdnMetricsHourly;
use App\Jobs\Rdn\PublishRdnMetric;
use App\Models\Org;
use Carbon\CarbonImmutable;
use Illuminate\Bus\PendingBatch;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('rdn-metrics')]
class DispatchRdnMetricsHourlyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.rdn_metrics.queue_url', 'https://sqs.test/rdn-metrics');
    }

    public function test_dispatches_one_batch_of_five_per_eligible_pro_org(): void
    {
        Bus::fake();

        $proOrg = $this->makeRdnEligibleOrg(ProTierEnum::CLARITY_PRO, optedIn: true, rdnId: 'TX1234');
        $liteOrg = $this->makeRdnEligibleOrg(ProTierEnum::PRO_LITE, optedIn: true, rdnId: 'AZ9999');

        (new DispatchRdnMetricsHourly)->handle();

        Bus::assertBatchCount(2);

        Bus::assertBatched(function (PendingBatch $batch) use ($proOrg) {
            return str_starts_with($batch->name, "rdn_metrics:{$proOrg->id}:")
                && $batch->jobs->count() === 5
                && $batch->jobs->every(fn ($job) => $job instanceof PublishRdnMetric);
        });

        Bus::assertBatched(function (PendingBatch $batch) use ($liteOrg) {
            $metricValues = $batch->jobs->map(fn (PublishRdnMetric $j) => $j->metric->value)->all();
            $expected = array_map(fn (RdnMetricType $m) => $m->value, RdnMetricType::cases());

            return str_starts_with($batch->name, "rdn_metrics:{$liteOrg->id}:")
                && count($metricValues) === 5
                && empty(array_diff($expected, $metricValues));
        });
    }

    public function test_skips_pro_org_that_is_not_opted_in(): void
    {
        Bus::fake();

        $this->makeRdnEligibleOrg(ProTierEnum::CLARITY_PRO, optedIn: false, rdnId: 'TX1234');

        (new DispatchRdnMetricsHourly)->handle();

        Bus::assertNothingBatched();
    }

    public function test_dispatches_for_opted_in_pro_plus_org_with_rdn_id(): void
    {
        Bus::fake();

        $proPlusOrg = $this->makeRdnEligibleOrg(ProTierEnum::CLARITY_PRO_PLUS, optedIn: true, rdnId: 'TX1234');

        (new DispatchRdnMetricsHourly)->handle();

        Bus::assertBatchCount(1);
        Bus::assertBatched(
            fn (PendingBatch $batch) => str_starts_with($batch->name, "rdn_metrics:{$proPlusOrg->id}:")
                && $batch->jobs->count() === 5,
        );
    }

    public function test_skips_basic_tier_org_even_when_opted_in(): void
    {
        Bus::fake();

        $org = Org::factory()->create(['rdn_id' => 'TX1234']);
        $org->meta()->create([
            'properties' => [
                'platform_repo_pro' => false,
                'platform_repo_pro_rdn_metrics_push' => true,
            ],
        ]);

        (new DispatchRdnMetricsHourly)->handle();

        Bus::assertNothingBatched();
    }

    /**
     * The test above is excluded by the SQL predicate (platform_repo_pro = false), so it never
     * reaches the PHP-side tier filter. This one passes every SQL predicate and is excluded
     * ONLY by that filter — the single guard stopping a churned org's counts reaching RDN.
     */
    public function test_skips_basic_tier_org_that_passes_the_sql_predicate(): void
    {
        Bus::fake();

        $org = $this->makeRdnEligibleOrg(ProTierEnum::BASIC, optedIn: true, rdnId: 'TX1234');

        $this->assertSame(ProTierEnum::BASIC, $org->proTier());

        (new DispatchRdnMetricsHourly)->handle();

        Bus::assertNothingBatched();
    }

    public function test_skips_org_whose_pending_downgrade_to_basic_has_taken_effect(): void
    {
        Bus::fake();

        $org = $this->makeRdnEligibleOrg(ProTierEnum::CLARITY_PRO_PLUS, optedIn: true, rdnId: 'TX1234');
        $org->meta->updateProperties([
            'platform_repo_pro_downgrade_target_tier' => ProTierEnum::BASIC->value,
            'platform_repo_pro_downgrade_effective_at' => now()->subDay()->toDateString(),
        ]);

        $this->assertSame(ProTierEnum::BASIC, $org->refresh()->proTier());

        (new DispatchRdnMetricsHourly)->handle();

        Bus::assertNothingBatched();
    }

    public function test_skips_org_without_rdn_id(): void
    {
        Bus::fake();

        $this->makeRdnEligibleOrg(ProTierEnum::CLARITY_PRO, optedIn: true, rdnId: null);

        (new DispatchRdnMetricsHourly)->handle();

        Bus::assertNothingBatched();
    }

    public function test_skips_org_with_whitespace_only_rdn_id(): void
    {
        Bus::fake();

        $this->makeRdnEligibleOrg(ProTierEnum::CLARITY_PRO, optedIn: true, rdnId: '   ');

        (new DispatchRdnMetricsHourly)->handle();

        Bus::assertNothingBatched();
    }

    public function test_scan_job_has_no_precomputed_count_hit_jobs_do(): void
    {
        Bus::fake();

        $this->makeRdnEligibleOrg(ProTierEnum::CLARITY_PRO, optedIn: true, rdnId: 'TX1234');

        (new DispatchRdnMetricsHourly)->handle();

        Bus::assertBatched(function (PendingBatch $batch) {
            $byMetric = $batch->jobs->keyBy(fn (PublishRdnMetric $j) => $j->metric->value);

            // Scan: count is resolved inside the worker (no precompute) so dispatcher work stays cheap.
            $scanJobIsNullPrecomputed = $byMetric[RdnMetricType::SCANS->value]->precomputedCount === null;

            // Hit metrics: dispatcher pre-resolves all 4 in one CH round-trip and pins them on the jobs.
            $allHitJobsArePrecomputed = collect(RdnMetricType::hitCases())
                ->every(fn (RdnMetricType $m) => is_int($byMetric[$m->value]->precomputedCount));

            return $scanJobIsNullPrecomputed && $allHitJobsArePrecomputed;
        });
    }

    public function test_dispatches_jobs_with_prior_hour_window(): void
    {
        Bus::fake();
        // Freeze time at 14:05 UTC — the completed hour is [13:00, 14:00).
        // If the dispatcher ever flips subHour()→addHour() or drops startOfHour(),
        // this test will catch it.
        Carbon::setTestNow('2026-05-13 14:05:23');

        $this->makeRdnEligibleOrg(ProTierEnum::CLARITY_PRO, optedIn: true, rdnId: 'TX1234');

        (new DispatchRdnMetricsHourly)->handle();

        Bus::assertBatched(function (PendingBatch $batch) {
            return $batch->jobs->every(fn (PublishRdnMetric $job) => $job->rangeStartIso === '2026-05-13T13:00:00Z'
                && $job->rangeEndIso === '2026-05-13T14:00:00Z');
        });

        Carbon::setTestNow();
    }

    public function test_org_is_skipped_entirely_when_hit_precompute_throws(): void
    {
        Bus::fake();

        $failingOrg = $this->makeRdnEligibleOrg(ProTierEnum::CLARITY_PRO, optedIn: true, rdnId: 'TX-FAIL');
        $healthyOrg = $this->makeRdnEligibleOrg(ProTierEnum::CLARITY_PRO, optedIn: true, rdnId: 'AZ-OK');

        $dispatcher = new DispatchRdnMetricsHourlyWithFailingOrg($failingOrg->id);

        $dispatcher->handle();

        // Exactly one batch — the healthy org's. The failing org must not have
        // a batch dispatched: partial publishes would lock in a bogus
        // idempotency_key on RDN's side and a corrected re-run would be deduped.
        Bus::assertBatchCount(1);
        Bus::assertBatched(
            fn (PendingBatch $batch) => str_starts_with($batch->name, "rdn_metrics:{$healthyOrg->id}:"),
        );
    }

    public function test_is_a_noop_when_queue_url_missing(): void
    {
        Bus::fake();
        config()->set('services.rdn_metrics.queue_url', null);

        $this->makeRdnEligibleOrg(ProTierEnum::CLARITY_PRO, optedIn: true, rdnId: 'TX1234');

        (new DispatchRdnMetricsHourly)->handle();

        Bus::assertNothingBatched();
    }

    protected function makeRdnEligibleOrg(ProTierEnum $tier, bool $optedIn, ?string $rdnId): Org
    {
        $org = Org::factory()->create(['rdn_id' => $rdnId]);

        $org->meta()->create([
            'properties' => [
                'platform_repo_pro' => true,
                'platform_repo_pro_tier' => $tier->value,
                'platform_repo_pro_rdn_metrics_push' => $optedIn,
            ],
        ]);

        $org->refresh()->load('meta');

        return $org;
    }
}

/**
 * Test-only subclass that simulates a ClickHouse failure for one specific org
 * so we can prove the dispatcher skips that org entirely (no partial publish,
 * no idempotency_key squat) without touching the real CH connection.
 */
class DispatchRdnMetricsHourlyWithFailingOrg extends DispatchRdnMetricsHourly
{
    public function __construct(public int $failForOrgId)
    {
        parent::__construct();
    }

    protected function resolveHitCounts(int $orgId, CarbonImmutable $rangeStart, CarbonImmutable $rangeEnd): array
    {
        if ($orgId === $this->failForOrgId) {
            throw new \RuntimeException('simulated CH timeout');
        }

        return parent::resolveHitCounts($orgId, $rangeStart, $rangeEnd);
    }
}
