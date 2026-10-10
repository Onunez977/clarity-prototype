@props([
    'hotlistId' => null,
    'lat' => null,
    'lng' => null,
])

<div x-data="{
        address: '',
        distance: '',
        loading: true,

        init() {
            if (!{{ $hotlistId }} || !{{ $lat }} || !{{ $lng }}) {
                this.loading = false;
                return;
            }

            fetchNearestGivenAddress({{ $hotlistId }}, {{ $lat }}, {{ $lng }})
                .then((res) => {
                    const payload = res && res.data ? res.data : null;
                    if (payload && payload.address) {
                        this.address = payload.address;
                        this.distance = (payload.distance_miles ?? '') + ' mi';
                    }
                })
                .catch(() => {
                    this.address = '';
                    this.distance = '';
                })
                .finally(() => {
                    this.loading = false;
                });
        },
     }"
>
    <div x-show="loading" class="flex items-center gap-1 text-gray-400">
        <x-filament::loading-indicator class="h-4 w-4" />
    </div>
    <span x-cloak x-show="!loading && address" x-text="address"></span>
    <span x-cloak x-show="!loading && !address">N/A</span>
</div>
