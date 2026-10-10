<?php

namespace App\Filament\Resources;

use App\Dto\ScanViewDto;
use App\Enums\DispositionEnum;
use App\Enums\HitTypeEnum;
use App\Filament\Actions\BulkDispositionAction;
use App\Filament\Resources\MissedOpportunityResource\Actions\DownloadAsPdf;
use App\Filament\Resources\MissedOpportunityResource\Actions\EmailMissedOpportunitiesAction;
use App\Filament\Resources\MissedOpportunityResource\Pages;
use App\Filament\Tables\Columns\DateTimeColumn;
use App\Filament\Tables\Columns\RemoteOrderColumn;
use App\Filament\Tables\Filters\DateTogglesFilter;
use App\Models\MissedOpportunity;
use App\Policies\MissedOpportunityPolicy;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Grouping\Group;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\HtmlString;

class MissedOpportunityResource extends Resource
{
    protected static ?string $model = MissedOpportunity::class;

    protected static ?int $navigationSort = 4;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-flag';

    protected static string|\UnitEnum|null $navigationGroup = 'Search';

    public static function getNavigationBadge(): ?string
    {
        return 'Pro';
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('scans_ocr')
                    ->label('Plate')
                    ->sortable()
                    ->toggleable()
                    ->formatStateUsing(function (Model $record, \stdClass $rowLoop): mixed {
                        if (! $record->scan_id) {
                            return 'N/A';
                        }

                        $scanMetadata = $record->scan_metadata ?? null;
                        if (\is_string($scanMetadata)) {
                            $scanMetadata = \json_decode($scanMetadata);
                        }

                        return view(
                            'filament.tables.columns.plate-images',
                            [
                                'model_id' => $record->scan_id,
                                'model' => ScanViewDto::fromHitRecord($record, $scanMetadata),
                                'showTabs' => true,
                                'rowLoop' => $rowLoop,
                                'filter' => 'scan.ocr',
                                'dispositionable' => true,
                                'missedOpportunity' => true,
                                'dispositionTooltip' => 'Change Validation Status',
                                'trigger_icon' => 'heroicon-o-viewfinder-circle',
                                'vin' => $record->hotlist_vin,
                                'ymm' => \trim(\html_entity_decode($record->hotlist_vehicle_year.' '.$record->hotlist_vehicle_make.' '.$record->hotlist_vehicle_model)),
                            ],
                        );
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

                Tables\Columns\TextColumn::make('mo_hit_type')
                    ->label('Type')
                    ->badge()
                    ->toggleable()
                    ->formatStateUsing(fn (string $state): string => HitTypeEnum::tryFrom($state)?->title() ?? $state),

                DateTimeColumn::make('close_date')
                    ->label('Close Date')
                    ->sortable()
                    ->default('N/A')
                    ->toggleable(),

                DateTimeColumn::make('repo_date')
                    ->label('Repo Date')
                    ->sortable()
                    ->default('N/A')
                    ->toggleable(),

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
                        if (\is_string($metadata)) {
                            $metadata = \json_decode($metadata);
                        }

                        $dto = ScanViewDto::fromHitRecord($record, $metadata);

                        return view('filament.tables.columns.geo-address', [
                            'model_id' => $record->scan_id,
                            'model' => $dto,
                            'address' => $dto->address,
                        ]);
                    }),

                Tables\Columns\TextColumn::make('disposition')
                    ->label('Validation')
                    ->badge()
                    ->default('Awaiting Review')
                    ->formatStateUsing(fn (mixed $state): string => $state ?? 'Awaiting Review')
                    ->color(fn (mixed $state): string => match ($state ?? 'Awaiting Review') {
                        DispositionEnum::REVIEWED->value => 'info',
                        DispositionEnum::VALID->value => 'success',
                        DispositionEnum::INVALID->value => 'danger',
                        default => 'warning',
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
                    ->html()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('lienholder_org_name')
                    ->label('Lien Holder')
                    ->html()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->toolbarActions([
                DownloadAsPdf::make(),

                EmailMissedOpportunitiesAction::make(),

                BulkDispositionAction::make('bulk-disposition')
                    ->setRelationship('scans')
                    ->setMissedOpportunity(true),
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
                            ->columnSpanFull()
                            ->options([
                                HitTypeEnum::Missed_Opportunity->value => HitTypeEnum::Missed_Opportunity->title(),
                                HitTypeEnum::Missed_Opportunity_Historical->value => HitTypeEnum::Missed_Opportunity_Historical->title(),
                            ]),

                        Forms\Components\TextInput::make('hotlist.vin')
                            ->label('Search by VIN')
                            ->columnSpanFull(),

                        Forms\Components\Select::make('dispositions')
                            ->label('Validation')
                            ->columnSpanFull()
                            ->multiple()
                            ->options(DispositionEnum::formOptions(missedOpportunity: true)),

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
                    ])
                    ->indicateUsing(function (array $data): ?array {
                        $indicators = [];

                        if ($data['hit_type']) {
                            $indicators[] = 'Type: '.(HitTypeEnum::tryFrom($data['hit_type'])->name ?? $data['hit_type']);
                        }

                        if ($data['dispositions']) {
                            $indicators[] = 'Validations(s): "'.\implode('" and "', $data['dispositions']).'"';
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
                        return $query
                            ->when(
                                $data['hit_type'] ?? false,
                                fn (Builder $query, $hitType): Builder => $query->forHitType($hitType),
                            )
                            ->when(
                                $data['hotlist']['vin'] ?? false,
                                fn (Builder $query, $vin): Builder => $query->forVin($vin),
                            )
                            ->when(
                                $data['clients'],
                                fn (Builder $query, $clientIds): Builder => $query->forClients($clientIds),
                            )
                            ->when(
                                $data['lienholders'],
                                fn (Builder $query, $lienholderIds): Builder => $query->forLienholders($lienholderIds),
                            )
                            ->when(
                                $data['dispositions'] !== null,
                                fn (Builder $query): Builder => $query->forDispositions($data['dispositions']),
                                fn (Builder $query): Builder => $query->excludeDisposition(DispositionEnum::INVALID->value),
                            );
                    }),

                DateTogglesFilter::make('hits.created_at')
                    ->label('Hit Created')
                    ->defaultDate('last_30d')
                    ->ignoreRelationship()
                    ->dateToggles([
                        'today' => 'Today',
                        'last_7d' => 'Last 7d',
                        'last_30d' => 'Last 30d',
                        'advanced' => 'Advanced',
                    ])
                    ->modifyQueryUsing(function (Builder $query, array $data): Builder {
                        return $query->forCreatedBetween($data['start'], $data['end']);
                    }),
            ])
            ->selectable()
            ->defaultSort('created_at', 'desc')
            ->defaultGroup('client_org_name')
            ->groupingDirectionSettingHidden()
            ->groups([
                Group::make('client_org_name')
                    ->label('Client')
                    ->getTitleFromRecordUsing(fn ($record) => new HtmlString($record->client_org_name ?? ''))
                    ->collapsible(),
            ])
            ->filtersLayout(Tables\Enums\FiltersLayout::Hidden);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListMissedOpportunities::route('/'),
        ];
    }

    public static function canAccess(): bool
    {
        return (new MissedOpportunityPolicy)->viewAny(auth()->user());
    }
}
