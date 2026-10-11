@props([
    'showModal' => true,
    'rowLoop' => null,
    'model_id' => null,
    'showTabs' => false,
    'missedOpportunity' => false,
    'probableMatch' => false,
    'dispositionable' => false,
    'defaultDisposition' => null,
    'dispositionTooltip' => 'Disposition Scan',
    'trigger_icon' => 'heroicon-m-hand-thumb-down',
    'ymm' => null,
    'vin' => null,
    'clickable' => true,
    'filter' => 'ocr',
    'flexMatchActive' => false,
    'isFlexMatchPro' => false,
])

@php
    $model_id = $model_id ?? $model->id;
    $id = $rowLoop?->index ?? $model_id;
@endphp

<div
    x-data
    id="scan-{{ $id }}"
    class="flex items-center gap-2"
>
    @if ($showModal && $model->plateImage)
        <div
            class="relative cursor-pointer popup-trigger"
            x-on:click="$dispatch('show-plates-modal', {
                modelId: '{{ $model_id }}',
                title: '{{ $model->plate ?? $model->ocr }} {{ $model->us_state ? '/ '.$model->us_state : '' }}',
                ocr: '{{ $model->ocr }}',
                rowLoop: '{{ \json_encode($rowLoop) }}',
                vehicleImage: '{{ $model->vehicleImage }}',
                plateImage: '{{ $model->plateImage }}',
                vin: '{{ $vin }}',
                ymm: '{{ $ymm ?? '' }}',
                showTabs: '{{ $showTabs }}',
                missedOpportunity: '{{ $missedOpportunity }}',
                dispositionable: '{{ $dispositionable }}'
            })"
            x-tooltip="{
                content: 'View Fullscreen Images',
                theme: $store.theme,
            }"
        >
            @if ($model->kit_org_id && $model->kit_org_id !== auth()->user()->org_id)
                <x-filament::icon-button
                    icon="heroicon-m-star"
                    size="xs"
                    color="warning"
                    tooltip="Shared"
                    class="absolute -top-2 left-2"
                    data-shared-scan-id="{{ $model_id }}"
                />
            @endif

            <x-filament::avatar
                :src="$model->plateImage"
                :circular="false"
                tooltip="View Fullscreen Images"
                size="max-h-20"
                class="rounded min-w-[120px] w-[120px] min-h-[50px] max-h-[50px] mx-auto flex-none"
            />
        </div>
    @elseif ($model->plateImage)
        <div class="relative flex">
            @if ($model->kit_org_id && $model->kit_org_id !== auth()->user()->org_id)
                <x-filament::icon-button
                    icon="heroicon-m-star"
                    size="xs"
                    color="warning"
                    tooltip="Shared"
                    class="absolute -top-2 left-2"
                    data-shared-scan-id="{{ $model_id }}"
                />
            @endif

            <x-filament::avatar
                :src="$model->plateImage"
                :circular="false"
                alt="Plate image for {{ $model->ocr }}"
                size="max-h-20"
                class="rounded min-w-[120px] w-[120px] min-h-[50px] max-h-[50px] mx-auto flex-none"
            />
        </div>
    @endif

    <div class="flex flex-col gap-2">
        <button
            @class([
                'inline-flex items-center gap-1 cursor-pointer' => $clickable && $model->ocr !== '',
                'text-rose-600 dark:text-rose-400 font-semibold' => $probableMatch,
            ])
            @if ($clickable && $model->ocr !== '')
                wire:click="addFilter('{{ $filter }}', '{{ \strtoupper($model->ocr) }}')"
                x-tooltip="{
                    content: 'Filter by this Plate',
                    theme: $store.theme,
                }"
            @endif
        >
            {{ $model->ocr ?? 'N/A' }}

            @if ($probableMatch)
                <x-filament::icon
                    icon="heroicon-m-exclamation-triangle"
                    class="h-4 w-4 text-amber-500"
                    x-tooltip="{
                        content: 'Probable Match — OCR variant match, plate may not be exact',
                        theme: $store.theme,
                    }"
                />
            @endif

            <x-filament::loading-indicator
                class="h-4 w-4"
                wire:loading
                wire:target="addFilter('{{ $filter }}', '{{ \strtoupper($model->ocr) }}')"
            />
        </button>

        <div class="flex flex-row items-center gap-2 text-sm text-gray-500 dark:text-gray-400">
            {{ $model->us_state ?? '' }}

            @if ($flexMatchActive)
                <div
                    x-data="{ hasFlexMatch: false }"
                    x-init="
                        getFlexMatchHotlists('{{ $model_id }}').then(res => {
                            hasFlexMatch = (res.data?.hotlists?.length ?? 0) > 0;
                        }).catch(() => {})
                    "
                    x-show="hasFlexMatch"
                    x-cloak
                >
                    @if ($isFlexMatchPro)
                        <div
                            class="flex-match-trigger"
                            x-on:click="$dispatch('show-flex-match-modal', {
                                scanId: '{{ $model_id }}',
                                ocr: '{{ $model->ocr }}',
                                plateImage: '{{ $model->plateImage }}',
                                vehicleImage: '{{ $model->vehicleImage }}',
                            })"
                        >
                            <x-filament::icon-button
                                size="lg"
                                icon="heroicon-o-exclamation-triangle"
                                color="warning"
                                label="Possible Hotlist Match"
                                tooltip="Possible hotlist match via FlexSearch — click to review"
                            />
                        </div>
                    @else
                        <x-pro-upgrade-popover
                            :active="false"
                            description="Possible hotlist matches found via plate variation analysis. Upgrade to PRO to send alerts to ClearPlan."
                            feature="resend-to-clearplan"
                        >
                            <x-filament::icon-button
                                href="{{ route('upgrade.from', ['feature' => 'resend-to-clearplan']) }}"
                                size="lg"
                                icon="heroicon-o-exclamation-triangle"
                                color="warning"
                                label="Possible Hotlist Match"
                                tooltip="Possible hotlist match — upgrade to PRO"
                            />
                        </x-pro-upgrade-popover>
                    @endif
                </div>
            @endif

            @if ($dispositionable)
                <div
                    class="disposition-trigger"
                    x-on:click="$dispatch('show-disposition-modal', {
                        modelId: '{{ $model_id }}',
                        title: '{{ $model->plate ?? $model->ocr }} {{ $model->us_state ? '/ '.$model->us_state : '' }}',
                        rowLoop: '{{ \json_encode($rowLoop) }}',
                        vehicleImage: '{{ $model->vehicleImage }}',
                        plateImage: '{{ $model->plateImage }}',
                        ymm: '{{ $ymm ?? '' }}',
                        scannedAt: '{{ $model->scanned_at->setTimezone(auth()->user()->timezone)->format(config('app.date_format')) }}',
                        address: '{{ \Illuminate\Support\Str::of($model->address)->replace("'", "\'")->replace(["\n", "\r"], " ")->toString() }}',
                        lat: '{{ $model->latitude }}',
                        lng: '{{ $model->longitude }}',
                        missedOpportunity: '{{ $missedOpportunity }}',
                        disposition: '{{ $model->disposition ?? $defaultDisposition }}',
                        defaultDisposition: '{{ $defaultDisposition ?? null }}'
                    })"
                >
                    <x-filament::icon-button
                        size="lg"
                        :icon="$trigger_icon"
                        label="Disposition Scan"
                        tooltip="{{ ($defaultDisposition === \App\Enums\DispositionEnum::INVALID_SCAN->value) ? 'Mark this Scan as Invalid' : $dispositionTooltip }}"
                    />
                </div>
            @endif
        </div>
    </div>
</div>
