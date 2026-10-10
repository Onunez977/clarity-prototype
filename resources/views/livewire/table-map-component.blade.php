<div class="{{ ! $hasData ? 'hidden' : '' }}" x-show-map="{{ (int) $hasData }}">
    <x-filament::section>
        <x-slot name="heading">
            Map
        </x-slot>

        <x-slot name="afterHeader">
            <x-filament::icon-button
                size="sm"
                color="danger"
                icon="heroicon-m-x-mark"
                label="Close Map"
                @click="$dispatch('load-records-for-map', { recordIds: [] })"
            />
        </x-slot>

        <div
            x-ignore
            x-load
            x-load-src="{{ Vite::asset('resources/js/components/filament-map-widget.js') }}"
            x-data="window.filamentMapsWidget($refs.map)"
            wire:ignore
        >
            <div
                x-ref="map"
                class="w-full"
                style="min-height: 300px; z-index: 1 !important;"
            ></div>
        </div>
    </x-filament::section>
</div>
