<?php

namespace App\Filament\Resources;

use App\Clickhouse\Models\AffiliateStatsHourly;
use App\Facades\Analytics;
use App\Filament\Exports\AffiliateStatsExporter;
use App\Filament\Resources\AffiliateStatsResource\Pages;
use App\Filament\Tables\Filters\DateTogglesFilter;
use App\Filament\Tables\Filters\KitUsersFilter;
use Filament\Actions\ExportAction;
use Filament\Actions\Exports\Models\Export;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use InsightLPR\BlackAdam\Enum\PermissionEnum;

class AffiliateStatsResource extends Resource
{
    protected static ?string $model = AffiliateStatsHourly::class;

    protected static ?int $navigationSort = 2;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-chart-bar';

    protected static string|\UnitEnum|null $navigationGroup = 'Reports';

    protected static ?string $navigationLabel = 'Affiliate Stats';

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('Name')
                    ->sortable(),

                Tables\Columns\TextColumn::make('scan_total')
                    ->label('Total Scans')
                    ->default('0')
                    ->sortable(),

                Tables\Columns\TextColumn::make('unique_scans')
                    ->label('Unique Scans')
                    ->default('0')
                    ->sortable(),

                Tables\Columns\TextColumn::make('unique_ratio')
                    ->label('Unique Ratio')
                    ->default('0')
                    ->formatStateUsing(fn (Model $record): string => $record->unique_scans ? round(($record->unique_scans / $record->scan_total) * 100, 1).'%' : '0%'),

                Tables\Columns\TextColumn::make('total_days')
                    ->label('Days')
                    ->default('0')
                    ->sortable(),

                Tables\Columns\TextColumn::make('average_per_day')
                    ->label('Average Per Day')
                    ->default('0')
                    ->sortable()
                    ->toggleable()
                    ->formatStateUsing(fn (Model $record): float|int => $record->total_days ? round($record->scan_total / $record->total_days, 0) : 0),

                Tables\Columns\TextColumn::make('total_hours')
                    ->label('Hours')
                    ->sortable()
                    ->default('0'),

                Tables\Columns\TextColumn::make('average_per_hour')
                    ->label('Average Per Hour')
                    ->default('0')
                    ->sortable()
                    ->toggleable()
                    ->formatStateUsing(fn (Model $record): float|int => $record->total_hours > 0 ? round($record->scan_total / $record->total_hours, 0) : 0),
            ])
            ->filters([
                Filter::make('filters')
                    ->columnSpanFull()
                    ->schema([
                        KitUsersFilter::make('kits')
                            ->label('Kit Users')
                            ->multiple(),
                    ])
                    ->indicateUsing(function (array $data): ?array {
                        $indicators = [];

                        if ($data['kits']) {
                            $indicators[] = KitUsersFilter::indicator($data['kits']);
                        }

                        return $indicators !== []
                            ? $indicators
                            : null;
                    }),

                DateTogglesFilter::make('date')
                    ->defaultDate('this_month')
                    ->dateToggles([
                        'yesterday' => 'Yesterday',
                        'this_month' => 'This Month',
                        'last_month' => 'Last Month',
                    ])
                    ->modifyQueryUsing(null),
            ])
            ->toolbarActions([
                ExportAction::make('export')
                    ->label('CSV Export')
                    ->icon('heroicon-m-arrow-down-tray')
                    ->exporter(AffiliateStatsExporter::class)
                    ->fileName(fn (Export $export): string => 'affiliate-stats-'.$export->getKey())
                    ->chunkSize(250)
                    ->columnMapping(false)
                    ->modal(false)
                    ->after(fn () => Analytics::track('export', auth()->user(), ['action' => 'export'], 'affiliate-stats')),
            ])
            ->query(function (HasTable $livewire) {
                $filters = $livewire->getTable()->getFilters()['filters']->getState();
                $dates = $livewire->getTable()->getFilters()['date']->getState();
                $kitIds = KitUsersFilter::format($filters['kits'] ?? []);

                return AffiliateStatsHourly::affiliateStatsQuery(
                    orgId: auth()->user()->org_id,
                    kitIds: $kitIds,
                    start: isset($dates['start']) ? Carbon::parse(convertToUtc((string) $dates['start'])) : now()->utc()->startOfMonth()->startOfDay(),
                    end: isset($dates['end']) ? Carbon::parse(convertToUtc((string) $dates['end'])) : now()->utc()->endOfDay(),
                );
            })
            ->emptyStateHeading('No affiliate stats.')
            ->filtersLayout(Tables\Enums\FiltersLayout::Hidden);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListAffiliateStats::route('/'),
        ];
    }

    public static function canAccess(): bool
    {
        return auth()->user()->can(PermissionEnum::Reports_View_Affiliate_Stats->value);
    }
}
