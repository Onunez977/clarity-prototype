<template>
    <div
        class="pointer-events-none rounded-2xl shadow-scout-panel border border-scout-edge"
        :class="size === 'large'
            ? 'bg-white/95 backdrop-blur-sm w-[280px] p-6'
            : 'bg-white/95 backdrop-blur-sm flex-1 p-5'"
    >
        <!-- Large card layout -->
        <template v-if="size === 'large'">
            <div class="flex items-center justify-between mb-2">
                <span class="text-xs font-bold text-gray-400 uppercase tracking-wider">{{ label }}</span>
                <!-- Camera icon -->
                <svg v-if="icon === 'camera'" class="w-7 h-7 text-blue-500" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M3 9a2 2 0 012-2h.93a2 2 0 001.664-.89l.812-1.22A2 2 0 0110.07 4h3.86a2 2 0 011.664.89l.812 1.22A2 2 0 0018.07 7H19a2 2 0 012 2v9a2 2 0 01-2 2H5a2 2 0 01-2-2V9z" />
                    <path stroke-linecap="round" stroke-linejoin="round" d="M15 13a3 3 0 11-6 0 3 3 0 016 0z" />
                </svg>
                <!-- Alert icon -->
                <svg v-else-if="icon === 'alert'" class="w-7 h-7 text-amber-500" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01M12 3a9 9 0 100 18 9 9 0 000-18z" />
                </svg>
                <!-- Map icon -->
                <svg v-else-if="icon === 'map'" class="w-7 h-7 text-green-500" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 6.75V15m6-6v8.25m.503-12.713l.002-.002a15.9 15.9 0 012.556-2.117A3.374 3.374 0 0121 3.748V15.93a3.375 3.375 0 01-2.94 3.34l-.16.022a15.9 15.9 0 01-2.556-.12L9 17.25 3.94 19.17a15.9 15.9 0 01-2.556.12l-.16-.022A3.375 3.375 0 010 15.93V3.748a3.374 3.374 0 012.939-.18 15.9 15.9 0 012.556 2.117l.002.002L9 9.375l6-2.625z" />
                </svg>
                <!-- Clock icon -->
                <svg v-else-if="icon === 'clock'" class="w-7 h-7 text-purple-500" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z" />
                </svg>
            </div>
            <p class="text-5xl font-bold text-gray-900 leading-none">
                {{ formattedValue }}<span v-if="suffix" class="text-lg font-normal text-gray-400 ml-1">{{ suffix }}</span>
            </p>
        </template>

        <!-- Small card layout -->
        <template v-else>
            <p class="text-xs font-bold text-gray-400 uppercase tracking-wider mb-1">{{ label }}</p>
            <p class="text-3xl font-bold text-gray-900 leading-none">{{ formattedValue }}</p>
        </template>
    </div>
</template>

<script setup>
import { computed } from 'vue';

const props = defineProps({
    value: {
        type: [String, Number],
        required: true,
    },
    label: {
        type: String,
        required: true,
    },
    size: {
        type: String,
        default: 'large',
        validator: (v) => ['large', 'small'].includes(v),
    },
    icon: {
        type: String,
        default: null,
    },
    suffix: {
        type: String,
        default: null,
    },
});

const formattedValue = computed(() => {
    if (typeof props.value === 'number') {
        return props.value.toLocaleString();
    }
    return props.value;
});
</script>
