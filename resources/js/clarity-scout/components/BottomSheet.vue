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

        <!-- Centering wrapper (no transform, uses flexbox) -->
        <div
            v-if="open"
            class="fixed left-0 right-0 z-30 flex justify-center px-4 pointer-events-none"
            :class="offsetClass"
        >
            <div
                ref="sheetEl"
                class="bg-white/95 backdrop-blur-sm rounded-3xl shadow-scout-panel border border-scout-edge flex flex-col pointer-events-auto"
                style="min-width: min(380px, calc(100vw - 2rem))"
                :class="animating ? 'transition-transform duration-300 ease-out' : ''"
                :style="{
                    transform: `translateY(${translateY}px)`,
                    opacity: entered ? 1 : 0,
                    maxHeight: '70vh',
                }"
            >
                <!-- Drag handle + header (gesture target) -->
                <div
                    @pointerdown="onPointerDown"
                    class="cursor-grab active:cursor-grabbing touch-none select-none"
                >
                    <!-- Drag handle -->
                    <div class="flex justify-center pt-2.5 pb-0.5">
                        <div class="w-10 h-1 rounded-full bg-gray-300"></div>
                    </div>

                    <!-- Header -->
                    <div class="flex items-center justify-between gap-3 px-5 py-2 flex-shrink-0">
                        <slot name="header">
                            <h2 class="text-sm font-bold text-gray-500 uppercase tracking-wider">{{ title }}</h2>
                        </slot>
                        <button
                            @click.stop="$emit('update:open', false)"
                            class="w-9 h-9 rounded-full bg-gray-100 flex items-center justify-center min-h-[44px] min-w-[44px]"
                        >
                            <svg class="w-4 h-4 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                            </svg>
                        </button>
                    </div>
                </div>

                <!-- Scrollable content -->
                <div
                    class="flex-1 overflow-y-auto px-5 pb-3"
                    :style="contentMaxHeight !== 'none' ? { maxHeight: contentMaxHeight } : {}"
                >
                    <slot />
                </div>
            </div>
        </div>
    </div>
</template>

<script setup>
import { ref, watch, nextTick } from 'vue';

const props = defineProps({
    open: {
        type: Boolean,
        default: false,
    },
    title: {
        type: String,
        default: '',
    },
    contentMaxHeight: {
        type: String,
        default: 'none',
    },
    offsetClass: {
        type: String,
        default: 'bottom-28',
    },
    // DEV-2594: when open stays true but the selected hit changes, re-run the
    // enter animation so the tap feels acknowledged (list row or map marker).
    contentKey: {
        type: [String, Number],
        default: null,
    },
});

const emit = defineEmits(['update:open']);

const sheetEl = ref(null);
const translateY = ref(300);
const entered = ref(false);
const animating = ref(false);
let isDragging = false;
let startY = 0;
let startTime = 0;
let dragOffset = 0;

function animateOpen() {
    translateY.value = 300;
    entered.value = false;
    nextTick(() => {
        // Force layout read, then animate in
        sheetEl.value?.offsetHeight;
        animating.value = true;
        entered.value = true;
        translateY.value = 0;
    });
}

// Animate in when opened, and again when content identity changes while open.
watch(() => [props.open, props.contentKey], ([isOpen]) => {
    if (isOpen) {
        animateOpen();
    } else {
        entered.value = false;
        animating.value = false;
        translateY.value = 300;
    }
});

function onPointerDown(e) {
    // Only respond to primary button (left click / touch)
    if (e.button !== 0) return;

    isDragging = true;
    startY = e.clientY;
    startTime = Date.now();
    dragOffset = 0;
    animating.value = false;

    // Capture pointer so we get move/up even outside the element
    e.target.setPointerCapture(e.pointerId);

    const onPointerMove = (ev) => {
        if (!isDragging) return;
        const dy = ev.clientY - startY;
        dragOffset = Math.max(0, dy);
        translateY.value = dragOffset;
    };

    const onPointerUp = (ev) => {
        if (!isDragging) return;
        isDragging = false;

        const elapsed = Date.now() - startTime;
        const velocity = dragOffset / Math.max(elapsed, 1);

        // Close if dragged far enough or swiped fast
        if (dragOffset > 80 || (velocity > 0.5 && dragOffset > 20)) {
            animating.value = true;
            const sheetHeight = sheetEl.value?.offsetHeight || 400;
            translateY.value = sheetHeight;
            setTimeout(() => {
                emit('update:open', false);
                translateY.value = 300;
                animating.value = false;
            }, 300);
        } else {
            // Snap back
            animating.value = true;
            translateY.value = 0;
            setTimeout(() => {
                animating.value = false;
            }, 300);
        }

        e.target.removeEventListener('pointermove', onPointerMove);
        e.target.removeEventListener('pointerup', onPointerUp);
        e.target.removeEventListener('pointercancel', onPointerCancel);
    };

    // An interrupted drag (system gesture, incoming call) fires pointercancel
    // instead of pointerup — snap back so the sheet doesn't freeze mid-drag.
    const onPointerCancel = () => {
        if (!isDragging) return;
        isDragging = false;

        animating.value = true;
        translateY.value = 0;
        setTimeout(() => {
            animating.value = false;
        }, 300);

        e.target.removeEventListener('pointermove', onPointerMove);
        e.target.removeEventListener('pointerup', onPointerUp);
        e.target.removeEventListener('pointercancel', onPointerCancel);
    };

    e.target.addEventListener('pointermove', onPointerMove);
    e.target.addEventListener('pointerup', onPointerUp);
    e.target.addEventListener('pointercancel', onPointerCancel);
}
</script>

<style scoped>
.fade-enter-active,
.fade-leave-active {
    transition: opacity 0.3s ease;
}
.fade-enter-from,
.fade-leave-to {
    opacity: 0;
}
</style>
