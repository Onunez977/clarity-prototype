<?php

namespace App\Filament\Pages;

use App\Enums\FeatureEnum;
use App\Facades\Analytics;
use App\Filament\Concerns\HasProFeature;
use App\Filament\Tables\Filters\DateTogglesFilter;
use App\Filament\Widgets;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Pages\Dashboard\Concerns\HasFiltersForm;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Illuminate\Contracts\Support\Htmlable;
use InsightLPR\BlackAdam\Enum\RoleEnum;

class ClientAnalytics extends Page
{
    use Concerns\HasStatDrillDown;
    use HasFiltersForm;
    use HasProFeature;

    protected static string|null|\BackedEnum $navigationIcon = 'heroicon-o-window';

    protected static ?string $navigationLabel = 'Client Analytics';

    protected string $view = 'filament.pages.client-analytics';

    protected static ?int $navigationSort = 2;

    public function mount(): void
    {
        Analytics::track('page-view', auth()->user(), ['action' => 'view'], page: 'client-analytics');
    }

    protected function getAnalyticsPage(): string
    {
        return 'client-analytics';
    }

    public function getTitle(): string|Htmlable
    {
        return 'Client Analytics';
    }

    public function getSubheading(): string|Htmlable|null
    {
        return 'Hover over stat cards to see detailed breakdown.';
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('methodology')
                ->label('How "Client Analytics" Work')
                ->icon('heroicon-o-question-mark-circle')
                ->color('gray')
                ->extraAttributes(trackAttr('client-analytics', 'guide-link'))
                ->url(ClientAnalyticsGuide::getUrl()),
        ];
    }

    public function getFeatureName(): string|array
    {
        return FeatureEnum::CLIENT_ANALYTICS->slug();
    }

    public static function getNavigationBadge(): ?string
    {
        return 'Pro';
    }

    public function getColumns(): int|array
    {
        return [
            'default' => 1,
            'sm' => 2,
            'md' => 3,
            'lg' => 5,
        ];
    }

    public function getHeaderWidgetsColumns(): int|array
    {
        return $this->getColumns();
    }

    public function getWidgets(): array
    {
        return [];
    }

    public function getWidgetData(): array
    {
        return [
            'org_id' => auth()->user()->org_id,
            'start_date' => $this->filters['start'] ?? null,
            'end_date' => $this->filters['end'] ?? null,
            'date_type' => $this->filters['date_toggles'] ?? 'this_month',
            'branch_id' => $this->filters['branch_id'] ?? null,
        ];
    }

    public function getFooterWidgetsColumns(): int|array
    {
        return $this->getColumns();
    }

    public function getFooterWidgets(): array
    {
        if (self::canAccess()) {
            return [
                Widgets\Client\TotalAssignmentsWidget::class,
                Widgets\Client\TotalRepossessionsWidget::class,
                Widgets\Client\LprAssistsWidget::class,
                Widgets\Client\MissedOpportunitiesWidget::class,
                Widgets\Client\AverageRecoveryRateWidget::class,
                Widgets\Client\ClientTable::class,
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
            ->components(\array_merge([
                Forms\Components\Select::make('branch_id')
                    ->hiddenLabel()
                    ->placeholder('All Branches')
                    ->options(fn () => auth()->user()->org->branches->pluck('branch_name', 'branch_id')->toArray())
                    ->searchable()
                    ->extraAttributes(trackAttr('client-analytics', 'branch-filter')),

            ],
                DateTogglesFilter::make('date')
                    ->track('date-filter', 'client-analytics')
                    ->defaultDate('this_month')
                    ->dateToggles([
                        'this_month' => 'MTD',
                        'ytd' => 'YTD',
                    ])
                    ->bare()
                    ->colSpan(full: false)
                    ->showAdvanced(hasTable: false)
                    ->modifyQueryUsing(null)
                    ->dateTime(false)
                    ->getCustomSchema()
            ));
    }

    public function updatedFilters(): void
    {
        $dateType = match ($this->filters['date_toggles'] ?? 'this_month') {
            'this_month' => 'this_month',
            'ytd' => 'ytd',
            default => 'custom',
        };

        $this->dispatch('refresh-dashboard',
            branch_id: $this->filters['branch_id'] ?? null,
            start_date: $this->filters['start'] ?? null,
            end_date: $this->filters['end'] ?? null,
            date_type: $dateType,
        );
    }

    public static function canAccess(): bool
    {
        return auth()->user()->hasRole(RoleEnum::ADMIN->value);
    }

    public function mountCanAuthorizeAccess(): void
    {
        // Overridden: moved to mount() method.
    }
}
