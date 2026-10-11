<?php

namespace Tests\Feature\Providers;

use App\Models\Org;
use Illuminate\Cache\RateLimiting\Unlimited;
use Illuminate\Support\Facades\RateLimiter;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

class RdnApiRateLimiterTest extends TestCase
{
    #[Group('providers')]
    public function test_rdn_api_uses_the_org_configured_rate_limit(): void
    {
        $org = Org::factory()->create();
        $org->meta()->create(['properties' => ['rdn_rate_limit' => 250]]);

        $limit = RateLimiter::limiter('rdn-api')((object) ['org' => $org->fresh(['meta'])]);

        $this->assertSame(250, $limit->maxAttempts);
        $this->assertSame('rdn-api:'.$org->id, $limit->key);
    }

    /**
     * GetOrgBranches, GetOrgRdnClients, GetOrgAgents and GetOrgBranchZips ride this
     * limiter with $tries = 3 and no retryUntil(). Introducing a default here would make
     * limiter releases eat their retry budget, so opting an org in stays explicit.
     */
    #[Group('providers')]
    public function test_rdn_api_stays_unlimited_for_an_org_with_no_configured_limit(): void
    {
        $org = Org::factory()->create();

        $this->assertInstanceOf(Unlimited::class, RateLimiter::limiter('rdn-api')((object) ['org' => $org]));

        $org->meta()->create(['properties' => ['rdn_rate_limit' => 0]]);

        $this->assertInstanceOf(Unlimited::class, RateLimiter::limiter('rdn-api')((object) ['org' => $org->fresh(['meta'])]));
    }

    #[Group('providers')]
    public function test_rdn_api_scopes_the_key_per_org(): void
    {
        $orgOne = Org::factory()->create();
        $orgOne->meta()->create(['properties' => ['rdn_rate_limit' => 10]]);

        $orgTwo = Org::factory()->create();
        $orgTwo->meta()->create(['properties' => ['rdn_rate_limit' => 10]]);

        $limitOne = RateLimiter::limiter('rdn-api')((object) ['org' => $orgOne->fresh(['meta'])]);
        $limitTwo = RateLimiter::limiter('rdn-api')((object) ['org' => $orgTwo->fresh(['meta'])]);

        $this->assertSame('rdn-api:'.$orgOne->id, $limitOne->key);
        $this->assertSame('rdn-api:'.$orgTwo->id, $limitTwo->key);
        $this->assertNotSame($limitOne->key, $limitTwo->key);
    }

    #[Group('providers')]
    public function test_case_info_uses_the_org_configured_rate_limit(): void
    {
        $org = Org::factory()->create();
        $org->meta()->create(['properties' => ['rdn_rate_limit' => 250]]);

        $limit = RateLimiter::limiter('rdn-case-info')((object) ['org' => $org->fresh(['meta'])]);

        $this->assertSame(250, $limit->maxAttempts);
    }

    #[Group('providers')]
    public function test_case_info_falls_back_to_a_real_limit_where_rdn_api_stays_unlimited(): void
    {
        $org = Org::factory()->create();

        $caseInfo = RateLimiter::limiter('rdn-case-info')((object) ['org' => $org]);
        $rdnApi = RateLimiter::limiter('rdn-api')((object) ['org' => $org]);

        // One case-info job is one SOAP call, so this middleware IS that job's only
        // throttle and an unconfigured org still needs a ceiling. The paginating siblings
        // throttle in the service layer instead, so they stay opt-in here.
        $this->assertSame($org->rdnRateLimit(), $caseInfo->maxAttempts);
        $this->assertLessThan($rdnApi->maxAttempts, $caseInfo->maxAttempts);
    }

    #[Group('providers')]
    public function test_case_info_scopes_the_key_per_org(): void
    {
        $orgOne = Org::factory()->create();
        $orgTwo = Org::factory()->create();

        $limitOne = RateLimiter::limiter('rdn-case-info')((object) ['org' => $orgOne]);
        $limitTwo = RateLimiter::limiter('rdn-case-info')((object) ['org' => $orgTwo]);

        $this->assertSame('rdn-case-info:'.$orgOne->id, $limitOne->key);
        $this->assertSame('rdn-case-info:'.$orgTwo->id, $limitTwo->key);
        $this->assertNotSame($limitOne->key, $limitTwo->key);
    }

    #[Group('providers')]
    public function test_the_two_limiters_are_independent(): void
    {
        $org = Org::factory()->create();

        $shared = RateLimiter::limiter('rdn-api')((object) ['org' => $org]);
        $caseInfo = RateLimiter::limiter('rdn-case-info')((object) ['org' => $org]);

        // Same org, different buckets: the case-info default must not spill onto the
        // sibling jobs, and the two must not double-count against one another.
        $this->assertInstanceOf(Unlimited::class, $shared);
        $this->assertSame(100, $caseInfo->maxAttempts);
        $this->assertNotSame($shared->key, $caseInfo->key);
    }

    #[Group('providers')]
    public function test_a_zero_case_info_rate_limit_falls_back_to_the_default_instead_of_blocking_everything(): void
    {
        $org = Org::factory()->create();
        $org->meta()->create(['properties' => ['rdn_rate_limit' => 0]]);

        $limit = RateLimiter::limiter('rdn-case-info')((object) ['org' => $org->fresh(['meta'])]);

        $this->assertSame(100, $limit->maxAttempts);
    }

    #[Group('providers')]
    public function test_a_negative_case_info_rate_limit_falls_back_to_the_default(): void
    {
        $org = Org::factory()->create();
        $org->meta()->create(['properties' => ['rdn_rate_limit' => -5]]);

        $limit = RateLimiter::limiter('rdn-case-info')((object) ['org' => $org->fresh(['meta'])]);

        $this->assertSame(100, $limit->maxAttempts);
    }

    #[Group('providers')]
    public function test_a_non_numeric_case_info_rate_limit_falls_back_to_the_default(): void
    {
        $org = Org::factory()->create();
        $org->meta()->create(['properties' => ['rdn_rate_limit' => 'unlimited']]);

        $limit = RateLimiter::limiter('rdn-case-info')((object) ['org' => $org->fresh(['meta'])]);

        $this->assertSame(100, $limit->maxAttempts);
    }

    #[Group('providers')]
    public function test_a_misconfigured_case_info_default_still_yields_a_usable_limit(): void
    {
        config(['services.rdn.default_rate_limit' => 0]);

        $org = Org::factory()->create();

        $limit = RateLimiter::limiter('rdn-case-info')((object) ['org' => $org]);

        $this->assertSame(1, $limit->maxAttempts);
    }
}
