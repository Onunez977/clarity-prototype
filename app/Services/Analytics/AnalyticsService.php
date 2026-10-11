<?php

namespace App\Services\Analytics;

use App\Dto\AnalyticsDataDto;
use App\Jobs\TrackAnalytics;
use App\Models\User;

class AnalyticsService
{
    public function track(string $feature, ?User $user = null, array $params = [], ?string $page = null): void
    {
        // Two impersonation checks: the session key covers web/Filament impersonation,
        // the token check covers the stateless Scout bearer path where no session exists.
        if (session()->has('impersonator') || $user?->isScoutForceImpersonator() || ! config('database.track_analytics') || ! config('database.use_clickhouse')) {
            return;
        }

        $analyticsData = AnalyticsDataDto::fromArray($params);
        $analyticsData->isPro = $user?->isProEnabled() ?? false;

        dispatch(new TrackAnalytics(
            $feature,
            $user?->id,
            $user?->org_id,
            $analyticsData,
            $page,
        ));
    }
}
