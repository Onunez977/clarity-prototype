@props([
    'description' => '',
    'active' => false,
    'feature' => '',
])

@if ($active)
    {{ $slot }}
@else
    @php
        $upgradeUrl = $feature
            ? route('upgrade.from', ['feature' => $feature])
            : \App\Filament\Pages\Subscription::getUrl();
    @endphp

    <div
        x-data="{
            showPopover: false,
            popoverTop: 0,
            popoverLeft: 0,
            updatePosition() {
                const rect = this.$el.getBoundingClientRect();
                this.popoverTop = rect.bottom + 8;
                this.popoverLeft = rect.left;
            }
        }"
        @mouseenter="updatePosition(); showPopover = true"
        @mouseleave="showPopover = false"
        class="relative"
    >
        {{ $slot }}

        <template x-teleport="body">
            <div
                x-show="showPopover"
                x-transition:enter="transition ease-out duration-150"
                x-transition:enter-start="opacity-0 translate-y-1"
                x-transition:enter-end="opacity-100 translate-y-0"
                x-transition:leave="transition ease-in duration-100"
                x-transition:leave-start="opacity-100 translate-y-0"
                x-transition:leave-end="opacity-0 translate-y-1"
                x-cloak
                :style="`position: fixed; top: ${popoverTop}px; left: ${popoverLeft}px; z-index: 9999;`"
                class="w-72 rounded-xl bg-gray-100 dark:bg-gray-900 p-4 shadow-xl ring-1 ring-white/10"
                @mouseenter="showPopover = true"
                @mouseleave="showPopover = false"
            >
                <div class="flex items-center gap-2 mb-2">
                    <span class="inline-flex items-center rounded-md bg-primary-500/20 px-2 py-0.5 text-xs font-semibold text-primary-400">
                        Pro
                    </span>
                    <span class="text-sm font-semibold text-gray-900 dark:text-white">Feature</span>
                </div>

                <p class="text-sm text-gray-800 dark:text-gray-300 leading-relaxed mb-3">
                    {{ $description }}
                </p>

                <a
                    href="{{ $upgradeUrl }}"
                    style="background-color: #FF6666;"
                    class="inline-flex items-center justify-center w-full rounded-lg px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:opacity-90 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-offset-gray-900"
                >
                    Upgrade to PRO
                </a>
            </div>
        </template>
    </div>
@endif
