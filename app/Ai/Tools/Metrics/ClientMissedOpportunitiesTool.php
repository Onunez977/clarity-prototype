<?php

declare(strict_types=1);

namespace App\Ai\Tools\Metrics;

use App\Enums\FeatureEnum;
use App\Filament\Widgets\Client\MissedOpportunitiesWidget;

class ClientMissedOpportunitiesTool extends ClientMetricTool
{
    protected function widget(): string
    {
        return MissedOpportunitiesWidget::class;
    }

    protected function periods(bool $forClient): array
    {
        return $forClient ? ['this_month', 'ytd', 'last_30d', 'last_90d'] : ['this_month', 'ytd'];
    }

    protected function widgetFeature(): ?FeatureEnum
    {
        return FeatureEnum::MISSED_OPPORTUNITY;
    }
}
