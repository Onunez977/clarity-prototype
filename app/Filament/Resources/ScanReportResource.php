<?php

namespace App\Filament\Resources;

use App\Clickhouse\Views\ScanReport;
use App\Facades\Analytics;
use App\Filament\Exports\ScanReportExporter;
use App\Filament\Resources\ScanReportResource\Pages;
use Filament\Actions\ExportAction;
use Filament\Actions\Exports\Models\Export;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\HtmlString;
use InsightLPR\BlackAdam\Enum\PermissionEnum;

class ScanReportResource extends Resource
{
    protected static ?string $model = ScanReport::class;

    protected static ?int $navigationSort = 1;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-document-chart-bar';

    protected static string|\UnitEnum|null $navigationGroup = 'Reports';

    protected static ?string $label = 'Scan Report';

    protected static ?string $navigationLabel = 'Scan Report';

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('Name')
                    ->sortable(),

                Tables\Columns\TextColumn::make('one_hour')
                    ->label('Last 1 Hour')
                    ->sortable(),

                Tables\Columns\TextColumn::make('four_hour')
                    ->label('Last 4 Hours')
                    ->sortable(),

                Tables\Columns\TextColumn::make('one_day')
                    ->label('Last 24 Hours')
                    ->sortable(),

                Tables\Columns\TextColumn::make('seven_day')
                    ->label('Last 7 Days')
                    ->sortable(),

                Tables\Columns\TextColumn::make('thirty_day')
                    ->label('Last 30 Days')
                    ->sortable(),

                Tables\Columns\TextColumn::make('one_year')
                    ->label('Last 365 Days')
                    ->sortable(),
            ])
            ->description(fn () => new HtmlString('This dataset is calculated in <a href="https://dateful.com/convert/utc" target="_blank" class="underline">Universal Time (UTC)</a>. Your timezone is '.auth()->user()->timezone.' ('.now(tz: auth()->user()->timezone)->format('T').')<br/>Example: Last 24 Hours is looking from '.now(tz: auth()->user()->timezone)->subDay()->format('m/d h:i A T').' to '.now(tz: auth()->user()->timezone)->format('m/d h:i A T')))
            ->headerActions([
                ExportAction::make('export')
                    ->label('CSV Export')
                    ->icon('heroicon-m-arrow-down-tray')
                    ->exporter(ScanReportExporter::class)
                    ->fileName(fn (Export $export): string => 'scan-report-'.$export->getKey())
                    ->chunkSize(250)
                    ->columnMapping(false)
                    ->modal(false)
                    ->after(fn () => Analytics::track('export', auth()->user(), ['action' => 'export'], 'scan-reports')),
            ])
            ->defaultSort('name')
            ->query(
                ScanReport::query()->where('org_id', auth()->user()->org_id)
            )
            ->paginationPageOptions([100, 250, 500])
            ->emptyStateHeading('No scan reports.');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListScanReports::route('/'),
        ];
    }

    public static function canAccess(): bool
    {
        return auth()->user()->can(PermissionEnum::Reports_View_Scan_Report->value);
    }
}
