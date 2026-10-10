---
type: Pattern
title: Jobs and Queues
description: Clarity's queue topology (Redis {low}/{default}/{high}/{otto} via Horizon), the RdnJob base + rate limiting, job batches, retries/timeouts, and serialize-IDs conventions. Corrected from the legacy .claude/docs/job-patterns.md.
resource: app/Jobs/Rdn/RdnJob.php
tags: [domain, lpr, jobs, queues, horizon, redis, patterns]
timestamp: 2026-08-12T16:40:00Z
---

# Jobs and Queues

Clarity runs background work on a **Redis** queue via **Laravel Horizon**. Logical queues include `{low}`, `{default}`, `{high}`, and `{otto}`, plus a rate-limited external-API job family (`RdnJob`) and `Bus::batch()` for fan-out. This page covers Clarity-specific conventions only.

> Supersedes the legacy `.claude/docs/job-patterns.md`. Queue names there were loose; the real names are Redis hash-tagged (`{low}`, `{default}`, `{high}`, `{otto}`).

## Queue topology

`config/queue.php` default connection is `redis` with base queue `{default}` (curly braces = Redis hash tag, required for Horizon/cluster-safe queue sharding). Horizon (`config/horizon.php`) runs supervisor profiles including:

| Supervisor | Consumes | Use |
|------------|----------|-----|
| `default` | `{default}`, `{high}` | general + priority jobs |
| (low) | `{low}` | bulk / background / external-API jobs |
| `supervisor-otto` | `{otto}` | Otto chat agent jobs (`RunOttoChat`) — see [otto.md](otto.md) |

Pick the queue by latency/criticality:

| Queue | When to use | Examples |
|-------|-------------|----------|
| `{low}` | Bulk, background, external-API, cache-warming — anything that can wait behind a large backlog | `LogFilterActivity`, `RefreshRecoveryViews`, `SyncDispositionToClickhouse`, `TrackAnalytics`, `GeocodeRecoveryAddress`, `ShareOrgKits`, all `Rdn\*` jobs, `Org\Cache*` |
| `{default}` | Normal user-facing async work | most dispatched jobs without an explicit `onQueue` |
| `{high}` | Priority / user-blocking | `FlushPennant` (clears feature-flag cache so a just-purchased Pro feature activates fast) |
| `{otto}` | Otto streaming chat | `RunOttoChat` — requires `QUEUE_CONNECTION=redis` locally (not `sync`) |

Always set the queue with the braces: `$this->onQueue('{low}');` — omitting them breaks cluster sharding.

## The `RdnJob` base (`app/Jobs/Rdn/RdnJob.php`)

Abstract base for all jobs that call the **RDN** (recovery-network) SOAP API. It encodes the safe external-API contract:

```php
abstract class RdnJob implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels, StatHelper;

    public $timeout = 600;
    public $backoff = 10;
    public $tries = 3;

    public function __construct(public int $orgId, public ?Org $org = null, public ?SoapClient $client = null)
    {
        $this->onQueue('{low}');
        $this->org ??= Org::find($this->orgId);
    }

    public function middleware(): array
    {
        return [new RateLimited('rdn-api')];
    }

    public function handle(): void
    {
        if (config('app.env') === 'production') { Nightwatch::sample(rate: 0.3); }
        $this::fetch(RDN::make(org: $this->org, client: $this->client), $this->org);
    }

    abstract public static function fetch(RDN $rdn, Org $org): void;
}
```

- **Rate limiting** — `RateLimited('rdn-api')`, defined in `app/Providers/AppServiceProvider.php`: `Limit::perMinute($job->org->meta->properties['rdn_rate_limit'])` keyed by `rdn-api:{org_id}`, or `Limit::none()` when the org has no per-org limit. So RDN calls are throttled **per org**, not globally.
- **Retries/timeouts** — `tries = 3`, `backoff = 10` (seconds between retries), `timeout = 600` (10 min, since SOAP calls can be slow).
- **Nightwatch sampling** — 30% in production to control telemetry volume.
- **Constructor takes IDs** — `int $orgId` (+ optional hydrated `Org`/`SoapClient`). Implement `fetch(RDN $rdn, Org $org): void`.

Concrete `RdnJob` subclasses (the per-org metadata pulls): `GetOrgRdnClients`, `GetOrgBranches`, `GetOrgBranchZips`, `GetOrgAgents`. The hotlist-case and RDN-metrics jobs (`GetOrgHotlistCases`, `GetOrgHotlistCaseInfo`, `DispatchRdnMetricsHourly`, `PublishRdnMetric`) implement `ShouldQueue` directly instead — see [RDN](rdn.md).

## Job batches

`Bus::batch([...])` fans out many small jobs. Batching metadata lives in `job_batches` on the `platform` connection (`config/queue.php` `batching`). Patterns in use:

- `app/Console/Commands/GenerateOrgCache.php`, `GenerateRdnCache.php`, `GeocodeRecoveryAddresses.php` — commands build a job array and `Bus::batch([...])->dispatch()`.
- `app/Jobs/Rdn/DispatchRdnMetricsHourly.php` — batches per-org `PublishRdnMetric` jobs (`->onQueue('{low}')`) that push hourly scan/hit counts back to RDN over SQS — see [RDN](rdn.md).
- Batchable jobs check `$this->batch()?->cancelled()` before doing work (`PublishRdnMetric`).

When writing a new fan-out job, prefer `Bus::batch()` over a single mega-job, and make the leaf jobs `Batchable` so cancellation propagates.

## Retries, timeouts, backoff — conventions

- External API jobs (`RdnJob`): `tries=3`, `backoff=10`, `timeout=600`.
- Redis `retry_after` is **300** seconds (`config/queue.php`) so a stalled worker re-queues after 5 minutes. It must stay greater than the longest job `$timeout` that Horizon actually runs (Otto `RunOttoChat` / `supervisor-otto` are 180s).
- Laravel 12.66+ requires `horizon:work` to declare `--stop-when-empty-for`. Horizon 5.46 does not; `App\Horizon\WorkCommand` shims it (PG-011). Remove the shim when `laravel/horizon` is >= 5.47.
- Set `$timeout` generously for any job doing network/ClickHouse work; never let a job exceed the worker timeout.
- Use `SerializesModels` (it's in `RdnJob` and standard) — but **prefer passing IDs over hydrated models** to job constructors (e.g. `RdnJob` takes `int $orgId`). Large model payloads inflate the Redis job store.

## Where it lives in code

- `RdnJob` base + RDN jobs: `app/Jobs/Rdn/*`
- Other jobs: `app/Jobs/*` (`LogFilterActivity`, `RefreshRecoveryViews`, `ShareOrgKits`, `SyncDispositionToClickhouse`, `TrackAnalytics`, `GeocodeRecoveryAddress`, `FlushPennant`, `UpdateUsPostalCodesWithGeocodioAndTigerJob`, `Org\CacheOrg*`)
- Rate limiter: `app/Providers/AppServiceProvider.php` (`rdn-api`)
- Config: `config/queue.php`, `config/horizon.php`
- Batch dispatchers: `app/Console/Commands/*`

## See also

- [RDN](rdn.md) — the `RdnJob` pull family and the RDN-metrics push jobs in full
- [ClearPlan](clearplan.md) — the outbound hit-replay jobs
- [Events and Observers](events-and-observers.md) — when to dispatch a job vs. fire an event vs. use an observer (`ShareOrgKits` is dispatched by `InviteObserver`)
- [Filament Conventions](filament-conventions.md) — `HasFilterActivityLogging` dispatches `LogFilterActivity`
- [Testing Conventions](testing-conventions.md) — faking queues/batches in tests
