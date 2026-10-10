<?php

namespace App\Filament\Widgets\Client\Lifetime;

use App\Clickhouse\Models\MissedOpportunity;
use App\Enums\FeatureEnum;
use App\Filament\Widgets\Client\Concerns\LifetimeStatWidget;
use App\Policies\MissedOpportunityPolicy;
use Laravel\Pennant\Feature;

class LifetimeMissedOpportunitiesWidget extends LifetimeStatWidget
{
    protected function getLabel(): string
    {
        return 'Lifetime Missed Opportunities';
    }

    protected function getModelQuery(): int
    {
        return (int) MissedOpportunity::query()
            ->forOrg($this->org_id)
            ->between($this->getStartDate(), $this->getEndDate())
            ->forBranch($this->branch_id)
            ->forClient($this->client_org_id)
            ->selectRaw('countDistinct(hotlist_id) as total')
            ->value('total') ?? 0;
    }

    public static function canView(): bool
    {
        if (config('app.env') === 'production') {
            return Feature::active(FeatureEnum::MISSED_OPPORTUNITY->slug()) || session()->has('admin_impersonator');
        }

        return (new MissedOpportunityPolicy)->viewAny(auth()->user());
    }

    protected function getTooltipDescription(): ?string
    {
        return 'All-time missed opportunities across all clients.';
    }
}
