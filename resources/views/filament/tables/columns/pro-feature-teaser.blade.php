@props(['feature' => '', 'description' => ''])

<x-pro-upgrade-popover :active="false" :description="$description" :feature="$feature">
    <div class="flex items-center gap-1.5 text-gray-400">
        <x-heroicon-o-lock-closed class="h-4 w-4" />
        <span class="inline-flex items-center rounded-md bg-primary-500/20 px-1.5 py-0.5 text-xs font-semibold text-primary-400">
            Pro
        </span>
    </div>
</x-pro-upgrade-popover>
