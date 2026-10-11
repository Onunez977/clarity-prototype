<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use InsightLPR\BlackAdam\Enum\RoleEnum;

class GateServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        Gate::define('admin', fn ($user) => $user->hasRole(RoleEnum::ADMIN->value));

        Gate::define('insight-lpr', function (User $user): bool {
            if (config('app.env') === 'local') {
                return true;
            }

            return $user->isInsightLpr() || session()->has('impersonator');
        });
    }
}
