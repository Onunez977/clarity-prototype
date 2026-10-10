@props(['items', 'colors' => [], 'showTotal' => true, 'toggleable' => false])

@php
    $defaultColors = [
        'Open' => 'bg-success-500',
        'Repossessed' => 'bg-info-500',
        'Closed' => 'bg-warning-500',
        'Hold' => 'bg-purple-500',
    ];
    $colors = array_merge($defaultColors, $colors);

    $totalValue = collect($items)->sum('value');
    $segments = collect($items)->map(function ($item) use ($totalValue, $colors) {
        $percentage = $totalValue > 0 ? round(($item['value'] / $totalValue) * 100, 1) : 0;

        return [
            'label' => $item['label'],
            'value' => $item['value'],
            'percentage' => $percentage,
            'colorClass' => $colors[$item['label']] ?? 'bg-gray-500',
        ];
    })->filter(fn ($s) => $s['value'] > 0)->values()->all();
@endphp

@if ($toggleable)
<div
    x-data="{
        segments: @js($segments),
        get activeSegments() {
            return this.segments.filter(s => activeStatuses.includes(s.label));
        },
        get activeTotal() {
            return this.activeSegments.reduce((sum, s) => sum + s.value, 0);
        },
        getPercentage(value) {
            return this.activeTotal > 0 ? Math.round((value / this.activeTotal) * 1000) / 10 : 0;
        },
        getWidth(value) {
            return this.activeTotal > 0 ? ((value / this.activeTotal) * 100) + '%' : '0%';
        }
    }"
    class="space-y-3"
>
    @if ($showTotal)
        <div class="text-sm font-semibold text-gray-900 dark:text-white">
            Total: <span x-text="activeTotal.toLocaleString()"></span>
        </div>
    @endif

    <div class="relative h-6 bg-gray-100 dark:bg-gray-700 rounded-full overflow-hidden flex">
        <template x-for="(segment, index) in activeSegments" :key="segment.label">
            <div
                x-data="{ showTooltip: false }"
                class="relative h-full cursor-pointer"
                :class="[
                    segment.colorClass,
                    index === 0 ? 'rounded-l-full' : '',
                    index === activeSegments.length - 1 ? 'rounded-r-full' : ''
                ]"
                :style="'width: ' + getWidth(segment.value)"
                x-on:mouseenter="showTooltip = true"
                x-on:mouseleave="showTooltip = false"
            >
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
                    <div class="font-semibold" x-text="segment.label + ': ' + segment.value.toLocaleString()"></div>
                    <div class="text-gray-300" x-text="getPercentage(segment.value) + '% of total'"></div>
                </div>
            </div>
        </template>
    </div>

    <div class="flex flex-wrap gap-x-4 gap-y-1">
        @foreach ($segments as $segment)
            <div
                class="flex items-center gap-1.5 text-sm cursor-pointer select-none transition-all"
                :class="isActive('{{ $segment['label'] }}') ? 'opacity-100' : 'opacity-60'"
                x-on:click="toggle('{{ $segment['label'] }}')"
            >
                <span
                    class="inline-block w-2.5 h-2.5 rounded-full transition-colors"
                    :class="isActive('{{ $segment['label'] }}') ? '{{ $segment['colorClass'] }}' : 'bg-gray-300 dark:bg-gray-600'"
                ></span>
                <span
                    class="transition-colors"
                    :class="isActive('{{ $segment['label'] }}') ? 'text-gray-600 dark:text-gray-400' : 'text-gray-400 dark:text-gray-500'"
                >{{ $segment['label'] }}</span>
                <span
                    class="font-medium transition-colors"
                    :class="isActive('{{ $segment['label'] }}') ? 'text-gray-900 dark:text-white' : 'text-gray-400 dark:text-gray-500'"
                >{{ number_format($segment['value']) }}</span>
            </div>
        @endforeach
    </div>
</div>
@else
<div class="space-y-3">
    @if ($showTotal)
        <div class="text-sm font-semibold text-gray-900 dark:text-white">
            Total: {{ number_format($totalValue) }}
        </div>
    @endif

    <div class="relative h-6 bg-gray-100 dark:bg-gray-700 rounded-full overflow-hidden flex">
        @foreach ($segments as $index => $segment)
            <div
                x-data="{ showTooltip: false }"
                class="relative h-full {{ $segment['colorClass'] }} cursor-pointer
                    {{ $index === 0 ? 'rounded-l-full' : '' }}
                    {{ $index === count($segments) - 1 ? 'rounded-r-full' : '' }}"
                style="width: {{ $segment['percentage'] }}%"
                x-on:mouseenter="showTooltip = true"
                x-on:mouseleave="showTooltip = false"
            >
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
                    <div class="font-semibold">{{ $segment['label'] }}: {{ number_format($segment['value']) }}</div>
                    <div class="text-gray-300">{{ $segment['percentage'] }}% of total</div>
                </div>
            </div>
        @endforeach
    </div>

    <div class="flex flex-wrap gap-x-4 gap-y-1">
        @foreach ($segments as $segment)
            <div class="flex items-center gap-1.5 text-sm">
                <span class="inline-block w-2.5 h-2.5 rounded-full {{ $segment['colorClass'] }}"></span>
                <span class="text-gray-600 dark:text-gray-400">{{ $segment['label'] }}</span>
                <span class="font-medium text-gray-900 dark:text-white">{{ number_format($segment['value']) }}</span>
            </div>
        @endforeach
    </div>
</div>
@endif
