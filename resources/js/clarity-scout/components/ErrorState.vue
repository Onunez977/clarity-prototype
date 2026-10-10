<template>
    <div class="bg-red-50 border border-red-200 rounded-xl p-4">
        <div class="flex items-center gap-3">
            <svg class="w-5 h-5 text-red-500 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
            </svg>
            <p class="flex-1 min-w-0 text-red-700 text-sm">{{ message }}</p>
            <button
                @click="emit('retry')"
                class="flex-shrink-0 px-4 py-2 bg-red-600 hover:bg-red-700 text-white text-sm font-medium rounded-lg min-h-[44px] transition-colors"
            >
                Retry
            </button>
        </div>
    </div>
</template>

<script setup>
defineProps({
    message: {
        type: String,
        required: true,
    },
});

const emit = defineEmits(['retry']);
</script>
