@props([
    'model_id' => null,
    'address' => null,
])

<div class="flex flex-col items-start justify-center"
     x-data="{
        open: false,
        address: '{{ \Illuminate\Support\Str::of($address ?? $model->address)->replace("'", "\'")->replace(["\n", "\r"], " ")->replaceFirst(', ', ",\\n")->toString() }}',

        init() {
            if (this.address === '') {
                this.address = '{{ \implode(', ', $model->coordinates) }}';
                geocodeReverse({{ $model->latitude }}, {{ $model->longitude }}, {{ get_class($model) === \App\Models\Scan::class ? ($model_id ?? $model->id) : null }})
                    .then((res) => {
                        var a = res.data?.results[0]?.formatted_address ?? '';
                        if (a !== '') {
                            this.address = a.replace(', ', ',\n');
                        }
                    });
            }
        },
     }"
>
    <div
        class="flex gap-1"
        :class="{ 'items-center': address === '', 'flex-col items-start': address !== '{{ $model->latitude }}, {{ $model->longitude }}' }"
    >
        <div class="inline-flex items-center gap-1">
            <x-filament::link
                tag="a"
                color="gray"
                class="cursor-pointer"
                tooltip="Filter for scans around this address"
                wire:click="addFilter(['lat', 'lon'], ['{{ $model->latitude }}', '{{ $model->longitude }}'])"
                style="white-space: pre;"
                x-text="address"
            />
            <x-filament::loading-indicator
                class="h-4 w-4"
                wire:loading
                wire:target="addFilter(['lat', 'lon'], ['{{ $model->latitude }}', '{{ $model->longitude }}'])"
            />
        </div>

        <div class="flex items-center">
            <x-filament::icon-button
                icon="heroicon-o-map-pin"
                href="https://www.google.com/maps/search/?api=1&query={{ \implode(', ', $model->coordinates) }}"
                tag="a"
                tooltip="Open in Google Maps"
                target="_blank"
            />

            <span :class="{ '!hidden': address === '{{ $model->latitude }}, {{ $model->longitude }}' }">
                <x-filament::icon-button
                    icon="heroicon-o-information-circle"
                    class="cursor-pointer"
                    color="gray"
                    tooltip="View additional details"
                    x-on:click="open = !open"
                />
            </span>
        </div>

        @if (($model->same_location_count ?? 0) > 0)
            <span class="block text-xs">
                Scanned {{ $model->same_location_count }} more time{{ $model->same_location_count > 1 ? 's' : '' }} at this location within 45 minutes.
            </span>
        @endif
    </div>

    <p class="mt-2" x-show="open">
        Lat/Long: <span class="font-bold">{{ \implode(', ', $model->coordinates) }}</span>
    </p>
</div>
