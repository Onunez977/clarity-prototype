<?php

declare(strict_types=1);

namespace App\Filament\Exports;

use App\Clickhouse\Models\LprRepossessionExportRow;
use Filament\Actions\Exports\Enums\ExportFormat;
use Filament\Actions\Exports\ExportColumn;
use Filament\Actions\Exports\Exporter;
use Filament\Actions\Exports\Models\Export;

class LprRepossessionExporter extends Exporter
{
    public static ?string $model = LprRepossessionExportRow::class;

    /**
     * @return array<int, ExportColumn>
     */
    public static function getColumns(): array
    {
        return [
            ExportColumn::make('vin')
                ->label('VIN'),

            ExportColumn::make('plate')
                ->label('Plate')
                ->default(''),

            ExportColumn::make('case_number')
                ->label('RDN Case')
                ->default(''),

            ExportColumn::make('claimed_by')
                ->label('Claimed By')
                ->default(''),

            ExportColumn::make('repo_agent_name')
                ->label('Repo Agent Name')
                ->default(''),
        ];
    }

    /**
     * @return array<int, ExportFormat>
     */
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
        return 'lpr-repossession-export';
    }

    public static function getCompletedNotificationBody(Export $export): string
    {
        $body = 'Your LPR repossession export has completed and '.number_format($export->successful_rows).' '.str('row')->plural($export->successful_rows).' exported.';

        if ($failedRowsCount = $export->getFailedRowsCount()) {
            $body .= ' '.number_format($failedRowsCount).' '.str('row')->plural($failedRowsCount).' failed to export.';
        }

        return $body;
    }
}
