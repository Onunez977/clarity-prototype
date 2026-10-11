<div id="morning-plan" class="space-y-4" x-data="{ dragging: null }">
    <div class="flex flex-wrap items-end justify-between gap-3">
        <div>
            <h2 class="text-base font-semibold text-gray-950 dark:text-white">Morning plan</h2>
            <p class="text-sm text-gray-500">{{ $board['coverage_label'] }}. Pins are Hot and Warm. Miles are straight-line.</p>
        </div>
        <x-filament::button color="gray" size="sm" id="back-to-queue" wire:click="$set('viewMode', 'queue')">Back to queue</x-filament::button>
    </div>

    @include('filament.pages.partials.opportunity-map', ['map' => $board['map'], 'label' => 'Coverage zone and high-score pins'])

    <div class="flex flex-wrap gap-x-4 gap-y-2 text-xs text-gray-600 dark:text-gray-300">
        <span class="inline-flex items-center gap-1.5"><span class="inline-block size-2.5 rounded-full bg-[#FF6666]"></span> Hot</span>
        <span class="inline-flex items-center gap-1.5"><span class="inline-block size-2.5 rounded-full bg-amber-500"></span> Warm</span>
        <span class="inline-flex items-center gap-1.5"><span class="inline-block size-2.5 rounded-full bg-gray-950"></span> Driver with a fresh fix</span>
        <span class="inline-flex items-center gap-1.5"><span class="inline-block h-2.5 w-4 rounded-sm border border-dashed border-[#FF6666] bg-[#FF6666]/10"></span> Coverage zone</span>
    </div>

    <div class="grid gap-4 xl:grid-cols-4">
        @foreach ($board['plan']['columns'] as $column)
            <section
                id="driver-column-{{ $column['id'] }}"
                class="rounded-xl border border-gray-200 bg-white p-3 dark:border-white/10 dark:bg-gray-900"
                x-on:dragover.prevent
                x-on:drop.prevent="$wire.moveStop(dragging, {{ $column['id'] }}, null)"
            >
                <div class="flex items-start justify-between gap-2">
                    <div>
                        <h3 class="text-sm font-semibold text-gray-950 dark:text-white">{{ $column['name'] }}</h3>
                        <p class="text-xs text-gray-500">{{ $column['kit'] }} · {{ $column['fix_label'] }}</p>
                    </div>
                    <button type="button" class="text-xs font-medium text-[#FF6666]" id="suggest-order-{{ $column['id'] }}" wire:click="suggestOrder({{ $column['id'] }})">
                        Suggest straight-line order
                    </button>
                </div>
                @unless ($column['fresh'])
                    <p class="mt-2 text-xs text-amber-700">GPS older than 24 hours. Distances from this driver are omitted.</p>
                @endunless
                <ol class="mt-3 space-y-2">
                    @forelse ($column['stops'] as $index => $stop)
                        <li
                            id="plan-stop-{{ $stop['plate'] }}"
                            draggable="true"
                            class="cursor-grab rounded-lg border border-gray-200 bg-gray-50 p-2 text-sm dark:border-white/10 dark:bg-white/5"
                            x-on:dragstart="dragging = {{ $stop['case_id'] }}"
                            x-on:dragover.prevent
                            x-on:drop.stop.prevent="$wire.moveStop(dragging, {{ $column['id'] }}, {{ $stop['case_id'] }})"
                        >
                            <div class="flex items-center justify-between gap-2">
                                <button type="button" class="font-semibold text-gray-950 dark:text-white" wire:click="openCase({{ $stop['case_id'] }})">
                                    {{ $index + 1 }}. {{ $stop['plate'] }}
                                </button>
                                <span class="text-xs font-semibold">{{ $stop['score'] }}</span>
                            </div>
                            <p class="text-gray-600 dark:text-gray-300">{{ $stop['address'] }}</p>
                            <p class="text-xs text-gray-500">{{ $stop['miles_label'] }} · {{ $stop['status_label'] }} · {{ $stop['due_label'] }}</p>
                            <div class="mt-2 flex flex-wrap items-center gap-1">
                                <button type="button" class="rounded border border-gray-300 px-2 py-0.5 text-xs" wire:click="nudgeStop({{ $stop['case_id'] }}, -1)">Up</button>
                                <button type="button" class="rounded border border-gray-300 px-2 py-0.5 text-xs" wire:click="nudgeStop({{ $stop['case_id'] }}, 1)">Down</button>
                                <select class="rounded border border-gray-300 bg-white px-1 py-0.5 text-xs dark:border-white/10 dark:bg-gray-900" x-on:change="$wire.moveStop({{ $stop['case_id'] }}, Number($event.target.value), null)">
                                    @foreach ($board['drivers'] as $driver)
                                        <option value="{{ $driver['id'] }}" @selected($driver['id'] === $column['id'])>{{ $driver['name'] }}</option>
                                    @endforeach
                                </select>
                            </div>
                        </li>
                    @empty
                        <li class="text-sm text-gray-500">No active stops. Drop one here.</li>
                    @endforelse
                </ol>
                @if ($column['extra'] > 0)
                    <p class="mt-2 text-xs text-gray-500">{{ $column['extra'] }} more stops stay assigned below this list of 8.</p>
                @endif
            </section>
        @endforeach
    </div>

    <section class="rounded-xl border border-gray-200 p-3 dark:border-white/10">
        <h3 class="text-sm font-semibold text-gray-950 dark:text-white">Unassigned hot</h3>
        <ul class="mt-2 space-y-2">
            @forelse ($board['plan']['unassigned'] as $row)
                <li class="flex flex-wrap items-center justify-between gap-2 text-sm">
                    <span><span class="font-semibold">{{ $row['plate'] }}</span> · {{ $row['score'] }} · {{ $row['address'] }}</span>
                    <x-filament::button size="xs" wire:click="openDispatch({{ $row['id'] }})">Dispatch</x-filament::button>
                </li>
            @empty
                <li class="text-sm text-gray-500">No unassigned hot orders.</li>
            @endforelse
        </ul>
    </section>
</div>
