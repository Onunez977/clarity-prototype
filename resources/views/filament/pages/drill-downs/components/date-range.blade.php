@props(['dates'])

<div class="flex items-center gap-2 text-sm text-gray-500 dark:text-gray-400 bg-gray-50 dark:bg-gray-800 rounded-lg px-4 py-2">
    <x-heroicon-o-calendar class="h-4 w-4" />
    <span>
        {{ \Carbon\Carbon::parse($dates['start'])->format('M j, Y') }}
        &mdash;
        {{ \Carbon\Carbon::parse($dates['end'])->format('M j, Y') }}
    </span>
</div>
