<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Laravel\Nightwatch\Facades\Nightwatch;
use Laravel\Nightwatch\Records\CacheEvent;
use Laravel\Nightwatch\Records\Query;

class NightWatchServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        Nightwatch::rejectCacheEvents(function (CacheEvent $cacheEvent) {
            return in_array($cacheEvent->key, [
                'illuminate:foundation:down',
                'illuminate:queue:restart',
                'illuminate:schedule:interrupt',
            ]);
        });

        Nightwatch::rejectQueries(function (Query $query) {
            return str_contains($query->sql, 'into "jobs"');
        });

        Nightwatch::rejectQueries(function (Query $query) {
            return str_contains($query->sql, ' from "cache"')
                || str_contains($query->sql, ' into "cache"');
        });
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        //
    }
}
