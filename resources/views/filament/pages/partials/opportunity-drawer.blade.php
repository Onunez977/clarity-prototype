@if ($drawer)
    <div class="fixed inset-0 z-40" style="background: rgb(3 7 18 / 0.45)" wire:click="closeCase"></div>
    <aside id="opportunity-drawer" class="fixed top-0 bottom-0 right-0 z-50 flex w-full flex-col overflow-y-auto border-l border-gray-200 bg-white shadow-xl dark:border-white/10 dark:bg-gray-900" style="max-width: 36rem">
        <div class="sticky top-0 z-10 flex items-start justify-between gap-3 border-b border-gray-200 bg-white px-5 py-4 dark:border-white/10 dark:bg-gray-900">
            <div>
                <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">Order {{ $drawer['order_id'] }}</p>
                <h2 class="text-lg font-semibold text-gray-950 dark:text-white">{{ $drawer['plate'] }} · {{ $drawer['state'] }}</h2>
                <p class="text-sm text-gray-600 dark:text-gray-300">{{ $drawer['ymm'] }} · {{ $drawer['client'] }} · {{ $drawer['branch'] }}</p>
            </div>
            <x-filament::button color="gray" size="sm" wire:click="closeCase">Close</x-filament::button>
        </div>

        <div class="space-y-6 px-5 py-5">
            <div class="flex items-center gap-3">
                <span class="inline-flex min-w-14 items-center justify-center rounded-lg px-3 py-2 text-2xl font-semibold text-white {{ $drawer['band'] === 'hot' ? 'bg-[#FF6666]' : ($drawer['band'] === 'warm' ? 'bg-amber-500' : 'bg-slate-400') }}">
                    {{ $drawer['score'] }}
                </span>
                <div>
                    <p class="text-sm font-semibold text-gray-950 dark:text-white">{{ $drawer['band_label'] }} · Recovery Likelihood</p>
                    <p class="text-sm text-gray-600 dark:text-gray-300">{{ $drawer['reason'] }}</p>
                </div>
            </div>

            <section id="score-breakdown">
                <h3 class="text-sm font-semibold text-gray-950 dark:text-white">Score breakdown</h3>
                <ul class="mt-2 divide-y divide-gray-100 rounded-xl border border-gray-200 dark:divide-white/10 dark:border-white/10">
                    @forelse ($drawer['breakdown'] as $signal)
                        <li class="flex items-start justify-between gap-3 px-3 py-2 text-sm">
                            <span>
                                <span class="font-medium text-gray-900 dark:text-gray-100">{{ $signal['label'] }}</span>
                                <span class="mt-0.5 block text-gray-500">{{ $signal['detail'] }}</span>
                            </span>
                            <span class="font-semibold {{ $signal['points'] < 0 ? 'text-red-600' : 'text-gray-950 dark:text-white' }}">
                                {{ $signal['points'] > 0 ? '+' : '' }}{{ $signal['points'] }}
                            </span>
                        </li>
                    @empty
                        <li class="px-3 py-2 text-sm text-gray-500">No points on this order.</li>
                    @endforelse
                </ul>
                @if ($drawer['warnings'] !== [])
                    <ul class="mt-2 space-y-1 text-sm text-amber-800 dark:text-amber-200">
                        @foreach ($drawer['warnings'] as $warning)
                            <li>{{ $warning }}</li>
                        @endforeach
                    </ul>
                @endif
            </section>

            <section>
                <h3 class="text-sm font-semibold text-gray-950 dark:text-white">Addresses</h3>
                <p class="mt-1 text-xs text-gray-500">Ranked by this address's points. {{ $drawer['coverage_label'] }}.</p>
                <ul class="mt-2 space-y-2">
                    @foreach ($drawer['addresses'] as $address)
                        <li class="rounded-xl border px-3 py-2 text-sm {{ $address['best'] ? 'border-[#FF6666]' : 'border-gray-200 dark:border-white/10' }}">
                            <div class="flex items-start justify-between gap-3">
                                <p class="font-medium text-gray-950 dark:text-white">Address {{ $address['position'] }} · {{ $address['line'] }}</p>
                                <span class="font-semibold">{{ $address['score'] }}</span>
                            </div>
                            <p class="text-gray-600 dark:text-gray-300">{{ $address['city'] }} · {{ $address['hit_count'] }} exact hits · nearest {{ $address['nearest'] }}</p>
                            <p class="text-gray-600 dark:text-gray-300">{{ $address['check_in_label'] }}</p>
                            <p class="text-gray-500">{{ $address['reason'] }}</p>
                        </li>
                    @endforeach
                </ul>
            </section>

            <section>
                <h3 class="text-sm font-semibold text-gray-950 dark:text-white">Hits and 0.1-mile halos</h3>
                <div class="mt-2">
                    @include('filament.pages.partials.opportunity-map', ['map' => $drawer['detail_map'], 'height' => 'h-64', 'label' => 'Hits near given addresses'])
                </div>
                <p class="mt-2 text-xs text-gray-500">Blue rings are 0.1 mile around each geocoded address. Coral dots are exact hits. Gray dots are historical. Tiles stay off without a Google Maps key.</p>
                <ul class="mt-3 divide-y divide-gray-100 text-sm dark:divide-white/10">
                    @forelse ($drawer['hits'] as $hit)
                        <li class="py-2 {{ $hit['historical'] ? 'text-gray-500' : 'text-gray-800 dark:text-gray-100' }}">
                            <span class="font-medium">{{ $hit['when'] }}</span>
                            · {{ $hit['type_label'] }}
                            @if ($hit['distance']) · {{ $hit['distance'] }} from Address {{ $hit['position'] }} @endif
                            · {{ $hit['local_label'] }}
                            @if ($hit['kit']) · {{ $hit['kit'] }} @endif
                            @if ($hit['disposition_label']) · {{ $hit['disposition_label'] }} @endif
                            @if ($hit['state_mismatch']) · state differs @endif
                            @if ($hit['historical']) · scanned before the order @endif
                        </li>
                    @empty
                        <li class="py-2 text-gray-500">No hits stored on this order.</li>
                    @endforelse
                </ul>
            </section>

            <section>
                <h3 class="text-sm font-semibold text-gray-950 dark:text-white">Check-in history</h3>
                <p class="mt-1 text-xs text-gray-500">A Scout kit's GPS was near the address. This is not a visit outcome.</p>
                <ul class="mt-2 space-y-1 text-sm text-gray-700 dark:text-gray-200">
                    @forelse ($drawer['check_ins'] as $checkIn)
                        <li>Address {{ $checkIn['position'] }} · {{ $checkIn['line'] }} · {{ $checkIn['when'] }} · {{ $checkIn['kit'] }}</li>
                    @empty
                        <li>No check-in recorded.</li>
                    @endforelse
                </ul>
            </section>

            <section class="rounded-xl border border-gray-200 p-3 dark:border-white/10">
                <h3 class="text-sm font-semibold text-gray-950 dark:text-white">Dispatch</h3>
                <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">
                    {{ $drawer['status_label'] }}
                    @if ($drawer['driver_name'])
                        · {{ $drawer['driver_name'] }} ({{ $drawer['kit_name'] }})
                        · {{ $drawer['priority_label'] }} · {{ $drawer['due_label'] }}
                    @endif
                </p>
                @if ($drawer['note'])
                    <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">{{ $drawer['note'] }}</p>
                @endif
                @if ($drawer['notification'])
                    <p class="mt-2 text-xs text-gray-500">{{ $drawer['notification'] }}</p>
                @endif
                <div class="mt-3 flex flex-wrap gap-2">
                    <x-filament::button size="sm" id="open-dispatch" wire:click="openDispatch({{ $drawer['id'] }})">
                        {{ $drawer['status'] === 'new' ? 'Dispatch' : 'Reassign' }}
                    </x-filament::button>
                    @if ($drawer['status'] !== 'new')
                        <x-filament::button size="sm" color="gray" id="status-checked-in" wire:click="setStatus({{ $drawer['id'] }}, 'checked_in')">Checked in</x-filament::button>
                        <x-filament::button size="sm" color="gray" id="status-recovered" wire:click="setStatus({{ $drawer['id'] }}, 'recovered')">Recovered</x-filament::button>
                        <x-filament::button size="sm" color="gray" id="status-not-found" wire:click="setStatus({{ $drawer['id'] }}, 'not_found')">Not found</x-filament::button>
                    @endif
                </div>
            </section>
        </div>
    </aside>
@endif
