<?php

use App\Console\Commands;
use App\Jobs\Rdn\DispatchRdnMetricsHourly;
use Illuminate\Support\Facades\Schedule;

Schedule::command('queue:prune-batches --hours=72 --unfinished=144')
    ->daily()
    ->runInBackground()
    ->onOneServer();

Schedule::command('queue:prune-failed --hours=72')
    ->daily()
    ->runInBackground()
    ->onOneServer();

Schedule::command(Commands\GeocodeRecoveryAddresses::class)
    ->environments(['production'])
    ->when(fn () => config('app.env') === 'production' && \strtolower(config('app.name')) === 'platform')
    ->everyTwoHours()
    ->runInBackground()
    ->onOneServer();

Schedule::command(Commands\GenerateOrgCache::class)
    ->everyFourHours()
    ->runInBackground()
    ->onOneServer();

Schedule::command(Commands\GenerateRdnCache::class)
    ->environments(['production'])
    ->when(fn () => config('app.env') === 'production' && \strtolower(config('app.name')) === 'platform')
    ->dailyAt('5:00')
    ->runInBackground()
    ->onOneServer();

Schedule::command(Commands\AggregateGeoHashScanDaily::class)
    ->environments(['production'])
    ->when(fn () => config('app.env') === 'production' && \strtolower(config('app.name')) === 'platform')
    ->dailyAt('6:00')
    ->runInBackground()
    ->onOneServer();

Schedule::command(Commands\SendApprovedClientInviteMails::class)
    ->everyMinute()
    ->withoutOverlapping()
    ->runInBackground()
    ->onOneServer();

Schedule::job(new DispatchRdnMetricsHourly)
    ->environments(['production'])
    ->when(fn () => config('app.env') === 'production'
        && \strtolower(config('app.name')) === 'platform'
        && filled(config('services.rdn_metrics.queue_url')))
    ->hourlyAt(5)
    ->onOneServer();

Schedule::command(Commands\CloseInactiveShiftsCommand::class)
    ->when(fn () => config('app.experimental.clarity_scout', false))
    ->everyThirtyMinutes()
    ->withoutOverlapping()
    ->runInBackground()
    ->onOneServer();

Schedule::command(Commands\ScoutRecordCheckIns::class)
    ->when(fn () => config('app.env') === 'production'
        && \strtolower(config('app.name')) === 'platform'
        && config('app.experimental.clarity_scout', false))
    ->everyFiveMinutes()
    ->withoutOverlapping(10)
    ->runInBackground()
    ->onOneServer();
