<?php

namespace App\Filament\Tables\Filters;

use App\Enums\FeatureEnum;
use App\Facades\Analytics;
use App\Filament\Forms\Components\ProFeatureToggle;
use Laravel\Pennant\Feature;

class FlexSearchToggle extends ProFeatureToggle
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->proFeature(FeatureEnum::FLEX_SEARCH->slug());

        $this->proDescription('FlexSearch automatically generates multiple plate variations to eliminate potential OCR errors. Upgrade to PRO to unlock this feature.');

        $this->label('FlexSearch');

        $this->default(fn () => Feature::active(FeatureEnum::FLEX_SEARCH->slug()) || session()->has('admin_impersonator'));

        $this->inline();

        $this->offIcon('heroicon-o-x-mark');

        $this->onIcon('heroicon-o-magnifying-glass');

        $this->columnSpanFull();

        $this->afterStateUpdated(function ($state): void {
            Analytics::track('flex-search-toggle', auth()->user(), [
                'action' => 'toggle',
                'value' => $state ? 'on' : 'off',
            ]);
        });
    }
}
