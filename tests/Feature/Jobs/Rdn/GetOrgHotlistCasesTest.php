<?php

namespace Tests\Feature\Jobs\Rdn;

use App\Console\Commands\GenerateRdnCache;
use App\Enums\HotlistStatusEnum;
use App\Jobs\Rdn\GetOrgHotlistCaseInfo;
use App\Jobs\Rdn\GetOrgHotlistCases;
use App\Models\Hotlist;
use App\Models\Org;
use DataDog\DogStatsd;
use Illuminate\Bus\PendingBatch;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schedule;
use InsightLPR\BlackAdam\Enum\IntegrationEnum;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

class GetOrgHotlistCasesTest extends TestCase
{
    /**
     * An org the enrichment run is allowed to act for: the gate reads rdn_api_key - what
     * RDN::__construct builds its SOAP client from - and without it GetOrgHotlistCases
     * bails before it selects anything.
     */
    private function rdnOrg(array $attributes = []): Org
    {
        return Org::factory()->create(array_merge([
            'rdn_id' => (string) fake()->numberBetween(1000, 9999),
            'rdn_api_key' => fake()->uuid(),
        ], $attributes));
    }

    /**
     * A hotlist the run is allowed to enrich. HotlistFactory defaults integration_id to a
     * fresh Integrations row, which is never RDN, so every candidate has to say so.
     */
    private function rdnHotlist(Org $org, array $attributes = []): Hotlist
    {
        return Hotlist::factory()->create(array_merge([
            'org_id' => $org->id,
            'integration_id' => IntegrationEnum::RDN->value,
        ], $attributes));
    }

    /**
     * The dispatch offsets of this run's fan-out, ascending.
     *
     * @return list<int>
     */
    private function dispatchedDelays(): array
    {
        $delays = collect(Bus::dispatched(GetOrgHotlistCaseInfo::class))
            ->map(fn (GetOrgHotlistCaseInfo $job): int => (int) $job->delay)
            ->sort()
            ->values()
            ->all();

        return $delays;
    }

    /**
     * hotlist_id => delay seconds, preserving which row landed in which slot.
     *
     * @return array<int, int>
     */
    private function delaysByHotlistId(): array
    {
        $map = [];

        foreach (Bus::dispatched(GetOrgHotlistCaseInfo::class) as $job) {
            $map[$job->hotlistId] = (int) $job->delay;
        }

        return $map;
    }

    private function releaseCaseInfoUniqueLocks(): void
    {
        // Bus::fake does not process jobs, so UniqueUntilProcessing locks linger and would
        // silently drop a second handle() of the same hotlists. ArrayStore keeps locks in a
        // separate $store->locks map that Cache::flush() does not clear.
        Cache::flush();
        $store = Cache::getStore();
        if (is_object($store) && property_exists($store, 'locks')) {
            $store->locks = [];
        }
    }

    private function insertHotlistJson(int $hotlistId, array $json): void
    {
        DB::connection('zod')->table('public.hotlist_json')->insert([
            'hotlist_id' => $hotlistId,
            'json' => json_encode($json),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * The dispatcher instances app:rdn queued, in batch order.
     *
     * @return list<GetOrgHotlistCases>
     */
    private function batchedDispatchers(): array
    {
        return collect(Bus::batched(fn (PendingBatch $batch): bool => true))
            ->flatMap(fn (PendingBatch $batch): array => $batch->jobs->all())
            ->filter(fn (mixed $job): bool => $job instanceof GetOrgHotlistCases)
            ->values()
            ->all();
    }

    protected function setUp(): void
    {
        parent::setUp();

        // ShouldBeUniqueUntilProcessing locks survive Bus::fake (jobs never process),
        // and RefreshDatabase reuses low hotlist ids — flush so each test starts clean.
        $this->releaseCaseInfoUniqueLocks();
    }

    #[Group('jobs')]
    #[Group('rdn')]
    public function test_it_dispatches_only_for_hotlists_without_a_branch_id(): void
    {
        Bus::fake();

        $org = $this->rdnOrg();

        $noJsonRow = $this->rdnHotlist($org);

        $missingBranchKey = $this->rdnHotlist($org);
        // Raw insert bypasses HotlistJsonData's cast, which only declares
        // branch_id and would otherwise silently drop the seeded key.
        $this->insertHotlistJson($missingBranchKey->id, ['case_number' => 'EXTERNAL-1']);

        $alreadyEnriched = $this->rdnHotlist($org);
        $this->insertHotlistJson($alreadyEnriched->id, ['branch_id' => 12345]);

        (new GetOrgHotlistCases(orgId: $org->id, org: $org))->handle();

        Bus::assertDispatchedTimes(GetOrgHotlistCaseInfo::class, 2);
        Bus::assertDispatched(GetOrgHotlistCaseInfo::class, fn ($job) => $job->hotlistId === $noJsonRow->id);
        Bus::assertDispatched(GetOrgHotlistCaseInfo::class, fn ($job) => $job->hotlistId === $missingBranchKey->id);
        Bus::assertNotDispatched(GetOrgHotlistCaseInfo::class, fn ($job) => $job->hotlistId === $alreadyEnriched->id);
    }

    #[Group('jobs')]
    #[Group('rdn')]
    public function test_it_skips_hotlists_outside_the_lookback_window(): void
    {
        Bus::fake();

        $org = $this->rdnOrg();

        $withinWindow = Hotlist::factory()->create([
            'org_id' => $org->id,
            'integration_id' => IntegrationEnum::RDN->value,
            'created_at' => now()->subDays(1),
        ]);

        $outsideWindow = Hotlist::factory()->create([
            'org_id' => $org->id,
            'integration_id' => IntegrationEnum::RDN->value,
            'created_at' => now()->subDays(config('services.rdn.case_info_lookback_days') + 1),
        ]);

        (new GetOrgHotlistCases(orgId: $org->id, org: $org))->handle();

        Bus::assertDispatchedTimes(GetOrgHotlistCaseInfo::class, 1);
        Bus::assertDispatched(GetOrgHotlistCaseInfo::class, fn ($job) => $job->hotlistId === $withinWindow->id);
        Bus::assertNotDispatched(GetOrgHotlistCaseInfo::class, fn ($job) => $job->hotlistId === $outsideWindow->id);
    }

    #[Group('jobs')]
    #[Group('rdn')]
    public function test_it_enriches_every_status_branch_analytics_reports_on(): void
    {
        Bus::fake();

        $org = $this->rdnOrg();

        $reportable = collect([
            HotlistStatusEnum::Open,
            HotlistStatusEnum::Hold,
            HotlistStatusEnum::Repossessed,
            HotlistStatusEnum::ClosedPositive,
            HotlistStatusEnum::Closed,
            HotlistStatusEnum::NewFromClient,
        ])->mapWithKeys(fn (HotlistStatusEnum $status): array => [
            $status->name => Hotlist::factory()->create([
                'org_id' => $org->id,
                'integration_id' => IntegrationEnum::RDN->value,
                'status' => $status->value,
            ])->id,
        ]);

        (new GetOrgHotlistCases(orgId: $org->id, org: $org))->handle();

        Bus::assertDispatchedTimes(GetOrgHotlistCaseInfo::class, $reportable->count());

        foreach ($reportable as $name => $hotlistId) {
            Bus::assertDispatched(
                GetOrgHotlistCaseInfo::class,
                fn ($job) => $job->hotlistId === $hotlistId,
                "status {$name} must stay eligible for branch enrichment",
            );
        }
    }

    #[Group('jobs')]
    #[Group('rdn')]
    public function test_it_enriches_a_hotlist_repossessed_before_its_first_enrichment_window(): void
    {
        Bus::fake();

        $org = $this->rdnOrg();

        // Assigned and recovered the same day, so it is already terminal by the time the
        // 05:00 run reaches it. Same-day recoveries are the headline branch metric, and
        // an Open-only predicate would drop them from branch attribution permanently.
        $sameDayRepo = Hotlist::factory()->create([
            'org_id' => $org->id,
            'integration_id' => IntegrationEnum::RDN->value,
            'status' => HotlistStatusEnum::Repossessed->value,
            'created_at' => now()->subHours(6),
        ]);

        (new GetOrgHotlistCases(orgId: $org->id, org: $org))->handle();

        Bus::assertDispatchedTimes(GetOrgHotlistCaseInfo::class, 1);
        Bus::assertDispatched(GetOrgHotlistCaseInfo::class, fn ($job) => $job->hotlistId === $sameDayRepo->id);
    }

    #[Group('jobs')]
    #[Group('rdn')]
    public function test_it_skips_statuses_no_analytics_surface_reports_on(): void
    {
        Bus::fake();

        $org = $this->rdnOrg();

        $invalid = Hotlist::factory()->create([
            'org_id' => $org->id,
            'integration_id' => IntegrationEnum::RDN->value,
            'status' => HotlistStatusEnum::Invalid->value,
        ]);

        (new GetOrgHotlistCases(orgId: $org->id, org: $org))->handle();

        Bus::assertNotDispatched(GetOrgHotlistCaseInfo::class, fn ($job) => $job->hotlistId === $invalid->id);
        Bus::assertNothingDispatched();
    }

    #[Group('jobs')]
    #[Group('rdn')]
    public function test_it_enriches_a_hotlist_still_sitting_at_the_accepted_metrics_source_status(): void
    {
        Bus::fake();

        $org = $this->rdnOrg();

        // HotlistStatusAggregate::scopeAccepted is new_status = Open with old_status IN
        // (Other, NewFromClient), so a row parked at Other has to already carry a
        // branch_id when it moves to Open - the aggregate freezes branch_id at the
        // transition and is never rewritten. Excluding Other zeroes the Accepted cohort,
        // which is the cohort this feature exists to fix.
        $parkedAtOther = Hotlist::factory()->create([
            'org_id' => $org->id,
            'integration_id' => IntegrationEnum::RDN->value,
            'status' => HotlistStatusEnum::Other->value,
        ]);

        (new GetOrgHotlistCases(orgId: $org->id, org: $org))->handle();

        Bus::assertDispatchedTimes(GetOrgHotlistCaseInfo::class, 1);
        Bus::assertDispatched(GetOrgHotlistCaseInfo::class, fn ($job) => $job->hotlistId === $parkedAtOther->id);

        $this->assertContains(HotlistStatusEnum::Other->value, GetOrgHotlistCases::ANALYTICS_STATUSES);
        $this->assertNotContains(HotlistStatusEnum::Invalid->value, GetOrgHotlistCases::ANALYTICS_STATUSES);
    }

    #[Group('jobs')]
    #[Group('rdn')]
    public function test_it_caps_dispatches_per_run(): void
    {
        Bus::fake();

        config(['services.rdn.case_info_max_per_run' => 2]);

        $org = $this->rdnOrg();

        Hotlist::factory()->count(5)->create([
            'org_id' => $org->id,
            'integration_id' => IntegrationEnum::RDN->value,
        ]);

        (new GetOrgHotlistCases(orgId: $org->id, org: $org))->handle();

        Bus::assertDispatchedTimes(GetOrgHotlistCaseInfo::class, 2);
    }

    #[Group('jobs')]
    #[Group('rdn')]
    public function test_it_honors_the_per_org_cap_override(): void
    {
        Bus::fake();

        config(['services.rdn.case_info_max_per_run' => 500]);

        $org = $this->rdnOrg();

        $org->meta()->create([
            'properties' => ['rdn_case_info_max_per_run' => 2],
        ]);
        $org->refresh();

        Hotlist::factory()->count(5)->create([
            'org_id' => $org->id,
            'integration_id' => IntegrationEnum::RDN->value,
        ]);

        (new GetOrgHotlistCases(orgId: $org->id, org: $org))->handle();

        Bus::assertDispatchedTimes(GetOrgHotlistCaseInfo::class, 2);
    }

    #[Group('jobs')]
    #[Group('rdn')]
    public function test_it_dispatches_for_a_hotlist_whose_branch_id_resolved_to_null(): void
    {
        Bus::fake();

        $org = $this->rdnOrg();

        // Exactly what recordUnresolvedLookup() writes after one miss: a null branch_id
        // AND a counter. A null is a transient miss, not an answer, so the hotlist stays
        // eligible until the counter reaches the ceiling.
        $resolvedNull = $this->rdnHotlist($org);
        $this->insertHotlistJson($resolvedNull->id, [
            'branch_id' => null,
            'branch_lookup_attempts' => 1,
            'case_number' => 'EXTERNAL-2',
        ]);

        $enriched = $this->rdnHotlist($org);
        $this->insertHotlistJson($enriched->id, ['branch_id' => 12345]);

        (new GetOrgHotlistCases(orgId: $org->id, org: $org))->handle();

        Bus::assertDispatchedTimes(GetOrgHotlistCaseInfo::class, 1);
        Bus::assertDispatched(GetOrgHotlistCaseInfo::class, fn ($job) => $job->hotlistId === $resolvedNull->id);
        Bus::assertNotDispatched(GetOrgHotlistCaseInfo::class, fn ($job) => $job->hotlistId === $enriched->id);
    }

    #[Group('jobs')]
    #[Group('rdn')]
    public function test_it_spends_the_run_on_the_newest_arrivals_first(): void
    {
        Bus::fake();

        config([
            'services.rdn.case_info_max_per_run' => 2,
            'services.rdn.case_info_lookback_days' => 7,
        ]);

        $org = $this->rdnOrg();

        // Attribution is forward-only, so an enrichment only counts for status transitions
        // that land after it. The oldest row has already transitioned; the newest has not.
        // FIFO here is what produced the Assignments-0 / Repossessions-N dashboard.
        $oldest = $this->rdnHotlist($org, ['created_at' => now()->subDays(5)]);
        $middle = $this->rdnHotlist($org, ['created_at' => now()->subDays(3)]);
        $newest = $this->rdnHotlist($org, ['created_at' => now()->subDay()]);

        (new GetOrgHotlistCases(orgId: $org->id, org: $org))->handle();

        Bus::assertDispatchedTimes(GetOrgHotlistCaseInfo::class, 2);
        Bus::assertDispatched(GetOrgHotlistCaseInfo::class, fn ($job) => $job->hotlistId === $newest->id);
        Bus::assertDispatched(GetOrgHotlistCaseInfo::class, fn ($job) => $job->hotlistId === $middle->id);
        Bus::assertNotDispatched(GetOrgHotlistCaseInfo::class, fn ($job) => $job->hotlistId === $oldest->id);
    }

    #[Group('jobs')]
    #[Group('rdn')]
    public function test_an_invalid_per_org_cap_falls_back_to_the_configured_default(): void
    {
        Bus::fake();

        config(['services.rdn.case_info_max_per_run' => 3]);

        $org = $this->rdnOrg();

        $org->meta()->create([
            'properties' => ['rdn_case_info_max_per_run' => 'off'],
        ]);

        Hotlist::factory()->count(5)->create([
            'org_id' => $org->id,
            'integration_id' => IntegrationEnum::RDN->value,
        ]);

        (new GetOrgHotlistCases(orgId: $org->id, org: $org->fresh()))->handle();

        Bus::assertDispatchedTimes(GetOrgHotlistCaseInfo::class, 3);
    }

    #[Group('jobs')]
    #[Group('rdn')]
    public function test_it_spreads_a_runs_fan_out_across_the_interval_instead_of_bursting(): void
    {
        Bus::fake();
        $this->freezeTime();

        // {low} is a single Horizon process shared with transactional mail, and a
        // fleet-wide run is ~76k of these jobs. Pushed undelayed they all become available
        // on the same second and every invite, receipt and end-of-shift summary queues
        // behind them; spread evenly they arrive at a rate the process absorbs.
        $org = $this->rdnOrg();

        foreach (range(1, 8) as $minutes) {
            $this->rdnHotlist($org, ['created_at' => now()->subMinutes($minutes)]);
        }

        (new GetOrgHotlistCases(orgId: $org->id, org: $org))->handle();

        $window = GetOrgHotlistCaseInfo::maxSpreadSeconds();
        $offsets = $this->dispatchedDelays();

        $this->assertCount(8, $offsets);
        $this->assertSame(8, count(array_unique($offsets)), 'two jobs shared one slot');
        $this->assertGreaterThanOrEqual(0, min($offsets));

        // Strictly inside the window, so the last job of a run is available before the
        // next run supersedes it - and inside what retryUntil() budgets for.
        $this->assertLessThan($window, max($offsets));

        // No gap larger than one even step: eight offsets summing to one window can only
        // satisfy that if they are evenly spaced, which is what "not a burst" means.
        $gaps = [];

        for ($i = 1; $i < count($offsets); $i++) {
            $gaps[] = $offsets[$i] - $offsets[$i - 1];
        }

        $gaps[] = $window - end($offsets) + $offsets[0];

        $this->assertLessThanOrEqual(intdiv($window, 8) + 1, max($gaps));
    }

    #[Group('jobs')]
    #[Group('rdn')]
    public function test_the_spread_is_deterministic_and_newest_gets_delay_zero(): void
    {
        Bus::fake();
        $this->freezeTime();

        $org = $this->rdnOrg();
        $older = $this->rdnHotlist($org, ['created_at' => now()->subMinutes(6)]);
        $newest = $this->rdnHotlist($org, ['created_at' => now()->subMinutes(5)]);

        (new GetOrgHotlistCases(orgId: $org->id, org: $org))->handle();
        $first = $this->dispatchedDelays();
        $byId = $this->delaysByHotlistId();

        // Newest-first is the attribution race: the newest row must not wait on a crc32
        // phase (~12h mean). Index 0 is delay 0; older rows take later slots.
        $this->assertSame(0, $byId[$newest->id]);
        $this->assertGreaterThan($byId[$newest->id], $byId[$older->id]);

        $this->releaseCaseInfoUniqueLocks();
        Bus::fake();

        (new GetOrgHotlistCases(orgId: $org->id, org: $org))->handle();

        // Child offsets are deterministic (no random()); same org, same slots every run.
        $this->assertSame($first, $this->dispatchedDelays());

        // Org interleaving lives on the dispatcher delay, not on child offsets.
        $dispatcherDelays = [];

        foreach (range(1, 8) as $ignored) {
            $dispatcherDelays[] = GetOrgHotlistCases::dispatchDelaySeconds($this->rdnOrg()->id);
        }

        $this->assertGreaterThan(1, count(array_unique($dispatcherDelays)));
    }

    #[Group('jobs')]
    #[Group('rdn')]
    public function test_the_wider_window_carries_only_the_unresolved_tail_not_more_arrivals(): void
    {
        Bus::fake();

        // Widening the window is what buys the retry attempts, and it is affordable only
        // because candidates already exclude anything answered: a row that enriched, and a
        // row that reached the ceiling, are both gone. So the extra days hold the
        // unresolved tail, not another day's arrivals.
        $org = $this->rdnOrg();
        $days = GetOrgHotlistCaseInfo::lookbackDays();

        $arrival = $this->rdnHotlist($org, ['created_at' => now()->subHour()]);

        $enriched = $this->rdnHotlist($org, ['created_at' => now()->subDays($days)->addHour()]);
        $this->insertHotlistJson($enriched->id, ['branch_id' => 4242]);

        $sealed = $this->rdnHotlist($org, ['created_at' => now()->subDays($days)->addHours(2)]);
        $this->insertHotlistJson($sealed->id, [
            'branch_id' => null,
            'branch_lookup_attempts' => GetOrgHotlistCaseInfo::maxLookupAttempts(),
        ]);

        $tail = $this->rdnHotlist($org, ['created_at' => now()->subDays($days)->addHours(3)]);
        $this->insertHotlistJson($tail->id, ['branch_id' => null, 'branch_lookup_attempts' => 1]);

        (new GetOrgHotlistCases(orgId: $org->id, org: $org))->handle();

        Bus::assertDispatchedTimes(GetOrgHotlistCaseInfo::class, 2);
        Bus::assertDispatched(GetOrgHotlistCaseInfo::class, fn ($job) => $job->hotlistId === $arrival->id);
        Bus::assertDispatched(GetOrgHotlistCaseInfo::class, fn ($job) => $job->hotlistId === $tail->id);
        Bus::assertNotDispatched(GetOrgHotlistCaseInfo::class, fn ($job) => $job->hotlistId === $enriched->id);
        Bus::assertNotDispatched(GetOrgHotlistCaseInfo::class, fn ($job) => $job->hotlistId === $sealed->id);
    }

    #[Group('jobs')]
    #[Group('rdn')]
    public function test_it_stops_selecting_a_hotlist_that_has_exhausted_its_lookup_attempts(): void
    {
        Bus::fake();

        $org = $this->rdnOrg();

        $exhausted = $this->rdnHotlist($org);
        $this->insertHotlistJson($exhausted->id, [
            'branch_id' => null,
            'branch_lookup_attempts' => GetOrgHotlistCaseInfo::maxLookupAttempts(),
        ]);

        (new GetOrgHotlistCases(orgId: $org->id, org: $org))->handle();

        Bus::assertNothingDispatched();
    }

    #[Group('jobs')]
    #[Group('rdn')]
    public function test_it_keeps_selecting_a_hotlist_still_below_the_attempt_ceiling(): void
    {
        Bus::fake();

        $org = $this->rdnOrg();

        // A genuinely transient miss - the Branch row had not synced yet - must keep its
        // remaining retries under SHIPPED config, which is the whole point of the 4-day
        // window. Sealing on the first null would be the old bug inverted.
        $retryable = $this->rdnHotlist($org);
        $this->insertHotlistJson($retryable->id, [
            'branch_id' => null,
            'branch_lookup_attempts' => GetOrgHotlistCaseInfo::maxLookupAttempts() - 1,
        ]);

        (new GetOrgHotlistCases(orgId: $org->id, org: $org))->handle();

        Bus::assertDispatchedTimes(GetOrgHotlistCaseInfo::class, 1);
        Bus::assertDispatched(GetOrgHotlistCaseInfo::class, fn ($job) => $job->hotlistId === $retryable->id);
    }

    #[Group('jobs')]
    #[Group('rdn')]
    public function test_an_exhausted_row_yields_its_place_in_the_run_budget_to_a_resolvable_one(): void
    {
        Bus::fake();

        config(['services.rdn.case_info_max_per_run' => 1]);

        $org = $this->rdnOrg();

        // Newest-first puts this row at the front of every run. Without a give-up rule it
        // would consume the whole per-run budget every night until it aged out, and the
        // resolvable row behind it would never be reached.
        $unresolvable = $this->rdnHotlist($org, ['created_at' => now()->subHour()]);
        $this->insertHotlistJson($unresolvable->id, [
            'branch_id' => null,
            'branch_lookup_attempts' => GetOrgHotlistCaseInfo::maxLookupAttempts(),
        ]);

        $resolvable = $this->rdnHotlist($org, ['created_at' => now()->subDay()]);

        (new GetOrgHotlistCases(orgId: $org->id, org: $org))->handle();

        Bus::assertDispatchedTimes(GetOrgHotlistCaseInfo::class, 1);
        Bus::assertDispatched(GetOrgHotlistCaseInfo::class, fn ($job) => $job->hotlistId === $resolvable->id);
        Bus::assertNotDispatched(GetOrgHotlistCaseInfo::class, fn ($job) => $job->hotlistId === $unresolvable->id);
    }

    #[Group('jobs')]
    #[Group('rdn')]
    public function test_the_attempt_ceiling_is_configurable_from_the_dispatcher_side(): void
    {
        Bus::fake();

        config([
            'services.rdn.case_info_lookback_days' => 6,
            'services.rdn.case_info_max_lookup_attempts' => 5,
        ]);

        $org = $this->rdnOrg();

        $hotlist = $this->rdnHotlist($org);
        $this->insertHotlistJson($hotlist->id, ['branch_id' => null, 'branch_lookup_attempts' => 3]);

        (new GetOrgHotlistCases(orgId: $org->id, org: $org))->handle();

        Bus::assertDispatchedTimes(GetOrgHotlistCaseInfo::class, 1);

        config(['services.rdn.case_info_max_lookup_attempts' => 2]);

        Bus::fake();

        (new GetOrgHotlistCases(orgId: $org->id, org: $org))->handle();

        Bus::assertNothingDispatched();
    }

    #[Group('jobs')]
    #[Group('rdn')]
    public function test_a_garbage_attempt_counter_does_not_break_candidate_selection(): void
    {
        Bus::fake();

        $org = $this->rdnOrg();

        // hotlist_json.json is externally owned, so the counter must be read defensively:
        // a non-numeric value has to fall back to "not exhausted", not raise a cast error.
        $hotlist = $this->rdnHotlist($org);
        $this->insertHotlistJson($hotlist->id, ['branch_id' => null, 'branch_lookup_attempts' => 'lots']);

        (new GetOrgHotlistCases(orgId: $org->id, org: $org))->handle();

        Bus::assertDispatchedTimes(GetOrgHotlistCaseInfo::class, 1);
    }

    #[Group('jobs')]
    #[Group('rdn')]
    public function test_it_does_not_dispatch_for_a_non_rdn_integration(): void
    {
        Bus::fake();

        $org = $this->rdnOrg();

        // remote_order_id is unique only per (org_id, remote_order_id, integration_id) -
        // the id spaces overlap - so asking RDN about another integration's order id can
        // come back with a real case for a DIFFERENT order and write its branch here. RDN
        // is also the only integration that can populate branch_id at all.
        $rdnOrder = $this->rdnHotlist($org, ['remote_order_id' => '778899']);

        $foreignOrder = Hotlist::factory()->create([
            'org_id' => $org->id,
            'integration_id' => IntegrationEnum::iRepo->value,
            'remote_order_id' => '778899',
        ]);

        (new GetOrgHotlistCases(orgId: $org->id, org: $org))->handle();

        Bus::assertDispatchedTimes(GetOrgHotlistCaseInfo::class, 1);
        Bus::assertDispatched(GetOrgHotlistCaseInfo::class, fn ($job) => $job->hotlistId === $rdnOrder->id);
        Bus::assertNotDispatched(GetOrgHotlistCaseInfo::class, fn ($job) => $job->hotlistId === $foreignOrder->id);
    }

    #[Group('jobs')]
    #[Group('rdn')]
    public function test_it_does_not_dispatch_for_an_org_without_rdn_credentials(): void
    {
        Bus::fake();

        // Every call this org could make is a guaranteed miss that still spends a slot,
        // burns the give-up counter and INSERTs a synthetic hotlist_json row.
        $org = Org::factory()->create(['rdn_id' => null, 'rdn_api_key' => null]);

        $this->rdnHotlist($org);

        (new GetOrgHotlistCases(orgId: $org->id, org: $org))->handle();

        Bus::assertNothingDispatched();
    }

    #[Group('jobs')]
    #[Group('rdn')]
    public function test_it_does_not_dispatch_for_an_org_whose_soap_client_cannot_be_built(): void
    {
        Bus::fake();

        // rdn_id alone satisfies hasRdnAccount(), but RDN::__construct builds its client
        // from rdn_api_key: with none, call() answers EMPTY_RESULT every time and each
        // row is sealed on a lookup that never left the process.
        $org = Org::factory()->create([
            'rdn_id' => (string) fake()->numberBetween(1000, 9999),
            'rdn_api_key' => null,
        ]);

        $this->rdnHotlist($org);

        (new GetOrgHotlistCases(orgId: $org->id, org: $org))->handle();

        Bus::assertNothingDispatched();
    }

    #[Group('jobs')]
    #[Group('rdn')]
    public function test_it_dispatches_for_an_org_with_an_api_key_but_no_rdn_id(): void
    {
        Bus::fake();

        // The two columns are independent populations. A key without an rdn_id can call
        // getRdnCaseInfo perfectly well, so excluding it drops the feature silently.
        $org = Org::factory()->create([
            'rdn_id' => null,
            'rdn_api_key' => fake()->uuid(),
        ]);

        $hotlist = $this->rdnHotlist($org);

        (new GetOrgHotlistCases(orgId: $org->id, org: $org))->handle();

        Bus::assertDispatchedTimes(GetOrgHotlistCaseInfo::class, 1);
        Bus::assertDispatched(GetOrgHotlistCaseInfo::class, fn ($job) => $job->hotlistId === $hotlist->id);
    }

    #[Group('jobs')]
    #[Group('rdn')]
    public function test_it_does_not_dispatch_for_a_hotlist_without_a_remote_order_id(): void
    {
        Bus::fake();

        $org = $this->rdnOrg();

        // remote_order_id is nullable and is the sole argument of getRdnCaseInfo.
        $withoutRemoteId = $this->rdnHotlist($org, ['remote_order_id' => null]);
        $withRemoteId = $this->rdnHotlist($org, ['remote_order_id' => '123456']);

        (new GetOrgHotlistCases(orgId: $org->id, org: $org))->handle();

        Bus::assertDispatchedTimes(GetOrgHotlistCaseInfo::class, 1);
        Bus::assertDispatched(GetOrgHotlistCaseInfo::class, fn ($job) => $job->hotlistId === $withRemoteId->id);
        Bus::assertNotDispatched(GetOrgHotlistCaseInfo::class, fn ($job) => $job->hotlistId === $withoutRemoteId->id);
    }

    #[Group('jobs')]
    #[Group('rdn')]
    public function test_it_selects_inside_the_lookback_window_and_not_past_it(): void
    {
        Bus::fake();

        $org = $this->rdnOrg();

        // A row still inside the window is reachable by every remaining run - that is the
        // retry budget the ceiling is derived from. Anything past it is not: forward-only
        // enrichment has already lost its first status transition, so the budget belongs
        // to newer arrivals.
        $days = GetOrgHotlistCaseInfo::lookbackDays();
        $missedYesterdaysRun = $this->rdnHotlist($org, ['created_at' => now()->subDays($days)->addHours(6)]);
        $tooOld = $this->rdnHotlist($org, ['created_at' => now()->subDays($days)->subHours(6)]);

        (new GetOrgHotlistCases(orgId: $org->id, org: $org))->handle();

        Bus::assertDispatchedTimes(GetOrgHotlistCaseInfo::class, 1);
        Bus::assertDispatched(GetOrgHotlistCaseInfo::class, fn ($job) => $job->hotlistId === $missedYesterdaysRun->id);
        Bus::assertNotDispatched(GetOrgHotlistCaseInfo::class, fn ($job) => $job->hotlistId === $tooOld->id);
    }

    #[Group('jobs')]
    #[Group('rdn')]
    public function test_the_seal_denies_a_selection_before_the_row_ages_out(): void
    {
        Bus::fake();

        // Shipped config, no overrides. The give-up rule only exists if the window can
        // actually deliver the ceiling's worth of misses AND still have a selection left
        // to deny - a ceiling the window cannot reach is a rule that never fires.
        $this->assertLessThan(
            GetOrgHotlistCaseInfo::selectionsPerWindow(),
            GetOrgHotlistCaseInfo::maxLookupAttempts(),
        );

        $org = $this->rdnOrg();

        $exhausted = $this->rdnHotlist($org, ['created_at' => now()->subHour()]);
        $this->insertHotlistJson($exhausted->id, [
            'branch_id' => null,
            'branch_lookup_attempts' => GetOrgHotlistCaseInfo::maxLookupAttempts(),
        ]);

        $untried = $this->rdnHotlist($org, ['created_at' => now()->subDay()]);

        (new GetOrgHotlistCases(orgId: $org->id, org: $org))->handle();

        Bus::assertDispatchedTimes(GetOrgHotlistCaseInfo::class, 1);
        Bus::assertDispatched(GetOrgHotlistCaseInfo::class, fn ($job) => $job->hotlistId === $untried->id);
        Bus::assertNotDispatched(GetOrgHotlistCaseInfo::class, fn ($job) => $job->hotlistId === $exhausted->id);
    }

    #[Group('jobs')]
    #[Group('rdn')]
    public function test_a_zero_lookback_does_not_silently_select_nothing(): void
    {
        Bus::fake();

        config(['services.rdn.case_info_lookback_days' => 0]);

        $org = $this->rdnOrg();

        $hotlist = Hotlist::factory()->create([
            'org_id' => $org->id,
            'integration_id' => IntegrationEnum::RDN->value,
            'created_at' => now()->subHours(2),
        ]);

        (new GetOrgHotlistCases(orgId: $org->id, org: $org))->handle();

        Bus::assertDispatchedTimes(GetOrgHotlistCaseInfo::class, 1);
        Bus::assertDispatched(GetOrgHotlistCaseInfo::class, fn ($job) => $job->hotlistId === $hotlist->id);
    }

    #[Group('jobs')]
    #[Group('rdn')]
    public function test_the_shipped_default_leaves_case_info_enrichment_on(): void
    {
        // No env var set is the production state on the day this ships. If the default
        // ever flips, the whole feature is dark and every other test here still passes.
        $this->assertTrue(config('services.rdn.case_info_enabled'));
        $this->assertTrue(GetOrgHotlistCaseInfo::isEnabled());
    }

    #[Group('jobs')]
    #[Group('rdn')]
    public function test_the_kill_switch_stops_the_run_before_it_queries_anything(): void
    {
        Bus::fake();

        config(['services.rdn.case_info_enabled' => false]);

        $org = $this->rdnOrg();
        $this->rdnHotlist($org);

        DB::connection('zod')->flushQueryLog();
        DB::connection('zod')->enableQueryLog();

        (new GetOrgHotlistCases(orgId: $org->id, org: $org))->handle();

        $queries = DB::connection('zod')->getQueryLog();

        DB::connection('zod')->disableQueryLog();

        Bus::assertNothingDispatched();
        $this->assertSame([], $queries, 'A disabled run must not reach the candidate query.');
    }

    #[Group('jobs')]
    #[Group('rdn')]
    public function test_the_env_flag_reads_false_and_zero_as_off(): void
    {
        // Re-evaluates the shipped config line rather than a hand-written cast: an operator
        // sets an env value, so the parsing of that value is the thing under test. Without
        // the (bool) the string "false" reaches config non-empty and reads as ON - the
        // switch would look flipped and change nothing.
        $read = function (?string $value): mixed {
            $previousEnv = $_ENV['RDN_CASE_INFO_ENABLED'] ?? null;
            $previousServer = $_SERVER['RDN_CASE_INFO_ENABLED'] ?? null;

            try {
                if ($value === null) {
                    unset($_ENV['RDN_CASE_INFO_ENABLED'], $_SERVER['RDN_CASE_INFO_ENABLED']);
                } else {
                    $_ENV['RDN_CASE_INFO_ENABLED'] = $value;
                    $_SERVER['RDN_CASE_INFO_ENABLED'] = $value;
                }

                return (require base_path('config/services.php'))['rdn']['case_info_enabled'];
            } finally {
                unset($_ENV['RDN_CASE_INFO_ENABLED'], $_SERVER['RDN_CASE_INFO_ENABLED']);

                if ($previousEnv !== null) {
                    $_ENV['RDN_CASE_INFO_ENABLED'] = $previousEnv;
                }

                if ($previousServer !== null) {
                    $_SERVER['RDN_CASE_INFO_ENABLED'] = $previousServer;
                }
            }
        };

        $this->assertFalse($read('false'));
        $this->assertFalse($read('0'));
        $this->assertTrue($read('true'));
        $this->assertTrue($read('1'));
        $this->assertTrue($read(null), 'An unset env var is the production state on rollout day.');
    }

    #[Group('jobs')]
    #[Group('rdn')]
    public function test_a_plain_manual_run_does_not_imply_the_override(): void
    {
        Bus::fake();

        // Passing an org id is a routine single-org run, not a canary. An operator who
        // turned the switch off because RDN is in trouble must not have it bypassed by
        // whoever runs app:rdn next.
        $org = $this->rdnOrg();

        Artisan::call(GenerateRdnCache::class, ['org' => $org->id]);

        $this->assertFalse($this->batchedDispatchers()[0]->forced);
    }

    #[Group('jobs')]
    #[Group('rdn')]
    public function test_the_force_option_reaches_the_dispatcher(): void
    {
        Bus::fake();

        $org = $this->rdnOrg();

        Artisan::call(GenerateRdnCache::class, ['org' => $org->id, '--force' => true]);

        $this->assertTrue($this->batchedDispatchers()[0]->forced);
    }

    #[Group('jobs')]
    #[Group('rdn')]
    public function test_a_scheduled_fleet_run_is_never_forced(): void
    {
        Bus::fake();

        // The fleet path is the one the scheduler calls, with no options at all.
        $this->rdnOrg();

        Artisan::call(GenerateRdnCache::class);

        $this->assertFalse($this->batchedDispatchers()[0]->forced);
    }

    #[Group('jobs')]
    #[Group('rdn')]
    public function test_the_kill_switch_stops_an_unforced_run_from_either_entry_point(): void
    {
        Bus::fake();

        config(['services.rdn.case_info_enabled' => false]);

        $org = $this->rdnOrg();
        $this->rdnHotlist($org);

        Artisan::call(GenerateRdnCache::class);
        Artisan::call(GenerateRdnCache::class, ['org' => $org->id]);

        $dispatchers = $this->batchedDispatchers();

        foreach ($dispatchers as $dispatcher) {
            $dispatcher->handle();
        }

        $this->assertCount(2, $dispatchers, 'Both the scheduled and the manual entry point must have queued a run.');
        Bus::assertNotDispatched(GetOrgHotlistCaseInfo::class);
    }

    #[Group('jobs')]
    #[Group('rdn')]
    public function test_a_forced_run_dispatches_while_the_kill_switch_is_off(): void
    {
        Bus::fake();

        config(['services.rdn.case_info_enabled' => false]);

        $org = $this->rdnOrg();
        $hotlist = $this->rdnHotlist($org);

        Artisan::call(GenerateRdnCache::class, ['org' => $org->id, '--force' => true]);

        $dispatchers = $this->batchedDispatchers();
        $this->assertCount(1, $dispatchers);

        $dispatchers[0]->handle();

        // The child reads the same flag, so the canary enriches nothing unless the
        // override travels with the dispatch.
        Bus::assertDispatchedTimes(GetOrgHotlistCaseInfo::class, 1);
        Bus::assertDispatched(
            GetOrgHotlistCaseInfo::class,
            fn (GetOrgHotlistCaseInfo $job): bool => $job->hotlistId === $hotlist->id && $job->forced === true,
        );
    }

    #[Group('jobs')]
    #[Group('rdn')]
    public function test_the_override_survives_queue_serialization(): void
    {
        Bus::fake();

        config(['services.rdn.case_info_enabled' => false]);

        $org = $this->rdnOrg();
        $this->rdnHotlist($org);

        // Neither job runs in-process: the dispatcher is batched and its children are
        // queued behind a spread, so an override that is not on the payload is gone by
        // the time either is picked up.
        $dispatcher = unserialize(serialize(new GetOrgHotlistCases(orgId: $org->id, org: $org, forced: true)));

        $this->assertTrue($dispatcher->forced);

        $dispatcher->handle();

        $child = collect(Bus::dispatched(GetOrgHotlistCaseInfo::class))->sole();

        $this->assertTrue(unserialize(serialize($child))->forced);
    }

    #[Group('jobs')]
    #[Group('rdn')]
    public function test_a_forced_run_does_not_bypass_the_org_gate(): void
    {
        Bus::fake();
        Log::spy();

        config(['services.rdn.case_info_enabled' => false]);

        // Without an api key RDN::__construct cannot build a client, so every row selected
        // is a guaranteed miss that still burns the give-up counter.
        $noKey = Org::factory()->create(['rdn_id' => '4321', 'rdn_api_key' => null]);
        $this->rdnHotlist($noKey);

        (new GetOrgHotlistCases(orgId: $noKey->id, org: $noKey, forced: true))->handle();

        Bus::assertNotDispatched(GetOrgHotlistCaseInfo::class);
        Log::shouldNotHaveReceived('info');
    }

    #[Group('jobs')]
    #[Group('rdn')]
    public function test_a_forced_run_still_honors_the_per_org_cap_and_the_seal(): void
    {
        Bus::fake();

        config(['services.rdn.case_info_enabled' => false]);

        $org = $this->rdnOrg();
        $org->meta()->create(['properties' => ['rdn_case_info_max_per_run' => 1]]);
        $org->load('meta');

        // The newest row of the three, and the only one the seal excludes.
        $sealed = $this->rdnHotlist($org, ['created_at' => now()]);
        $this->insertHotlistJson($sealed->id, [
            'branch_id' => null,
            'branch_lookup_attempts' => GetOrgHotlistCaseInfo::maxLookupAttempts(),
        ]);

        $newestEligible = $this->rdnHotlist($org, ['created_at' => now()->subHour()]);
        $this->rdnHotlist($org, ['created_at' => now()->subHours(2)]);

        (new GetOrgHotlistCases(orgId: $org->id, org: $org, forced: true))->handle();

        Bus::assertDispatchedTimes(GetOrgHotlistCaseInfo::class, 1);
        Bus::assertDispatched(
            GetOrgHotlistCaseInfo::class,
            fn (GetOrgHotlistCaseInfo $job): bool => $job->hotlistId === $newestEligible->id,
        );
    }

    #[Group('jobs')]
    #[Group('rdn')]
    public function test_a_forced_run_still_honors_the_lookback_window(): void
    {
        Bus::fake();

        config(['services.rdn.case_info_enabled' => false]);

        // Shipped cap, so the window is the only thing that can exclude a row here.
        $org = $this->rdnOrg();

        $days = GetOrgHotlistCaseInfo::lookbackDays();
        $inWindow = $this->rdnHotlist($org, ['created_at' => now()->subDays($days)->addHours(6)]);
        $tooOld = $this->rdnHotlist($org, ['created_at' => now()->subDays($days)->subHours(6)]);

        (new GetOrgHotlistCases(orgId: $org->id, org: $org, forced: true))->handle();

        Bus::assertDispatchedTimes(GetOrgHotlistCaseInfo::class, 1);
        Bus::assertDispatched(
            GetOrgHotlistCaseInfo::class,
            fn (GetOrgHotlistCaseInfo $job): bool => $job->hotlistId === $inWindow->id,
        );
        Bus::assertNotDispatched(
            GetOrgHotlistCaseInfo::class,
            fn (GetOrgHotlistCaseInfo $job): bool => $job->hotlistId === $tooOld->id,
        );
    }

    #[Group('jobs')]
    #[Group('rdn')]
    public function test_the_run_summary_records_a_forced_run(): void
    {
        Bus::fake();
        Log::spy();

        config(['services.rdn.case_info_enabled' => false]);

        $org = $this->rdnOrg();
        $this->rdnHotlist($org);

        (new GetOrgHotlistCases(orgId: $org->id, org: $org, forced: true))->handle();

        // A canary run and a scheduled one are otherwise indistinguishable in the log.
        Log::shouldHaveReceived('info')
            ->once()
            ->withArgs(fn (string $message, array $context): bool => $message === 'rdn.case_info.run'
                && $context['forced'] === true);
    }

    #[Group('jobs')]
    #[Group('rdn')]
    public function test_it_logs_one_run_summary_per_org(): void
    {
        Bus::fake();
        Log::spy();

        $org = $this->rdnOrg();

        $this->rdnHotlist($org);
        $this->rdnHotlist($org);

        (new GetOrgHotlistCases(orgId: $org->id, org: $org))->handle();

        Log::shouldHaveReceived('info')
            ->once()
            ->withArgs(fn (string $message, array $context): bool => $message === 'rdn.case_info.run'
                && $context['org_id'] === $org->id
                && $context['dispatched'] === 2
                && $context['cap'] === $org->rdnCaseInfoMaxPerRun()
                && $context['capped'] === false
                && $context['forced'] === false
                && $context['lookback_days'] === GetOrgHotlistCaseInfo::lookbackDays()
                && $context['max_lookup_attempts'] === GetOrgHotlistCaseInfo::maxLookupAttempts()
                // Exact, not a subset: the summary is fleet-wide production output, and an
                // extra field is how a plate, VIN or order id gets into it by accident.
                && $context['candidates'] === 2
                && $context['failed'] === 0
                && $context['suppressed'] === 0
                && array_keys($context) === [
                    'org_id',
                    'dispatched',
                    'candidates',
                    'failed',
                    'suppressed',
                    'cap',
                    'capped',
                    'forced',
                    'lookback_days',
                    'max_lookup_attempts',
                ]);
    }

    #[Group('jobs')]
    #[Group('rdn')]
    public function test_the_run_summary_reports_zero_rather_than_going_silent(): void
    {
        Bus::fake();
        Log::spy();

        // An RDN org with nothing to enrich. "Selected nothing" is the first thing to rule
        // out on a pipeline that has never run, so it cannot look the same as not running.
        $org = $this->rdnOrg();

        (new GetOrgHotlistCases(orgId: $org->id, org: $org))->handle();

        Bus::assertNothingDispatched();

        Log::shouldHaveReceived('info')
            ->once()
            ->withArgs(fn (string $message, array $context): bool => $message === 'rdn.case_info.run'
                && $context['dispatched'] === 0
                && $context['candidates'] === 0
                && $context['failed'] === 0
                && $context['suppressed'] === 0);
    }

    #[Group('jobs')]
    #[Group('rdn')]
    public function test_the_run_summary_flags_a_run_the_per_org_cap_truncated(): void
    {
        Bus::fake();
        Log::spy();

        $org = $this->rdnOrg();
        $org->meta()->create(['properties' => ['rdn_case_info_max_per_run' => 2]]);
        $org->load('meta');

        $this->rdnHotlist($org);
        $this->rdnHotlist($org);
        $this->rdnHotlist($org);

        (new GetOrgHotlistCases(orgId: $org->id, org: $org))->handle();

        Bus::assertDispatchedTimes(GetOrgHotlistCaseInfo::class, 2);

        Log::shouldHaveReceived('info')
            ->once()
            ->withArgs(fn (string $message, array $context): bool => $message === 'rdn.case_info.run'
                && $context['dispatched'] === 2
                && $context['cap'] === 2
                && $context['capped'] === true);
    }

    #[Group('jobs')]
    #[Group('rdn')]
    public function test_a_disabled_run_emits_no_summary_and_a_gated_org_emits_none_either(): void
    {
        Bus::fake();
        Log::spy();

        config(['services.rdn.case_info_enabled' => false]);

        $org = $this->rdnOrg();
        $this->rdnHotlist($org);

        (new GetOrgHotlistCases(orgId: $org->id, org: $org))->handle();

        config(['services.rdn.case_info_enabled' => true]);

        $noKey = Org::factory()->create(['rdn_id' => '4321', 'rdn_api_key' => null]);
        $this->rdnHotlist($noKey);

        (new GetOrgHotlistCases(orgId: $noKey->id, org: $noKey))->handle();

        Log::shouldNotHaveReceived('info');
    }

    #[Group('jobs')]
    #[Group('rdn')]
    public function test_a_failing_counter_does_not_re_dispatch_the_whole_fan_out(): void
    {
        Bus::fake();

        // A throw out of the summary would fail this job, and its retry would re-run the
        // candidate query and dispatch the run's fan-out a second time.
        config(['services.datadog_forwarding' => true]);

        $this->mock(DogStatsd::class, function (MockInterface $mock) {
            $mock->shouldReceive('increment')->andThrow(new \RuntimeException('statsd is down'));
        });

        $org = $this->rdnOrg();
        $this->rdnHotlist($org);

        (new GetOrgHotlistCases(orgId: $org->id, org: $org))->handle();

        Bus::assertDispatchedTimes(GetOrgHotlistCaseInfo::class, 1);
    }

    #[Group('jobs')]
    #[Group('rdn')]
    public function test_the_run_summary_costs_no_extra_query(): void
    {
        Bus::fake();

        $org = $this->rdnOrg();
        $org->meta()->create(['properties' => ['rdn_case_info_max_per_run' => 5]]);
        $org->load('meta');

        $this->rdnHotlist($org);

        DB::connection('zod')->flushQueryLog();
        DB::connection('platform')->flushQueryLog();
        DB::connection('zod')->enableQueryLog();
        DB::connection('platform')->enableQueryLog();

        (new GetOrgHotlistCases(orgId: $org->id, org: $org))->handle();

        $queries = array_merge(
            DB::connection('zod')->getQueryLog(),
            DB::connection('platform')->getQueryLog(),
        );

        DB::connection('zod')->disableQueryLog();
        DB::connection('platform')->disableQueryLog();

        // The candidate select and nothing else: cap comes from the already-loaded meta
        // relation and every other field in the summary is a config read.
        $this->assertLessThanOrEqual(2, count($queries), 'Candidate select is at most two queries (retry + fresh); summary must not add more: '.json_encode(
            array_column($queries, 'query')
        ));
    }

    #[Group('jobs')]
    #[Group('rdn')]
    public function test_the_dispatcher_cannot_outlive_its_own_queue_reservation(): void
    {
        // Production resolves the redis connection; phpunit forces sync, which never
        // expires a reservation. The pair that has to be coherent is this job's runtime
        // bound against the connection the fleet actually runs on.
        config(['queue.default' => 'redis']);

        $org = $this->rdnOrg();

        $job = new GetOrgHotlistCases(orgId: $org->id, org: $org);

        $reservation = (int) config('queue.connections.redis.retry_after');

        $this->assertGreaterThan(0, $reservation);

        // The defect this pins: the dispatcher declared no $timeout at all and inherited
        // Horizon supervisor-2's 3600, far above retry_after=90 - the connection released
        // the run back to the ready list while a worker was still executing it, and a
        // second worker fanned out the whole run a second time. Reuses the child's
        // derivation rather than introducing a second independent number.
        $this->assertGreaterThan(0, $job->timeout);
        $this->assertLessThan($reservation, $job->timeout);
        $this->assertSame(GetOrgHotlistCaseInfo::timeoutSeconds(), $job->timeout);
    }

    #[Group('jobs')]
    #[Group('rdn')]
    public function test_the_dispatchers_runtime_bound_follows_a_reconfigured_reservation_window(): void
    {
        config(['queue.default' => 'redis']);

        $org = $this->rdnOrg();

        // retry_after is shared by every queued job in the app, so this ticket must not
        // move it - but a future one can, and the dispatcher's bound has to track it
        // rather than becoming the second half of an incoherent pair again.
        foreach ([40, 90, 120, 3600] as $reservation) {
            config(['queue.connections.redis.retry_after' => $reservation]);

            $job = new GetOrgHotlistCases(orgId: $org->id, org: $org);

            $this->assertSame($reservation, GetOrgHotlistCaseInfo::reservationSeconds());
            $this->assertLessThan($reservation, $job->timeout, "retry_after {$reservation}");
        }
    }

    #[Group('jobs')]
    #[Group('rdn')]
    public function test_force_without_an_org_is_rejected(): void
    {
        Bus::fake();

        $this->rdnOrg();

        $exit = Artisan::call(GenerateRdnCache::class, ['--force' => true]);

        $this->assertSame(1, $exit);
        $this->assertCount(0, $this->batchedDispatchers());
        $this->assertStringContainsString('requires an org id', Artisan::output());
    }

    #[Group('jobs')]
    #[Group('rdn')]
    public function test_a_second_run_does_not_duplicate_in_flight_case_info_jobs(): void
    {
        Bus::fake();

        $org = $this->rdnOrg();
        $hotlist = $this->rdnHotlist($org, ['created_at' => now()->subHour()]);

        (new GetOrgHotlistCases(orgId: $org->id, org: $org))->handle();
        $this->assertCount(1, Bus::dispatched(GetOrgHotlistCaseInfo::class));

        // Re-run without releasing the unique lock: the canary path that used to burn
        // attempts 0→2 for one answer.
        (new GetOrgHotlistCases(orgId: $org->id, org: $org))->handle();

        $this->assertCount(1, Bus::dispatched(GetOrgHotlistCaseInfo::class));
        $this->assertSame($hotlist->id, Bus::dispatched(GetOrgHotlistCaseInfo::class)[0]->hotlistId);
    }

    #[Group('jobs')]
    #[Group('rdn')]
    public function test_the_cap_reserves_slots_for_prior_misses(): void
    {
        Bus::fake();

        $org = $this->rdnOrg();
        $org->meta()->create(['properties' => ['rdn_case_info_max_per_run' => 2]]);
        $org->load('meta');

        // Two prior misses (would be starved under pure newest-first once fresher rows fill the cap)
        $missOld = $this->rdnHotlist($org, ['created_at' => now()->subDays(2)]);
        $this->insertHotlistJson($missOld->id, ['branch_id' => null, 'branch_lookup_attempts' => 1]);
        $missNewer = $this->rdnHotlist($org, ['created_at' => now()->subDay()]);
        $this->insertHotlistJson($missNewer->id, ['branch_id' => null, 'branch_lookup_attempts' => 1]);

        // Two fresh arrivals that would otherwise consume the whole cap
        $freshOlder = $this->rdnHotlist($org, ['created_at' => now()->subHours(3)]);
        $freshNewest = $this->rdnHotlist($org, ['created_at' => now()->subHour()]);

        (new GetOrgHotlistCases(orgId: $org->id, org: $org))->handle();

        $ids = collect(Bus::dispatched(GetOrgHotlistCaseInfo::class))
            ->map(fn (GetOrgHotlistCaseInfo $job): int => $job->hotlistId)
            ->all();

        $this->assertCount(2, $ids);
        $this->assertContains($freshNewest->id, $ids, 'fresh budget still prefers the newest arrival');
        $this->assertTrue(
            in_array($missOld->id, $ids, true) || in_array($missNewer->id, $ids, true),
            'retry budget must pick at least one prior miss',
        );
        $this->assertNotContains($freshOlder->id, $ids);
    }

    #[Group('jobs')]
    #[Group('rdn')]
    public function test_a_second_run_summary_counts_suppressed_not_as_dispatched(): void
    {
        Bus::fake();
        Log::spy();

        $org = $this->rdnOrg();
        $this->rdnHotlist($org, ['created_at' => now()->subHour()]);

        (new GetOrgHotlistCases(orgId: $org->id, org: $org))->handle();
        (new GetOrgHotlistCases(orgId: $org->id, org: $org))->handle();

        // Second handle selected the same candidate but UniqueUntilProcessing suppressed the push.
        Log::shouldHaveReceived('info')
            ->withArgs(fn (string $message, array $context): bool => $message === 'rdn.case_info.run'
                && $context['dispatched'] === 0
                && $context['candidates'] === 1
                && $context['suppressed'] === 1
                && $context['failed'] === 0);
    }

    #[Group('jobs')]
    #[Group('rdn')]
    public function test_a_dispatch_throw_still_emits_summary_with_failed_count(): void
    {
        Log::spy();
        Cache::flush();

        $org = $this->rdnOrg();
        $ok = $this->rdnHotlist($org, ['created_at' => now()->subHours(2)]);
        $bad = $this->rdnHotlist($org, ['created_at' => now()->subHour()]);

        $failId = $bad->id;
        $pushed = [];

        $fake = \Mockery::mock(Dispatcher::class);
        $fake->shouldReceive('dispatch')->andReturnUsing(
            function (mixed $job) use ($failId, &$pushed): mixed {
                if ($job instanceof GetOrgHotlistCaseInfo && $job->hotlistId === $failId) {
                    throw new \RuntimeException('simulated dispatch failure');
                }
                $pushed[] = $job;

                return null;
            }
        );
        $this->app->instance(Dispatcher::class, $fake);

        (new GetOrgHotlistCases(orgId: $org->id, org: $org))->handle();

        $this->assertCount(1, $pushed);
        $this->assertSame($ok->id, $pushed[0]->hotlistId);

        Log::shouldHaveReceived('warning')
            ->once()
            ->withArgs(fn (string $message, array $context): bool => $message === 'rdn.case_info.dispatch_failed'
                && $context['hotlist_id'] === $failId
                && $context['org_id'] === $org->id);

        Log::shouldHaveReceived('info')
            ->once()
            ->withArgs(fn (string $message, array $context): bool => $message === 'rdn.case_info.run'
                && $context['dispatched'] === 1
                && $context['candidates'] === 2
                && $context['failed'] === 1
                && $context['suppressed'] === 0);
    }

    #[Group('jobs')]
    #[Group('rdn')]
    public function test_failed_jobs_are_scheduled_to_be_pruned(): void
    {
        $events = Schedule::events();

        $pruneFailed = collect($events)->first(
            fn ($event): bool => str_contains($event->command ?? $event->description ?? '', 'queue:prune-failed')
        );

        $this->assertNotNull($pruneFailed, 'queue:prune-failed must be scheduled');
    }
}
