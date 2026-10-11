@php
    $initial = $getZonesGeoJson();
    $chips = $getZoneChips();
@endphp

<x-dynamic-component :component="$getFieldWrapperView()" :field="$field">
    <div
        x-ignore
        x-load
        x-load-src="{{ \Illuminate\Support\Facades\Vite::asset('resources/js/components/zone-map-display.js') }}"
        x-data="window.filamentZoneMapDisplay({
            initial: @js($initial),
        })"
        wire:ignore
    >
        <div
            x-ref="map"
            class="w-full rounded-lg border border-gray-300 dark:border-gray-700"
            style="min-height: 600px; height: 100%;"
        ></div>
    </div>

    @if (! empty($chips))
        <div class="mt-3 flex flex-wrap gap-2">
            @foreach ($chips as $chip)
                @php
                    $color = $chip['color'];
                    $isNoScan = $chip['is_no_scan_zone'];
                    $style = "border-color: {$color}; color: {$color};";
                    if ($isNoScan) {
                        $style .= " background-image: repeating-linear-gradient(45deg, {$color}33 0, {$color}33 6px, transparent 6px, transparent 12px); background-color: {$color}1a;";
                    } else {
                        $style .= " background-color: {$color}1a;";
                    }
                @endphp
                <a
                    href="{{ \App\Filament\Pages\ManageZones::getUrl(['zone' => $chip['id']]) }}"
                    class="inline-flex items-center gap-1.5 rounded-full border px-2.5 py-1 text-xs font-medium transition hover:brightness-110 focus:outline-none focus:ring-2 focus:ring-offset-1 dark:focus:ring-offset-gray-900"
                    style="{{ $style }}"
                >
                    <span
                        class="inline-block size-2 rounded-full"
                        style="background-color: {{ $color }};"
                        aria-hidden="true"
                    ></span>
                    <span>{{ $chip['name'] }}</span>
                </a>
            @endforeach
        </div>
    @endif
</x-dynamic-component>
