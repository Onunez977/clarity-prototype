<?php

namespace App\Filament\Exports;

use App\Clickhouse\Views\ScanReport;
use Filament\Actions\Exports\Enums\ExportFormat;
use Filament\Actions\Exports\ExportColumn;
use Filament\Actions\Exports\Exporter;
use Filament\Actions\Exports\Models\Export;

/**
 * @codeCoverageIgnore
 */
class ScanReportExporter extends Exporter
{
    public static ?string $model = ScanReport::class;

    public static function getColumns(): array
    {
        return [
            ExportColumn::make('name')
                ->label('Name'),

            ExportColumn::make('one_hour')
                ->label('Last 1 Hour'),

            ExportColumn::make('four_hour')
                ->label('Last 4 Hours'),

            ExportColumn::make('one_day')
                ->label('Last 24 Hours'),

            ExportColumn::make('seven_day')
                ->label('Last 7 Days'),

            ExportColumn::make('thirty_day')
                ->label('Last 30 Days'),

            ExportColumn::make('one_year')
                ->label('Last 365 Days'),
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
        return 'scan-report-export';
    }

    public static function getCompletedNotificationBody(Export $export): string
    {
        $body = 'Your scan report export has completed and '.number_format($export->successful_rows).' '.str('row')->plural($export->successful_rows).' exported.';

        if ($failedRowsCount = $export->getFailedRowsCount()) {
            $body .= ' '.number_format($failedRowsCount).' '.str('row')->plural($failedRowsCount).' failed to export.';
        }

        return $body;
    }
}
