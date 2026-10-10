<template>
    <div
        v-show="show"
        id="otto-detail-popover"
        class="fixed z-[70] pointer-events-auto"
        :style="{ top: position.top + 'px', left: position.left + 'px' }"
        @mouseenter="cancelHide"
        @mouseleave="scheduleHide"
    >
        <div
            ref="popoverContent"
            class="w-[380px] overflow-hidden rounded-xl border border-gray-200 bg-white shadow-2xl dark:border-gray-700 dark:bg-gray-800"
        >
            <div v-if="data">
                <div class="relative">
                    <img
                        :src="data.car_image"
                        alt="Vehicle"
                        class="h-40 w-full bg-gray-100 object-cover dark:bg-gray-900"
                        @error="onCarError"
                    >
                    <img
                        v-if="data.plate_image"
                        :src="data.plate_image"
                        alt="Plate"
                        class="absolute bottom-2 right-2 h-10 rounded border-2 border-white bg-gray-100 shadow-md dark:border-gray-700"
                        @error="onPlateError"
                    >
                    <div class="absolute top-2 left-2">
                        <span
                            class="inline-flex items-center rounded-md px-2 py-1 text-xs font-bold shadow-sm"
                            :class="data.type === 'hit' ? 'bg-red-500 text-white' : 'bg-blue-500 text-white'"
                        >{{ data.type === 'hit' ? 'HIT' : 'SCAN' }}</span>
                    </div>
                </div>

                <div class="space-y-2 px-4 py-3">
                    <div class="flex items-center justify-between">
                        <span class="font-mono text-lg font-bold text-gray-800 dark:text-gray-100">{{ data.plate }}</span>
                        <span class="text-xs text-gray-400">{{ data.state }}</span>
                    </div>
                    <div class="text-xs text-gray-500 dark:text-gray-400">{{ data.scanned_at }}</div>

                    <div
                        v-if="data.type === 'hit' && (data.hotlist_name || data.hotlist_description)"
                        class="rounded-lg border border-red-200 bg-red-50 px-3 py-2 dark:border-red-800 dark:bg-red-900/20"
                    >
                        <div class="text-xs font-bold text-red-700 dark:text-red-300">{{ data.hotlist_name }}</div>
                        <div v-if="data.hotlist_description" class="mt-0.5 text-xs text-red-600 dark:text-red-400">{{ data.hotlist_description }}</div>
                        <div v-if="data.case_number" class="mt-0.5 text-[10px] text-red-500">Order: {{ data.case_number }}</div>
                    </div>

                    <div class="grid grid-cols-2 gap-x-4 gap-y-1 text-xs">
                        <div v-if="data.vehicle_make">
                            <span class="text-gray-400">Make</span>
                            <div class="text-gray-700 dark:text-gray-300">{{ data.vehicle_make }}</div>
                        </div>
                        <div v-if="data.vehicle_model">
                            <span class="text-gray-400">Model</span>
                            <div class="text-gray-700 dark:text-gray-300">{{ data.vehicle_model }}</div>
                        </div>
                        <div v-if="data.vehicle_color">
                            <span class="text-gray-400">Color</span>
                            <div class="text-gray-700 dark:text-gray-300">{{ data.vehicle_color }}</div>
                        </div>
                        <div v-if="data.vehicle_type">
                            <span class="text-gray-400">Type</span>
                            <div class="text-gray-700 dark:text-gray-300">{{ data.vehicle_type }}</div>
                        </div>
                    </div>

                    <div class="border-t border-gray-100 pt-1 text-xs text-gray-400 dark:border-gray-700">
                        <span>{{ data.camera || 'Unknown camera' }}</span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>

<script setup>
import { nextTick, onBeforeUnmount, onMounted, ref } from 'vue';

const show = ref(false);
const data = ref(null);
const position = ref({ top: 0, left: 0 });
const popoverContent = ref(null);
let hideTimeout = null;
let currentKey = null;
let historyOpen = false;
let held = false;

function popupsBlocked() {
    return historyOpen || held;
}

function showDetail(item, event) {
    const key = item.type + '-' + item.id;
    if (currentKey === key && show.value) {
        return;
    }

    clearTimeout(hideTimeout);
    currentKey = key;
    data.value = item;
    show.value = true;
    nextTick(() => positionAt(event));
}

function scheduleHide() {
    hideTimeout = setTimeout(() => {
        show.value = false;
        data.value = null;
        currentKey = null;
    }, 200);
}

function cancelHide() {
    clearTimeout(hideTimeout);
}

function positionAt(event) {
    if (!event?.target) {
        return;
    }
    const rect = event.target.closest('tr, [data-otto-hoverable]')?.getBoundingClientRect();
    if (!rect) {
        return;
    }
    const popover = popoverContent.value;
    if (!popover) {
        return;
    }

    let top = rect.bottom + 8;
    let left = rect.left;

    const pw = popover.offsetWidth || 380;
    const ph = popover.offsetHeight || 300;
    if (top + ph > window.innerHeight) {
        top = rect.top - ph - 8;
    }
    if (left + pw > window.innerWidth) {
        left = window.innerWidth - pw - 16;
    }
    if (left < 8) {
        left = 8;
    }

    position.value = { top, left };
}

function onShow(e) {
    if (popupsBlocked()) {
        return;
    }
    showDetail(e.detail.item, e.detail.event);
}

function onHide() {
    scheduleHide();
}

function forceHide() {
    clearTimeout(hideTimeout);
    show.value = false;
    data.value = null;
    currentKey = null;
}

function onHistoryOpen(e) {
    historyOpen = e.detail === true;
    if (historyOpen) {
        forceHide();
    }
}

function onDismiss() {
    held = true;
    forceHide();
}

function onResume() {
    held = false;
    forceHide();
}

function onCarError(e) {
    e.target.src = '/img/no-image.svg';
}

function onPlateError(e) {
    e.target.style.display = 'none';
}

onMounted(() => {
    window.addEventListener('otto-show-detail', onShow);
    window.addEventListener('otto-hide-detail', onHide);
    window.addEventListener('otto-history-open', onHistoryOpen);
    window.addEventListener('otto-dismiss-popups', onDismiss);
    window.addEventListener('otto-popups-resume', onResume);
});

onBeforeUnmount(() => {
    window.removeEventListener('otto-show-detail', onShow);
    window.removeEventListener('otto-hide-detail', onHide);
    window.removeEventListener('otto-history-open', onHistoryOpen);
    window.removeEventListener('otto-dismiss-popups', onDismiss);
    window.removeEventListener('otto-popups-resume', onResume);
    clearTimeout(hideTimeout);
});
</script>
