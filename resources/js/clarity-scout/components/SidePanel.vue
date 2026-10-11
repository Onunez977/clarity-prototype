<template>
    <div>
        <!-- Backdrop -->
        <Transition name="fade">
            <div
                v-if="open"
                class="fixed inset-0 z-30 bg-black/20"
                @click="$emit('update:open', false)"
            ></div>
        </Transition>

        <!-- Panel -->
        <Transition name="slide">
            <div
                v-if="open"
                class="fixed top-0 left-0 h-full z-30 bg-white/95 backdrop-blur-sm border-r border-scout-edge shadow-xl flex flex-col"
                :style="{ width, maxWidth: 'calc(100vw - 20px)', paddingTop: 'env(safe-area-inset-top)', paddingBottom: 'env(safe-area-inset-bottom)' }"
            >
                <!-- Header -->
                <div class="flex items-center justify-between px-5 py-4 border-b border-gray-200/60 flex-shrink-0">
                    <h2 class="text-base font-bold text-gray-500 uppercase tracking-wider">{{ title }}</h2>
                    <button
                        @click="$emit('update:open', false)"
                        class="w-9 h-9 rounded-full bg-gray-100 flex items-center justify-center min-h-[44px] min-w-[44px]"
                    >
                        <svg class="w-4 h-4 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>

                <!-- Scrollable content -->
                <div class="flex-1 overflow-y-auto px-5 py-4">
                    <slot />
                </div>
            </div>
        </Transition>
    </div>
</template>

<script setup>
defineProps({
    open: {
        type: Boolean,
        default: false,
    },
    title: {
        type: String,
        default: '',
    },
    width: {
        type: String,
        default: '400px',
    },
});

defineEmits(['update:open']);
</script>

<style scoped>
.slide-enter-active,
.slide-leave-active {
    transition: transform 0.3s ease;
}
.slide-enter-from,
.slide-leave-to {
    transform: translateX(-100%);
}

.fade-enter-active,
.fade-leave-active {
    transition: opacity 0.3s ease;
}
.fade-enter-from,
.fade-leave-to {
    opacity: 0;
}
</style>
