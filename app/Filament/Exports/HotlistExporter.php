<?php

namespace App\Filament\Exports;

use Carbon\Carbon;
use Filament\Actions\Exports\Enums\ExportFormat;
use Filament\Actions\Exports\ExportColumn;
use Filament\Actions\Exports\Exporter;
use Filament\Actions\Exports\Models\Export;
use Illuminate\Database\Eloquent\Model;

/**
 * @codeCoverageIgnore
 */
class HotlistExporter extends Exporter
{
    public static function getColumns(): array
    {
        return [
            ExportColumn::make('plate')
                ->label('Plate')
                ->default('N/A'),

            ExportColumn::make('status')
                ->label('Status')
                ->formatStateUsing(fn (Model $record): string => match ($record->status ?? 0) {
                    1 => 'Open',
                    3 => 'Repossessed',
                    default => 'Other',
                }),

            ExportColumn::make('vin')
                ->label('VIN'),

            ExportColumn::make('vehicle')
                ->label('YMM')
                ->formatStateUsing(fn (Model $record): string => $record->vehicle),

            ExportColumn::make('remote_order_id')
                ->label('Order ID')
                ->formatStateUsing(fn (Model $record): string => $record->remoteLink ? $record->remoteLink : $record->remote_order_id),

            ExportColumn::make('created_at')
                ->label('Order Date')
                ->formatStateUsing(function ($state): string {
                    $tz = auth()->user()?->timezone ?? 'UTC';

                    return $state instanceof Carbon
                        ? $state->setTimezone($tz)->format(config('app.date_format'))
                        : Carbon::parse($state, $tz)->format(config('app.date_format'));
                }),

            ExportColumn::make('client_org_id')
                ->label('Client')
                ->formatStateUsing(fn (Model $record): string => \htmlspecialchars_decode($record->client_org_name ?? 'N/A')),

            ExportColumn::make('lienholder_org_id')
                ->label('Lien Holder')
                ->formatStateUsing(fn (Model $record): string => \htmlspecialchars_decode($record->lienholder_org_name ?? 'N/A')),
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
        return 'hotlist-export';
    }

    public static function getCompletedNotificationBody(Export $export): string
    {
        $body = 'Your hotlist export has completed and '.number_format($export->successful_rows).' '.str('row')->plural($export->successful_rows).' exported.';

        if ($failedRowsCount = $export->getFailedRowsCount()) {
            $body .= ' '.number_format($failedRowsCount).' '.str('row')->plural($failedRowsCount).' failed to export.';
        }

        return $body;
    }
}
