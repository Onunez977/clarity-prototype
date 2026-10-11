<?php

namespace Tests\Feature\Jobs\Rdn;

use App\Jobs\Rdn\GetOrgAgents;
use App\Jobs\Rdn\GetOrgBranches;
use App\Jobs\Rdn\GetOrgBranchZips;
use App\Jobs\Rdn\GetOrgRdnClients;
use App\Jobs\Rdn\RdnJob;
use App\Models\Branch;
use App\Models\Org;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Support\Facades\RateLimiter;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

class RdnJobTest extends TestCase
{
    /**
     * @return list<class-string<RdnJob>>
     */
    public static function queueGatedJobs(): array
    {
        return [
            [GetOrgBranches::class],
            [GetOrgRdnClients::class],
            [GetOrgAgents::class],
            [GetOrgBranchZips::class],
        ];
    }

    #[Group('jobs')]
    #[Group('rdn')]
    public function test_every_sibling_still_carries_the_shared_queue_limiter(): void
    {
        $org = Org::factory()->create();

        foreach (self::queueGatedJobs() as [$job]) {
            $middleware = (new $job(orgId: $org->id, org: $org))->middleware();

            $this->assertCount(1, $middleware, $job);
            $this->assertInstanceOf(RateLimited::class, $middleware[0], $job);
        }
    }

    #[Group('jobs')]
    #[Group('rdn')]
    public function test_every_sibling_still_charges_the_service_level_limiter(): void
    {
        // The RateLimited('rdn-api') middleware charges once per JOB, and these jobs
        // paginate, so it is not a per-CALL throttle. The service-level limiter is the only
        // one that counts calls, so it must stay on - gatedByQueue() here would strip it.
        // It is not a double count either: RateLimited buckets on md5(limiterName.key),
        // never the raw 'rdn-api:{orgId}' the service layer uses.
        foreach (self::queueGatedJobs() as [$job]) {
            $org = Org::factory()->create(['rdn_api_key' => fake()->uuid()]);
            $org->meta()->create(['properties' => ['rdn_rate_limit' => 10]]);

            Branch::factory()->create(['org_id' => $org->id, 'branch_id' => 4242]);

            $client = $this->partialMock(\SoapClient::class, function (MockInterface $mock) {
                $mock->shouldReceive('__soapCall')->andReturn(null);
            });

            RateLimiter::clear('rdn-api:'.$org->id);

            (new $job(orgId: $org->id, org: $org->fresh(), client: $client))->handle();

            $this->assertGreaterThan(0, RateLimiter::attempts('rdn-api:'.$org->id), $job);
        }
    }

    #[Group('jobs')]
    #[Group('rdn')]
    public function test_a_paginating_sibling_charges_one_call_at_a_time_not_one_job(): void
    {
        // GetOrgBranchZips makes one SOAP call PER BRANCH. Under the queue middleware alone
        // all five would be charged as one, so an opted-in org's configured limit would buy
        // it an unbounded number of calls. The service-level limiter is what stops the run
        // at the limit it was actually given.
        $org = Org::factory()->create(['rdn_api_key' => fake()->uuid()]);
        $org->meta()->create(['properties' => ['rdn_rate_limit' => 2]]);

        foreach (range(1, 5) as $index) {
            Branch::factory()->create(['org_id' => $org->id, 'branch_id' => 5000 + $index]);
        }

        $client = $this->partialMock(\SoapClient::class, function (MockInterface $mock) {
            $mock->shouldReceive('__soapCall')->andReturn(null);
        });

        RateLimiter::clear('rdn-api:'.$org->id);

        $this->expectException(ThrottleRequestsException::class);

        try {
            (new GetOrgBranchZips(orgId: $org->id, org: $org->fresh(), client: $client))->handle();
        } finally {
            $this->assertSame(2, RateLimiter::attempts('rdn-api:'.$org->id));
        }
    }
}
