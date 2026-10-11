<?php

namespace App\Filament\Exports;

use App\Clickhouse\Models\RecoveryKitScanReport;
use App\Enums\HotlistStatusEnum;
use Carbon\Carbon;
use Filament\Actions\Exports\Enums\ExportFormat;
use Filament\Actions\Exports\ExportColumn;
use Filament\Actions\Exports\Exporter;
use Filament\Actions\Exports\Models\Export;

/**
 * @codeCoverageIgnore
 */
class ScanPercentageExporter extends Exporter
{
    public static ?string $model = RecoveryKitScanReport::class;

    public static function getColumns(): array
    {
        return [
            ExportColumn::make('kit_name')
                ->label('Kit Name'),

            ExportColumn::make('vin')
                ->label('VIN'),

            ExportColumn::make('plate')
                ->label('Plate'),

            ExportColumn::make('status')
                ->label('Status')
                ->formatStateUsing(fn (mixed $state): string => match ($state) {
                    HotlistStatusEnum::Other => 'Other',
                    HotlistStatusEnum::Open => 'Open',
                    HotlistStatusEnum::Repossessed => 'Repossessed',
                    default => 'Unknown',
                }),

            ExportColumn::make('hotlist_scan_percentage')
                ->label('Scan Percentage')
                ->default('0%')
                ->formatStateUsing(fn ($state): string => $state !== '0%' ? \number_format((float) $state, 2).'%' : $state),

            ExportColumn::make('repo_date')
                ->label('Recovery Date')
                ->formatStateUsing(function ($state): string {
                    if (is_null($state)) {
                        return 'N/A';
                    }

                    return $state instanceof Carbon
                        ? $state->setTimezone(auth()->user()?->timezone ?? 'UTC')->format(config('app.date_format'))
                        : Carbon::parse($state, auth()->user()?->timezone ?? 'UTC')->format(config('app.date_format'));
                }),

            ExportColumn::make('count')
                ->label('Kit Scan Count')
                ->default(0),

            ExportColumn::make('hotlist_scan_count')
                ->label('Repo Scan Count')
                ->default(0),
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
        return 'scan-percentage-export';
    }

    public static function getCompletedNotificationBody(Export $export): string
    {
        $body = 'Your scan percentage export has completed and '.number_format($export->successful_rows).' '.str('row')->plural($export->successful_rows).' exported.';

        if ($failedRowsCount = $export->getFailedRowsCount()) {
            $body .= ' '.number_format($failedRowsCount).' '.str('row')->plural($failedRowsCount).' failed to export.';
        }

        return $body;
    }
}
