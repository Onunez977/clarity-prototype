<?php

namespace App\Providers;

use App\Clickhouse\Logging\LoggingClickhouseConnection;
use App\Models\Hit;
use App\Models\Hotlist;
use App\Models\Integrations;
use App\Models\Invite;
use App\Models\Kit;
use App\Models\MissedOpportunity;
use App\Models\Org;
use App\Models\Scan;
use App\Models\User;
use App\Observers\InviteObserver;
use App\Policies\ActivityPolicy;
use App\Services\Analytics\AnalyticsService;
use App\Support\Prototype\ClickhouseSqlCompat;
use DataDog\DogStatsd;
use Filament\Auth\Http\Responses\LoginResponse;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Spatie\Activitylog\ActivityLogger;
use Spatie\Activitylog\Models\Activity;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(
            LoginResponse::class,
            \App\Http\Responses\Auth\LoginResponse::class
        );

        $this->app->singleton(AnalyticsService::class);

        // Library default is 2, which formats a positive sub-cent count such as
        // clarity.otto_estimated_cost_usd ($0.00242) as the sample "0" before
        // the |c packet is sent. sum().as_count() then reads $0.00. Integer
        // counts (token totals, message = 1) are unchanged after trailing-zero trim.
        $this->app->singleton(DogStatsd::class, function (): DogStatsd {
            return new DogStatsd([
                'decimal_precision' => 8,
            ]);
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Honor FORCE_HTTPS so isolated cloud / local `artisan serve` (plain HTTP)
        // can keep redirects and absolute URLs on http://. Production keeps the
        // default FORCE_HTTPS=true → https scheme.
        if (config('app.force_https')) {
            URL::forceScheme('https');
        }

        $this->rewriteClickhouseSqlForPostgres();

        Relation::morphMap([
            'org' => Org::class,
            'user' => User::class,
            'hotlist' => Hotlist::class,
            'kit' => Kit::class,
            'scan' => Scan::class,
            'hit' => Hit::class,
            'missed_opportunity' => MissedOpportunity::class,
            'activity_log' => Activity::class,
            'invite' => Invite::class,
            'integrations' => Integrations::class,
        ]);

        Gate::policy(Activity::class, ActivityPolicy::class);
        $this->app->bind(ActivityLogger::class, \App\Services\ActivityLogger::class);

        Invite::observe(InviteObserver::class);

        DB::macro('clickhouse', function (string $table, string $connection = 'clarity', bool $final = false, ?string $as = null): Builder {
            $connectionName = $connection === 'zod' ? 'public' : $connection;
            $driver = config('database.connections.'.$connectionName.'_clickhouse.driver');

            // FINAL keyword only applies to ClickHouse
            $tableName = ($final && $driver === 'clickhouse') ? $table.' FINAL' : $table;

            if ($driver === 'pgsql' && $connection === 'clarity') {
                $tableName = 'clarity.'.$tableName;
            }

            return DB::connection($connectionName.'_clickhouse')
                ->table(DB::raw($tableName.($as !== null ? (' AS '.$as) : '')));
        });

        // Shared by GetOrgBranches, GetOrgRdnClients, GetOrgAgents and GetOrgBranchZips,
        // which all still budget retries with $tries. This admits the JOB - those jobs
        // paginate, so their per-CALL throttle stays in RDN::makeRateLimitedCall(), on a
        // separate bucket (RateLimited keys on md5(limiterName.key)). Opting in is explicit.
        RateLimiter::for('rdn-api', function (object $job) {
            $rateLimit = $job->org->meta?->properties['rdn_rate_limit'] ?? null;

            return $rateLimit
                ? Limit::perMinute($rateLimit)->by('rdn-api:'.$job->org->id)
                : Limit::none();
        });

        // GetOrgHotlistCaseInfo only. One job is exactly one SOAP call, so this per-job
        // charge IS the per-call throttle and the job stands the service-level one down. It
        // needs a default ceiling for orgs RDN never reported a limit for, and it budgets
        // retries with retryUntil() so limiter releases cannot exhaust it.
        RateLimiter::for('rdn-case-info', function (object $job) {
            return Limit::perMinute($job->org->rdnRateLimit())->by('rdn-case-info:'.$job->org->id);
        });

        // Last web route: unknown paths → Filament login. Registered in booted()
        // so it runs AFTER withBroadcasting()'s Broadcast::routes() (also booted,
        // but registered earlier during Application::configure). See PG-033.
        // Exclude Horizon so /horizon is never stolen if route order drifts.
        $this->app->booted(function () {
            Route::middleware('web')->group(function () {
                Route::any('{any}', fn () => redirect('/app/login'))
                    ->where('any', '^(?!horizon(?:/|$)|broadcasting(?:/|$)|__qa(?:/|$)).*');
            });
        });

        // ClickhouseServiceProvider boots later and registers the stock client.
        // booted() runs after every provider, so this extend wins. Connections
        // resolved earlier in boot are not used; the first query resolves here.
        $this->app->booted(function () {
            $this->app->make('db')->extend('clickhouse', function (array $config, string $name) {
                $config['name'] = $name;

                return LoggingClickhouseConnection::createWithClient($config);
            });
        });
    }

    /**
     * Postgres stand-in for ClickHouse understands neither ClickHouse aggregates
     * nor GROUP BY … WITH ROLLUP. Rewrite those statements on the pgsql driver only.
     */
    private function rewriteClickhouseSqlForPostgres(): void
    {
        if (config('database.connections.clarity_clickhouse.driver') !== 'pgsql') {
            return;
        }

        // ClickHouse LIMIT n BY col keeps n rows per group. Postgres has no
        // equivalent on the query builder; returning the filtered rows is enough
        // for the prototype lists (plates may repeat).
        EloquentBuilder::macro('limitBy', function (int $limit, string $column) {
            return $this;
        });

        foreach (['clarity_clickhouse', 'clarity_clickhouse_write', 'public_clickhouse'] as $name) {
            try {
                DB::connection($name)->beforeExecuting(function (string &$query): void {
                    $query = ClickhouseSqlCompat::rewrite($query);
                });
            } catch (\Throwable) {
                // Connection is unavailable during some package-discovery boots.
            }
        }
    }
}
