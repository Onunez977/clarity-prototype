<?php

namespace App\Filament\Resources;

use App\Enums\HotlistStatusEnum;
use App\Facades\Analytics;
use App\Filament\Exports\HotlistExporter;
use App\Filament\Resources\HotlistResource\Pages;
use App\Filament\Tables\Columns\DateTimeColumn;
use App\Filament\Tables\Filters\FlexSearchToggle;
use App\Filament\Tables\Filters\PlateFilter;
use App\Models\Hotlist;
use Filament\Actions\ExportAction;
use Filament\Actions\Exports\Models\Export;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class HotlistResource extends Resource
{
    protected static ?string $model = Hotlist::class;

    protected static ?int $navigationSort = 3;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-fire';

    protected static string|\UnitEnum|null $navigationGroup = 'Search';

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('plate')
                    ->label('Plate')
                    ->default('N/A')
                    ->badge(fn (Model $record): bool => ! $record->plate || $record->plate === '')
                    ->tooltip(fn (Model $record): ?string => $record->plate !== '' ? 'Filter by this Plate' : null)
                    ->extraAttributes(function (Model $record): array {
                        if ($record->plate !== '') {
                            return [
                                // Dynamically add a filter when clicked.
                                'wire:click' => "addFilter('plate', '".\strtoupper($record->plate)."')",
                                'class' => 'cursor-pointer',
                            ];
                        }

                        return [];
                    }),

                Tables\Columns\TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (int $state): string => match ($state) {
                        1 => 'Open',
                        3 => 'Repossessed',
                        default => 'Other',
                    })
                    ->color(fn (int $state): string => match ($state) {
                        1 => 'success',
                        3 => 'danger',
                        default => 'warning',
                    }),

                Tables\Columns\TextColumn::make('vin')
                    ->label('VIN')
                    ->tooltip('Filter by this VIN')
                    ->extraAttributes(function (Model $record): array {
                        return [
                            // Dynamically add a filter when clicked.
                            'wire:click' => "addFilter('vin', '".\trim($record->vin)."')",
                            'class' => 'cursor-pointer',
                        ];
                    }),

                Tables\Columns\TextColumn::make('vehicle_make')
                    ->label('YMM')
                    ->toggleable()
                    ->extraHeaderAttributes(['title' => 'Year Make Model'])
                    ->formatStateUsing(fn (Model $record): string => $record->vehicle),

                Tables\Columns\TextColumn::make('remote_order_id')
                    ->label('Order ID')
                    ->formatStateUsing(fn (Model $record): string => $record->remoteLink ? $record->remote_order_id : $record->remoteName)
                    ->description(fn (Model $record): string => $record->remoteLink ? $record->remoteName : $record->remote_order_id)
                    ->url(fn (Model $record): mixed => $record->remoteLink ? $record->remoteLink : false)
                    ->openUrlInNewTab(),

                DateTimeColumn::make('created_at')
                    ->label('Order Date')
                    ->sortable(),

                Tables\Columns\TextColumn::make('client_org_id')
                    ->label('Client')
                    ->toggleable()
                    ->formatStateUsing(fn (Model $record): string => \htmlspecialchars_decode($record->client_org_name ?? 'N/A')),

                Tables\Columns\TextColumn::make('lienholder_org_id')
                    ->label('Lien Holder')
                    ->toggleable()
                    ->formatStateUsing(fn (Model $record): string => \htmlspecialchars_decode($record->lienholder_org_name ?? 'N/A')),
            ])
            ->filters([
                Filter::make('filters')
                    ->columnSpanFull()
                    ->schema([
                        Forms\Components\Select::make('status')
                            ->label('Status')
                            ->default(HotlistStatusEnum::Open->value)
                            ->options(HotlistStatusEnum::formOptions()),

                        Forms\Components\TextInput::make('vin')
                            ->label('VIN'),

                        PlateFilter::make('plate')
                            ->label('Plate'),

                        FlexSearchToggle::make('flexsearch'),

                        Forms\Components\Toggle::make('missing_plate')
                            ->label('Missing Plate')
                            ->offIcon('heroicon-o-x-mark')
                            ->onIcon('heroicon-o-finger-print')
                            ->columnSpanFull()
                            ->inline(),

                        Forms\Components\TextInput::make('order_id')
                            ->label('Order ID'),

                        Forms\Components\Select::make('clients')
                            ->label('Client(s)')
                            ->multiple()
                            ->optionsLimit(125)
                            ->options(auth()->user()->org->getHotlistClients()),
                    ])
                    ->indicateUsing(function (array $data): ?array {
                        $indicators = [];

                        if ($data['status']) {
                            $indicators[] = 'Type: '.(HotlistStatusEnum::tryFrom($data['status'])?->name ?? $data['status']);
                        }

                        if ($data['vin']) {
                            $indicators[] = 'VIN: '.$data['vin'];
                        }

                        if ($data['plate']) {
                            $indicators[] = 'Plate: '.$data['plate'];
                        }

                        if ($data['order_id']) {
                            $indicators[] = 'Order ID: '.$data['order_id'];
                        }

                        if ($data['clients']) {
                            $indicators[] = 'Clients: '.\count($data['clients']).' selected';
                        }

                        if ($data['missing_plate']) {
                            $indicators[] = 'Missing Plate';
                        }

                        return $indicators !== []
                            ? $indicators
                            : null;
                    })
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when(
                            \in_array($data['status'], \array_keys(HotlistStatusEnum::formOptions())),
                            fn (Builder $query): Builder => $query->forStatus((int) $data['status']),
                        )
                        ->when(
                            $data['vin'],
                            fn (Builder $query, $vin): Builder => $query->forVin($vin),
                        )
                        ->when(
                            $data['plate'],
                            fn (Builder $query, $plate): Builder => $query->forPlate($plate, $data['flexsearch'] ?? false),
                        )
                        ->when(
                            $data['order_id'],
                            fn (Builder $query, $order_id): Builder => $query->forOrderId($order_id),
                        )
                        ->when(
                            $data['clients'],
                            fn (Builder $query, $client_ids): Builder => $query->forClients($client_ids),
                        )
                        ->when(
                            $data['missing_plate'],
                            fn (Builder $query): Builder => $query->missingPlate(),
                        )
                    ),
            ])
            ->toolbarActions([
                ExportAction::make('export')
                    ->label('CSV Export')
                    ->icon('heroicon-m-arrow-down-tray')
                    ->exporter(HotlistExporter::class)
                    ->fileName(fn (Export $export): string => 'hotlist-'.$export->getKey())
                    ->chunkSize(250)
                    ->columnMapping(false)
                    ->modal(false)
                    ->after(fn () => Analytics::track('export', auth()->user(), ['action' => 'export'], 'hotlists')),
            ])
            ->defaultSort('created_at', 'desc')
            ->filtersLayout(Tables\Enums\FiltersLayout::Hidden);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListHotlists::route('/'),
        ];
    }
}
