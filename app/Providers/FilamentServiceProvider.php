<?php

namespace App\Providers;

use App\Filament\Client\Resources\ClientSearchScansResource\Pages\ListClientSearchScans;
use App\Filament\Resources\ActivityResource\Pages\ListActivities;
use App\Filament\Resources\AffiliateStatsResource\Pages\ListAffiliateStats;
use App\Filament\Resources\HitsResource\Pages\ListHits;
use App\Filament\Resources\HotlistResource\Pages\ListHotlists;
use App\Filament\Resources\InviteResource\Pages\ListInvites;
use App\Filament\Resources\MissedOpportunityResource\Pages\ListMissedOpportunities;
use App\Filament\Resources\ScansResource\Pages\ListScans;
use App\Filament\Resources\ScoutUsersResource\Pages\ListScoutUsers;
use App\Filament\Resources\UsersResource\Pages\ListUsers;
use App\Filament\Resources\ZoneResource\Pages\ListZones;
use Filament\Actions\Action;
use Filament\Support\Assets\Js;
use Filament\Support\Facades\FilamentAsset;
use Filament\Support\Facades\FilamentView;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Enums\RecordActionsPosition;
use Filament\Tables\Table;
use Filament\Tables\View\TablesRenderHook;
use Filament\View\PanelsRenderHook;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;

class FilamentServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Table::configureUsing(function (Table $table): void {
            $table
                ->striped()
                ->poll(null)
                ->deferFilters(config('app.env') !== 'testing')
                ->deferLoading(config('app.env') !== 'testing')
                ->selectCurrentPageOnly()
                ->hiddenFilterIndicators()
                ->paginationPageOptions([50, 100, 250])
                ->recordActionsPosition(RecordActionsPosition::BeforeColumns)
                ->filtersLayout(FiltersLayout::AboveContentCollapsible)
                ->filtersTriggerAction(fn (Action $action) => $action->label('Filters')->button())
                ->filtersApplyAction(fn (Action $action) => $action
                    ->label('Search')
                    ->keyBindings(['enter'])
                    ->after(function ($livewire) {
                        if (method_exists($livewire, 'trackFilterAnalytics')) {
                            $livewire->trackFilterAnalytics();
                        }
                    })
                )
                ->columnManagerTriggerAction(fn (Action $action) => $action
                    ->label('Toggle Columns')
                    ->tooltip('Toggle Column Visibility')
                    ->button()
                );
        });

        FilamentAsset::register([
            Js::make('appjs', Vite::asset('resources/js/app.js'))->module(),
        ]);

        FilamentView::registerRenderHook(
            PanelsRenderHook::HEAD_END,
            fn (): string => Blade::render('filament.hooks.analytics'),
        );

        FilamentView::registerRenderHook(
            PanelsRenderHook::BODY_START,
            fn (): string => Blade::render('filament.hooks.impersonate'),
        );

        FilamentView::registerRenderHook(
            PanelsRenderHook::CONTENT_END,
            fn (): string => Blade::render('filament.hooks.footer'),
        );

        // Resource must have a Bulk Action or a "selectable" method.
        FilamentView::registerRenderHook(
            TablesRenderHook::SELECTION_INDICATOR_ACTIONS_AFTER,
            fn (): string => Blade::render('filament.hooks.selectable-map-record'),
            scopes: [
                ListScans::class,
                ListHits::class,
            ],
        );

        FilamentView::registerRenderHook(
            PanelsRenderHook::PAGE_FOOTER_WIDGETS_BEFORE,
            fn (): string => Blade::render('filament.hooks.scans.modal-popups'),
            scopes: [
                ListScans::class,
                ListHits::class,
                ListMissedOpportunities::class,
            ],
        );

        $filterableResources = [
            ListClientSearchScans::class,
            ListInvites::class,
            ListScans::class,
            ListHits::class,
            ListHotlists::class,
            ListMissedOpportunities::class,
            ListAffiliateStats::class,
            ListUsers::class,
            ListActivities::class,
            ListScoutUsers::class,
            ListZones::class,
        ];

        // Resource must have at least 1 "toggleable" column.
        FilamentView::registerRenderHook(
            TablesRenderHook::TOOLBAR_START,
            fn (): string => Blade::render('filament.hooks.sidebar-filters-toggle'),
            scopes: $filterableResources,
        );

        FilamentView::registerRenderHook(
            PanelsRenderHook::RESOURCE_PAGES_LIST_RECORDS_TABLE_BEFORE,
            fn (): string => Blade::render('filament.hooks.sidebar-filters'),
            scopes: $filterableResources,
        );

        FilamentView::registerRenderHook(
            PanelsRenderHook::RESOURCE_PAGES_LIST_RECORDS_TABLE_AFTER,
            fn (): string => Blade::render('filament.hooks.cluster-view'),
            scopes: [
                ListScans::class,
                ListHits::class,
            ],
        );
    }
}
