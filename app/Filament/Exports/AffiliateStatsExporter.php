<?php

namespace App\Filament\Exports;

use App\Clickhouse\Models\AffiliateStatsHourly;
use Filament\Actions\Exports\Enums\ExportFormat;
use Filament\Actions\Exports\ExportColumn;
use Filament\Actions\Exports\Exporter;
use Filament\Actions\Exports\Models\Export;
use Illuminate\Database\Eloquent\Model;

/**
 * @codeCoverageIgnore
 */
class AffiliateStatsExporter extends Exporter
{
    public static ?string $model = AffiliateStatsHourly::class;

    public static function getColumns(): array
    {
        return [
            ExportColumn::make('name')
                ->label('Name'),

            ExportColumn::make('scan_total')
                ->label('Total Scans')
                ->default('0'),

            ExportColumn::make('unique_scans')
                ->label('Unique Scans')
                ->default('0'),

            ExportColumn::make('unique_ratio')
                ->label('Unique Ratio')
                ->state(fn (Model $record): string => $record->unique_scans ? round(($record->unique_scans / $record->scan_total) * 100, 1).'%' : '0%'),

            ExportColumn::make('total_days')
                ->label('Days')
                ->default('0'),

            ExportColumn::make('average_per_day')
                ->label('Average Per Day')
                ->default('0')
                ->state(fn (Model $record): float|int => $record->total_days ? round($record->scan_total / $record->total_days, 0) : 0),

            ExportColumn::make('total_hours')
                ->label('Hours')
                ->default('0'),

            ExportColumn::make('average_per_hour')
                ->label('Average Per Hour')
                ->default('0')
                ->state(fn (Model $record): float|int => $record->total_hours > 0 ? round($record->scan_total / $record->total_hours, 0) : 0),
        ];
    }

    public function getFormats(): array
    {
        return [
            ExportFormat::Csv,
        ];
    }

    public function getFileDisk(): string
    {
        return 's3';
    }

    public function getJobQueue(): ?string
    {
        return '{high}';
    }

    public function getJobBatchName(): ?string
    {
        return 'affiliate-stats-export';
    }

    public static function getCompletedNotificationBody(Export $export): string
    {
        $body = 'Your affiliate stats export has completed and '.number_format($export->successful_rows).' '.str('row')->plural($export->successful_rows).' exported.';

        if ($failedRowsCount = $export->getFailedRowsCount()) {
            $body .= ' '.number_format($failedRowsCount).' '.str('row')->plural($failedRowsCount).' failed to export.';
        }

        return $body;
    }
}
