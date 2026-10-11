<?php

namespace App\Jobs\Rdn;

use App\Filament\Widgets\Dashboard\Concerns\StatHelper;
use App\Models\Org;
use App\Services\RDN;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Queue\SerializesModels;
use Laravel\Nightwatch\Facades\Nightwatch;
use SoapClient;

abstract class RdnJob implements ShouldQueue
{
    use Batchable;
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;
    use StatHelper;

    public $timeout = 600;

    public $backoff = 10;

    public $tries = 3;

    public function __construct(
        public int $orgId,
        public ?Org $org = null,
        public ?SoapClient $client = null,
    ) {
        $this->onQueue('{low}');

        if (! $org) {
            $this->org = Org::find($this->orgId);
        }
    }

    /**
     * Admission for the JOB, not for its calls. 'rdn-api' stays Limit::none() until an org
     * is opted in, and these jobs still budget retries with $tries rather than
     * retryUntil(), so the limiter definition is deliberately left alone - see
     * App\Providers\AppServiceProvider.
     */
    public function middleware(): array
    {
        return [new RateLimited('rdn-api')];
    }

    /**
     * These jobs paginate - GetOrgBranchZips makes one SOAP call PER BRANCH - so the
     * middleware's single per-job charge throttles nothing inside. RDN::makeRateLimitedCall()
     * is their only per-CALL limiter and must stay on: do NOT call gatedByQueue() here. It
     * is not a double count either, as RateLimited buckets on md5(limiterName.key) rather
     * than the raw 'rdn-api:{orgId}' the service layer uses.
     */
    public function handle(): void
    {
        if (config('app.env') === 'production') {
            Nightwatch::sample(rate: 0.3);
        }

        $this::fetch(RDN::make(org: $this->org, client: $this->client), $this->org);
    }

    abstract public static function fetch(RDN $rdn, Org $org): void;
}
