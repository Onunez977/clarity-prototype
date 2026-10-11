<?php

namespace App\Filament\Pages;

use App\Enums\FeatureEnum;
use App\Facades\Analytics;
use Filament\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\HtmlString;
use InsightLPR\BlackAdam\Enum\RoleEnum;
use Laravel\Pennant\Feature;

class ClientAnalyticsGuide extends Page
{
    protected string $view = 'filament.pages.client-analytics-guide';

    protected static bool $shouldRegisterNavigation = false;

    public function mount(): void
    {
        Analytics::track('page-view', auth()->user(), ['action' => 'view'], page: 'client-analytics-guide');
    }

    public function getTitle(): string|Htmlable
    {
        return 'Client Analytics Guide';
    }

    public function getSubheading(): string|Htmlable|null
    {
        return new HtmlString('<a href="'.ClientAnalytics::getUrl(panel: 'app').'">&larr; Back to Client Analytics</a>');
    }

    public static function canAccess(): bool
    {
        return auth()->user()->hasRole(RoleEnum::ADMIN->value)
            && (Feature::active(FeatureEnum::CLIENT_ANALYTICS->slug()) || session()->has('admin_impersonator'));
    }
}
