<?php

namespace App\Providers;

use App\Enums\FeatureEnum;
use App\Models\User;
use Illuminate\Support\ServiceProvider;
use Laravel\Pennant\Feature;

class PennantServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Feature::define(FeatureEnum::SHARED_KITS->slug(), fn (User $user) => $user->isInsightLpr() || $user->isProEnabled(FeatureEnum::SHARED_KITS));

        Feature::define(FeatureEnum::INVITES->slug(), fn (User $user) => $user->isInsightLpr() || $user->isProEnabled(FeatureEnum::INVITES));

        Feature::define(FeatureEnum::SCAN_CLUSTERING->slug(), fn (User $user) => $user->isInsightLpr() || $user->isProEnabled(FeatureEnum::SCAN_CLUSTERING));

        Feature::define(FeatureEnum::REPO_HEATMAP->slug(), fn (User $user) => $user->isInsightLpr() || $user->isProEnabled(FeatureEnum::REPO_HEATMAP));

        Feature::define(FeatureEnum::MISSED_OPPORTUNITY->slug(), fn (User $user) => $user->isInsightLpr() || $user->isProEnabled(FeatureEnum::MISSED_OPPORTUNITY));

        Feature::define(FeatureEnum::FLEX_SEARCH->slug(), fn (User $user) => $user->isInsightLpr() || $user->isProEnabled(FeatureEnum::FLEX_SEARCH));

        Feature::define(FeatureEnum::ALERT_SETTINGS->slug(), fn (User $user) => $user->isInsightLpr() || $user->isProEnabled(FeatureEnum::ALERT_SETTINGS));

        Feature::define(FeatureEnum::CLIENT_ANALYTICS->slug(), fn (User $user) => $user->isInsightLpr() || $user->isProEnabled(FeatureEnum::CLIENT_ANALYTICS));

        Feature::define(FeatureEnum::CLICKHOUSE_SCANS->slug(), fn (User $user) => config('app.experimental.clickhouse.scans', false) && ($user->isInsightLpr() || $user->isProEnabled(FeatureEnum::CLICKHOUSE_SCANS)));

        Feature::define(FeatureEnum::CLICKHOUSE_HITS->slug(), fn (User $user) => config('app.experimental.clickhouse.hits', false) && ($user->isInsightLpr() || $user->isProEnabled(FeatureEnum::CLICKHOUSE_HITS)));

        Feature::define(FeatureEnum::AI_NOTIFICATIONS->slug(), fn (User $user) => $user->isInsightLpr() || $user->isProEnabled(FeatureEnum::AI_NOTIFICATIONS));

        Feature::define(FeatureEnum::LPR_VISITS->slug(), fn (User $user) => $user->isInsightLpr() || $user->isProEnabled(FeatureEnum::LPR_VISITS));

        Feature::define(FeatureEnum::CLEARPLAN_RESEND->slug(), fn (User $user) => config('app.experimental.clearplan', false) && ($user->isInsightLpr() || $user->isProEnabled(FeatureEnum::CLEARPLAN_RESEND)));

        Feature::define(FeatureEnum::HIT_ADDRESS_DISTANCE->slug(), fn (User $user) => $user->isInsightLpr() || $user->isProEnabled(FeatureEnum::HIT_ADDRESS_DISTANCE));

        Feature::define(FeatureEnum::CLARITY_SCOUT->slug(), fn (User $user) => config('app.experimental.clarity_scout', false) && ($user->isInsightLpr() || $user->isProEnabled(FeatureEnum::CLARITY_SCOUT)));

        Feature::define(FeatureEnum::CLARITY_SCOUT_TRIPS->slug(), fn () => config('app.experimental.clarity_scout_trips', false));

        Feature::define(FeatureEnum::HIT_WEBHOOK->slug(), fn (User $user) => $user->isInsightLpr() || $user->isProEnabled(FeatureEnum::HIT_WEBHOOK));
    }
}
