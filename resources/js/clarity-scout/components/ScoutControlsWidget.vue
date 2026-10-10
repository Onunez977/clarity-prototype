<template>
    <!-- Floating controls — top-left, above the map but below the left-column
         Live Hits rail (z-[26], DEV-2594) and below slide-out panels (z-30) /
         modals. Replaces the old <header>. Stacked rows: title+dot /
         Help+bell+profile / End Shift.
         DESKTOP ONLY: on compact (mobile) the whole widget is hidden — its actions
         relocate (Help/Notifications/End Shift → the bottom action row in App.vue;
         Profile + connection dot → top-right in DashboardOverlay). The only piece
         kept on mobile is the contextual trip "Back" chip below. -->
    <div
        v-if="!mapStore.adminMode && !isCompact"
        ref="rootEl"
        class="fixed left-5 z-[25] w-[min(280px,calc(100vw-2.5rem))] bg-white/95 backdrop-blur-sm rounded-2xl shadow-scout-panel border border-scout-edge px-3 py-2.5"
        style="top: calc(env(safe-area-inset-top) + 12px)"
    >
        <div class="flex flex-col gap-2">
            <!-- Row 1: contextual back + title + connection dot + collapse toggle.
                 This row is the whole widget when collapsed (map-first on phones);
                 the lower rows show only when expanded. -->
            <div class="flex items-center gap-2">
                <button
                    v-if="showBack"
                    @click="navBack"
                    title="Back"
                    aria-label="Back"
                    class="w-9 h-9 rounded-full bg-gray-100 flex items-center justify-center min-h-[44px] min-w-[44px] flex-shrink-0"
                >
                    <svg class="w-5 h-5 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7" />
                    </svg>
                </button>

                <span class="text-lg font-bold text-gray-900">{{ mapStore.adminMode ? 'Org Map' : 'Clarity Scout' }}</span>

                <!-- WebSocket connection indicator — dot only; state stays in the
                     title/aria-label for hover & accessibility. When a sustained
                     offline is detected in Live mode, the dot gains a stale ring
                     and an "offline" label beside it. Hidden in Bird's Eye (admin
                     has no driver Echo connection). -->
                <span
                    v-if="!mapStore.adminMode"
                    class="relative inline-flex h-2 w-2 flex-shrink-0"
                    :class="{ 'opacity-60 rounded-full ring-2 ring-amber-400/70': showStale }"
                    role="status"
                    :title="`Realtime: ${echoState}`"
                    :aria-label="`WebSocket connection: ${echoState}`"
                >
                    <span
                        v-if="echoState === 'connected'"
                        class="animate-ping absolute inline-flex h-full w-full rounded-full bg-emerald-400 opacity-75"
                    ></span>
                    <span class="relative inline-flex rounded-full h-2 w-2" :class="echoDotClass"></span>
                </span>
                <span v-if="showStale && !mapStore.adminMode" class="text-[10px] font-semibold uppercase tracking-wider text-amber-600">offline</span>

                <button
                    @click="toggle"
                    :title="expanded ? 'Collapse controls' : 'Expand controls'"
                    :aria-label="expanded ? 'Collapse controls' : 'Expand controls'"
                    :aria-expanded="expanded"
                    class="ml-auto flex items-center justify-center w-9 h-9 min-h-[44px] min-w-[44px] flex-shrink-0 text-gray-400"
                >
                    <svg
                        class="w-5 h-5 transition-transform duration-200"
                        :class="{ 'rotate-180': expanded }"
                        fill="none" stroke="currentColor" viewBox="0 0 24 24"
                    >
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
                    </svg>
                </button>
            </div>

            <!-- Row 2: Help + notifications + profile — driver-account features,
                 hidden in Bird's Eye (DEV-2116). -->
            <div v-if="!mapStore.adminMode && expanded" class="flex items-center gap-2">
                <button
                    @click="navHelp"
                    title="Help & glossary"
                    aria-label="Help & glossary"
                    class="inline-flex items-center gap-2 h-10 px-4 rounded-full border-2 font-semibold text-sm transition active:scale-[0.98] min-h-[44px] flex-shrink-0"
                    :class="isHelpActive
                        ? 'border-[#FF6666] text-[#FF6666] bg-[#FF666620]'
                        : 'border-gray-200 text-gray-600 bg-white hover:bg-gray-50'"
                >
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9.879 7.519c1.171-1.025 3.071-1.025 4.242 0 1.172 1.025 1.172 2.687 0 3.712-.203.179-.43.326-.67.442-.745.361-1.45.999-1.45 1.827v.75M21 12a9 9 0 11-18 0 9 9 0 0118 0zm-9 5.25h.008v.008H12v-.008z" />
                    </svg>
                    Help
                </button>

                <NotificationBell />

                <button
                    @click="navProfile"
                    title="Your profile"
                    aria-label="Your profile"
                    class="w-10 h-10 rounded-full bg-gray-100 flex items-center justify-center min-h-[44px] min-w-[44px] flex-shrink-0"
                >
                    <svg class="w-5 h-5 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0zM4.501 20.118a7.5 7.5 0 0114.998 0A17.933 17.933 0 0112 21.75c-2.676 0-5.216-.584-7.499-1.632z" />
                    </svg>
                </button>
            </div>

            <!-- Row 3: End Shift — active shifts only — then the "Shift ended" chip.
                 Driver-only; hidden in Bird's Eye (DEV-2116). -->
            <button
                v-if="!mapStore.adminMode && expanded && shift.open.value"
                type="button"
                @click="confirmEndShift = true"
                :disabled="shift.loading.value"
                title="End your shift"
                aria-label="End your shift"
                class="w-full inline-flex items-center justify-center gap-2 h-10 px-4 rounded-full border-2 border-rose-600 text-rose-600 bg-white hover:bg-rose-50 active:scale-[0.98] transition font-semibold text-sm disabled:opacity-60 disabled:cursor-wait min-h-[44px]"
            >
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2.25">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M5.25 5.25h13.5v13.5H5.25z"/>
                </svg>
                End Shift
            </button>
            <span
                v-else-if="!mapStore.adminMode && expanded && shift.shiftId.value"
                :title="endedAtLabel ? `Shift ended · ${endedAtLabel}` : 'Shift ended'"
                class="inline-flex items-center gap-1.5 h-10 px-3 rounded-full text-emerald-700 bg-emerald-50 border border-emerald-200 text-xs font-medium"
            >
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2.5">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/>
                </svg>
                Shift ended<span v-if="endedAtLabel" class="ml-1 text-emerald-600/80">· {{ endedAtLabel }}</span>
            </span>
        </div>
    </div>

    <!-- Mobile-only contextual Back chip — the widget is hidden on compact, but a
         trip/day view still needs a way back to the live map (closePanel → router.back).
         Shown only in a trip/day view on a phone. -->
    <button
        v-else-if="!mapStore.adminMode && isCompact && showBack"
        @click="navBack"
        title="Back"
        aria-label="Back to map"
        class="fixed left-5 z-[25] w-11 h-11 min-h-[44px] min-w-[44px] flex items-center justify-center rounded-full bg-white/95 backdrop-blur-sm shadow-scout-panel border border-scout-edge text-gray-500"
        style="top: calc(env(safe-area-inset-top) + 12px)"
    >
        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7" />
        </svg>
    </button>
</template>

<script setup>
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { openHelp, openProfile, closePanel } from '../lib/scoutNav';
import { useShift } from '../composables/useShift';
import { useViewport } from '../composables/useViewport';
import { useCollapsible } from '../composables/useCollapsible';
import { useEchoStatus } from '../composables/useEchoStatus';
import { useMapStore } from '../stores/map';
import NotificationBell from './NotificationBell.vue';

const route = useRoute();
const router = useRouter();
const shift = useShift();
const mapStore = useMapStore();

// Map-first on small screens: collapse to the title row (logo + connection dot)
// by default below `lg`, expand on tap; an explicit choice persists.
const { isCompact } = useViewport();
const { expanded, toggle } = useCollapsible('scanner_controls_expanded', { isCompact, group: 'scout-overlays' });
// Shared with the confirmation modal in App.vue (kept at root so it layers above
// the bottom stat bar). Setting it true opens the "End your shift?" modal.
const confirmEndShift = shift.confirmEndShift;

const hasTrip = computed(() => route.query.day != null);
const isHelpActive = computed(() => route.query.help !== undefined);
const showBack = computed(() => hasTrip.value);

const { echoState, echoDotClass, showStale } = useEchoStatus();

const endedAtLabel = computed(() => {
    if (!shift.endedAt.value) {
        return null;
    }
    try {
        return new Date(shift.endedAt.value).toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' });
    } catch {
        return null;
    }
});

function navHelp() { openHelp(router); }
function navProfile() { openProfile(router); }
function navBack() { closePanel(router); }

// Publish the widget's live height so the left-column overlay cards can sit just
// below it. The widget's height varies by state (the End Shift row, the Back
// button), so a fixed offset can't stay flush — overlays read --scout-controls-h.
//
// DEV-2594: attach whenever the desktop root mounts, not only in onMounted. The
// desktop widget is v-if'd on !isCompact && !adminMode; mounting while compact
// (or admin) left rootEl null and the early-return permanently skipped the
// observer. Live Hits then kept the 132px fallback while the real expanded
// chrome was taller, so z-[25] controls covered the newest Live Hits row and
// stole its clicks.
const rootEl = ref(null);
let resizeObserver = null;

function publishControlsHeight(height) {
    document.documentElement.style.setProperty('--scout-controls-h', `${Math.round(height)}px`);
}

function clearControlsHeight() {
    document.documentElement.style.removeProperty('--scout-controls-h');
}

function detachControlsHeightObserver() {
    resizeObserver?.disconnect();
    resizeObserver = null;
    clearControlsHeight();
}

function attachControlsHeightObserver(el) {
    detachControlsHeightObserver();
    if (!el || typeof ResizeObserver === 'undefined') {
        return;
    }
    resizeObserver = new ResizeObserver(([entry]) => {
        const height = entry.borderBoxSize?.[0]?.blockSize ?? entry.contentRect.height;
        publishControlsHeight(height);
    });
    resizeObserver.observe(el);
    publishControlsHeight(el.getBoundingClientRect().height);
}

watch(rootEl, (el) => {
    if (el) {
        attachControlsHeightObserver(el);
    } else {
        detachControlsHeightObserver();
    }
}, { flush: 'post' });

onBeforeUnmount(() => {
    detachControlsHeightObserver();
});
</script>
