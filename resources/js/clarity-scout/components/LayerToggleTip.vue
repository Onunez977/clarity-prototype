<template>
    <!-- Wraps a Map Layers toggle button with the same help-text popover the bottom
         stats bar uses (MapStatsBar.vue): hover/focus on desktop, tap to toggle on
         mobile. The parent owns a single `open` key so only one tip shows at a time.
         The tip opens below the toggle (the panel is anchored to the top), left-aligned
         and capped at the panel width so it never clips off a phone-width screen. -->
    <div
        class="relative"
        @mouseenter="emit('update:open', tipKey)"
        @mouseleave="emit('update:open', null)"
        @focusin="emit('update:open', tipKey)"
        @focusout="emit('update:open', null)"
        @click.stop="emit('update:open', open === tipKey ? null : tipKey)"
    >
        <slot />

        <transition
            enter-active-class="transition ease-out duration-200"
            enter-from-class="opacity-0 -translate-y-1"
            enter-to-class="opacity-100 translate-y-0"
            leave-active-class="transition ease-in duration-150"
            leave-from-class="opacity-100 translate-y-0"
            leave-to-class="opacity-0 -translate-y-1"
        >
            <div
                v-if="open === tipKey"
                class="absolute top-full left-0 mt-1 w-56 max-w-full rounded-lg bg-gray-900 px-3 py-2 text-xs leading-snug text-white shadow-lg z-50 pointer-events-none normal-case tracking-normal text-left font-medium"
            >
                {{ text }}
            </div>
        </transition>
    </div>
</template>

<script setup>
defineProps({
    tipKey: {
        type: String,
        required: true,
    },
    open: {
        type: String,
        default: null,
    },
    text: {
        type: String,
        required: true,
    },
});

const emit = defineEmits(['update:open']);
</script>
