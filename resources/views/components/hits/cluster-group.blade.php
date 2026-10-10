@props([
    'open' => false,
    'group' => collect(),
    'table' => null,
    'filterStartDate' => null,
    'filterEndDate' => null,
    'addressMode' => false,
    'filterCoordinates' => [],
    'searchedPlate' => null,
    'flexSearchEnabled' => false,
])

@php
    $first = $group->first();
    $last = $group->last();
    $scanMetadata = $first->scan_metadata ?? null;
    if (\gettype($scanMetadata) === 'string') {
        $scanMetadata = \json_decode($scanMetadata);
    }
    $dto = \App\Dto\ScanViewDto::fromHitRecord($first, $scanMetadata);

    if ($addressMode) {
        $plates = $group->pluck('scans_ocr')->filter()->unique()->values();
        $headingText = 'Plates: ' . $plates->take(3)->implode(', ');
        if ($plates->count() > 3) {
            $headingText .= ' + ' . ($plates->count() - 3) . ' more';
        }
    } else {
        $headingText = 'Plate: ' . \trim(($first->scans_ocr ?? 'N/A') . ' ' . ($first->scans_us_state ?? ''));
    }

    $lprVisitsActive = session()->has('admin_impersonator')
        || \Laravel\Pennant\Feature::active(\App\Enums\FeatureEnum::LPR_VISITS->slug());

    $isCloseMatch = ! $addressMode
        && \App\Support\FlexSearch::isCloseMatch($searchedPlate, $first->scans_ocr ?? null, $flexSearchEnabled);
@endphp

<div
    wire:key="hit-cluster-{{ $group->pluck('id')->sort()->implode('-') }}"
    x-data="{
        heading: '{{ $headingText }}',
        centerPoint: '{{ $group->avg('latitude') }}, {{ $group->avg('longitude') }}',
        lprVisitText: '',

        init() {
            geocodeReverse({{ $group->avg('latitude') }}, {{ $group->avg('longitude') }})
                .then((res) => {
                    var a = res.data?.results[0]?.formatted_address ?? '';
                    if (a !== '') {
                        this.centerPoint = a;
                    }
                });

            @if ($lprVisitsActive)
            fetchLprVisitCount(
                {{ $group->avg('latitude') }},
                {{ $group->avg('longitude') }},
                '{{ $last->scans_scanned_at ? \Illuminate\Support\Carbon::parse($last->scans_scanned_at)->toDateString() : ($filterStartDate ?? '') }}',
                '{{ $first->scans_scanned_at ? \Illuminate\Support\Carbon::parse($first->scans_scanned_at)->toDateString() : ($filterEndDate ?? '') }}'
            ).then((res) => {
                const data = res.data;
                if (data) {
                    this.lprVisitText = data.last_visit
                        ? `${data.lpr_visits ?? 0} visits in the past 60 days, last visit ${data.last_visit}`
                        : `${data.lpr_visits ?? 0} visits in the past 60 days`;
                }
            }).catch(() => {});
            @endif
        },

        copyToClipboard: function () {
            let text = '';

            @if ($group->count() > 1)
                text = '{{ $group->count() }} Hits {{ $first->scans_scanned_at ? \Illuminate\Support\Carbon::parse($first->scans_scanned_at)->setTimezone(auth()->user()->timezone)->format('h:i A T') : 'N/A' }} - {{ $last->scans_scanned_at ? \Illuminate\Support\Carbon::parse($last->scans_scanned_at)->setTimezone(auth()->user()->timezone)->format('h:i A T') : 'N/A' }}';
                text += ', NEWEST: {{ $first->scans_scanned_at ? \Illuminate\Support\Carbon::parse($first->scans_scanned_at)->setTimezone(auth()->user()->timezone)->format(config('app.date_format')) : 'N/A' }}';
            @else
                text = '1 Hit {{ $first->scans_scanned_at ? \Illuminate\Support\Carbon::parse($first->scans_scanned_at)->setTimezone(auth()->user()->timezone)->format(config('app.date_format')) : '' }}';
            @endif

            try {
                navigator.clipboard.writeText(text);

                new FilamentNotification()
                    .title('Copied to Clipboard!')
                    .body(text)
                    .color('info')
                    .send()
            } catch (e) {
                try {
                    new FilamentNotification()
                        .title('There was an error copying to clipboard.')
                        .body('Please try again or copy the text manually.')
                        .color('warning')
                        .send()
                } catch (e) {
                    //
                }
            }
        },
    }"
>
    <x-filament::section
        :collapsed="!$open"
        :collapsible="true"
        class="cluster-group-section"
    >
        <x-slot name="heading">
            <span @class(['text-rose-600 dark:text-rose-400' => $isCloseMatch]) x-text="heading"></span>
            <small>(Count: {{ $group->count() }})</small>
        </x-slot>

        <x-slot name="description">
            <div class="flex flex-col lg:flex-row justify-between items-start lg:items-center gap-2">
                <div class="flex flex-col gap-1 break-words text-sm lg:text-base text-gray-500 dark:text-gray-400">
                    <span>
                        <strong>Location{{ $group->count() > 1 ? ' Near' : '' }}:</strong>
                        <span x-text="centerPoint"></span>
                    </span>
                    <span>
                        <strong>{{ $group->count() > 1 ? 'First Scan' : 'Scanned At' }}:</strong> {{ $last->scans_scanned_at ? \Illuminate\Support\Carbon::parse($last->scans_scanned_at)->setTimezone(auth()->user()->timezone)->format(config('app.date_format')) : 'N/A' }}
                    </span>
                    @if ($last->id !== $first->id)
                        <span>
                            <strong>Last Scan:</strong> {{ $first->scans_scanned_at ? \Illuminate\Support\Carbon::parse($first->scans_scanned_at)->setTimezone(auth()->user()->timezone)->format(config('app.date_format')) : 'N/A' }}
                        </span>
                    @endif
                    @if ($lprVisitsActive)
                        <span x-show="lprVisitText" x-cloak class="flex items-center gap-1">
                            <x-filament::icon-button
                                icon="heroicon-o-information-circle"
                                color="gray"
                                size="sm"
                                tooltip="LPR Visits represents the number of times an LPR-equipped vehicle passed through this location. Scans are grouped into 1-hour time blocks — each block with at least one scan counts as one visit."
                            />
                            <strong>LPR Visits:</strong>
                            <span x-text="lprVisitText"></span>
                        </span>
                    @else
                        <x-pro-upgrade-popover
                            :active="false"
                            description="Visits is a count of how many times one of the LPR systems drove by a particular address, regardless of how many scans it may have captured."
                            feature="lpr-visits"
                        >
                            <span class="cursor-default">
                                <x-heroicon-m-lock-closed class="w-3.5 h-3.5 inline-flex align-middle -mt-0.5" />
                                <strong>Visits:</strong>
                                <span class="inline-flex items-center rounded-md bg-primary-500/20 px-1.5 py-0.5 text-xs font-semibold text-primary-400 align-middle">Pro</span>
                                Upgrade to unlock
                            </span>
                        </x-pro-upgrade-popover>
                    @endif
                </div>

                <div class="flex flex-row gap-2">
                    @if ($dto->plateImage)
                        <x-filament::avatar
                            :src="$dto->plateImage"
                            :circular="false"
                            size="max-h-20"
                            class="rounded min-w-[120px] w-[120px] min-h-[50px] max-h-[50px] mx-auto flex-none"
                        />
                    @endif

                    <x-filament::button
                        size="sm"
                        color="gray"
                        outlined="true"
                        icon-position="after"
                        tooltip="Copy to Clipboard"
                        icon="heroicon-m-clipboard-document-list"
                        x-on:click="copyToClipboard()"
                    >
                        Copy
                    </x-filament::button>
                </div>
            </div>
        </x-slot>

        <div class="flex flex-col">
            <div class="cluster-map-button">
                <x-filament::button
                    size="sm"
                    outlined="true"
                    icon="heroicon-o-map"
                    x-on:click="$dispatch('load-records-for-map', { recordIds: {{ $group->pluck('id')->toJson() }}, center: { lat: {{ $group->avg('latitude') }}, lng: {{ $group->avg('longitude') }} } })"
                >
                    Show Cluster on Map
                </x-filament::button>
            </div>

            <div class="section-headers hidden lg:grid lg:grid-cols-5 gap-4 space-y-4 lg:space-y-0">
                <div class="fi-ta-header-cell">Plate</div>
                <div class="fi-ta-header-cell">Scanned</div>
                <div class="fi-ta-header-cell">Address</div>
                <div class="fi-ta-header-cell">Client</div>
                <div class="fi-ta-header-cell">Status</div>
            </div>

            <div @class([
                'divide-y divide-gray-200 overflow-hidden bg-white ring-1 ring-gray-950/5 dark:divide-white/10 dark:bg-gray-900 dark:ring-white/10' => true,
                'flex-match' => $isCloseMatch,
            ])>
                @foreach($group as $record)
                    @php
                        $recordMeta = $record->scan_metadata ?? null;
                        if (\gettype($recordMeta) === 'string') {
                            $recordMeta = \json_decode($recordMeta);
                        }
                        $recordDto = \App\Dto\ScanViewDto::fromHitRecord($record, $recordMeta);
                        $recordIsCloseMatch = \App\Support\FlexSearch::isCloseMatch($searchedPlate, $record->scans_ocr ?? null, $flexSearchEnabled);
                        $recordIsProbable = ($record->hit_type ?? $record->type ?? null) === \App\Enums\HitTypeEnum::Probable_Match->value;
                    @endphp
                    <x-filament::fieldset data-cluster-record="{{ $record->id }}">
                        <div class="grid grid-cols-1 lg:grid-cols-5 gap-4 space-y-4 lg:space-y-0">
                            @include('filament.tables.columns.plate-images', [
                                'model' => $recordDto,
                                'model_id' => $record->scan_id,
                                'showTabs' => true,
                                'dispositionable' => ($record->kit_org_id ?? null) === auth()->user()->org_id,
                                'probableMatch' => $recordIsProbable || $recordIsCloseMatch,
                                'trigger_icon' => 'heroicon-o-viewfinder-circle',
                                'vin' => $record->hotlist_vin,
                                'ymm' => \trim(\html_entity_decode(($record->hotlist_vehicle_year ?? '').' '.($record->hotlist_vehicle_make ?? '').' '.($record->hotlist_vehicle_model ?? ''))),
                            ])

                            {!!
                                \App\Filament\Tables\Columns\DateTimeColumn::make('scans_scanned_at')
                                    ->label('Scanned')
                                    ->showIcon()
                                    ->table($table)
                                    ->record($record)
                                    ->renderInLayout()
                            !!}

                            @include('filament.tables.columns.geo-address', [
                               'model' => $recordDto,
                               'model_id' => $record->scan_id,
                               'address' => $recordDto->address,
                            ])

                            {!!
                                \Filament\Tables\Columns\TextColumn::make('client_org_name')
                                    ->label('Client')
                                    ->default('N/A')
                                    ->table($table)
                                    ->record($record)
                                    ->renderInLayout()
                            !!}

                            <div class="flex items-center">
                                @php
                                    $recStatus = match ((int) ($record->hotlist_status ?? 0)) {
                                        1 => 'Open',
                                        3 => 'Repossessed',
                                        default => 'Other',
                                    };
                                    $recColor = match ((int) ($record->hotlist_status ?? 0)) {
                                        1 => 'success',
                                        3 => 'danger',
                                        default => 'warning',
                                    };
                                @endphp
                                <x-filament::badge :color="$recColor">{{ $recStatus }}</x-filament::badge>
                            </div>
                        </div>
                    </x-filament::fieldset>
                @endforeach
            </div>
        </div>
    </x-filament::section>
</div>
