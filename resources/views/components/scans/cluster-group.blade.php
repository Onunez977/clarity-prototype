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
    if ($addressMode) {
        $plates = $group->pluck('ocr')->filter()->unique()->values();
        $headingText = 'Plates: ' . $plates->take(3)->implode(', ');
        if ($plates->count() > 3) {
            $headingText .= ' + ' . ($plates->count() - 3) . ' more';
        }
    } else {
        $headingText = 'Plate: ' . \trim(($group->first()?->ocr ?? 'N/A') . ' ' . ($group->first()?->us_state ?? ''));
    }

    $clusterLat = ($addressMode && !empty($filterCoordinates['lat'])) ? $filterCoordinates['lat'] : $group->avg('latitude');
    $clusterLng = ($addressMode && !empty($filterCoordinates['lon'])) ? $filterCoordinates['lon'] : $group->avg('longitude');

    $lprVisitsActive = session()->has('admin_impersonator')
        || \Laravel\Pennant\Feature::active(\App\Enums\FeatureEnum::LPR_VISITS->slug());

    $isCloseMatch = ! $addressMode
        && \App\Support\FlexSearch::isCloseMatch($searchedPlate, $group->first()?->ocr, $flexSearchEnabled);
@endphp

<div
    wire:key="scan-cluster-{{ $group->pluck('id')->sort()->implode('-') }}"
    x-data="{
        heading: '{{ $headingText }}',
        centerPoint: '{{ $clusterLat }}, {{ $clusterLng }}',
        lprVisitText: '',

        init() {
            geocodeReverse({{ $clusterLat }}, {{ $clusterLng }})
                .then((res) => {
                    var a = res.data?.results[0]?.formatted_address ?? '';
                    if (a !== '') {
                        this.centerPoint = a;
                    }
                });

            @if ($lprVisitsActive)
            fetchLprVisitCount(
                {{ $clusterLat }},
                {{ $clusterLng }},
                '{{ $group->last()?->scanned_at?->toDateString() ?? $filterStartDate ?? '' }}',
                '{{ $group->first()?->scanned_at?->toDateString() ?? $filterEndDate ?? '' }}'
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
                text = '{{ $group->count() }} LPRs {{ $group->first()?->scanned_at?->setTimezone(auth()->user()->timezone)->format('h:i A T') ?? 'N/A' }} - {{ $group->last()?->scanned_at?->setTimezone(auth()->user()->timezone)->format('h:i A T') ?? 'N/A' }}';
                text += ', NEWEST: {{ $group->first()?->scanned_at?->setTimezone(auth()->user()->timezone)->format(config('app.date_format')) ?? 'N/A' }}';
            @else
                text = '1 LPR {{ $group->first()?->scanned_at?->setTimezone(auth()->user()->timezone)->format(config('app.date_format')) ?? '' }}';
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
            <span @class(['text-rose-600 dark:text-rose-400' => $isCloseMatch]) x-text="heading"></span> <small>(Count: {{ $group->count() }})</small>
        </x-slot>

        <x-slot name="description">
            <div class="flex flex-col lg:flex-row justify-between items-start lg:items-center gap-2">
                <div class="flex flex-col gap-1 break-words text-sm lg:text-base text-gray-500 dark:text-gray-400">
                    <span>
                        <strong>Location{{ $group->count() > 1 ? ' Near' : '' }}:</strong>
                        <span x-text="centerPoint"></span>
                    </span>
                    <span>
                        <strong>{{ $group->count() > 1 ? 'First Scan' : 'Scanned At' }}:</strong> {{ $group->last()?->scanned_at?->setTimezone(auth()->user()->timezone)->format(config('app.date_format')) ?? 'N/A' }}
                    </span>
                    @if ($group->last()->id !== $group->first()->id)
                        <span>
                            <strong>Last Scan:</strong> {{ $group->first()?->scanned_at?->setTimezone(auth()->user()->timezone)->format(config('app.date_format')) ?? 'N/A' }}
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
                    @if ($group->first()?->plateImage || $group->last()?->plateImage)
                        <x-filament::avatar
                            :src="$group->first()?->plateImage ?? $group->last()?->plateImage"
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
                    x-on:click="$dispatch('load-records-for-map', { recordIds: {{ $group->pluck('id')->toJson() }}, center: { lat: {{ $clusterLat }}, lng: {{ $clusterLng }} } })"
                >
                    Show Cluster on Map
                </x-filament::button>
            </div>

            <div class="section-headers hidden lg:grid lg:grid-cols-4 gap-4 space-y-4 lg:space-y-0">
                @foreach (($table?->getVisibleColumns() ?? []) as $column)
                    <div class="fi-ta-header-cell">
                        {{ $column->getLabel() }}
                    </div>
                @endforeach
            </div>

            <div @class([
                'divide-y divide-gray-200 overflow-hidden bg-white ring-1 ring-gray-950/5 dark:divide-white/10 dark:bg-gray-900 dark:ring-white/10' => true,
                'flex-match' => $isCloseMatch,
            ])>
                @foreach($group as $record)
                    @php
                        $recordIsCloseMatch = \App\Support\FlexSearch::isCloseMatch($searchedPlate, $record->ocr, $flexSearchEnabled);
                    @endphp
                    <x-filament::fieldset data-cluster-record="{{ $record->id }}">
                        <div class="grid grid-cols-1 lg:grid-cols-4 gap-4 space-y-4 lg:space-y-0">
                            @include('filament.tables.columns.plate-images', [
                                'model' => $record,
                                'showTabs' => true,
                                'dispositionable' => $record->kit_org_id === auth()->user()->org_id,
                                'trigger_icon' => 'heroicon-m-hand-thumb-down',
                                'defaultDisposition' => \App\Enums\DispositionEnum::INVALID_SCAN->value,
                                'probableMatch' => $recordIsCloseMatch,
                            ])

                            {!!
                                \App\Filament\Tables\Columns\DateTimeColumn::make('scanned_at')
                                    ->label('Scanned')
                                    ->showIcon()
                                    ->table($table)
                                    ->record($record)
                                    ->renderInLayout()
                            !!}

                            @include('filament.tables.columns.geo-address', [
                               'model' => $record,
                            ])

                            {!!
                                \Filament\Tables\Columns\TextColumn::make('kit_name')
                                    ->label('User')
                                    ->default('N/A')
                                    ->table($table)
                                    ->record($record)
                                    ->renderInLayout()
                            !!}
                        </div>
                    </x-filament::fieldset>
                @endforeach
            </div>
        </div>
    </x-filament::section>
</div>
