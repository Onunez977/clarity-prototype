@props([
    'hotlistId' => null,
    'lat' => null,
    'lng' => null,
])

<div x-data="{
        distance: '',
        loading: true,

        init() {
            if (!{{ $hotlistId }} || !{{ $lat }} || !{{ $lng }}) {
                this.loading = false;
                return;
            }

            fetchNearestGivenAddress({{ $hotlistId }}, {{ $lat }}, {{ $lng }})
                .then((res) => {
                    const miles = res && res.data ? res.data.distance_miles : undefined;
                    if (miles !== undefined && miles !== null) {
                        this.distance = miles + ' mi';
                    }
                })
                .catch(() => {
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
    <span x-cloak x-show="!loading && distance" x-text="distance"></span>
    <span x-cloak x-show="!loading && !distance">N/A</span>
</div>
