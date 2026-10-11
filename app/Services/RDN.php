<?php

namespace App\Services;

use App\Dto\Rdn\AgentDto;
use App\Dto\Rdn\AgentInfoDto;
use App\Dto\Rdn\ClientDto;
use App\Models\Org;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\LazyCollection;

class RDN
{
    /** The shape call() answers with when RDN returned nothing. */
    private const EMPTY_RESULT = [
        'items' => [],
        'hasMore' => false,
        'totalPages' => 0,
    ];

    /**
     * True when the calling job makes exactly one SOAP call, so its RateLimited middleware
     * has already charged that call and the service-level limiter stands down. See
     * gatedByQueue().
     */
    public bool $queueGated = false;

    /** Whether the most recent call() reached RDN and got an answer. See lastCallCompleted(). */
    private bool $lastCallCompleted = false;

    /** The exception the most recent call() caught, if any. See lastCallException(). */
    private ?\Exception $lastCallException = null;

    public function __construct(
        public Org $org,
        public ?\SoapClient $client = null,
    ) {
        if ($this->org->rdn_api_key) {
            $this->org->loadMissing('meta');

            $url = config('services.rdn.api_url').'/?wsdl&api_key='.$this->org->rdn_api_key;

            $apiHeader = new \SoapHeader('ns', 'apiKey', (object) [
                'api_key' => $this->org->rdn_api_key,
            ]);

            try {
                $this->client ??= new \SoapClient($url, [
                    'trace' => true,
                    'exceptions' => true,
                ]);

                $this->client->__setSoapHeaders([$apiHeader]);
            } catch (\Exception $exception) {
                //
            }
        } else {
            Log::warning('RDN API Key not set', [
                'org_id' => $this->org->id,
                'org_name' => $this->org->name,
            ]);
        }
    }

    public static function make(Org $org, ?\SoapClient $client = null): self
    {
        return new static($org, $client);
    }

    /**
     * Hand throttling to the caller's RateLimited queue middleware. Only correct for a job
     * that makes exactly ONE SOAP call, because RateLimited charges once per JOB: for such
     * a caller the two layers charge the same event into two independent buckets (they
     * never shared one - RateLimited keys on md5(limiterName.key), not the raw
     * 'rdn-api:{orgId}' below), halving the effective rate, and only the middleware can
     * release an over-limit job. GetOrgHotlistCaseInfo is that caller.
     *
     * RdnJob and its four subclasses paginate - one job is many calls - so the middleware
     * charge is no per-call throttle at all and they keep this limiter, as do non-queued
     * callers (Org::getRdnClients()) with no middleware to be admitted by.
     */
    public function gatedByQueue(): self
    {
        $this->queueGated = true;

        return $this;
    }

    /**
     * Whether the last call() reached RDN, as opposed to never leaving the process.
     *
     * call() answers EMPTY_RESULT for both, and that stays true - toLazyCollection() ends
     * a book on it and Org::getRdnClients() caches on it, so widening the return type or
     * throwing would change five callers to fix one. Callers for which a blip and an empty
     * answer differ ask here instead: GetOrgHotlistCaseInfo spends one of a hotlist's
     * attempts per lookup and must not spend it on a SOAP fault, a TLS or DNS failure, a
     * connect timeout, or a rotated key.
     *
     * Read it immediately after the call it describes - it is per-instance and paginated
     * pulls overwrite it every page.
     */
    public function lastCallCompleted(): bool
    {
        return $this->lastCallCompleted;
    }

    /**
     * The exception the last call() caught, or null when it caught none.
     *
     * Kept so a caller can tell a per-case refusal from a transport failure, which
     * lastCallCompleted() cannot: both leave it false. Like lastCallCompleted(), read it
     * immediately after the call it describes. Never log or serialize it.
     */
    public function lastCallException(): ?\Exception
    {
        return $this->lastCallException;
    }

    public function call(string $method, array $args = [], int $page = 1): array
    {
        $this->lastCallCompleted = false;
        $this->lastCallException = null;

        return $this->makeRateLimitedCall(function () use ($method, $args, $page) {
            $pagerHeader = new \SoapHeader('ns', 'pager', (object) [
                'page' => $page,
                'per_page' => 100,
            ]);

            try {
                $response = $this->client?->__soapCall(name: $method, args: $args, inputHeaders: [$pagerHeader]);

                // A null client is a WSDL that never loaded, not an answer of none.
                $this->lastCallCompleted = $this->client !== null;
            } catch (\Exception $exception) {
                Log::error($exception->getMessage(), ['org_id' => $this->org->id]);

                $this->lastCallException = $exception;

                $response = null;
            }

            // If client is null or response is null, return empty result
            if (! $response) {
                return self::EMPTY_RESULT;
            }

            if ($page === 1) {
                $this->detectRateLimit();
            }

            $pager = $this->getPager();

            return [
                'items' => ($response->item ?? false)
                    ? (is_array($response->item) ? $response->item : [$response->item])
                    : [$response],
                'hasMore' => $pager && $pager['page'] < $pager['total_pages'],
                'totalPages' => $pager['total_pages'] ?? 1,
            ];
        });
    }

    private function getPager(): ?array
    {
        try {
            $xml = new \SimpleXMLElement($this->client?->__getLastResponse());

            // Register the namespaces
            $xml->registerXPathNamespace('SOAP-ENV', 'http://schemas.xmlsoap.org/soap/envelope/');
            $xml->registerXPathNamespace('ns1', config('services.rdn.api_url'));

            // Get the pager element
            return (array) $xml->xpath('//ns1:pager')[0];
        } catch (\Exception  $exception) {
            return null;
        }
    }

    private function detectRateLimit(): void
    {
        try {
            $headers = $this->client?->__getLastResponseHeaders();

            if (! $headers) {
                return;
            }

            // Parse headers to find X-RateLimit-Limit
            if (preg_match('/X-RateLimit-Limit:\s*(\d+)/i', $headers, $matches)) {
                $rateLimit = (int) $matches[1];

                if ($meta = $this->org->meta) {
                    // One case-info job is one page-1 call, so this used to fire once per
                    // hotlist - tens of thousands of identical read-modify-writes of the
                    // whole properties blob per day, each one a lost-update window against
                    // its other writers. RDN reports the same limit every time, so writing
                    // only on a change makes all but the first a no-op.
                    if (isset($meta->properties['rdn_rate_limit'])
                        && (int) $meta->properties['rdn_rate_limit'] === $rateLimit) {
                        return;
                    }

                    $meta->updateProperties([
                        'rdn_rate_limit' => $rateLimit,
                    ]);
                } else {
                    $this->org->meta()->create([
                        'properties' => [
                            'rdn_rate_limit' => $rateLimit,
                        ],
                    ]);
                }
            }
        } catch (\Exception $exception) {
            // Silently fail - rate limit detection is not critical
        }
    }

    /**
     * Typed : array, and it must stay that way. RateLimiter::attempt() answers false when
     * the bucket is full - returned straight out of call(): array that is a TypeError in
     * whatever job or request happened to make the call - and it also collapses a falsy
     * callback result to true. tooManyAttempts()/hit() is the same admission decision
     * without either hazard.
     *
     * Over-limit throws rather than answering EMPTY_RESULT: "throttled" is a control
     * condition and must not be readable as "RDN has no rows". toLazyCollection() would
     * end a paginated pull mid-book, and Org::getRdnClients() would cache the empty list
     * until end of day, so a web caller gets a 429 instead of a 500 or a silently short
     * list. Single-call queued jobs opt out of this - see gatedByQueue().
     */
    private function makeRateLimitedCall(callable $callback): array
    {
        $rateLimit = $this->queueGated
            ? null
            : $this->org->meta?->properties['rdn_rate_limit'] ?? null;

        if (! $rateLimit) {
            return $callback();
        }

        $key = 'rdn-api:'.$this->org->id;

        if (RateLimiter::tooManyAttempts($key, $rateLimit)) {
            throw new ThrottleRequestsException(
                'RDN API rate limit of '.$rateLimit.'/min reached for org '.$this->org->id.'.'
            );
        }

        RateLimiter::hit($key);

        return $callback();
    }

    public function toLazyCollection(string $method, $format = null, array $args = []): LazyCollection
    {
        return LazyCollection::make(function () use ($method, $format, $args) {
            $currentPage = 1;
            $hasMore = true;

            while ($hasMore) {
                $result = $this->call($method, $args, $currentPage);

                $items = collect($result['items'])
                    ->map(fn ($item) => $format ? $format($item) : $item);

                foreach ($items as $item) {
                    if ($item !== null) {
                        yield $item;
                    }
                }

                $hasMore = $result['hasMore'];
                $currentPage++;
            }
        });
    }

    public function getClientList(): LazyCollection
    {
        return $this->toLazyCollection(
            method: 'getClientList',
            format: fn ($item) => is_object($item) || is_array($item)
                ? ClientDto::fromObject(\gettype($item) === 'array' ? (object) $item : $item)
                : null,
        );
    }

    public function getAgents(string $type = 'Company Adjuster'): LazyCollection
    {
        return $this->toLazyCollection(
            method: 'getAgents',
            format: fn ($item) => is_object($item) || is_array($item)
                ? AgentDto::fromObject(\gettype($item) === 'array' ? (object) $item : $item)
                : null,
            args: ['type' => $type],
        );
    }

    public function getAgentInfo(int $agentId): ?AgentInfoDto
    {
        $result = $this->call(
            method: 'getAgentInfo',
            args: ['agent_id' => $agentId],
        );

        $item = $result['items'][0] ?? null;

        if (! $item || (! is_object($item) && ! is_array($item))) {
            return null;
        }

        try {
            return AgentInfoDto::fromObject(\gettype($item) === 'array' ? (object) $item : $item);
        } catch (\Exception $exception) {
            Log::error('Failed to parse agent info', [
                'org_id' => $this->org->id,
                'agent_id' => $agentId,
            ]);

            return null;
        }
    }

    public function getBranchZips(int $branchId): array
    {
        $result = $this->call(
            method: 'getBranchZips',
            args: ['branch_id' => $branchId],
        );

        $items = $result['items'] ?? [];

        // getBranchZips returns a flat array of zip code strings
        return collect($items)
            ->filter(fn ($item) => is_string($item))
            ->values()
            ->all();
    }

    public function updateCaseCollateral(int $caseId, array $collateral): array
    {
        return $this->call(
            method: 'updateCaseCollateral',
            args: [
                'case_id' => $caseId,
                'collateral' => (object) $collateral,
            ],
        );
    }
}
