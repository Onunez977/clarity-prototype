<?php

namespace App\Providers;

use App\Horizon\WorkCommand;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Laravel\Horizon\Console\WorkCommand as HorizonWorkCommand;
use Laravel\Horizon\Horizon;
use Laravel\Horizon\HorizonApplicationServiceProvider;

class HorizonServiceProvider extends HorizonApplicationServiceProvider
{
    public function register(): void
    {
        parent::register();

        $this->app->bind(HorizonWorkCommand::class, function ($app): WorkCommand {
            return new WorkCommand($app['queue.worker'], $app['cache.store']);
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        parent::boot();

        // Horizon::routeSmsNotificationsTo('15556667777');
        // Horizon::routeMailNotificationsTo('example@example.com');
        // Horizon::routeSlackNotificationsTo('slack-webhook-url', '#channel');
    }

    /**
     * Configure the Horizon authorization services.
     *
     * Previews keep APP_ENV=preprod, so the package default (local OR the
     * viewHorizon gate) would 403 every dashboard request. Authenticated
     * preview users may open the UI; production / QA stay on the gate.
     */
    protected function authorization(): void
    {
        $this->gate();

        Horizon::auth(function (Request $request): bool {
            if (app()->environment('local')) {
                return true;
            }

            if (config('horizon.preview') && $request->user() !== null) {
                return true;
            }

            return Gate::check('viewHorizon', [$request->user()]);
        });
    }

    /**
     * Register the Horizon gate.
     *
     * This gate determines who can access Horizon in non-local environments.
     */
    protected function gate(): void
    {
        Gate::define('viewHorizon', fn (?User $user = null): bool => $user?->isInsightLpr() || session()->has('admin_impersonator') || session()->has('backyard_horizon'));
    }
}
