<?php

namespace Tests\Feature\Jobs\Rdn;

use App\Jobs\Rdn\GetOrgHotlistCaseInfo;
use App\Jobs\Rdn\GetOrgHotlistCases;
use App\Models\Branch;
use App\Models\Hotlist;
use App\Models\HotlistJson;
use App\Models\Org;
use DataDog\DogStatsd;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use InsightLPR\BlackAdam\Enum\IntegrationEnum;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

class GetOrgHotlistCaseInfoTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
    }

    #[Group('jobs')]
    #[Group('rdn')]
    public function test_it_creates_hotlist_case_from_rdn_response(): void
    {
        $mockedSoapClient = $this->partialMock(\SoapClient::class, function (MockInterface $mock) {
            $mock
                ->shouldReceive('__soapCall')
                ->once()
                ->andReturn((object) [
                    'item' => [
                        (object) [
                            'order_date' => '2024-01-15',
                            'order_type' => 'Voluntary Repo',
                            'vendor_branch_name' => 'Test Branch',
                            'repo_date' => '2024-01-20',
                            'status' => 'Active',
                        ],
                    ],
                ]);

            $mock
                ->shouldReceive('__getLastResponse')
                ->once()
                ->andReturn(
                    '<?xml version="1.0" encoding="UTF-8"?><SOAP-ENV:Envelope xmlns:SOAP-ENV="http://schemas.xmlsoap.org/soap/envelope/" xmlns:ns1="'.config('services.rdn.api_url').'"><SOAP-ENV:Header><ns1:pager><page>1</page><per_page>1</per_page><next_page>1</next_page><page_items>1</page_items><total_items>1</total_items><total_pages>1</total_pages></ns1:pager></SOAP-ENV:Header></SOAP-ENV:Envelope>',
                );
        });

        $org = Org::factory()->create([
            'rdn_api_key' => fake()->uuid(),
        ]);

        $branch = Branch::factory()->create([
            'org_id' => $org->id,
            'branch_id' => 12345,
            'branch_name' => 'Test Branch',
        ]);

        $hotlist = Hotlist::factory()->create([
            'org_id' => $org->id,
            'remote_order_id' => 'TEST-CASE-123',
        ]);

        (new GetOrgHotlistCaseInfo(
            orgId: $org->id,
            org: $org,
            hotlistId: $hotlist->id,
            hotlist: $hotlist,
            client: $mockedSoapClient
        ))->handle();

        $hotlistJson = HotlistJson::where('hotlist_id', $hotlist->id)->first();
        $this->assertNotNull($hotlistJson);
        $this->assertEquals(12345, $hotlistJson->json->branch_id);
    }

    #[Group('jobs')]
    #[Group('rdn')]
    public function test_it_updates_existing_hotlist_case(): void
    {
        $mockedSoapClient = $this->partialMock(\SoapClient::class, function (MockInterface $mock) {
            $mock
                ->shouldReceive('__soapCall')
                ->once()
                ->andReturn((object) [
                    'item' => [
                        (object) [
                            'order_date' => '2024-01-20',
                            'order_type' => 'Involuntary Repo',
                            'vendor_branch_name' => 'Updated Branch',
                            'repo_date' => '2024-01-25',
                            'status' => 'Completed',
                        ],
                    ],
                ]);

            $mock
                ->shouldReceive('__getLastResponse')
                ->once()
                ->andReturn(
                    '<?xml version="1.0" encoding="UTF-8"?><SOAP-ENV:Envelope xmlns:SOAP-ENV="http://schemas.xmlsoap.org/soap/envelope/" xmlns:ns1="'.config('services.rdn.api_url').'"><SOAP-ENV:Header><ns1:pager><page>1</page><per_page>1</per_page><next_page>1</next_page><page_items>1</page_items><total_items>1</total_items><total_pages>1</total_pages></ns1:pager></SOAP-ENV:Header></SOAP-ENV:Envelope>',
                );
        });

        $org = Org::factory()->create([
            'rdn_api_key' => fake()->uuid(),
        ]);

        $branch = Branch::factory()->create([
            'org_id' => $org->id,
            'branch_id' => 67890,
            'branch_name' => 'Updated Branch',
        ]);

        $hotlist = Hotlist::factory()->create([
            'org_id' => $org->id,
            'remote_order_id' => 'TEST-CASE-456',
        ]);

        // Row exists (e.g. written by the external producer) but has no branch_id
        // key yet, so the job is not yet enriched and must still take the update path.
        DB::connection('zod')->table('public.hotlist_json')->insert([
            'hotlist_id' => $hotlist->id,
            'json' => json_encode(['case_number' => 'EXTERNAL-456']),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        (new GetOrgHotlistCaseInfo(
            orgId: $org->id,
            org: $org,
            hotlistId: $hotlist->id,
            hotlist: $hotlist,
            client: $mockedSoapClient
        ))->handle();

        $hotlistJson = HotlistJson::where('hotlist_id', $hotlist->id)->first();
        $this->assertNotNull($hotlistJson);
        $this->assertEquals(67890, $hotlistJson->json->branch_id);

        $rawJson = DB::connection('zod')->table('public.hotlist_json')->where('hotlist_id', $hotlist->id)->value('json');
        $this->assertSame('EXTERNAL-456', json_decode($rawJson, true)['case_number']);
    }

    #[Group('jobs')]
    #[Group('rdn')]
    public function test_it_handles_empty_response(): void
    {
        $mockedSoapClient = $this->partialMock(\SoapClient::class, function (MockInterface $mock) {
            $mock
                ->shouldReceive('__soapCall')
                ->once()
                ->andReturn((object) []);

            $mock
                ->shouldReceive('__getLastResponse')
                ->once()
                ->andReturn(
                    '<?xml version="1.0" encoding="UTF-8"?><SOAP-ENV:Envelope xmlns:SOAP-ENV="http://schemas.xmlsoap.org/soap/envelope/" xmlns:ns1="'.config('services.rdn.api_url').'"><SOAP-ENV:Header><ns1:pager><page>1</page><per_page>1</per_page><next_page>1</next_page><page_items>0</page_items><total_items>0</total_items><total_pages>1</total_pages></ns1:pager></SOAP-ENV:Header></SOAP-ENV:Envelope>',
                );
        });

        $org = Org::factory()->create([
            'rdn_api_key' => fake()->uuid(),
        ]);

        $hotlist = Hotlist::factory()->create([
            'org_id' => $org->id,
            'remote_order_id' => 'NONEXISTENT-CASE',
        ]);

        (new GetOrgHotlistCaseInfo(
            orgId: $org->id,
            org: $org,
            hotlistId: $hotlist->id,
            hotlist: $hotlist,
            client: $mockedSoapClient
        ))->handle();

        $hotlistJson = HotlistJson::where('hotlist_id', $hotlist->id)->first();
        $this->assertNotNull($hotlistJson);
        $this->assertNull($hotlistJson->json->branch_id);
    }

    #[Group('jobs')]
    #[Group('rdn')]
    public function test_it_preserves_existing_json_keys_when_writing_branch_id(): void
    {
        $mockedSoapClient = $this->partialMock(\SoapClient::class, function (MockInterface $mock) {
            $mock
                ->shouldReceive('__soapCall')
                ->once()
                ->andReturn((object) [
                    'item' => [
                        (object) [
                            'order_date' => '2024-01-15',
                            'order_type' => 'Voluntary Repo',
                            'vendor_branch_name' => 'Test Branch',
                            'repo_date' => '2024-01-20',
                            'status' => 'Active',
                        ],
                    ],
                ]);

            $mock
                ->shouldReceive('__getLastResponse')
                ->once()
                ->andReturn(
                    '<?xml version="1.0" encoding="UTF-8"?><SOAP-ENV:Envelope xmlns:SOAP-ENV="http://schemas.xmlsoap.org/soap/envelope/" xmlns:ns1="'.config('services.rdn.api_url').'"><SOAP-ENV:Header><ns1:pager><page>1</page><per_page>1</per_page><next_page>1</next_page><page_items>1</page_items><total_items>1</total_items><total_pages>1</total_pages></ns1:pager></SOAP-ENV:Header></SOAP-ENV:Envelope>',
                );
        });

        $org = Org::factory()->create([
            'rdn_api_key' => fake()->uuid(),
        ]);

        Branch::factory()->create([
            'org_id' => $org->id,
            'branch_id' => 12345,
            'branch_name' => 'Test Branch',
        ]);

        $hotlist = Hotlist::factory()->create([
            'org_id' => $org->id,
            'remote_order_id' => 'TEST-CASE-789',
        ]);

        // Raw insert bypasses HotlistJsonData's cast, which only declares
        // branch_id and would otherwise silently drop these external keys.
        DB::connection('zod')->table('public.hotlist_json')->insert([
            'hotlist_id' => $hotlist->id,
            'json' => json_encode(['case_number' => 'ABC-1', 'vehicle_color' => 'Blue']),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        (new GetOrgHotlistCaseInfo(
            orgId: $org->id,
            org: $org,
            hotlistId: $hotlist->id,
            hotlist: $hotlist,
            client: $mockedSoapClient
        ))->handle();

        $rawJson = DB::connection('zod')
            ->table('public.hotlist_json')
            ->where('hotlist_id', $hotlist->id)
            ->value('json');

        $decoded = json_decode($rawJson, true);
        $this->assertSame('ABC-1', $decoded['case_number']);
        $this->assertSame('Blue', $decoded['vehicle_color']);
        $this->assertSame(12345, $decoded['branch_id']);
    }

    #[Group('jobs')]
    #[Group('rdn')]
    public function test_it_does_not_call_rdn_when_branch_id_is_already_present(): void
    {
        $mockedSoapClient = $this->partialMock(\SoapClient::class, function (MockInterface $mock) {
            $mock->shouldReceive('__soapCall')->never();
            $mock->shouldReceive('__getLastResponse')->never();
        });

        $org = Org::factory()->create([
            'rdn_api_key' => fake()->uuid(),
        ]);

        $hotlist = Hotlist::factory()->create([
            'org_id' => $org->id,
            'remote_order_id' => 'TEST-CASE-ALREADY-ENRICHED',
        ]);

        HotlistJson::create([
            'hotlist_id' => $hotlist->id,
            'json' => [
                'branch_id' => 11111,
            ],
        ]);

        (new GetOrgHotlistCaseInfo(
            orgId: $org->id,
            org: $org,
            hotlistId: $hotlist->id,
            hotlist: $hotlist,
            client: $mockedSoapClient
        ))->handle();

        $hotlistJson = HotlistJson::where('hotlist_id', $hotlist->id)->first();
        $this->assertNotNull($hotlistJson);
        $this->assertEquals(11111, $hotlistJson->json->branch_id);
    }

    #[Group('jobs')]
    #[Group('rdn')]
    public function test_it_retries_a_hotlist_whose_branch_id_resolved_to_null(): void
    {
        $mockedSoapClient = $this->mockCaseInfoResponse('Later Branch');

        $org = Org::factory()->create([
            'rdn_api_key' => fake()->uuid(),
        ]);

        Branch::factory()->create([
            'org_id' => $org->id,
            'branch_id' => 55555,
            'branch_name' => 'Later Branch',
        ]);

        $hotlist = Hotlist::factory()->create([
            'org_id' => $org->id,
            'remote_order_id' => 'TEST-CASE-NULL-RETRY',
        ]);

        // Exactly what recordUnresolvedLookup() leaves behind after one miss - RDN had no
        // item, or the Branch row had not synced yet. Under the ceiling, so it must not
        // seal the hotlist. Seeding a bare null instead would test a state the write path
        // never produces.
        $this->insertHotlistJson($hotlist->id, [
            'branch_id' => null,
            GetOrgHotlistCaseInfo::ATTEMPTS_KEY => 1,
            'case_number' => 'EXTERNAL-NULL',
        ]);

        (new GetOrgHotlistCaseInfo(
            orgId: $org->id,
            org: $org,
            hotlistId: $hotlist->id,
            hotlist: $hotlist,
            client: $mockedSoapClient
        ))->handle();

        $decoded = $this->rawJson($hotlist->id);
        $this->assertSame(55555, $decoded['branch_id']);
        $this->assertSame('EXTERNAL-NULL', $decoded['case_number']);
    }

    #[Group('jobs')]
    #[Group('rdn')]
    public function test_it_throttles_on_its_own_limiter_key_not_the_shared_rdn_api_one(): void
    {
        $org = Org::factory()->create();
        $hotlist = Hotlist::factory()->create(['org_id' => $org->id]);

        $middleware = (new GetOrgHotlistCaseInfo(
            orgId: $org->id,
            org: $org,
            hotlistId: $hotlist->id,
            hotlist: $hotlist,
        ))->middleware();

        // 'rdn-api' is shared with GetOrgBranches, GetOrgRdnClients, GetOrgAgents and
        // GetOrgBranchZips, which budget retries with $tries and cannot absorb releases.
        $this->assertCount(1, $middleware);
        $this->assertInstanceOf(RateLimited::class, $middleware[0]);
        $this->assertSame('rdn-case-info', (new \ReflectionProperty(RateLimited::class, 'limiterName'))->getValue($middleware[0]));
    }

    #[Group('jobs')]
    #[Group('rdn')]
    public function test_it_writes_branch_id_with_a_single_atomic_upsert(): void
    {
        $mockedSoapClient = $this->mockCaseInfoResponse('Test Branch');

        $org = Org::factory()->create([
            'rdn_api_key' => fake()->uuid(),
        ]);

        Branch::factory()->create([
            'org_id' => $org->id,
            'branch_id' => 12345,
            'branch_name' => 'Test Branch',
        ]);

        $hotlist = Hotlist::factory()->create([
            'org_id' => $org->id,
            'remote_order_id' => 'TEST-CASE-UPSERT',
        ]);

        DB::connection('zod')->flushQueryLog();
        DB::connection('zod')->enableQueryLog();

        (new GetOrgHotlistCaseInfo(
            orgId: $org->id,
            org: $org,
            hotlistId: $hotlist->id,
            hotlist: $hotlist,
            client: $mockedSoapClient
        ))->handle();

        $writes = collect(DB::connection('zod')->getQueryLog())
            ->map(fn (array $query): string => strtolower($query['query']))
            ->filter(fn (string $sql): bool => str_contains($sql, 'hotlist_json')
                && (str_starts_with($sql, 'insert') || str_starts_with($sql, 'update')));

        DB::connection('zod')->disableQueryLog();

        $this->assertCount(1, $writes, 'branch_id must be written by one statement, not a check-then-act pair.');
        $this->assertStringContainsString('on conflict (hotlist_id) do update', $writes->first());
        $this->assertSame(12345, $this->rawJson($hotlist->id)['branch_id']);
    }

    #[Group('jobs')]
    #[Group('rdn')]
    public function test_a_second_run_upserts_over_an_existing_row_without_colliding(): void
    {
        $org = Org::factory()->create([
            'rdn_api_key' => fake()->uuid(),
        ]);

        $hotlist = Hotlist::factory()->create([
            'org_id' => $org->id,
            'remote_order_id' => 'TEST-CASE-SECOND-RUN',
        ]);

        // No Branch row has synced yet, so the first run writes branch_id: null. Shipped
        // config gives the row three attempts, so the miss does not seal it.
        (new GetOrgHotlistCaseInfo(
            orgId: $org->id,
            org: $org,
            hotlistId: $hotlist->id,
            hotlist: $hotlist,
            client: $this->mockCaseInfoResponse('Second Run Branch')
        ))->handle();

        $this->assertNull($this->rawJson($hotlist->id)['branch_id']);

        // The Branch row lands, and the second run must update the same primary key.
        Branch::factory()->create([
            'org_id' => $org->id,
            'branch_id' => 9001,
            'branch_name' => 'Second Run Branch',
        ]);

        (new GetOrgHotlistCaseInfo(
            orgId: $org->id,
            org: $org,
            hotlistId: $hotlist->id,
            hotlist: $hotlist,
            client: $this->mockCaseInfoResponse('Second Run Branch')
        ))->handle();

        $this->assertSame(9001, $this->rawJson($hotlist->id)['branch_id']);
        $this->assertSame(1, DB::connection('zod')->table('public.hotlist_json')->where('hotlist_id', $hotlist->id)->count());
    }

    #[Group('jobs')]
    #[Group('rdn')]
    public function test_the_retry_deadline_absorbs_the_whole_dispatch_spread(): void
    {
        $this->freezeTime();

        $org = Org::factory()->create();
        $hotlist = Hotlist::factory()->create(['org_id' => $org->id]);

        $job = new GetOrgHotlistCaseInfo(
            orgId: $org->id,
            org: $org,
            hotlistId: $hotlist->id,
            hotlist: $hotlist,
        );

        // The deadline is stamped at PUSH but the job is not available until its dispatch
        // spread elapses, so the spread is spent before draining begins. This is the test
        // that fails if the spread ever outgrows what the deadline can absorb: the most
        // delayed job of a run must still get a full drain margin of runway.
        $latestStart = now()->addSeconds(GetOrgHotlistCaseInfo::maxSpreadSeconds());

        $this->assertObjectNotHasProperty('tries', $job);
        $this->assertSame(3, $job->maxExceptions);
        $this->assertGreaterThanOrEqual(
            $latestStart->copy()->addMinutes(GetOrgHotlistCaseInfo::drainMarginMinutes()),
            $job->retryUntil(),
        );

        // A wider spread has to widen the deadline with it, not eat the margin.
        config(['services.rdn.case_info_run_interval_minutes' => 2880]);

        $this->assertGreaterThanOrEqual(
            now()->addSeconds(GetOrgHotlistCaseInfo::maxSpreadSeconds())
                ->addMinutes(GetOrgHotlistCaseInfo::drainMarginMinutes()),
            $job->retryUntil(),
        );
    }

    #[Group('jobs')]
    #[Group('rdn')]
    public function test_the_retry_deadline_follows_a_reconfigured_schedule_interval(): void
    {
        $this->freezeTime();

        // Running more often shortens the wait a job can face, so the deadline tracks the
        // interval rather than a hardcoded day. A zero or negative interval would stamp a
        // deadline in the past and fail every job before it ran, so it floors at one.
        config(['services.rdn.case_info_run_interval_minutes' => 360]);

        $org = Org::factory()->create();
        $hotlist = Hotlist::factory()->create(['org_id' => $org->id]);

        $job = new GetOrgHotlistCaseInfo(
            orgId: $org->id,
            org: $org,
            hotlistId: $hotlist->id,
            hotlist: $hotlist,
        );

        $this->assertEquals(now()->addMinutes(720), $job->retryUntil());

        config(['services.rdn.case_info_run_interval_minutes' => 0]);

        $this->assertTrue($job->retryUntil() > now());
    }

    #[Group('jobs')]
    #[Group('rdn')]
    public function test_the_retry_deadline_does_not_depend_on_the_orgs_own_rate_limit(): void
    {
        $this->freezeTime();

        $org = Org::factory()->create();
        $org->meta()->create(['properties' => ['rdn_rate_limit' => 1]]);

        $hotlist = Hotlist::factory()->create(['org_id' => $org->id]);

        $slowOrg = new GetOrgHotlistCaseInfo(
            orgId: $org->id,
            org: $org->fresh(),
            hotlistId: $hotlist->id,
            hotlist: $hotlist,
        );

        $unconfigured = Org::factory()->create();
        $otherHotlist = Hotlist::factory()->create(['org_id' => $unconfigured->id]);

        $default = new GetOrgHotlistCaseInfo(
            orgId: $unconfigured->id,
            org: $unconfigured,
            hotlistId: $otherHotlist->id,
            hotlist: $otherHotlist,
        );

        // The old derivation scaled the window off cap/rateLimit, which models one org and
        // ignores the 1,511 others queued in front of it on the same single process.
        $this->assertEquals($default->retryUntil(), $slowOrg->retryUntil());
    }

    #[Group('jobs')]
    #[Group('rdn')]
    public function test_the_job_cannot_outlive_its_own_queue_reservation(): void
    {
        // Production resolves the redis connection; phpunit forces sync, which never
        // expires a reservation. The pair that has to be coherent is this job's runtime
        // bound against the connection the fleet actually runs on.
        config(['queue.default' => 'redis']);

        $org = Org::factory()->create();
        $hotlist = Hotlist::factory()->create(['org_id' => $org->id]);

        $job = new GetOrgHotlistCaseInfo(
            orgId: $org->id,
            org: $org,
            hotlistId: $hotlist->id,
            hotlist: $hotlist,
        );

        $reservation = (int) config('queue.connections.redis.retry_after');

        $this->assertGreaterThan(0, $reservation);

        // The defect this pins: $timeout was 600 against a retry_after of 90, so the
        // connection released the job back to the ready list while the worker was still
        // executing it - a duplicate RDN call and a duplicate branch_lookup_attempts
        // increment for one answer. Queue::createObjectPayload reads this property, so it
        // is the number the worker's alarm is actually set from.
        $this->assertGreaterThan(0, $job->timeout);
        $this->assertLessThan($reservation, $job->timeout);
        $this->assertSame(GetOrgHotlistCaseInfo::timeoutSeconds(), $job->timeout);

        // ...and not so tight that the one call this job exists to make cannot finish.
        $this->assertGreaterThan(intdiv($reservation, 2), $job->timeout);

        $socketBound = (int) ini_get('default_socket_timeout');

        if ($socketBound > 0 && $socketBound < $reservation) {
            // A hung call has to reach PHP's per-read socket bound - which RDN::call()
            // flattens into the transport failure that costs the row no attempt - before
            // the worker's alarm kills the process. Asserted only where the ini leaves
            // room for both bounds under the reservation.
            $this->assertGreaterThan($socketBound, $job->timeout);
        }
    }

    #[Group('jobs')]
    #[Group('rdn')]
    public function test_the_runtime_bound_follows_a_reconfigured_reservation_window(): void
    {
        config(['queue.default' => 'redis']);

        $org = Org::factory()->create();
        $hotlist = Hotlist::factory()->create(['org_id' => $org->id]);

        // retry_after is shared by every queued job in the app, so this ticket must not
        // move it - but a future one can, and the bound has to track it instead of
        // becoming the second half of an incoherent pair again.
        foreach ([40, 90, 120, 3600] as $reservation) {
            config(['queue.connections.redis.retry_after' => $reservation]);

            $job = new GetOrgHotlistCaseInfo(
                orgId: $org->id,
                org: $org,
                hotlistId: $hotlist->id,
                hotlist: $hotlist,
            );

            $this->assertSame($reservation, GetOrgHotlistCaseInfo::reservationSeconds());
            $this->assertLessThan($reservation, $job->timeout, "retry_after {$reservation}");
            $this->assertGreaterThan(intdiv($reservation, 2), $job->timeout, "retry_after {$reservation}");
        }
    }

    #[Group('jobs')]
    #[Group('rdn')]
    public function test_a_connection_that_never_expires_a_reservation_still_bounds_the_job(): void
    {
        // sync and array run a job to completion in-process, so there is no reservation to
        // outlive and no second worker to duplicate the call. A zero or absent bound would
        // still be wrong - Laravel reads zero as "no alarm" - and the stand-in must not be
        // a value that would be incoherent the moment this ran on redis.
        config(['queue.default' => 'sync']);

        $org = Org::factory()->create();
        $hotlist = Hotlist::factory()->create(['org_id' => $org->id]);

        $job = new GetOrgHotlistCaseInfo(
            orgId: $org->id,
            org: $org,
            hotlistId: $hotlist->id,
            hotlist: $hotlist,
        );

        $this->assertSame(0, GetOrgHotlistCaseInfo::reservationSeconds());
        $this->assertSame(GetOrgHotlistCaseInfo::UNRESERVED_TIMEOUT_SECONDS, $job->timeout);
        $this->assertGreaterThan(0, $job->timeout);
        $this->assertLessThan((int) config('queue.connections.redis.retry_after'), $job->timeout);
    }

    #[Group('jobs')]
    #[Group('rdn')]
    public function test_the_case_info_call_is_not_charged_to_the_shared_service_limiter(): void
    {
        $mockedSoapClient = $this->mockCaseInfoResponse('Gated Branch');

        $org = Org::factory()->create([
            'rdn_api_key' => fake()->uuid(),
        ]);

        $org->meta()->create(['properties' => ['rdn_rate_limit' => 100]]);

        Branch::factory()->create([
            'org_id' => $org->id,
            'branch_id' => 7777,
            'branch_name' => 'Gated Branch',
        ]);

        $hotlist = Hotlist::factory()->create([
            'org_id' => $org->id,
            'remote_order_id' => 'TEST-CASE-GATED',
        ]);

        RateLimiter::clear('rdn-api:'.$org->id);

        (new GetOrgHotlistCaseInfo(
            orgId: $org->id,
            org: $org->fresh(),
            hotlistId: $hotlist->id,
            hotlist: $hotlist,
            client: $mockedSoapClient
        ))->handle();

        // The RateLimited('rdn-case-info') middleware is the single admission gate for this
        // job. Charging the same SOAP call to 'rdn-api' as well is two uncoordinated
        // buckets of N/min against one N/min vendor budget - and the service-level bucket
        // cannot release a job, only fail it.
        $this->assertSame(7777, $this->rawJson($hotlist->id)['branch_id']);
        $this->assertSame(0, RateLimiter::attempts('rdn-api:'.$org->id));
    }

    #[Group('jobs')]
    #[Group('rdn')]
    public function test_an_unresolved_branch_name_bumps_the_bounded_attempt_counter(): void
    {
        // RDN answers, but no Branch row carries that name. Under the non-null enrichment
        // predicate alone this row stays eligible forever and, being oldest, re-takes the
        // front of every FIFO run - so the miss has to leave a bounded trace behind.
        $mockedSoapClient = $this->mockCaseInfoResponse('Branch That Does Not Exist');

        $org = Org::factory()->create(['rdn_api_key' => fake()->uuid()]);

        $hotlist = Hotlist::factory()->create([
            'org_id' => $org->id,
            'remote_order_id' => 'TEST-CASE-UNRESOLVABLE',
        ]);

        $this->insertHotlistJson($hotlist->id, ['case_number' => 'EXTERNAL-MISS']);

        (new GetOrgHotlistCaseInfo(
            orgId: $org->id,
            org: $org,
            hotlistId: $hotlist->id,
            hotlist: $hotlist,
            client: $mockedSoapClient
        ))->handle();

        $decoded = $this->rawJson($hotlist->id);

        $this->assertNull($decoded['branch_id']);
        $this->assertSame(1, $decoded[GetOrgHotlistCaseInfo::ATTEMPTS_KEY]);
        $this->assertSame('EXTERNAL-MISS', $decoded['case_number']);
    }

    #[Group('jobs')]
    #[Group('rdn')]
    public function test_consecutive_misses_accumulate_on_the_same_counter(): void
    {
        $org = Org::factory()->create(['rdn_api_key' => fake()->uuid()]);

        $hotlist = Hotlist::factory()->create([
            'org_id' => $org->id,
            'remote_order_id' => 'TEST-CASE-ACCUMULATE',
        ]);

        $this->insertHotlistJson($hotlist->id, [GetOrgHotlistCaseInfo::ATTEMPTS_KEY => 1]);

        (new GetOrgHotlistCaseInfo(
            orgId: $org->id,
            org: $org,
            hotlistId: $hotlist->id,
            hotlist: $hotlist,
            client: $this->mockCaseInfoResponse('Still Missing')
        ))->handle();

        $this->assertSame(2, $this->rawJson($hotlist->id)[GetOrgHotlistCaseInfo::ATTEMPTS_KEY]);
    }

    #[Group('jobs')]
    #[Group('rdn')]
    public function test_a_resolved_branch_id_leaves_no_attempt_counter_behind(): void
    {
        $mockedSoapClient = $this->mockCaseInfoResponse('Resolvable Branch');

        $org = Org::factory()->create(['rdn_api_key' => fake()->uuid()]);

        Branch::factory()->create([
            'org_id' => $org->id,
            'branch_id' => 3131,
            'branch_name' => 'Resolvable Branch',
        ]);

        $hotlist = Hotlist::factory()->create([
            'org_id' => $org->id,
            'remote_order_id' => 'TEST-CASE-RESOLVES',
        ]);

        (new GetOrgHotlistCaseInfo(
            orgId: $org->id,
            org: $org,
            hotlistId: $hotlist->id,
            hotlist: $hotlist,
            client: $mockedSoapClient
        ))->handle();

        $decoded = $this->rawJson($hotlist->id);

        // The happy-path write stays exactly the one key it has always been; branch_id
        // seals the row on its own and the counter would be noise in an external blob.
        $this->assertSame(3131, $decoded['branch_id']);
        $this->assertArrayNotHasKey(GetOrgHotlistCaseInfo::ATTEMPTS_KEY, $decoded);
    }

    #[Group('jobs')]
    #[Group('rdn')]
    public function test_a_lookup_that_finally_resolves_seals_the_row_despite_earlier_misses(): void
    {
        $mockedSoapClient = $this->mockCaseInfoResponse('Late Branch');

        $org = Org::factory()->create(['rdn_api_key' => fake()->uuid()]);

        Branch::factory()->create([
            'org_id' => $org->id,
            'branch_id' => 6262,
            'branch_name' => 'Late Branch',
        ]);

        $hotlist = Hotlist::factory()->create([
            'org_id' => $org->id,
            'remote_order_id' => 'TEST-CASE-LATE-SYNC',
        ]);

        // Two transient misses already recorded, still under the shipped ceiling: the
        // Branch row synced late, and the give-up rule must not have cost us the
        // resolution.
        $this->insertHotlistJson($hotlist->id, [
            'branch_id' => null,
            GetOrgHotlistCaseInfo::ATTEMPTS_KEY => 2,
        ]);

        (new GetOrgHotlistCaseInfo(
            orgId: $org->id,
            org: $org,
            hotlistId: $hotlist->id,
            hotlist: $hotlist,
            client: $mockedSoapClient
        ))->handle();

        $this->assertSame(6262, $this->rawJson($hotlist->id)['branch_id']);
    }

    #[Group('jobs')]
    #[Group('rdn')]
    public function test_it_does_not_call_rdn_once_the_attempt_ceiling_is_reached(): void
    {
        $mockedSoapClient = $this->partialMock(\SoapClient::class, function (MockInterface $mock) {
            $mock->shouldReceive('__soapCall')->never();
            $mock->shouldReceive('__getLastResponse')->never();
        });

        $org = Org::factory()->create(['rdn_api_key' => fake()->uuid()]);

        $hotlist = Hotlist::factory()->create([
            'org_id' => $org->id,
            'remote_order_id' => 'TEST-CASE-EXHAUSTED',
        ]);

        $ceiling = GetOrgHotlistCaseInfo::maxLookupAttempts();

        $this->insertHotlistJson($hotlist->id, [
            'branch_id' => null,
            GetOrgHotlistCaseInfo::ATTEMPTS_KEY => $ceiling,
        ]);

        (new GetOrgHotlistCaseInfo(
            orgId: $org->id,
            org: $org,
            hotlistId: $hotlist->id,
            hotlist: $hotlist,
            client: $mockedSoapClient
        ))->handle();

        $this->assertSame($ceiling, $this->rawJson($hotlist->id)[GetOrgHotlistCaseInfo::ATTEMPTS_KEY]);
    }

    #[Group('jobs')]
    #[Group('rdn')]
    public function test_the_attempt_ceiling_is_configurable(): void
    {
        config([
            'services.rdn.case_info_lookback_days' => 6,
            'services.rdn.case_info_max_lookup_attempts' => 5,
        ]);

        $org = Org::factory()->create(['rdn_api_key' => fake()->uuid()]);

        $hotlist = Hotlist::factory()->create([
            'org_id' => $org->id,
            'remote_order_id' => 'TEST-CASE-CEILING',
        ]);

        // Three misses seal at the shipped window and stay open at six days / five.
        $this->insertHotlistJson($hotlist->id, [
            'branch_id' => null,
            GetOrgHotlistCaseInfo::ATTEMPTS_KEY => 3,
        ]);

        (new GetOrgHotlistCaseInfo(
            orgId: $org->id,
            org: $org,
            hotlistId: $hotlist->id,
            hotlist: $hotlist,
            client: $this->mockCaseInfoResponse('Absent Branch')
        ))->handle();

        $this->assertSame(5, GetOrgHotlistCaseInfo::maxLookupAttempts());
        $this->assertSame(4, $this->rawJson($hotlist->id)[GetOrgHotlistCaseInfo::ATTEMPTS_KEY]);
    }

    #[Group('jobs')]
    #[Group('rdn')]
    public function test_a_non_numeric_attempt_ceiling_falls_back_rather_than_sealing_everything(): void
    {
        config(['services.rdn.case_info_max_lookup_attempts' => 0]);

        $this->assertSame(1, GetOrgHotlistCaseInfo::maxLookupAttempts());
    }

    #[Group('jobs')]
    #[Group('rdn')]
    public function test_the_attempt_ceiling_stays_below_what_the_lookback_window_delivers(): void
    {
        // Shipped config. A row that ages out after N selections can never record an Nth
        // miss, so a ceiling that is not strictly below N is a give-up rule that never
        // denies a selection - the mechanism existing only on paper.
        $this->assertLessThan(
            GetOrgHotlistCaseInfo::selectionsPerWindow(),
            GetOrgHotlistCaseInfo::maxLookupAttempts(),
        );

        // And the shipped window has to deliver every attempt the config asks for. A window
        // that derives the ceiling BELOW the configured value seals rows early and silently
        // - which is exactly what a 2-day window did, turning "three attempts" into one.
        $this->assertSame(
            (int) config('services.rdn.case_info_max_lookup_attempts'),
            GetOrgHotlistCaseInfo::maxLookupAttempts(),
        );

        // The pair cannot be configured back into incoherence: two days of daily runs is
        // two selections, so three attempts collapses to one.
        config([
            'services.rdn.case_info_lookback_days' => 2,
            'services.rdn.case_info_run_interval_minutes' => 1440,
            'services.rdn.case_info_max_lookup_attempts' => 3,
        ]);

        $this->assertSame(2, GetOrgHotlistCaseInfo::selectionsPerWindow());
        $this->assertSame(1, GetOrgHotlistCaseInfo::maxLookupAttempts());

        // Widening the window is what raises the ceiling; the config value only lowers it.
        config(['services.rdn.case_info_lookback_days' => 6]);

        $this->assertSame(6, GetOrgHotlistCaseInfo::selectionsPerWindow());
        $this->assertSame(3, GetOrgHotlistCaseInfo::maxLookupAttempts());
    }

    #[Group('jobs')]
    #[Group('rdn')]
    public function test_a_fractional_counter_that_reads_as_valid_also_writes(): void
    {
        // jsonb_typeof() answers 'number' for 1.5 and the read path casts ::numeric, so
        // this row is selectable. An ::int on the write path would raise here - after the
        // SOAP call had been spent - and the row would never seal.
        $org = Org::factory()->create(['rdn_api_key' => fake()->uuid()]);

        $hotlist = Hotlist::factory()->create([
            'org_id' => $org->id,
            'remote_order_id' => 'TEST-CASE-FRACTIONAL',
        ]);

        $this->insertHotlistJson($hotlist->id, [
            'branch_id' => null,
            GetOrgHotlistCaseInfo::ATTEMPTS_KEY => 1.5,
        ]);

        (new GetOrgHotlistCaseInfo(
            orgId: $org->id,
            org: $org,
            hotlistId: $hotlist->id,
            hotlist: $hotlist,
            client: $this->mockCaseInfoResponse('Absent Branch')
        ))->handle();

        $this->assertSame(2.5, $this->rawJson($hotlist->id)[GetOrgHotlistCaseInfo::ATTEMPTS_KEY]);
    }

    #[Group('jobs')]
    #[Group('rdn')]
    public function test_a_branch_id_resolved_during_the_call_survives_the_miss_write(): void
    {
        $org = Org::factory()->create(['rdn_api_key' => fake()->uuid()]);

        $hotlist = Hotlist::factory()->create([
            'org_id' => $org->id,
            'remote_order_id' => 'TEST-CASE-CONCURRENT-RESOLVE',
        ]);

        $this->insertHotlistJson($hotlist->id, ['case_number' => 'EXTERNAL-CONCURRENT']);

        // A concurrent execution resolves the branch between this job's seal guard and its
        // write. The upsert protects the primary key, not the value, so the miss write has
        // to leave a resolved branch_id alone or it un-enriches a row that is done.
        $concurrentResolve = function () use ($hotlist): void {
            DB::connection('zod')->table('public.hotlist_json')
                ->where('hotlist_id', $hotlist->id)
                ->update(['json' => json_encode(['case_number' => 'EXTERNAL-CONCURRENT', 'branch_id' => 4242])]);
        };

        (new GetOrgHotlistCaseInfo(
            orgId: $org->id,
            org: $org,
            hotlistId: $hotlist->id,
            hotlist: $hotlist,
            client: $this->mockCaseInfoResponse('Branch That Does Not Exist', $concurrentResolve)
        ))->handle();

        $decoded = $this->rawJson($hotlist->id);

        $this->assertSame(4242, $decoded['branch_id']);
        $this->assertSame(1, $decoded[GetOrgHotlistCaseInfo::ATTEMPTS_KEY]);
        $this->assertSame('EXTERNAL-CONCURRENT', $decoded['case_number']);
    }

    #[Group('jobs')]
    #[Group('rdn')]
    public function test_a_case_rdn_answered_nothing_for_counts_as_an_attempt(): void
    {
        // The call COMPLETED and RDN had no case to give - a deleted or unknown order.
        // That is a real answer, so it costs a slot in every run and is bounded on the
        // counter. It is not the transport-failure case: __soapCall returned rather than
        // throwing, and the two must not be conflated - see
        // test_a_call_that_never_completed_does_not_burn_the_rows_one_attempt.
        $mockedSoapClient = $this->partialMock(\SoapClient::class, function (MockInterface $mock) {
            $mock->shouldReceive('__soapCall')->once()->andReturn(null);
        });

        $org = Org::factory()->create(['rdn_api_key' => fake()->uuid()]);

        $hotlist = Hotlist::factory()->create([
            'org_id' => $org->id,
            'remote_order_id' => 'TEST-CASE-UNKNOWN-TO-RDN',
        ]);

        (new GetOrgHotlistCaseInfo(
            orgId: $org->id,
            org: $org,
            hotlistId: $hotlist->id,
            hotlist: $hotlist,
            client: $mockedSoapClient
        ))->handle();

        $decoded = $this->rawJson($hotlist->id);

        $this->assertNull($decoded['branch_id']);
        $this->assertSame(1, $decoded[GetOrgHotlistCaseInfo::ATTEMPTS_KEY]);
    }

    #[Group('jobs')]
    #[Group('rdn')]
    public function test_a_call_that_never_completed_does_not_burn_the_rows_one_attempt(): void
    {
        Bus::fake();

        // RDN::call() catches every \Exception out of __soapCall - a SOAP fault, a TLS or
        // DNS failure, a connect timeout, a rotated key - and answers the same
        // EMPTY_RESULT it answers when RDN genuinely has no case. Counting a blip spends
        // one of the row's real attempts, and there is no un-seal path and no TTL.
        $mockedSoapClient = $this->partialMock(\SoapClient::class, function (MockInterface $mock) {
            $mock
                ->shouldReceive('__soapCall')
                ->once()
                ->andThrow(new \SoapFault('HTTP', 'Could not connect to host'));
        });

        $org = Org::factory()->create(['rdn_api_key' => fake()->uuid()]);

        $hotlist = Hotlist::factory()->create([
            'org_id' => $org->id,
            'integration_id' => IntegrationEnum::RDN->value,
            'remote_order_id' => 'TEST-CASE-TRANSPORT-FAILURE',
        ]);

        (new GetOrgHotlistCaseInfo(
            orgId: $org->id,
            org: $org,
            hotlistId: $hotlist->id,
            hotlist: $hotlist,
            client: $mockedSoapClient
        ))->handle();

        // Nothing recorded at all: no counter to seal on, and no synthetic row either.
        $this->assertSame(
            0,
            DB::connection('zod')->table('public.hotlist_json')->where('hotlist_id', $hotlist->id)->count(),
        );

        // The next daily run is the retry, so the row has to still be selectable by it.
        (new GetOrgHotlistCases(orgId: $org->id, org: $org))->handle();

        Bus::assertDispatchedTimes(GetOrgHotlistCaseInfo::class, 1);
        Bus::assertDispatched(GetOrgHotlistCaseInfo::class, fn ($job) => $job->hotlistId === $hotlist->id);
    }

    #[Group('jobs')]
    #[Group('rdn')]
    public function test_a_call_that_never_completed_leaves_an_existing_counter_where_it_was(): void
    {
        // Same exemption on the update path, at the point where it costs the most: the row
        // is one miss below the shipped ceiling, so counting a transient failure would seal
        // it here and lose its branch attribution for good.
        $atRisk = GetOrgHotlistCaseInfo::maxLookupAttempts() - 1;

        $mockedSoapClient = $this->partialMock(\SoapClient::class, function (MockInterface $mock) {
            $mock
                ->shouldReceive('__soapCall')
                ->once()
                ->andThrow(new \SoapFault('HTTP', 'Error Fetching http headers'));
        });

        $org = Org::factory()->create(['rdn_api_key' => fake()->uuid()]);

        $hotlist = Hotlist::factory()->create([
            'org_id' => $org->id,
            'integration_id' => IntegrationEnum::RDN->value,
            'remote_order_id' => 'TEST-CASE-TRANSPORT-FAILURE-EXISTING',
        ]);

        $this->insertHotlistJson($hotlist->id, [
            'case_number' => 'EXTERNAL-TRANSPORT',
            'branch_id' => null,
            GetOrgHotlistCaseInfo::ATTEMPTS_KEY => $atRisk,
        ]);

        (new GetOrgHotlistCaseInfo(
            orgId: $org->id,
            org: $org,
            hotlistId: $hotlist->id,
            hotlist: $hotlist,
            client: $mockedSoapClient
        ))->handle();

        $decoded = $this->rawJson($hotlist->id);

        $this->assertSame($atRisk, $decoded[GetOrgHotlistCaseInfo::ATTEMPTS_KEY]);
        $this->assertSame('EXTERNAL-TRANSPORT', $decoded['case_number']);

        // And the row still has its last attempt: the next run must re-select it.
        Bus::fake();

        (new GetOrgHotlistCases(orgId: $org->id, org: $org))->handle();

        Bus::assertDispatched(GetOrgHotlistCaseInfo::class, fn ($job) => $job->hotlistId === $hotlist->id);
    }

    #[Group('jobs')]
    #[Group('rdn')]
    public function test_a_case_refusal_seals_the_row_on_first_sight_and_is_not_re_dispatched(): void
    {
        Bus::fake();

        // RDN refusing this key for this one case never changes, so the first sighting is
        // the only call: the counter goes straight to the ceiling instead of up by one.
        $refusal = new \SoapFault('SOAP-ENV:Server', 'This API key is not authorized to access this case');

        $mockedSoapClient = $this->partialMock(\SoapClient::class, function (MockInterface $mock) use ($refusal) {
            $mock->shouldReceive('__soapCall')->once()->andThrow($refusal);
        });

        $org = Org::factory()->create(['rdn_api_key' => 'fake-dev2934-key-never-printed']);

        $hotlist = Hotlist::factory()->create([
            'org_id' => $org->id,
            'integration_id' => IntegrationEnum::RDN->value,
            'remote_order_id' => 'TEST-CASE-REFUSAL',
        ]);

        $this->insertHotlistJson($hotlist->id, ['case_number' => 'EXTERNAL-REFUSAL']);

        (new GetOrgHotlistCaseInfo(
            orgId: $org->id,
            org: $org,
            hotlistId: $hotlist->id,
            hotlist: $hotlist,
            client: $mockedSoapClient
        ))->handle();

        $decoded = $this->rawJson($hotlist->id);

        $this->assertSame(GetOrgHotlistCaseInfo::maxLookupAttempts(), $decoded[GetOrgHotlistCaseInfo::ATTEMPTS_KEY]);
        $this->assertNull($decoded['branch_id']);
        $this->assertSame('EXTERNAL-REFUSAL', $decoded['case_number']);

        (new GetOrgHotlistCases(orgId: $org->id, org: $org))->handle();

        Bus::assertNotDispatched(GetOrgHotlistCaseInfo::class, fn ($job) => $job->hotlistId === $hotlist->id);
    }

    #[Group('jobs')]
    #[Group('rdn')]
    public function test_a_case_refusal_reports_the_no_branch_name_outcome_not_a_transport_failure(): void
    {
        $statsd = $this->datadogSpy();

        $refusal = new \SoapFault('SOAP-ENV:Server', 'This API key is not authorized to access this case');

        $mockedSoapClient = $this->partialMock(\SoapClient::class, function (MockInterface $mock) use ($refusal) {
            $mock->shouldReceive('__soapCall')->once()->andThrow($refusal);
        });

        $org = Org::factory()->create(['rdn_api_key' => 'fake-dev2934-key-never-printed']);

        $hotlist = Hotlist::factory()->create([
            'org_id' => $org->id,
            'remote_order_id' => 'TEST-CASE-REFUSAL-OUTCOME',
        ]);

        (new GetOrgHotlistCaseInfo(
            orgId: $org->id,
            org: $org,
            hotlistId: $hotlist->id,
            hotlist: $hotlist,
            client: $mockedSoapClient
        ))->handle();

        $this->assertOutcomeReported($statsd, GetOrgHotlistCaseInfo::OUTCOME_MISS_NO_BRANCH_NAME, $org->id);
        $this->assertOutcomeNotReported($statsd, GetOrgHotlistCaseInfo::OUTCOME_TRANSPORT_FAILED);
        $this->assertOutcomeNotReported($statsd, GetOrgHotlistCaseInfo::OUTCOME_MISS_NO_BRANCH_MATCH);
    }

    #[Group('jobs')]
    #[Group('rdn')]
    public function test_a_case_refusal_seals_a_row_that_already_had_a_miss(): void
    {
        $refusal = new \SoapFault('SOAP-ENV:Server', 'This API key is not authorized to access this case');

        $mockedSoapClient = $this->partialMock(\SoapClient::class, function (MockInterface $mock) use ($refusal) {
            $mock->shouldReceive('__soapCall')->once()->andThrow($refusal);
        });

        $org = Org::factory()->create(['rdn_api_key' => 'fake-dev2934-key-never-printed']);

        $hotlist = Hotlist::factory()->create([
            'org_id' => $org->id,
            'remote_order_id' => 'TEST-CASE-REFUSAL-EXISTING-MISS',
        ]);

        $this->insertHotlistJson($hotlist->id, [
            'branch_id' => null,
            GetOrgHotlistCaseInfo::ATTEMPTS_KEY => 1,
        ]);

        (new GetOrgHotlistCaseInfo(
            orgId: $org->id,
            org: $org,
            hotlistId: $hotlist->id,
            hotlist: $hotlist,
            client: $mockedSoapClient
        ))->handle();

        $decoded = $this->rawJson($hotlist->id);

        $this->assertSame(GetOrgHotlistCaseInfo::maxLookupAttempts(), $decoded[GetOrgHotlistCaseInfo::ATTEMPTS_KEY]);
        $this->assertNull($decoded['branch_id']);
    }

    #[Group('jobs')]
    #[Group('rdn')]
    public function test_a_generic_server_fault_is_still_a_transport_failure(): void
    {
        Bus::fake();

        $statsd = $this->datadogSpy();

        $mockedSoapClient = $this->partialMock(\SoapClient::class, function (MockInterface $mock) {
            $mock
                ->shouldReceive('__soapCall')
                ->once()
                ->andThrow(new \SoapFault('SOAP-ENV:Server', 'Internal Server Error'));
        });

        $org = Org::factory()->create(['rdn_api_key' => 'fake-dev2934-key-never-printed']);

        $hotlist = Hotlist::factory()->create([
            'org_id' => $org->id,
            'integration_id' => IntegrationEnum::RDN->value,
            'remote_order_id' => 'TEST-CASE-GENERIC-500',
        ]);

        (new GetOrgHotlistCaseInfo(
            orgId: $org->id,
            org: $org,
            hotlistId: $hotlist->id,
            hotlist: $hotlist,
            client: $mockedSoapClient
        ))->handle();

        $this->assertSame(
            0,
            DB::connection('zod')->table('public.hotlist_json')->where('hotlist_id', $hotlist->id)->count(),
        );
        $this->assertOutcomeReported($statsd, GetOrgHotlistCaseInfo::OUTCOME_TRANSPORT_FAILED, $org->id);
        $this->assertOutcomeNotReported($statsd, GetOrgHotlistCaseInfo::OUTCOME_MISS_NO_BRANCH_NAME);

        (new GetOrgHotlistCases(orgId: $org->id, org: $org))->handle();

        Bus::assertDispatched(GetOrgHotlistCaseInfo::class, fn ($job) => $job->hotlistId === $hotlist->id);
    }

    #[Group('jobs')]
    #[Group('rdn')]
    public function test_a_key_wide_refusal_is_not_treated_as_a_case_refusal(): void
    {
        Bus::fake();

        $statsd = $this->datadogSpy();

        // A rotated or revoked key refuses every case, so sealing on it would seal a whole
        // org's rows with no un-seal path. It has to stay a retryable transport failure.
        $mockedSoapClient = $this->partialMock(\SoapClient::class, function (MockInterface $mock) {
            $mock
                ->shouldReceive('__soapCall')
                ->once()
                ->andThrow(new \SoapFault('SOAP-ENV:Server', 'This API key is not authorized'));
        });

        $org = Org::factory()->create(['rdn_api_key' => 'fake-dev2934-key-never-printed']);

        $hotlist = Hotlist::factory()->create([
            'org_id' => $org->id,
            'integration_id' => IntegrationEnum::RDN->value,
            'remote_order_id' => 'TEST-CASE-KEY-WIDE-REFUSAL',
        ]);

        (new GetOrgHotlistCaseInfo(
            orgId: $org->id,
            org: $org,
            hotlistId: $hotlist->id,
            hotlist: $hotlist,
            client: $mockedSoapClient
        ))->handle();

        $this->assertSame(
            0,
            DB::connection('zod')->table('public.hotlist_json')->where('hotlist_id', $hotlist->id)->count(),
        );
        $this->assertOutcomeReported($statsd, GetOrgHotlistCaseInfo::OUTCOME_TRANSPORT_FAILED, $org->id);

        (new GetOrgHotlistCases(orgId: $org->id, org: $org))->handle();

        Bus::assertDispatched(GetOrgHotlistCaseInfo::class, fn ($job) => $job->hotlistId === $hotlist->id);
    }

    #[Group('jobs')]
    #[Group('rdn')]
    public function test_only_the_per_case_refusal_phrase_is_a_case_refusal(): void
    {
        $literal = 'This API key is not authorized to access this case';

        $this->assertTrue(GetOrgHotlistCaseInfo::isCaseRefusal(new \SoapFault('SOAP-ENV:Server', $literal)));
        $this->assertTrue(GetOrgHotlistCaseInfo::isCaseRefusal(new \SoapFault('SOAP-ENV:Server', strtoupper($literal))));

        $this->assertFalse(GetOrgHotlistCaseInfo::isCaseRefusal(null));
        $this->assertFalse(GetOrgHotlistCaseInfo::isCaseRefusal(new \Exception($literal)));
        $this->assertFalse(GetOrgHotlistCaseInfo::isCaseRefusal(new \SoapFault('SOAP-ENV:Server', 'Internal Server Error')));
        $this->assertFalse(GetOrgHotlistCaseInfo::isCaseRefusal(new \SoapFault('HTTP', 'Could not connect to host')));
        $this->assertFalse(GetOrgHotlistCaseInfo::isCaseRefusal(new \SoapFault('SOAP-ENV:Server', 'This API key is not authorized')));
        $this->assertFalse(GetOrgHotlistCaseInfo::isCaseRefusal(new \SoapFault('SOAP-ENV:Server', 'Invalid API key')));
        $this->assertFalse(GetOrgHotlistCaseInfo::isCaseRefusal(new \SoapFault('SOAP-ENV:Server', 'API key not authorized for this case')));
    }

    #[Group('jobs')]
    #[Group('rdn')]
    public function test_a_case_refusal_never_logs_the_api_key(): void
    {
        $fakeKey = 'fake-dev2934-key-never-printed';

        $logged = [];

        Log::listen(function (MessageLogged $event) use (&$logged): void {
            $logged[] = $event;
        });

        $refusal = new \SoapFault('SOAP-ENV:Server', 'This API key is not authorized to access this case');

        $mockedSoapClient = $this->partialMock(\SoapClient::class, function (MockInterface $mock) use ($refusal) {
            $mock->shouldReceive('__soapCall')->once()->andThrow($refusal);
        });

        $org = Org::factory()->create(['rdn_api_key' => $fakeKey]);

        $hotlist = Hotlist::factory()->create([
            'org_id' => $org->id,
            'remote_order_id' => 'TEST-CASE-REFUSAL-LOGS',
        ]);

        (new GetOrgHotlistCaseInfo(
            orgId: $org->id,
            org: $org,
            hotlistId: $hotlist->id,
            hotlist: $hotlist,
            client: $mockedSoapClient
        ))->handle();

        // The fault is logged by RDN::call(), so an empty capture would mean the listener
        // is not live and the assertions below would prove nothing.
        $this->assertNotEmpty($logged);

        foreach ($logged as $event) {
            $this->assertStringNotContainsString($fakeKey, $event->message.json_encode($event->context));
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
     * @return array<string, mixed>
     */
    private function rawJson(int $hotlistId): array
    {
        return json_decode(
            DB::connection('zod')->table('public.hotlist_json')->where('hotlist_id', $hotlistId)->value('json'),
            true
        );
    }

    private function mockCaseInfoResponse(string $vendorBranchName, ?callable $duringCall = null): \SoapClient
    {
        return $this->partialMock(\SoapClient::class, function (MockInterface $mock) use ($vendorBranchName, $duringCall) {
            $mock
                ->shouldReceive('__soapCall')
                ->andReturnUsing(function () use ($vendorBranchName, $duringCall) {
                    if ($duringCall) {
                        $duringCall();
                    }

                    return (object) [
                        'item' => [
                            (object) [
                                'order_date' => '2024-01-15',
                                'order_type' => 'Voluntary Repo',
                                'vendor_branch_name' => $vendorBranchName,
                                'repo_date' => '2024-01-20',
                                'status' => 'Active',
                            ],
                        ],
                    ];
                });

            $mock
                ->shouldReceive('__getLastResponse')
                ->andReturn(
                    '<?xml version="1.0" encoding="UTF-8"?><SOAP-ENV:Envelope xmlns:SOAP-ENV="http://schemas.xmlsoap.org/soap/envelope/" xmlns:ns1="'.config('services.rdn.api_url').'"><SOAP-ENV:Header><ns1:pager><page>1</page><per_page>1</per_page><next_page>1</next_page><page_items>1</page_items><total_items>1</total_items><total_pages>1</total_pages></ns1:pager></SOAP-ENV:Header></SOAP-ENV:Envelope>',
                );
        });
    }

    #[Group('jobs')]
    #[Group('rdn')]
    public function test_the_kill_switch_stops_a_job_already_sitting_on_the_queue(): void
    {
        // The fan-out is spread across a whole run interval, so flipping the flag leaves up
        // to a day of these already queued. Without this check the switch would stop
        // selection and keep making SOAP calls for another day.
        config(['services.rdn.case_info_enabled' => false]);

        $mockedSoapClient = $this->partialMock(\SoapClient::class, function (MockInterface $mock) {
            $mock->shouldNotReceive('__soapCall');
        });

        $org = Org::factory()->create(['rdn_api_key' => fake()->uuid()]);

        $hotlist = Hotlist::factory()->create([
            'org_id' => $org->id,
            'integration_id' => IntegrationEnum::RDN->value,
            'remote_order_id' => 'TEST-CASE-KILL-SWITCH',
        ]);

        DB::connection('zod')->flushQueryLog();
        DB::connection('zod')->enableQueryLog();

        (new GetOrgHotlistCaseInfo(
            orgId: $org->id,
            org: $org,
            hotlistId: $hotlist->id,
            hotlist: $hotlist,
            client: $mockedSoapClient
        ))->handle();

        $queries = DB::connection('zod')->getQueryLog();

        DB::connection('zod')->disableQueryLog();

        // Not even the sealed lookup: the flag is read before any query.
        $this->assertSame([], $queries);
        $this->assertSame(
            0,
            DB::connection('zod')->table('public.hotlist_json')->where('hotlist_id', $hotlist->id)->count(),
        );
    }

    #[Group('jobs')]
    #[Group('rdn')]
    public function test_a_drained_backlog_does_not_burn_the_rows_attempts(): void
    {
        config(['services.rdn.case_info_enabled' => false]);

        $mockedSoapClient = $this->partialMock(\SoapClient::class, function (MockInterface $mock) {
            $mock->shouldNotReceive('__soapCall');
        });

        $org = Org::factory()->create(['rdn_api_key' => fake()->uuid()]);

        $hotlist = Hotlist::factory()->create([
            'org_id' => $org->id,
            'integration_id' => IntegrationEnum::RDN->value,
            'remote_order_id' => 'TEST-CASE-KILL-SWITCH-COUNTER',
        ]);

        $this->insertHotlistJson($hotlist->id, [
            'branch_id' => null,
            GetOrgHotlistCaseInfo::ATTEMPTS_KEY => 1,
        ]);

        (new GetOrgHotlistCaseInfo(
            orgId: $org->id,
            org: $org,
            hotlistId: $hotlist->id,
            hotlist: $hotlist,
            client: $mockedSoapClient
        ))->handle();

        // Turning the feature off must not consume the row's retry budget - re-enabling it
        // has to find the same row where it left it.
        $this->assertSame(1, $this->rawJson($hotlist->id)[GetOrgHotlistCaseInfo::ATTEMPTS_KEY]);
    }

    #[Group('jobs')]
    #[Group('rdn')]
    public function test_the_override_only_overrides_the_enabled_flag(): void
    {
        config(['services.rdn.case_info_enabled' => false]);

        $this->assertFalse(GetOrgHotlistCaseInfo::isEnabled());
        $this->assertTrue(GetOrgHotlistCaseInfo::isEnabled(forced: true));

        config(['services.rdn.case_info_enabled' => true]);

        $this->assertTrue(GetOrgHotlistCaseInfo::isEnabled());
        $this->assertTrue(GetOrgHotlistCaseInfo::isEnabled(forced: true));
    }

    #[Group('jobs')]
    #[Group('rdn')]
    public function test_a_forced_job_runs_while_the_kill_switch_is_off(): void
    {
        config(['services.rdn.case_info_enabled' => false]);

        $org = Org::factory()->create(['rdn_api_key' => fake()->uuid()]);

        Branch::factory()->create([
            'org_id' => $org->id,
            'branch_id' => 54321,
            'branch_name' => 'Canary Branch',
        ]);

        $hotlist = Hotlist::factory()->create([
            'org_id' => $org->id,
            'integration_id' => IntegrationEnum::RDN->value,
            'remote_order_id' => 'TEST-CASE-FORCED',
        ]);

        // Without the override reaching here, a forced dispatcher queues a canary whose
        // every child no-ops on its own read of the flag - the run would look like it
        // happened and enrich nothing.
        (new GetOrgHotlistCaseInfo(
            orgId: $org->id,
            org: $org,
            hotlistId: $hotlist->id,
            hotlist: $hotlist,
            client: $this->mockCaseInfoResponse('Canary Branch'),
            forced: true,
        ))->handle();

        $this->assertSame(54321, $this->rawJson($hotlist->id)['branch_id']);
    }

    #[Group('jobs')]
    #[Group('rdn')]
    public function test_a_forced_job_does_not_bypass_the_attempt_ceiling(): void
    {
        config(['services.rdn.case_info_enabled' => false]);

        $mockedSoapClient = $this->partialMock(\SoapClient::class, function (MockInterface $mock) {
            $mock->shouldNotReceive('__soapCall');
        });

        $org = Org::factory()->create(['rdn_api_key' => fake()->uuid()]);

        $hotlist = Hotlist::factory()->create([
            'org_id' => $org->id,
            'integration_id' => IntegrationEnum::RDN->value,
            'remote_order_id' => 'TEST-CASE-FORCED-SEALED',
        ]);

        $this->insertHotlistJson($hotlist->id, [
            'branch_id' => null,
            GetOrgHotlistCaseInfo::ATTEMPTS_KEY => GetOrgHotlistCaseInfo::maxLookupAttempts(),
        ]);

        (new GetOrgHotlistCaseInfo(
            orgId: $org->id,
            org: $org,
            hotlistId: $hotlist->id,
            hotlist: $hotlist,
            client: $mockedSoapClient,
            forced: true,
        ))->handle();

        $this->assertSame(
            GetOrgHotlistCaseInfo::maxLookupAttempts(),
            $this->rawJson($hotlist->id)[GetOrgHotlistCaseInfo::ATTEMPTS_KEY],
        );
    }

    #[Group('jobs')]
    #[Group('rdn')]
    public function test_a_resolved_lookup_reports_the_resolved_outcome(): void
    {
        $statsd = $this->datadogSpy();

        $org = Org::factory()->create(['rdn_api_key' => fake()->uuid()]);

        Branch::factory()->create([
            'org_id' => $org->id,
            'branch_id' => 12345,
            'branch_name' => 'Test Branch',
        ]);

        $hotlist = Hotlist::factory()->create([
            'org_id' => $org->id,
            'remote_order_id' => 'TEST-CASE-OUTCOME-RESOLVED',
        ]);

        (new GetOrgHotlistCaseInfo(
            orgId: $org->id,
            org: $org,
            hotlistId: $hotlist->id,
            hotlist: $hotlist,
            client: $this->mockCaseInfoResponse('Test Branch')
        ))->handle();

        $this->assertOutcomeReported($statsd, GetOrgHotlistCaseInfo::OUTCOME_RESOLVED, $org->id);
        $this->assertSame(12345, $this->rawJson($hotlist->id)['branch_id']);
    }

    #[Group('jobs')]
    #[Group('rdn')]
    public function test_a_branch_name_no_row_matches_is_reported_apart_from_a_missing_case(): void
    {
        // The whole reason the two miss buckets exist. The match is exact, then normalized,
        // against Branch.branch_name, so a 0% resolve rate reads completely differently
        // depending on which of these fires: RDN naming a branch nothing matches is a
        // string-format or GetOrgBranches problem, RDN naming nothing at all is not.
        $statsd = $this->datadogSpy();

        $org = Org::factory()->create(['rdn_api_key' => fake()->uuid()]);

        Branch::factory()->create([
            'org_id' => $org->id,
            'branch_id' => 777,
            'branch_name' => 'Dallas Branch',
        ]);

        $hotlist = Hotlist::factory()->create([
            'org_id' => $org->id,
            'remote_order_id' => 'TEST-CASE-OUTCOME-NO-MATCH',
        ]);

        (new GetOrgHotlistCaseInfo(
            orgId: $org->id,
            org: $org,
            hotlistId: $hotlist->id,
            hotlist: $hotlist,
            client: $this->mockCaseInfoResponse('Houston Branch')
        ))->handle();

        $this->assertOutcomeReported($statsd, GetOrgHotlistCaseInfo::OUTCOME_MISS_NO_BRANCH_MATCH, $org->id);
        $this->assertOutcomeNotReported($statsd, GetOrgHotlistCaseInfo::OUTCOME_MISS_NO_BRANCH_NAME);
        $this->assertSame(1, $this->rawJson($hotlist->id)[GetOrgHotlistCaseInfo::ATTEMPTS_KEY]);
    }

    #[Group('jobs')]
    #[Group('rdn')]
    public function test_a_case_rdn_had_nothing_for_reports_the_no_branch_name_outcome(): void
    {
        $statsd = $this->datadogSpy();

        $mockedSoapClient = $this->partialMock(\SoapClient::class, function (MockInterface $mock) {
            $mock->shouldReceive('__soapCall')->once()->andReturn(null);
        });

        $org = Org::factory()->create(['rdn_api_key' => fake()->uuid()]);

        $hotlist = Hotlist::factory()->create([
            'org_id' => $org->id,
            'remote_order_id' => 'TEST-CASE-OUTCOME-NO-CASE',
        ]);

        (new GetOrgHotlistCaseInfo(
            orgId: $org->id,
            org: $org,
            hotlistId: $hotlist->id,
            hotlist: $hotlist,
            client: $mockedSoapClient
        ))->handle();

        $this->assertOutcomeReported($statsd, GetOrgHotlistCaseInfo::OUTCOME_MISS_NO_BRANCH_NAME, $org->id);
        $this->assertOutcomeNotReported($statsd, GetOrgHotlistCaseInfo::OUTCOME_MISS_NO_BRANCH_MATCH);
    }

    #[Group('jobs')]
    #[Group('rdn')]
    public function test_a_call_that_never_completed_reports_a_transport_failure_not_a_miss(): void
    {
        // A miss and a call that never happened look identical in hotlist_json - neither
        // resolves - but only one of them means the fleet is burning calls into a void.
        $statsd = $this->datadogSpy();

        $mockedSoapClient = $this->partialMock(\SoapClient::class, function (MockInterface $mock) {
            $mock
                ->shouldReceive('__soapCall')
                ->once()
                ->andThrow(new \SoapFault('HTTP', 'Could not connect to host'));
        });

        $org = Org::factory()->create(['rdn_api_key' => fake()->uuid()]);

        $hotlist = Hotlist::factory()->create([
            'org_id' => $org->id,
            'remote_order_id' => 'TEST-CASE-OUTCOME-TRANSPORT',
        ]);

        (new GetOrgHotlistCaseInfo(
            orgId: $org->id,
            org: $org,
            hotlistId: $hotlist->id,
            hotlist: $hotlist,
            client: $mockedSoapClient
        ))->handle();

        $this->assertOutcomeReported($statsd, GetOrgHotlistCaseInfo::OUTCOME_TRANSPORT_FAILED, $org->id);
        $this->assertOutcomeNotReported($statsd, GetOrgHotlistCaseInfo::OUTCOME_MISS_NO_BRANCH_NAME);
        $this->assertOutcomeNotReported($statsd, GetOrgHotlistCaseInfo::OUTCOME_MISS_NO_BRANCH_MATCH);
    }

    #[Group('jobs')]
    #[Group('rdn')]
    public function test_a_job_whose_row_sealed_before_it_ran_reports_the_sealed_outcome(): void
    {
        $statsd = $this->datadogSpy();

        $mockedSoapClient = $this->partialMock(\SoapClient::class, function (MockInterface $mock) {
            $mock->shouldNotReceive('__soapCall');
        });

        $org = Org::factory()->create(['rdn_api_key' => fake()->uuid()]);

        $hotlist = Hotlist::factory()->create([
            'org_id' => $org->id,
            'remote_order_id' => 'TEST-CASE-OUTCOME-SEALED',
        ]);

        $this->insertHotlistJson($hotlist->id, ['branch_id' => 999]);

        (new GetOrgHotlistCaseInfo(
            orgId: $org->id,
            org: $org,
            hotlistId: $hotlist->id,
            hotlist: $hotlist,
            client: $mockedSoapClient
        ))->handle();

        $this->assertOutcomeReported($statsd, GetOrgHotlistCaseInfo::OUTCOME_SEALED, $org->id);
    }

    #[Group('jobs')]
    #[Group('rdn')]
    public function test_the_outcome_signal_carries_no_row_content(): void
    {
        $statsd = $this->datadogSpy();

        $org = Org::factory()->create(['rdn_api_key' => fake()->uuid()]);

        $hotlist = Hotlist::factory()->create([
            'org_id' => $org->id,
            'remote_order_id' => 'RDN-ORDER-90210',
        ]);

        (new GetOrgHotlistCaseInfo(
            orgId: $org->id,
            org: $org,
            hotlistId: $hotlist->id,
            hotlist: $hotlist,
            client: $this->mockCaseInfoResponse('Some Vendor Branch Name')
        ))->handle();

        $statsd->shouldHaveReceived('increment')
            ->withArgs(function (string $metric, float $rate, array $tags) use ($hotlist): bool {
                $emitted = strtolower($metric.'|'.implode('|', $tags));

                foreach (['rdn-order-90210', 'some vendor branch name', strtolower($hotlist->plate)] as $secret) {
                    $this->assertStringNotContainsString($secret, $emitted);
                }

                return true;
            });
    }

    #[Group('jobs')]
    #[Group('rdn')]
    public function test_a_failing_counter_does_not_re_run_the_lookup(): void
    {
        // A throw here would fail the job, and the retry would re-run
        // recordUnresolvedLookup() and spend a second attempt on one answer.
        config(['services.datadog_forwarding' => true]);

        $this->mock(DogStatsd::class, function (MockInterface $mock) {
            $mock->shouldReceive('increment')->andThrow(new \RuntimeException('statsd is down'));
        });

        $mockedSoapClient = $this->partialMock(\SoapClient::class, function (MockInterface $mock) {
            $mock->shouldReceive('__soapCall')->once()->andReturn(null);
        });

        $org = Org::factory()->create(['rdn_api_key' => fake()->uuid()]);

        $hotlist = Hotlist::factory()->create([
            'org_id' => $org->id,
            'remote_order_id' => 'TEST-CASE-STATSD-DOWN',
        ]);

        (new GetOrgHotlistCaseInfo(
            orgId: $org->id,
            org: $org,
            hotlistId: $hotlist->id,
            hotlist: $hotlist,
            client: $mockedSoapClient
        ))->handle();

        $this->assertSame(1, $this->rawJson($hotlist->id)[GetOrgHotlistCaseInfo::ATTEMPTS_KEY]);
    }

    /**
     * logDatadogCount() no-ops unless forwarding is on, so both halves have to be in place
     * before an outcome can be observed at all.
     */
    private function datadogSpy(): MockInterface
    {
        config(['services.datadog_forwarding' => true]);

        return $this->spy(DogStatsd::class);
    }

    private function assertOutcomeReported(MockInterface $statsd, string $outcome, int $orgId): void
    {
        $statsd->shouldHaveReceived('increment')
            ->once()
            ->withArgs(fn (string $metric, float $rate, array $tags, $value): bool => $metric === 'rdn_case_info.'.$outcome
                && $tags === ['category' => 'rdn_case_info', 'item' => 'org_'.$orgId]
                && (int) $value === 1);
    }

    private function assertOutcomeNotReported(MockInterface $statsd, string $outcome): void
    {
        $statsd->shouldNotHaveReceived('increment', ['rdn_case_info.'.$outcome]);
    }

    #[Group('jobs')]
    #[Group('jobs')]
    #[Group('rdn')]
    public function test_duplicate_branch_names_pick_the_lowest_id(): void
    {
        $mockedSoapClient = $this->partialMock(\SoapClient::class, function (MockInterface $mock) {
            $mock
                ->shouldReceive('__soapCall')
                ->once()
                ->andReturn((object) [
                    'item' => [
                        (object) [
                            'vendor_branch_name' => 'Dup Branch',
                        ],
                    ],
                ]);

            $mock
                ->shouldReceive('__getLastResponse')
                ->once()
                ->andReturn(
                    '<?xml version="1.0" encoding="UTF-8"?><SOAP-ENV:Envelope xmlns:SOAP-ENV="http://schemas.xmlsoap.org/soap/envelope/" xmlns:ns1="'.config('services.rdn.api_url').'"><SOAP-ENV:Header><ns1:pager><page>1</page><per_page>1</per_page><next_page>1</next_page><page_items>1</page_items><total_items>1</total_items><total_pages>1</total_pages></ns1:pager></SOAP-ENV:Header></SOAP-ENV:Envelope>',
                );
        });

        $org = Org::factory()->create([
            'rdn_api_key' => fake()->uuid(),
        ]);

        $first = Branch::factory()->create([
            'org_id' => $org->id,
            'branch_id' => 999,
            'branch_name' => 'Dup Branch',
        ]);
        $second = Branch::factory()->create([
            'org_id' => $org->id,
            'branch_id' => 111,
            'branch_name' => 'Dup Branch',
        ]);

        $expectedBranchId = $first->id < $second->id ? $first->branch_id : $second->branch_id;

        $hotlist = Hotlist::factory()->create([
            'org_id' => $org->id,
            'remote_order_id' => 'TEST-DUP-BRANCH',
        ]);

        (new GetOrgHotlistCaseInfo(
            orgId: $org->id,
            org: $org,
            hotlistId: $hotlist->id,
            hotlist: $hotlist,
            client: $mockedSoapClient,
        ))->handle();

        $hotlistJson = HotlistJson::where('hotlist_id', $hotlist->id)->first();
        $this->assertNotNull($hotlistJson);
        $this->assertSame($expectedBranchId, $hotlistJson->json->branch_id);
    }

    #[Group('jobs')]
    #[Group('rdn')]
    public function test_normalized_fallback_resolves_case_whitespace_punctuation_variants(): void
    {
        $org = Org::factory()->create(['rdn_api_key' => fake()->uuid()]);
        Branch::factory()->create(['org_id' => $org->id, 'branch_id' => 4242, 'branch_name' => 'Tampa Office']);
        $hotlist = Hotlist::factory()->create(['org_id' => $org->id, 'remote_order_id' => 'TEST-NORMALIZED']);

        (new GetOrgHotlistCaseInfo(
            orgId: $org->id,
            org: $org,
            hotlistId: $hotlist->id,
            hotlist: $hotlist,
            client: $this->mockCaseInfoResponse('  tampa-office. ')
        ))->handle();

        $this->assertSame(4242, $this->rawJson($hotlist->id)['branch_id']);
    }

    #[Group('jobs')]
    #[Group('rdn')]
    public function test_exact_match_wins_over_normalized(): void
    {
        $org = Org::factory()->create(['rdn_api_key' => fake()->uuid()]);
        // Lower id, normalized-equal only.
        Branch::factory()->create(['org_id' => $org->id, 'branch_id' => 1, 'branch_name' => 'Tampa Office']);
        Branch::factory()->create(['org_id' => $org->id, 'branch_id' => 2, 'branch_name' => 'tampa office']);

        $this->assertSame(2, GetOrgHotlistCaseInfo::findBranch($org->id, 'tampa office')?->branch_id);
        $this->assertSame(1, GetOrgHotlistCaseInfo::findBranch($org->id, 'Tampa Office')?->branch_id);
    }

    #[Group('jobs')]
    #[Group('rdn')]
    public function test_normalized_ties_resolve_by_lowest_id(): void
    {
        $org = Org::factory()->create(['rdn_api_key' => fake()->uuid()]);
        $first = Branch::factory()->create(['org_id' => $org->id, 'branch_id' => 31, 'branch_name' => 'Tampa  Office']);
        Branch::factory()->create(['org_id' => $org->id, 'branch_id' => 32, 'branch_name' => 'TAMPA-OFFICE']);

        $this->assertSame($first->branch_id, GetOrgHotlistCaseInfo::findBranch($org->id, 'tampa office.')?->branch_id);
    }

    #[Group('jobs')]
    #[Group('rdn')]
    public function test_no_false_match_for_different_names(): void
    {
        $org = Org::factory()->create(['rdn_api_key' => fake()->uuid()]);
        $peer = Org::factory()->create();
        Branch::factory()->create(['org_id' => $org->id, 'branch_id' => 51, 'branch_name' => 'Tampa Office']);
        Branch::factory()->create(['org_id' => $peer->id, 'branch_id' => 52, 'branch_name' => 'Tampa']);

        $this->assertNull(GetOrgHotlistCaseInfo::findBranch($org->id, 'Tampa'));
        $this->assertNull(GetOrgHotlistCaseInfo::findBranch($org->id, 'Tampa Office East'));
        $this->assertNull(GetOrgHotlistCaseInfo::findBranch($org->id, ' -- '));
    }

    #[Group('jobs')]
    #[Group('rdn')]
    public function test_normalize_branch_name_is_case_and_punctuation_insensitive(): void
    {
        $this->assertSame('tampa office', GetOrgHotlistCaseInfo::normalizeBranchName('  Tampa-Office. '));
        $this->assertSame('st louis 2', GetOrgHotlistCaseInfo::normalizeBranchName('St. Louis #2'));
        $this->assertSame('são paulo', GetOrgHotlistCaseInfo::normalizeBranchName('SÃO   PAULO'));
        $this->assertSame('', GetOrgHotlistCaseInfo::normalizeBranchName(' .,- '));
    }
}
