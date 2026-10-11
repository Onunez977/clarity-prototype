<?php

namespace App\Facades;

use App\Services\Analytics\AnalyticsService;
use Illuminate\Support\Facades\Facade;

/**
 * @method static void track(string $feature, ?\App\Models\User $user = null, array $params = [], ?string $page = null)
 */
class Analytics extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return AnalyticsService::class;
    }
}
