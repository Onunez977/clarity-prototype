<template>
    <Teleport to="body">
        <div
            v-if="open && src"
            class="fixed inset-0 z-[60] flex items-center justify-center bg-black/80"
            role="dialog"
            aria-modal="true"
            :aria-label="alt || 'Enlarged scan image'"
            @click.self="close"
        >
            <button
                ref="closeButton"
                type="button"
                class="absolute top-4 right-4 flex items-center justify-center min-h-[44px] min-w-[44px] rounded-full bg-white text-gray-700 text-xl font-bold leading-none"
                aria-label="Close enlarged image"
                @click="close"
            >
                ×
            </button>
            <img
                :src="src"
                :alt="alt"
                class="max-h-[100vh] max-w-[100vw] object-contain"
            />
        </div>
    </Teleport>
</template>

<script setup>
import { nextTick, onUnmounted, ref, watch } from 'vue';

const props = defineProps({
    open: {
        type: Boolean,
        default: false,
    },
    src: {
        type: String,
        default: '',
    },
    alt: {
        type: String,
        default: '',
    },
});

const emit = defineEmits(['close']);

const closeButton = ref(null);

function close() {
    emit('close');
}

function onKeydown(event) {
    if (event.key === 'Escape' && props.open) {
        event.preventDefault();
        close();
    }
}

watch(() => props.open, (isOpen) => {
    if (!isOpen) {
        window.removeEventListener('keydown', onKeydown);

        return;
    }

    window.addEventListener('keydown', onKeydown);
    nextTick(() => {
        closeButton.value?.focus();
    });
});

onUnmounted(() => {
    window.removeEventListener('keydown', onKeydown);
});
</script>
