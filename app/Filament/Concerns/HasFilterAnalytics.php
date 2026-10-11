<?php

namespace App\Filament\Concerns;

use App\Facades\Analytics;

trait HasFilterAnalytics
{
    public function trackFilterAnalytics(): void
    {
        if (! property_exists($this, 'trackedFilters') || empty($this->trackedFilters)) {
            return;
        }

        $user = auth()->user();

        $page = property_exists($this, 'analyticsPage') ? $this->analyticsPage : null;

        foreach ($this->trackedFilters as $statePath => $featureName) {
            $value = data_get($this->tableFilters, $statePath);

            if ($this->isFilterValueActive($value)) {
                Analytics::track($featureName, $user, ['action' => 'filter-applied'], $page);
            }
        }

        $this->trackConditionalFilterAnalytics();
    }

    protected function trackConditionalFilterAnalytics(): void
    {
        // Override in pages for compound/conditional filter tracking.
    }

    protected function isFilterValueActive(mixed $value): bool
    {
        if ($value === null || $value === '' || $value === false) {
            return false;
        }

        return ! (is_array($value) && empty($value));
    }
}
