<?php

namespace App\Filament\Pages;

use App\Enums\FeatureEnum;
use App\Facades\Analytics;
use App\Filament\Forms\Components\ProFeatureToggle;
use App\Filament\Tables\Filters\KitUsersFilter;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Pages\Dashboard\Concerns\HasFiltersForm;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Geocodio\Geocodio;
use Illuminate\Contracts\Support\Htmlable;
use InsightLPR\BlackAdam\Enum\PermissionEnum;
use Livewire\Attributes\On;

class KitUserPath extends Page
{
    use HasFiltersForm;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-map';

    protected static string|\UnitEnum|null $navigationGroup = 'Geo-Spatial';

    protected string $view = 'filament.pages.kit-user-path';

    public function getHeading(): string|Htmlable
    {
        return '';
    }

    public function mount(): void
    {
        Analytics::track('page-view', auth()->user(), ['action' => 'view'], page: 'kit-user-path');
    }

    public ?string $date_toggles = '7_days';

    public ?string $centeredAddress = null;

    public ?string $address = null;

    public ?array $kits = [];

    public bool $loadGeohash = false;

    public bool $loadStagingGeohash = false;

    public ?array $heatmapTiers = ['tier_1', 'tier_2_4', 'tier_5_9', 'tier_10_24', 'tier_25_plus'];

    public ?array $stagingHeatmapTiers = ['tier_1', 'tier_2_4', 'tier_5_9', 'tier_10_24', 'tier_25_plus'];

    public bool $loadHitDensity = false;

    public ?array $hitDensityTiers = ['hit_tier_1', 'hit_tier_2_5', 'hit_tier_6_15', 'hit_tier_16_50', 'hit_tier_51_plus'];

    #[On('map-loaded')]
    public function load(): void
    {
        // Skip if no kits selected.
        if (! $this->kits) {
            return;
        }

        $this->dispatch('load-data',
            kits: $this->kits,
            date_toggles: $this->date_toggles,
            address: $this->address,
        );
    }

    public function getFiltersForm(): Schema
    {
        if ((! $this->isCachingSchemas) && $this->hasCachedSchema('filtersForm')) {
            return $this->getSchema('filtersForm');
        }

        return $this->filtersForm($this->makeSchema()
            ->components([
                Forms\Components\TextInput::make('address')
                    ->label('Center to Address')
                    ->placeholder('Address...')
                    ->extraInputAttributes(trackAttr('kit-user-path', 'address-filter')),

                KitUsersFilter::make('kits')
                    ->label('Kit User')
                    ->multiple()
                    ->placeholder('Select Kit Users')
                    ->helperText('Please select at least one kit user.')
                    ->options(
                        auth()->user()->getUserKits(withDeleted: false)->pluck('name', 'id')
                    )
                    ->extraAttributes(trackAttr('kit-user-path', 'kits-filter')),

                ProFeatureToggle::make('loadGeohash')
                    ->proFeature(FeatureEnum::REPO_HEATMAP->slug())
                    ->proDescription('Repo Heatmap visualizes address density from your hotlists on the map. Upgrade to PRO to unlock this feature.')
                    ->label('Repo Heatmap')
                    ->live(debounce: '250ms')
                    ->inline(false)
                    ->hintAction(
                        fn (ProFeatureToggle $component) => $component->isProInactive()
                            ? Action::make('upgrade')
                                ->hiddenLabel()
                                ->icon('heroicon-o-lock-closed')
                                ->color('gray')
                                ->size('lg')
                                ->tooltip($component->getProDescription())
                                ->actionJs("\$dispatch('open-modal', { id: 'pro-modal' })")
                            : Action::make('refreshGeohash')
                                ->label('Refresh')
                                ->tooltip('Refresh heatmap based on current Map view')
                                ->icon('heroicon-o-arrow-path')
                                ->visible(fn (): bool => $this->loadGeohash)
                                ->extraAttributes(trackAttr('kit-user-path', 'heatmap-refresh'))
                                ->action(fn () => $this->dispatch('load-geohash', load: true, tiers: $this->heatmapTiers))
                    ),

                Forms\Components\CheckboxList::make('heatmapTiers')
                    ->label('Repo Heatmap Density')
                    ->visible(fn (): bool => $this->loadGeohash)
                    ->live(debounce: '250ms')
                    ->default([
                        'tier_1',
                        'tier_2_4',
                        'tier_5_9',
                        'tier_10_24',
                        'tier_25_plus',
                    ])
                    ->options([
                        'tier_1' => '<span class="inline-flex items-center gap-2"><span class="w-3 h-3 rounded-full inline-block" style="background:#E1BEE7"></span>1 address</span>',
                        'tier_2_4' => '<span class="inline-flex items-center gap-2"><span class="w-3 h-3 rounded-full inline-block" style="background:#CE93D8"></span>2-4 addresses</span>',
                        'tier_5_9' => '<span class="inline-flex items-center gap-2"><span class="w-3 h-3 rounded-full inline-block" style="background:#AB47BC"></span>5-9 addresses</span>',
                        'tier_10_24' => '<span class="inline-flex items-center gap-2"><span class="w-3 h-3 rounded-full inline-block" style="background:#7B1FA2"></span>10-24 addresses</span>',
                        'tier_25_plus' => '<span class="inline-flex items-center gap-2"><span class="w-3 h-3 rounded-full inline-block" style="background:#4A148C"></span>25+ addresses</span>',
                    ])
                    ->allowHtml(),

                ProFeatureToggle::make('loadStagingGeohash')
                    ->proFeature(FeatureEnum::REPO_HEATMAP->slug())
                    ->proDescription('Staging Hotlists visualizes address density from staging assignments shared with your org. Upgrade to PRO to unlock this feature.')
                    ->label('Staging Hotlists')
                    ->live(debounce: '250ms')
                    ->inline(false)
                    ->hintAction(
                        fn (ProFeatureToggle $component) => $component->isProInactive()
                            ? Action::make('upgrade_staging')
                                ->hiddenLabel()
                                ->icon('heroicon-o-lock-closed')
                                ->color('gray')
                                ->size('lg')
                                ->tooltip($component->getProDescription())
                                ->actionJs("\$dispatch('open-modal', { id: 'pro-modal' })")
                            : Action::make('refreshStagingGeohash')
                                ->label('Refresh')
                                ->tooltip('Refresh staging heatmap based on current Map view')
                                ->icon('heroicon-o-arrow-path')
                                ->visible(fn (): bool => $this->loadStagingGeohash)
                                ->extraAttributes(trackAttr('kit-user-path', 'staging-heatmap-refresh'))
                                ->action(fn () => $this->dispatch('load-staging-geohash', load: true, tiers: $this->stagingHeatmapTiers))
                    ),

                Forms\Components\CheckboxList::make('stagingHeatmapTiers')
                    ->label('Staging Hotlist Density')
                    ->visible(fn (): bool => $this->loadStagingGeohash)
                    ->live(debounce: '250ms')
                    ->default([
                        'tier_1',
                        'tier_2_4',
                        'tier_5_9',
                        'tier_10_24',
                        'tier_25_plus',
                    ])
                    ->options([
                        'tier_1' => '<span class="inline-flex items-center gap-2"><span class="w-3 h-3 rounded-full inline-block" style="background:#C8E6C9"></span>1 address</span>',
                        'tier_2_4' => '<span class="inline-flex items-center gap-2"><span class="w-3 h-3 rounded-full inline-block" style="background:#81C784"></span>2-4 addresses</span>',
                        'tier_5_9' => '<span class="inline-flex items-center gap-2"><span class="w-3 h-3 rounded-full inline-block" style="background:#43A047"></span>5-9 addresses</span>',
                        'tier_10_24' => '<span class="inline-flex items-center gap-2"><span class="w-3 h-3 rounded-full inline-block" style="background:#2E7D32"></span>10-24 addresses</span>',
                        'tier_25_plus' => '<span class="inline-flex items-center gap-2"><span class="w-3 h-3 rounded-full inline-block" style="background:#1B5E20"></span>25+ addresses</span>',
                    ])
                    ->allowHtml(),

                ProFeatureToggle::make('loadHitDensity')
                    ->proFeature(FeatureEnum::REPO_HEATMAP->slug())
                    ->proDescription('Hit Density visualizes where vehicles on your hotlists are being spotted most frequently. Upgrade to PRO to unlock this feature.')
                    ->label('Hit Density')
                    ->live(debounce: '250ms')
                    ->inline(false)
                    ->hintAction(
                        fn (ProFeatureToggle $component) => $component->isProInactive()
                            ? Action::make('upgrade_hit_density')
                                ->hiddenLabel()
                                ->icon('heroicon-o-lock-closed')
                                ->color('gray')
                                ->size('lg')
                                ->tooltip($component->getProDescription())
                                ->actionJs("\$dispatch('open-modal', { id: 'pro-modal' })")
                            : Action::make('refreshHitDensity')
                                ->label('Refresh')
                                ->tooltip('Refresh hit density based on current Map view')
                                ->icon('heroicon-o-arrow-path')
                                ->visible(fn (): bool => $this->loadHitDensity)
                                ->extraAttributes(trackAttr('kit-user-path', 'hit-density-refresh'))
                                ->action(fn () => $this->dispatch('load-hit-density', load: true, tiers: $this->hitDensityTiers))
                    ),

                Forms\Components\CheckboxList::make('hitDensityTiers')
                    ->label('Hit Density Levels')
                    ->visible(fn (): bool => $this->loadHitDensity)
                    ->live(debounce: '250ms')
                    ->default([
                        'hit_tier_1',
                        'hit_tier_2_5',
                        'hit_tier_6_15',
                        'hit_tier_16_50',
                        'hit_tier_51_plus',
                    ])
                    ->options([
                        'hit_tier_1' => '<span class="inline-flex items-center gap-2"><span class="w-3 h-3 rounded-full inline-block" style="background:#FFD6CC"></span>1 hit</span>',
                        'hit_tier_2_5' => '<span class="inline-flex items-center gap-2"><span class="w-3 h-3 rounded-full inline-block" style="background:#FFAB91"></span>2-5 hits</span>',
                        'hit_tier_6_15' => '<span class="inline-flex items-center gap-2"><span class="w-3 h-3 rounded-full inline-block" style="background:#FF7043"></span>6-15 hits</span>',
                        'hit_tier_16_50' => '<span class="inline-flex items-center gap-2"><span class="w-3 h-3 rounded-full inline-block" style="background:#F4511E"></span>16-50 hits</span>',
                        'hit_tier_51_plus' => '<span class="inline-flex items-center gap-2"><span class="w-3 h-3 rounded-full inline-block" style="background:#D84315"></span>51+ hits</span>',
                    ])
                    ->allowHtml(),

                Forms\Components\ToggleButtons::make('date_toggles')
                    ->label('Filter by Date')
                    ->inline()
                    ->columnSpan(1)
                    ->default($this->date_toggles)
                    ->options([
                        'yesterday' => 'Yesterday',
                        '7_days' => '7 Days',
                        '15_days' => '15 Days',
                        '30_days' => '30 Days',
                    ]),
            ])
        );
    }

    public function getFiltersApplyAction(): Action
    {
        return Action::make('applyFilters')
            ->label('Search')
            ->action('search')
            ->button();
    }

    public function resetTableFiltersForm(): void
    {
        $this->reset('date_toggles', 'address', 'kits', 'loadGeohash', 'loadStagingGeohash', 'heatmapTiers', 'stagingHeatmapTiers', 'loadHitDensity', 'hitDensityTiers');
        $this->heatmapTiers = ['tier_1', 'tier_2_4', 'tier_5_9', 'tier_10_24', 'tier_25_plus'];
        $this->stagingHeatmapTiers = ['tier_1', 'tier_2_4', 'tier_5_9', 'tier_10_24', 'tier_25_plus'];
        $this->hitDensityTiers = ['hit_tier_1', 'hit_tier_2_5', 'hit_tier_6_15', 'hit_tier_16_50', 'hit_tier_51_plus'];

        $this->dispatch('reset-map');
    }

    public function search(): void
    {
        Analytics::track('date-filter', auth()->user(), [
            'action' => 'filter-change',
            'value' => $this->date_toggles,
        ], page: 'kit-user-path');

        $this->geocodeAddress();

        $this->dispatch('reset-map');

        $this->load();

        if ($this->loadGeohash) {
            $this->dispatch('load-geohash',
                load: true,
                tiers: $this->heatmapTiers,
            );
        }

        if ($this->loadStagingGeohash) {
            $this->dispatch('load-staging-geohash',
                load: true,
                tiers: $this->stagingHeatmapTiers,
            );
        }

        if ($this->loadHitDensity) {
            $this->dispatch('load-hit-density',
                load: true,
                tiers: $this->hitDensityTiers,
            );
        }
    }

    private function geocodeAddress(): void
    {
        $value = $this->address;

        if ($value === '' || $value === null) {
            $this->reset('centeredAddress');
            $this->dispatch('set-center');
        } elseif ($this->centeredAddress !== $value) {
            $this->centeredAddress = $value;

            // New geocode and re-centering.
            try {
                // Geocode into Lat/Lon
                $geocoder = new Geocodio;
                $geocoder->setApiKey(config('geocodio.api_key'));

                $response = cache()->remember(
                    'geocode-'.md5($this->centeredAddress),
                    now()->addDay(),
                    fn () => (array) $geocoder->geocode($this->centeredAddress, [], 1, 'simple'),
                );

                $this->dispatch('set-center', lat: $response['lat'], lng: $response['lng']);
            } catch (\Exception $e) {
                //
            }
        }
    }

    public function updatedLoadGeohash(): void
    {
        Analytics::track('heatmap-toggle', auth()->user(), [
            'action' => 'toggle',
            'value' => $this->loadGeohash ? 'on' : 'off',
        ], page: 'kit-user-path');

        $this->dispatch('load-geohash',
            load: $this->loadGeohash,
            tiers: $this->heatmapTiers,
        );
    }

    public function updatedLoadStagingGeohash(): void
    {
        Analytics::track('staging-heatmap-toggle', auth()->user(), [
            'action' => 'toggle',
            'value' => $this->loadStagingGeohash ? 'on' : 'off',
        ], page: 'kit-user-path');

        $this->dispatch('load-staging-geohash',
            load: $this->loadStagingGeohash,
            tiers: $this->stagingHeatmapTiers,
        );
    }

    public function updatedHeatmapTiers(): void
    {
        Analytics::track('heatmap-tiers', auth()->user(), [
            'action' => 'filter-change',
            'value' => implode(',', $this->heatmapTiers),
        ], page: 'kit-user-path');

        if ($this->loadGeohash) {
            $this->dispatch('load-geohash',
                load: true,
                tiers: $this->heatmapTiers,
            );
        }
    }

    public function updatedStagingHeatmapTiers(): void
    {
        Analytics::track('staging-heatmap-tiers', auth()->user(), [
            'action' => 'filter-change',
            'value' => implode(',', $this->stagingHeatmapTiers),
        ], page: 'kit-user-path');

        if ($this->loadStagingGeohash) {
            $this->dispatch('load-staging-geohash',
                load: true,
                tiers: $this->stagingHeatmapTiers,
            );
        }
    }

    public function updatedLoadHitDensity(): void
    {
        Analytics::track('hit-density-toggle', auth()->user(), [
            'action' => 'toggle',
            'value' => $this->loadHitDensity ? 'on' : 'off',
        ], page: 'kit-user-path');

        $this->dispatch('load-hit-density',
            load: $this->loadHitDensity,
            tiers: $this->hitDensityTiers,
        );
    }

    public function updatedHitDensityTiers(): void
    {
        Analytics::track('hit-density-tiers', auth()->user(), [
            'action' => 'filter-change',
            'value' => implode(',', $this->hitDensityTiers),
        ], page: 'kit-user-path');

        if ($this->loadHitDensity) {
            $this->dispatch('load-hit-density',
                load: true,
                tiers: $this->hitDensityTiers,
            );
        }
    }

    public static function canAccess(): bool
    {
        return auth()->user()->can(PermissionEnum::Analytics_Geospatial_Kit_User_Path->value);
    }
}
