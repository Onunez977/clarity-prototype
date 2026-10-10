<template>
    <div
        class="contents"
        @keydown.escape.window="onEscape"
        @pointermove.window="onPointerMove"
        @pointerup.window="onWindowPointerUp"
        @pointercancel.window="onWindowPointerUp"
    >
        <button
            v-show="!isExpanded"
            ref="launcher"
            type="button"
            style="touch-action: none;"
            :style="launcherStyle"
            :class="dragging ? 'cursor-grabbing' : 'cursor-grab'"
            class="fixed z-[60] inline-flex h-14 w-14 select-none items-center justify-center rounded-full bg-primary-500 text-white shadow-lg ring-1 ring-black/5 transition-colors hover:bg-primary-600 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 focus-visible:ring-offset-2 dark:ring-white/10"
            :aria-label="open ? 'Hide Otto' : 'Open Otto assistant'"
            aria-haspopup="dialog"
            @pointerdown="onPointerDown"
            @pointerup="onLauncherPointerUp"
            @pointercancel="onPointerCancel"
            @lostpointercapture="onPointerCancel"
            @click="onKeyActivate"
            @dragstart.prevent
        >
            <img :src="config.ottoAvatar" alt="" draggable="false" class="h-10 w-10 rounded-full object-cover" />
        </button>

        <div
            v-if="hasOpened"
            v-show="open && !minimized"
            :style="panelStyle"
            role="dialog"
            aria-label="Otto assistant"
            class="fixed z-[60] flex flex-col overflow-hidden bg-white shadow-2xl dark:bg-gray-900"
            :class="fullscreen
                ? 'inset-0 h-[100dvh] w-screen'
                : 'rounded-2xl border border-gray-200 dark:border-gray-700 w-[calc(100vw-1.5rem)] h-[calc(100dvh-6rem)] sm:w-[420px] sm:h-[600px] lg:w-[560px] lg:h-[680px] max-h-[calc(100dvh-2.5rem)]'"
            @pointerdown="onPanelPointerDown"
        >
            <header class="flex shrink-0 items-center justify-between gap-2 border-b border-gray-100 bg-gray-50 px-3 py-2 dark:border-gray-800 dark:bg-gray-900/60">
                <div class="flex min-w-0 items-center gap-2">
                    <img :src="config.ottoAvatar" alt="Otto" class="h-7 w-7 shrink-0 rounded-full object-cover" />
                    <span class="truncate text-sm font-semibold text-gray-800 dark:text-gray-100">Otto</span>
                    <span v-if="fullscreen" class="ml-1 flex shrink-0 items-center border-l border-gray-300 pl-3 dark:border-gray-700">
                        <img :src="config.logo" alt="Clarity" class="block h-5 w-auto dark:hidden" />
                        <img :src="config.logoDark" alt="Clarity" class="hidden h-5 w-auto dark:block" />
                    </span>
                </div>

                <div class="flex items-center gap-0.5">
                    <button
                        v-if="!fullscreen"
                        type="button"
                        :class="pinned ? 'text-primary-600 dark:text-primary-400' : 'text-gray-400 dark:text-gray-500'"
                        class="inline-flex h-8 w-8 items-center justify-center rounded-lg transition hover:bg-gray-100 dark:hover:bg-gray-800 focus:outline-none focus:ring-2 focus:ring-primary-500"
                        :aria-pressed="pinned.toString()"
                        :aria-label="pinned ? 'Unpin (close when clicking away)' : 'Pin (keep open when clicking away)'"
                        :title="pinned ? 'Pinned — stays open. Click to unpin.' : 'Unpinned — closes on click away. Click to pin.'"
                        @click="togglePin"
                    >
                        <Thumbtack :variant="pinned ? 'filled' : 'outline'" svg-class="h-4 w-4" />
                    </button>

                    <button
                        type="button"
                        class="inline-flex h-8 w-8 items-center justify-center rounded-lg text-gray-400 transition hover:bg-gray-100 dark:text-gray-500 dark:hover:bg-gray-800 focus:outline-none focus:ring-2 focus:ring-primary-500"
                        aria-label="Minimize Otto"
                        title="Minimize"
                        @click="minimize"
                    >
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M5 12h14" /></svg>
                    </button>

                    <button
                        type="button"
                        class="inline-flex h-8 w-8 items-center justify-center rounded-lg text-gray-400 transition hover:bg-gray-100 dark:text-gray-500 dark:hover:bg-gray-800 focus:outline-none focus:ring-2 focus:ring-primary-500"
                        :aria-label="fullscreen ? 'Exit fullscreen' : 'Expand Otto to fullscreen'"
                        :aria-pressed="fullscreen.toString()"
                        :title="fullscreen ? 'Exit fullscreen' : 'Fullscreen'"
                        @click="toggleFullscreen"
                    >
                        <svg v-if="!fullscreen" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 3.75v4.5m0-4.5h4.5m-4.5 0L9 9M3.75 20.25v-4.5m0 4.5h4.5m-4.5 0L9 15M20.25 3.75h-4.5m4.5 0v4.5m0-4.5L15 9m5.25 11.25h-4.5m4.5 0v-4.5m0 4.5L15 15" />
                        </svg>
                        <svg v-else class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M9 9V4.5M9 9H4.5M9 9L3.75 3.75M9 15v4.5M9 15H4.5M9 15l-5.25 5.25M15 9h4.5M15 9V4.5M15 9l5.25-5.25M15 15h4.5M15 15v4.5m0-4.5l5.25 5.25" />
                        </svg>
                    </button>

                    <button
                        type="button"
                        class="inline-flex h-8 w-8 items-center justify-center rounded-lg text-gray-400 transition hover:bg-gray-100 dark:text-gray-500 dark:hover:bg-gray-800 focus:outline-none focus:ring-2 focus:ring-primary-500"
                        aria-label="Close Otto"
                        title="Close"
                        @click="close"
                    >
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" /></svg>
                    </button>
                </div>
            </header>

            <div class="otto-popout-body min-h-0 flex-1 overflow-hidden">
                <OttoChat ref="chat" :config="config" :fullscreen="fullscreen" />
            </div>
        </div>

        <DetailPopover />
    </div>
</template>

<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import OttoChat from './OttoChat.vue';
import DetailPopover from './components/DetailPopover.vue';
import Thumbtack from './components/icons/Thumbtack.vue';

const props = defineProps({
    config: { type: Object, required: true },
});

const open = ref(false);
const pinned = ref(true);
const minimized = ref(false);
const fullscreen = ref(false);
const hasOpened = ref(false);
const posX = ref(null);
const posY = ref(null);
const dragging = ref(false);
const launcher = ref(null);
const chat = ref(null);

const pointerActive = ref(false);
let pointerId = null;
let startX = 0;
let startY = 0;
let origX = 0;
let origY = 0;

const DRAG_THRESHOLD = 5;
const SIZE = 56;
const EDGE = 8;
const PANEL_MARGIN = 20;
const HELP_CLEARANCE = 72;

const isExpanded = computed(() => open.value && !minimized.value);

const launcherStyle = computed(() => {
    if (posX.value === null) {
        return {};
    }

    return { left: `${posX.value}px`, top: `${posY.value}px` };
});

const panelStyle = computed(() => {
    if (fullscreen.value) {
        return {};
    }
    const m = `${PANEL_MARGIN}px`;
    if (posX.value === null) {
        return { left: '', right: m, top: '', bottom: m };
    }
    const right = posX.value + SIZE / 2 > window.innerWidth / 2;
    const bottom = posY.value + SIZE / 2 > window.innerHeight / 2;

    return {
        left: right ? '' : m,
        right: right ? m : '',
        top: bottom ? '' : m,
        bottom: bottom ? m : '',
    };
});

function defaultPosition() {
    posX.value = window.innerWidth - SIZE - PANEL_MARGIN;
    posY.value = window.innerHeight - SIZE - PANEL_MARGIN - HELP_CLEARANCE;
}

function clampToViewport() {
    if (posX.value === null) {
        return;
    }
    const maxX = window.innerWidth - SIZE - EDGE;
    const maxY = window.innerHeight - SIZE - EDGE;
    posX.value = Math.min(Math.max(posX.value, EDGE), Math.max(EDGE, maxX));
    posY.value = Math.min(Math.max(posY.value, EDGE), Math.max(EDGE, maxY));
}

function restore() {
    try {
        const raw = localStorage.getItem('otto-popout');
        if (!raw) {
            return;
        }
        const s = JSON.parse(raw);
        open.value = !!s.open;
        pinned.value = s.pinned ?? true;
        minimized.value = !!s.minimized;
        if (typeof s.posX === 'number' && typeof s.posY === 'number') {
            posX.value = s.posX;
            posY.value = s.posY;
        }
    } catch {
        /* ignore corrupt state */
    }
}

function save() {
    try {
        localStorage.setItem(
            'otto-popout',
            JSON.stringify({
                open: open.value,
                pinned: pinned.value,
                minimized: minimized.value,
                posX: posX.value,
                posY: posY.value,
            }),
        );
    } catch {
        /* storage may be unavailable */
    }
}

function syncFullscreenFlag() {
    window._ottoFullscreen = fullscreen.value;
    window.dispatchEvent(new CustomEvent('otto-fullscreen-changed', { detail: fullscreen.value }));
}

function onPointerDown(e) {
    if (isExpanded.value) {
        return;
    }
    if (e.button != null && e.button !== 0) {
        return;
    }

    pointerActive.value = true;
    dragging.value = false;
    pointerId = e.pointerId ?? null;
    startX = e.clientX;
    startY = e.clientY;
    origX = posX.value;
    origY = posY.value;

    const el = e.currentTarget;
    if (el && el.setPointerCapture && e.pointerId != null) {
        try {
            el.setPointerCapture(e.pointerId);
        } catch {
            /* noop */
        }
    }
}

function onPointerMove(e) {
    if (!pointerActive.value) {
        return;
    }
    if (pointerId !== null && e.pointerId != null && e.pointerId !== pointerId) {
        return;
    }

    if (e.buttons === 0) {
        if (dragging.value) {
            save();
        }
        resetPointer();

        return;
    }

    const dx = e.clientX - startX;
    const dy = e.clientY - startY;
    if (!dragging.value && Math.hypot(dx, dy) < DRAG_THRESHOLD) {
        return;
    }

    dragging.value = true;
    posX.value = origX + dx;
    posY.value = origY + dy;
    clampToViewport();
}

function onLauncherPointerUp(e) {
    if (!pointerActive.value) {
        return;
    }
    if (pointerId !== null && e.pointerId != null && e.pointerId !== pointerId) {
        return;
    }

    const wasDrag = dragging.value;
    resetPointer();

    if (wasDrag) {
        save();
    } else {
        openPanel();
    }
}

function onWindowPointerUp(e) {
    if (!pointerActive.value) {
        return;
    }
    if (pointerId !== null && e && e.pointerId != null && e.pointerId !== pointerId) {
        return;
    }
    if (dragging.value) {
        save();
    }
    resetPointer();
}

function onPointerCancel() {
    resetPointer();
}

function resetPointer() {
    pointerActive.value = false;
    dragging.value = false;
    pointerId = null;
}

function openPanel() {
    open.value = true;
    minimized.value = false;
    hasOpened.value = true;
}

function onKeyActivate(e) {
    if (e.detail !== 0) {
        return;
    }
    openPanel();
}

function togglePin() {
    pinned.value = !pinned.value;
}

function minimize() {
    minimized.value = true;
}

function toggleFullscreen() {
    fullscreen.value = !fullscreen.value;
    window.Livewire?.dispatch('otto-set-fullscreen', { fullscreen: fullscreen.value });
}

function openFullscreenFromWidget() {
    if (fullscreen.value) {
        return;
    }
    openPanel();
    fullscreen.value = true;
    window.Livewire?.dispatch('otto-set-fullscreen', { fullscreen: true });
}

function close() {
    chat.value?.startNewConversation();
    window.Livewire?.dispatch('otto-start-new-chat');
    open.value = false;
    minimized.value = false;
    fullscreen.value = false;
    window.Livewire?.dispatch('otto-set-fullscreen', { fullscreen: false });
}

function onPanelPointerDown(e) {
    panelHost = e.currentTarget;
}

let panelHost = null;

function onDocumentPointerDown(e) {
    if (!isExpanded.value || pinned.value) {
        return;
    }
    const target = e.target;
    if (launcher.value?.contains(target)) {
        return;
    }
    if (panelHost?.contains(target)) {
        return;
    }
    if (target?.closest?.('#otto-detail-popover')) {
        return;
    }
    minimize();
}

function onEscape() {
    if (isExpanded.value && !pinned.value) {
        minimize();
    }
}

function onResize() {
    clampToViewport();
    save();
}

onMounted(() => {
    restore();
    if (posX.value === null || posY.value === null) {
        defaultPosition();
    }
    clampToViewport();
    hasOpened.value = open.value;
    syncFullscreenFlag();
    window.addEventListener('resize', onResize);
    window.addEventListener('otto-open-fullscreen', openFullscreenFromWidget);
    document.addEventListener('pointerdown', onDocumentPointerDown);
});

onBeforeUnmount(() => {
    window.removeEventListener('resize', onResize);
    window.removeEventListener('otto-open-fullscreen', openFullscreenFromWidget);
    document.removeEventListener('pointerdown', onDocumentPointerDown);
});

watch(open, save);
watch(pinned, save);
watch(minimized, save);
watch(fullscreen, syncFullscreenFlag);
</script>
