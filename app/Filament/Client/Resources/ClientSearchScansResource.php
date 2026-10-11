<?php

namespace App\Filament\Client\Resources;

use App\Filament\Client\Resources\ClientSearchScansResource\Pages;
use App\Filament\Tables\Columns\DateTimeColumn;
use App\Filament\Tables\Filters\DateTogglesFilter;
use App\Filament\Tables\Filters\PlateFilter;
use App\Models\Scan;
use Filament\Facades\Filament;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class ClientSearchScansResource extends Resource
{
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-camera';

    protected static string|\UnitEnum|null $navigationGroup = 'Client Search';

    protected static ?string $navigationLabel = 'Scans';

    protected static ?string $slug = 'scans';

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('ocr')
                    ->label('Plate')
                    ->formatStateUsing(fn (Model $record, \stdClass $rowLoop): View => view(
                        'filament.tables.columns.plate-images',
                        [
                            'model' => $record,
                            'clickable' => false,
                            'showModal' => false,
                            'rowLoop' => $rowLoop,
                            'dispositionable' => false,
                        ],
                    )),

                DateTimeColumn::make('scanned_at')
                    ->label('Scanned')
                    ->showIcon()
                    ->sortable(),
            ])
            ->filters([
                Filter::make('filters')
                    ->columnSpanFull()
                    ->schema([
                        PlateFilter::make('plate')
                            ->label('Plate')
                            ->autofocus()
                            ->default(''),
                    ])
                    ->indicateUsing(function (array $data): ?array {
                        $indicators = [];

                        if ($data['plate']) {
                            $indicators[] = 'Plate: '.$data['plate'];
                        }

                        return $indicators !== []
                            ? $indicators
                            : null;
                    })
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when(
                            ($data['plate'] !== null && $data['plate'] !== ''),
                            fn (Builder $query): Builder => PlateFilter::modifyQuery(query: $query, column: 'ocr', plate: $data['plate']),
                            fn (Builder $query): Builder => $query->where('ocr', ''), // Don't show any results if no plate is specified.
                        )
                    ),

                DateTogglesFilter::make('scanned_at')
                    ->defaultDate('last_30d')
                    ->dateToggles([
                        'today' => 'Today',
                        'yesterday' => 'Yesterday',
                        'last_7d' => 'Last 7d',
                        'last_30d' => 'Last 30d',
                        'last_3m' => 'Last 3m',
                        'last_6m' => 'Last 6m',
                        'year' => 'Year',
                    ]),
            ])
            ->query(
                Scan::query()
                    ->forOrg(auth()->user()->accessible_repo_org_ids)
                    ->select([
                        'id',
                        'ocr',
                        'us_state',
                        'scanned_at',
                    ])
            )
            ->emptyStateHeading('No scans')
            ->emptyStateDescription('Search for a plate number under the filters section.')
            ->filtersLayout(Tables\Enums\FiltersLayout::Hidden);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListClientSearchScans::route('/'),
        ];
    }

    public static function canAccess(): bool
    {
        return (auth()->user()?->canAccessPanel(Filament::getPanel('client')) ?? false)
            || session()->has('admin_impersonator');
    }
}
