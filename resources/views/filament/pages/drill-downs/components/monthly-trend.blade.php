@props(['data', 'color' => 'bg-primary-500'])

@php
    $maxValue = collect($data)->max('value');
@endphp

@if (! empty($data))
    <div class="space-y-2">
        @foreach ($data as $item)
            @php
                $barWidth = $maxValue > 0 ? round(($item['value'] / $maxValue) * 100, 1) : 0;
            @endphp
            <div x-data="{ showTooltip: false }" class="relative flex items-center gap-3">
                <span class="text-xs font-medium text-gray-500 dark:text-gray-400 w-16 shrink-0 text-right">
                    {{ $item['month'] }}
                </span>
                <div
                    class="flex-1 h-4 bg-gray-100 dark:bg-gray-700 rounded-full overflow-hidden cursor-pointer"
                    x-on:mouseenter="showTooltip = true"
                    x-on:mouseleave="showTooltip = false"
                >
                    <div
                        class="{{ $color }} h-full rounded-full transition-all duration-500"
                        style="width: {{ $barWidth }}%"
                    ></div>
                </div>
                <span class="text-sm font-semibold text-gray-900 dark:text-white w-14 shrink-0">
                    {{ number_format($item['value']) }}
                </span>

                <div
                    x-show="showTooltip"
                    x-transition:enter="transition ease-out duration-150"
                    x-transition:enter-start="opacity-0 translate-y-1"
                    x-transition:enter-end="opacity-100 translate-y-0"
                    x-transition:leave="transition ease-in duration-100"
                    x-transition:leave-start="opacity-100 translate-y-0"
                    x-transition:leave-end="opacity-0 translate-y-1"
                    class="absolute left-1/2 -translate-x-1/2 bottom-full mb-2 rounded-lg bg-gray-900 dark:bg-gray-600 px-3 py-2 text-sm text-white shadow-lg z-50 whitespace-nowrap pointer-events-none"
                    x-cloak
                >
                    <div class="font-semibold">{{ $item['month'] }}: {{ number_format($item['value']) }}</div>
                    @if ($maxValue > 0)
                        <div class="text-gray-300">{{ round(($item['value'] / $maxValue) * 100, 1) }}% of peak ({{ number_format($maxValue) }})</div>
                    @endif
                </div>
            </div>
        @endforeach
    </div>
@else
    <p class="text-sm text-gray-500 dark:text-gray-400 text-center py-4">No monthly data available.</p>
@endif
