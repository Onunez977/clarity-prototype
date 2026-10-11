<?php

namespace App\Filament\Resources;

use App\Clickhouse\Models\ClickhouseModel;
use App\Dto\ScanViewDto;
use App\Enums\DispositionEnum;
use App\Enums\FeatureEnum;
use App\Enums\HitTypeEnum;
use App\Enums\HotlistStatusEnum;
use App\Filament\Actions\BulkDispositionAction;
use App\Filament\Actions\BulkMapAction;
use App\Filament\Actions\ResendToClearPlanAction;
use App\Filament\Forms\Components\ProFeatureToggle;
use App\Filament\Resources\HitsResource\Pages;
use App\Filament\Tables\Columns\DateTimeColumn;
use App\Filament\Tables\Columns\RemoteOrderColumn;
use App\Filament\Tables\Filters\AddressFilter;
use App\Filament\Tables\Filters\DateTogglesFilter;
use App\Filament\Tables\Filters\FlexSearchToggle;
use App\Filament\Tables\Filters\KitUsersFilter;
use App\Filament\Tables\Filters\PlateFilter;
use App\Models\Hit;
use App\Support\FlexSearch;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Laravel\Pennant\Feature;

class HitsResource extends Resource
{
    protected static ?string $model = Hit::class;

    protected static ?int $navigationSort = 2;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-bell';

    protected static string|\UnitEnum|null $navigationGroup = 'Search';

    public static function table(Table $table): Table
    {
        return $table
            ->poll(fn (HasTable $livewire): ?string => ($livewire->getTableFilterState('filters')['polling'] ?? false) ? '10s' : null)
            ->columns([
                Tables\Columns\TextColumn::make('scans_ocr')
                    ->label('Plate')
                    ->sortable()
                    ->toggleable()
                    ->formatStateUsing(function (Model $record, \stdClass $rowLoop, HasTable $livewire): mixed {
                        if ($record->scan_id) {
                            $scanMetadata = $record->scan_metadata ?? null;
                            if (\gettype($scanMetadata) === 'string') {
                                $scanMetadata = \json_decode($scanMetadata);
                            }

                            $filters = $livewire->getTableFilterState('filters');
                            $searchedPlate = $filters['scan']['ocr'] ?? null;
                            $flexEnabled = ($filters['flexsearch'] ?? false) && FlexSearch::isAvailable();

                            return view(
                                'filament.tables.columns.plate-images',
                                [
                                    'model_id' => $record->scan_id,
                                    'model' => ScanViewDto::fromHitRecord($record, $scanMetadata),
                                    'showTabs' => true,
                                    'rowLoop' => $rowLoop,
                                    'filter' => 'scan.ocr',
                                    'dispositionable' => $record->kit_org_id === auth()->user()->org_id,
                                    'probableMatch' => $record->type === HitTypeEnum::Probable_Match->value
                                        || FlexSearch::isCloseMatch($searchedPlate, $record->scans_ocr, $flexEnabled),
                                    'trigger_icon' => 'heroicon-o-viewfinder-circle',
                                    'vin' => $record->hotlist_vin,
                                    'ymm' => \trim(\html_entity_decode($record->hotlist_vehicle_year.' '.$record->hotlist_vehicle_make.' '.$record->hotlist_vehicle_model)),
                                ],
                            );
                        }

                        return 'N/A';
                    }),

                Tables\Columns\TextColumn::make('hotlist_plate')
                    ->label('Hotlist Plate')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->description(fn (Model $record): string => $record->hotlist_us_state ?? ''),

                Tables\Columns\TextColumn::make('hotlist_vin')
                    ->label('Vehicle')
                    ->toggleable()
                    ->description(fn (Model $record): string => \trim(\html_entity_decode($record->hotlist_vehicle_year.' '.$record->hotlist_vehicle_make.' '.$record->hotlist_vehicle_model)))
                    ->tooltip('Filter by this VIN')
                    ->extraAttributes(function (Model $record): array {
                        return [
                            // Dynamically add a filter when clicked.
                            'wire:click' => "addFilter('hotlist.vin', '".$record->hotlist_vin."')",
                            'class' => 'cursor-pointer',
                        ];
                    }),

                Tables\Columns\TextColumn::make('hotlist_status')
                    ->label('Status/Type')
                    ->badge()
                    ->formatStateUsing(fn (int $state): string => match ($state) {
                        1 => 'Open',
                        3 => 'Repossessed',
                        5 => 'Closed',
                        default => 'Other',
                    })
                    ->color(fn (int $state): string => match ($state) {
                        1 => 'success',
                        3 => 'danger',
                        default => 'warning'
                    })
                    ->description(fn (Model $record, HasTable $livewire): string => 'Type: '.(HitTypeEnum::tryFrom($record->type)?->title() ?? Str::headline($record->type))),

                DateTimeColumn::make('created_at')
                    ->label('Hit Created')
                    ->sortable(),

                DateTimeColumn::make('scans_scanned_at')
                    ->label('Scanned')
                    ->showIcon()
                    ->sortable(),

                Tables\Columns\TextColumn::make('scans_geo')
                    ->label('Address')
                    ->toggleable()
                    ->default('-')
                    ->formatStateUsing(function (Model $record): mixed {
                        if (! $record->scan_id) {
                            return 'N/A';
                        }

                        $metadata = $record->scan_metadata ?? null;
                        if (\gettype($metadata) === 'string') {
                            $metadata = \json_decode($metadata);
                        }

                        $dto = ScanViewDto::fromHitRecord($record, $metadata);

                        return view('filament.tables.columns.geo-address', [
                            'model_id' => $record->scan_id,
                            'model' => $dto,
                            'address' => $dto->address,
                        ]);
                    }),

                Tables\Columns\TextColumn::make('scan_metadata')
                    ->label('Disposition')
                    ->toggleable()
                    ->default('New Hit')
                    ->formatStateUsing(function (mixed $state, Model $record): string {
                        // The default keeps the blank-state early return from skipping this closure; CH never selects scan_metadata.
                        if ($record instanceof ClickhouseModel) {
                            return $record->disposition ?? 'New Hit';
                        }

                        if (\gettype($state) === 'string') {
                            $state = \json_decode($state);
                        }

                        return \is_object($state) ? ($state->disposition ?? 'New Hit') : 'New Hit';
                    }),

                Tables\Columns\TextColumn::make('scans_camera_name')
                    ->label('Camera')
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('kit_name')
                    ->label('Kit User')
                    ->toggleable(isToggledHiddenByDefault: true),

                RemoteOrderColumn::make('hotlist_remote_order_id')
                    ->label('Order Id')
                    ->setIntegrationId(fn (Model $record): mixed => $record->hotlist_integration_id),

                Tables\Columns\TextColumn::make('client_org_name')
                    ->label('Client')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('lienholder_org_name')
                    ->label('Lien Holder')
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('distance_from_given_miles')
                    ->label('Distance from Given')
                    ->toggleable(isToggledHiddenByDefault: Feature::inactive(FeatureEnum::HIT_ADDRESS_DISTANCE->slug()) || session()->missing('admin_impersonator'))
                    ->visible(fn (): bool => Feature::active(FeatureEnum::HIT_ADDRESS_DISTANCE->slug()) || session()->has('admin_impersonator'))
                    ->default('-')
                    ->formatStateUsing(function (mixed $state, Model $record) {
                        if ($state !== null && $state !== '-') {
                            return $state.' mi';
                        }

                        $hotlistId = $record->hotlist_id ?? null;
                        $lat = $record->lat ?? null;
                        $lng = $record->lng ?? null;

                        if ($hotlistId && $lat && $lng) {
                            return view('filament.tables.columns.distance-from-given', [
                                'hotlistId' => $hotlistId,
                                'lat' => $lat,
                                'lng' => $lng,
                            ]);
                        }

                        return 'N/A';
                    }),

                Tables\Columns\TextColumn::make('nearest_given_address')
                    ->label('Nearest Given Address')
                    ->toggleable(isToggledHiddenByDefault: ! (Feature::active(FeatureEnum::HIT_ADDRESS_DISTANCE->slug()) || session()->has('admin_impersonator')))
                    ->visible(fn (): bool => Feature::active(FeatureEnum::HIT_ADDRESS_DISTANCE->slug()) || session()->has('admin_impersonator'))
                    ->default('-')
                    ->wrap()
                    ->formatStateUsing(function (mixed $state, Model $record) {
                        if (Feature::inactive(FeatureEnum::HIT_ADDRESS_DISTANCE->slug()) && session()->missing('admin_impersonator')) {
                            return view('filament.tables.columns.pro-feature-teaser', [
                                'feature' => FeatureEnum::HIT_ADDRESS_DISTANCE->slug(),
                                'description' => FeatureEnum::HIT_ADDRESS_DISTANCE->description(),
                            ]);
                        }

                        if ($state !== null && $state !== '-') {
                            return $state;
                        }

                        $hotlistId = $record->hotlist_id ?? null;
                        $lat = $record->lat ?? null;
                        $lng = $record->lng ?? null;

                        if ($hotlistId && $lat && $lng) {
                            return view('filament.tables.columns.nearest-given-address', [
                                'hotlistId' => $hotlistId,
                                'lat' => $lat,
                                'lng' => $lng,
                            ]);
                        }

                        return 'N/A';
                    }),
            ])
            ->toolbarActions([
                BulkMapAction::make('map')
                    ->setRelationship('scans'),

                BulkDispositionAction::make('bulk-disposition')
                    ->setRelationship('scans'),

                ResendToClearPlanAction::make('resend-to-clearplan'),
            ])
            ->filters([
                Filter::make('filters')
                    ->columnSpanFull()
                    ->columns([
                        'default' => 1,
                        'lg' => 2,
                    ])
                    ->schema([
                        Forms\Components\Select::make('hit_type')
                            ->label('Type')
                            ->placeholder('All')
                            ->default('All')
                            ->options(
                                collect(HitTypeEnum::formOptions())
                                    ->filter(fn ($type, $key) => $key !== HitTypeEnum::Missed_Opportunity->value)
                                    ->toArray()
                            ),

                        Forms\Components\Select::make('status')
                            ->label('Status')
                            ->default(1)
                            ->selectablePlaceholder(false)
                            ->options(HotlistStatusEnum::formOptions()),

                        PlateFilter::make('scan.ocr')
                            ->label('Search by Vehicle Plate')
                            ->columnSpanFull(),

                        FlexSearchToggle::make('flexsearch'),

                        ProFeatureToggle::make('cluster')
                            ->proFeature(FeatureEnum::SCAN_CLUSTERING->slug())
                            ->proDescription('Cluster hits by proximity to each other by prioritizing locations of interest within 0.1 miles. Upgrade to PRO to unlock this feature.')
                            ->label('Location Cluster')
                            ->columnSpanFull()
                            ->when(
                                Feature::active(FeatureEnum::SCAN_CLUSTERING->slug()) || session()->has('admin_impersonator'),
                                fn (ProFeatureToggle $toggle) => $toggle->default(false),
                                fn (ProFeatureToggle $toggle) => $toggle->default(false),
                            )
                            ->inline()
                            ->live(debounce: '250ms')
                            ->offIcon('heroicon-o-x-mark')
                            ->onIcon('heroicon-o-rectangle-stack'),

                        Forms\Components\TextInput::make('hotlist.vin')
                            ->label('Search by VIN')
                            ->columnSpanFull(),

                        Forms\Components\TextInput::make('hotlist.remote_order_id')
                            ->label('Search By Order ID')
                            ->columnSpanFull(),

                        Forms\Components\Select::make('dispositions')
                            ->label('Disposition')
                            ->columnSpanFull()
                            ->multiple()
                            ->default([
                                DispositionEnum::NEW_HIT->value,
                                DispositionEnum::ACCESSIBLE->value,
                            ])
                            ->options(DispositionEnum::formOptions()),

                        KitUsersFilter::make('kits')
                            ->label('Kit Users')
                            ->multiple(),

                        Forms\Components\Select::make('clients')
                            ->label('Client(s)')
                            ->columnSpanFull()
                            ->multiple()
                            ->optionsLimit(125)
                            ->options(auth()->user()->org->getHotlistClients()),

                        Forms\Components\Select::make('lienholders')
                            ->label('Lienholder(s)')
                            ->columnSpanFull()
                            ->multiple()
                            ->optionsLimit(125)
                            ->options(auth()->user()->org->getHotlistLienholders()),

                        Forms\Components\Toggle::make('polling')
                            ->label('Live Polling')
                            ->offIcon('heroicon-o-x-mark')
                            ->onIcon('heroicon-o-arrow-path')
                            ->columnSpanFull()
                            ->inline(),
                    ])
                    ->indicateUsing(function (array $data): ?array {
                        $indicators = [];

                        if ($data['polling']) {
                            $indicators[] = 'Live Polling';
                        }

                        if ($data['hit_type']) {
                            $indicators[] = 'Type: '.(HitTypeEnum::tryFrom($data['hit_type'])->name ?? $data['hit_type']);
                        }

                        if ($data['status'] !== null) {
                            $indicators[] = 'Status: '.(HotlistStatusEnum::tryFrom($data['status'])->name ?? $data['status']);
                        }

                        if ($data['dispositions']) {
                            $indicators[] = 'Disposition(s): "'.\implode('" and "', $data['dispositions']).'"';
                        }

                        if ($data['kits']) {
                            $indicators[] = KitUsersFilter::indicator($data['kits']);
                        }

                        if ($data['clients']) {
                            if (\count($data['clients']) > 1) {
                                $indicators[] = 'Clients: '.\count($data['clients']).' selected';
                            }
                        }

                        if ($data['lienholders']) {
                            if (\count($data['lienholders']) > 1) {
                                $indicators[] = 'Lienholders: '.\count($data['lienholders']).' selected';
                            }
                        }

                        return $indicators !== []
                            ? $indicators
                            : null;
                    })
                    ->query(function (Builder $query, array $data): Builder {
                        $isCh = $query->getModel() instanceof ClickhouseModel;

                        return $query
                            ->when(
                                $data['hit_type'] !== null
                                    && \in_array($data['hit_type'], \array_keys(HitTypeEnum::formOptions()), true),
                                fn (Builder $q) => $q->forHitType($data['hit_type']),
                            )
                            ->when(
                                $data['status'] !== null
                                    && \in_array((int) $data['status'], \array_keys(HotlistStatusEnum::formOptions()), true),
                                fn (Builder $q) => $q->forStatus((int) $data['status'])
                                    ->when(
                                        ! $isCh && \in_array($data['hit_type'], [HitTypeEnum::Live->value, HitTypeEnum::Historical->value], true),
                                        fn ($sub) => $sub->where('kits.org_id', auth()->user()->org_id),
                                    ),
                            )
                            ->when(
                                $data['scan']['ocr'] ?? false,
                                fn (Builder $q, $plate) => $q->forPlate($plate, $data['flexsearch'] ?? false),
                            )
                            ->when(
                                $data['hotlist']['vin'] ?? false,
                                fn (Builder $q, $vin) => $q->forVin($vin),
                            )
                            ->when(
                                $data['hotlist']['remote_order_id'] ?? false,
                                fn (Builder $q, $id) => $q->forOrderId($id),
                            )
                            ->when(
                                $data['kits'],
                                fn (Builder $q, $kits) => $q->forKits(KitUsersFilter::format($kits)),
                            )
                            ->when(
                                $data['clients'],
                                fn (Builder $q, $ids) => $q->forClients($ids),
                            )
                            ->when(
                                $data['lienholders'],
                                fn (Builder $q, $ids) => $q->forLienholders($ids),
                            )
                            ->when(
                                $data['dispositions'],
                                fn (Builder $q, $dispositions) => $q->forDispositions($dispositions),
                            );
                    }),

                AddressFilter::make('scan.geo'),

                DateTogglesFilter::make('hits.created_at')
                    ->label('Hit Date')
                    ->defaultDate('today')
                    ->ignoreRelationship()
                    ->dateToggles([
                        'today' => 'Today',
                        'last_7d' => 'Last 7d',
                        'last_30d' => 'Last 30d',
                        'advanced' => 'Advanced',
                    ])
                    ->modifyQueryUsing(fn (Builder $query, array $data) => $query->forCreatedBetween($data['start'], $data['end'])),

                DateTogglesFilter::make('scans.scanned_at')
                    ->label('Scanned Date')
                    ->defaultDate('all')
                    ->ignoreRelationship()
                    ->showAdvanced(false)
                    ->dateToggles([
                        'all' => 'Any',
                        'today' => 'Today',
                        'last_7d' => 'Last 7d',
                        'last_30d' => 'Last 30d',
                        'last_3m' => 'Last 3m',
                        'last_6m' => 'Last 6m',
                    ])
                    ->modifyQueryUsing(fn (Builder $query, array $data) => $query->forScannedBetween($data['start'], $data['end'])),
            ])
            ->selectable()
            ->defaultSort('created_at', 'desc')
            ->recordClasses(function (HasTable $livewire, Model $record): ?string {
                $filters = $livewire->getTableFilterState('filters');
                $flexEnabled = ($filters['flexsearch'] ?? false) && FlexSearch::isAvailable();
                $searchedPlate = $filters['scan']['ocr'] ?? null;

                return FlexSearch::isCloseMatch($searchedPlate, $record->scans_ocr ?? null, $flexEnabled)
                    ? 'flex-match'
                    : null;
            })
            ->filtersLayout(Tables\Enums\FiltersLayout::Hidden);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListHits::route('/'),
        ];
    }
}
