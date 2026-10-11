<?php

namespace App\Filament\Exports;

use App\Clickhouse\Models\RecoveryKitLeaderboard;
use Filament\Actions\Exports\Enums\ExportFormat;
use Filament\Actions\Exports\ExportColumn;
use Filament\Actions\Exports\Exporter;
use Filament\Actions\Exports\Models\Export;

/**
 * @codeCoverageIgnore
 */
class RecoveryLeaderboardExporter extends Exporter
{
    public static ?string $model = RecoveryKitLeaderboard::class;

    public static function getColumns(): array
    {
        return [
            ExportColumn::make('name')
                ->label('Kit Name'),

            ExportColumn::make('total')
                ->label('Total')
                ->default(0),

            ExportColumn::make('rank')
                ->label('Rank')
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
        return 'recovery-leader-export';
    }

    public static function getCompletedNotificationBody(Export $export): string
    {
        $body = 'Your recovery leaderboard export has completed and '.number_format($export->successful_rows).' '.str('row')->plural($export->successful_rows).' exported.';

        if ($failedRowsCount = $export->getFailedRowsCount()) {
            $body .= ' '.number_format($failedRowsCount).' '.str('row')->plural($failedRowsCount).' failed to export.';
        }

        return $body;
    }
}
