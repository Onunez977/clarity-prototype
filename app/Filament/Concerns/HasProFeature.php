<?php

namespace App\Filament\Concerns;

use App\Filament\Pages\Subscription;
use InsightLPR\BlackAdam\Enum\RoleEnum;
use Laravel\Pennant\Feature;

trait HasProFeature
{
    public function bootHasProFeature(): void
    {
        if (
            session()->missing('admin_impersonator')
            && ! auth()->user()?->hasRole(RoleEnum::CLIENT->value)
            && $this->getFeatureName() !== ''
            && $this->getFeatureName() !== []
        ) {
            $featureName = $this->getFeatureName();
            if (
                (is_string($featureName) && ! Feature::active($featureName))
                || (is_array($featureName) && ! Feature::allAreActive($featureName))
            ) {
                $source = is_array($featureName) ? $featureName[0] : $featureName;
                $sources = session('upgrade_sources', []);
                if (! in_array($source, $sources, true)) {
                    session()->push('upgrade_sources', $source);
                }
                $this->redirect(Subscription::getUrl());
            }
        }
    }

    abstract public function getFeatureName(): string|array;
}
