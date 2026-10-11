<?php

namespace App\Filament\Pages;

use App\Enums\FeatureEnum;
use App\Facades\Analytics;
use App\Filament\Concerns\HasProFeature;
use App\Filament\Tables\Filters\DateTogglesFilter;
use App\Filament\Widgets;
use App\Models\Org;
use App\Support\Filament\FilamentRouteRedirect;
use Filament\Pages\Dashboard\Concerns\HasFiltersForm;
use Filament\Pages\Page;
use Filament\Panel;
use Filament\Schemas\Schema;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\HtmlString;
use InsightLPR\BlackAdam\Enum\RoleEnum;
use Laravel\Pennant\Feature;

class IndividualClientAnalytics extends Page
{
    use Concerns\HasStatDrillDown;
    use FilamentRouteRedirect;
    use HasFiltersForm;
    use HasProFeature;

    public int $clientId;

    protected static bool $shouldRegisterNavigation = false;

    protected string $view = 'filament.pages.individual-client-analytics';

    public static function getRoutePath(Panel $panel): string
    {
        return '/client-dashboard/{clientId}';
    }

    protected function getAnalyticsPage(): string
    {
        return 'individual-client-analytics';
    }

    public function mount(int $clientId): void
    {
        $this->clientId = $clientId;

        if (! auth()->user()->org->hasHotlistClient($this->clientId)) {
            $this->redirect(Dashboard::getUrl(panel: 'app'));
        }

        Analytics::track('page-view', auth()->user(), ['action' => 'view'], page: 'individual-client-analytics');
        Analytics::track('view-client', auth()->user(), ['action' => 'click', 'client_id' => $this->clientId], page: 'client-analytics');

        $this->dispatch('refresh-dashboard',

            branch_id: null,
            start_date: null,
            end_date: null,
            date_type: 'this_month',
            client_org_id: $this->clientId
        );
    }

    public function getTitle(): string|Htmlable
    {
        $client = Org::find($this->clientId);

        return $client?->name ?? 'Client';
    }

    public function getSubheading(): string|Htmlable|null
    {
        return new HtmlString('<a href="'.ClientAnalytics::getUrl(panel: 'app').'">&larr; Back to Client Analytics</a>');
    }

    public function getFeatureName(): string|array
    {
        return FeatureEnum::CLIENT_ANALYTICS->slug();
    }

    public function getHeaderWidgetsColumns(): int|array
    {
        return [
            'default' => 1,
            'md' => 2,
        ];
    }

    public function getHeaderWidgets(): array
    {
        if (self::canAccess()) {
            return [
                Widgets\Client\CurrentOpenAssignmentsWidget::class,
                Widgets\Client\ThirtyDayRecoveryRateWidget::class,
            ];
        }

        return [];
    }

    public function getWidgets(): array
    {
        return [];
    }

    public function getFooterWidgetsColumns(): int|array
    {
        return [
            'default' => 1,
            'md' => 2,
            'lg' => 4,
        ];
    }

    public function getWidgetData(): array
    {
        $start_date = $this->filters['start'] ?? null;
        $end_date = $this->filters['end'] ?? null;

        if ($start_date === null || $end_date === null) {
            $dateRange = $this->getDateRangeForType('this_month');
            $start_date = $dateRange['start']->toDateString();
            $end_date = $dateRange['end']->toDateString();
        }

        return [
            'org_id' => auth()->user()->org_id,
            'start_date' => $start_date,
            'end_date' => $end_date,
            'client_org_id' => $this->clientId,
            'date_type' => 'this_month',
        ];
    }

    public function getFooterWidgets(): array
    {
        if (self::canAccess()) {
            return [
                Widgets\Client\TotalClientAssignmentsWidget::class,
                Widgets\Client\TotalAcceptedWidget::class,
                Widgets\Client\TotalRepossessionsWidget::class,
                Widgets\Client\LprAssistsWidget::class,
                Widgets\Client\AverageRecoveryRateWidget::class,
                Widgets\Client\TotalDeclinedWidget::class,
                Widgets\Client\DaysToRepoWidget::class,
                Widgets\Client\MissedOpportunitiesWidget::class,
                Widgets\Client\AssignmentBreakdownChart::class,
                Widgets\Client\DaysToRepoChart::class,
                Widgets\Client\HistoricalCoverageBreakdownChart::class,
            ];
        }

        return [];
    }

    public function filtersForm(Schema $schema): Schema
    {
        return $schema
            ->columns([
                'default' => 1,
                'lg' => 2,
            ])
            ->components(
                DateTogglesFilter::make('date')
                    ->defaultDate('this_month')
                    ->dateToggles([
                        'this_month' => 'MTD',
                        'ytd' => 'YTD',
                        'last_30d' => 'Last 30d',
                        'last_90d' => 'Last 90d',
                    ])
                    ->bare()
                    ->colSpan(full: false)
                    ->showAdvanced(hasTable: false)
                    ->modifyQueryUsing(null)
                    ->getCustomSchema()
            );
    }

    public function updatedFilters(): void
    {
        $dateType = match ($this->filters['date_toggles'] ?? 'this_month') {
            'this_month' => 'this_month',
            'ytd' => 'ytd',
            'last_30d' => 'last_30d',
            'last_90d' => 'last_90d',
            default => 'custom',
        };

        $this->dispatch('refresh-dashboard',
            branch_id: $this->filters['branch_id'] ?? null,
            start_date: $this->filters['start'] ?? null,
            end_date: $this->filters['end'] ?? null,
            date_type: $dateType,
            client_org_id: $this->clientId
        );
    }

    public static function canAccess(): bool
    {
        return auth()->user()->hasRole(RoleEnum::ADMIN->value)
            && (Feature::active(FeatureEnum::CLIENT_ANALYTICS->slug()) || session()->has('admin_impersonator'));
    }

    public function mountCanAuthorizeAccess(): void
    {
        // Overridden: moved to mount() method.
    }
}
