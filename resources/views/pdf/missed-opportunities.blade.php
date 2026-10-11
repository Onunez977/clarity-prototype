@extends('pdf.base')
@section('content')
    <p class="text-center">
        Generic template wording goes here...
    </p>

    <div class="grid grid-cols-4 gap-4 mt-6 mb-2 px-6">
        <h3 class="mx-2 font-bold">Plate</h3>
        <h3 class="mx-2 font-bold">Created</h3>
        <h3 class="mx-2 font-bold">Scanned</h3>
        <h3 class="mx-2 font-bold">Validation</h3>
    </div>

    <div class="flex flex-col flex-nowrap gap-2">
        @forelse($records ?? [] as $record)
            <x-filament::fieldset>
                <div class="grid grid-cols-4 gap-4">
                    @include('filament.tables.columns.plate-images', [
                        'model_id' => $record->scan_id,
                        'model' => \App\Dto\ScanViewDto::fromHitRecord($record),
                        'showModal' => false,
                        'clickable' => false,
                        'dispositionable' => false,
                        'vin' => $record->hotlist_vin,
                        'ymm' => \trim(\html_entity_decode($record->hotlist_vehicle_year.' '.$record->hotlist_vehicle_make.' '.$record->hotlist_vehicle_model)),
                    ])

                    {!!
                        \App\Filament\Tables\Columns\DateTimeColumn::make('created_at')
                            ->label('Created')
                            ->table($table)
                            ->record($record)
                            ->renderInLayout()
                    !!}

                    {!!
                        \App\Filament\Tables\Columns\DateTimeColumn::make('scans_scanned_at')
                            ->label('Scanned')
                            ->table($table)
                            ->record($record)
                            ->renderInLayout()
                    !!}

                    {!!
                        \Filament\Tables\Columns\TextColumn::make('scan_metadata')
                            ->label('Validation')
                            ->table($table)
                            ->record($record)
                            ->default('Awaiting Review')
                            ->formatStateUsing(function (mixed $state): string {
                                if (\gettype($state) === 'string') {
                                    $state = \json_decode($state);
                                }

                                return $state->disposition ?? 'Awaiting Review';
                            })
                            ->renderInLayout()
                        !!}
                </div>
            </x-filament::fieldset>
        @empty
            <p class="text-center">
                No records found.
            </p>
       @endforelse
    </div>
@endsection
