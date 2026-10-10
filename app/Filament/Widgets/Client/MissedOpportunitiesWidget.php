<?php

namespace App\Filament\Widgets\Client;

use App\Clickhouse\Models as Clickhouse;
use App\Enums\FeatureEnum;
use App\Filament\Widgets\Client\Concerns\ClientStatWidget;
use App\Policies\MissedOpportunityPolicy;
use Laravel\Pennant\Feature;

class MissedOpportunitiesWidget extends ClientStatWidget
{
    protected function getLabel(): string
    {
        return 'Missed Opportunities';
    }

    protected function getDrillDownKey(): ?string
    {
        return 'missedOpportunities';
    }

    protected function getBatchCacheKey(): ?string
    {
        return 'missed';
    }

    public function getModelQueryForDates(string $startDate, string $endDate): int
    {
        return (int) Clickhouse\MissedOpportunity::query()
            ->forOrg($this->org_id)
            ->between($startDate, $endDate, $this->getTimezone())
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
        return 'Vehicles scanned but not recovered before assignment ended for the selected time range.';
    }
}
