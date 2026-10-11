<template>
    <SidePanel :open="open" title="Profile" width="480px" @update:open="$emit('close')">
        <!-- Identity card -->
        <div class="bg-white rounded-2xl shadow-sm shadow-gray-200/60 p-6 mb-6 border border-gray-100">
            <div class="flex items-center gap-4">
                <div class="w-16 h-16 rounded-full flex items-center justify-center text-white text-xl font-bold flex-shrink-0"
                     style="background-color: hsl(227, 100%, 22%)">
                    {{ initials }}
                </div>
                <div class="min-w-0">
                    <p class="text-xl font-bold text-gray-900 truncate">{{ auth.user?.name || 'Scout User' }}</p>
                    <p class="text-sm text-gray-500 truncate">{{ auth.user?.email }}</p>
                    <span v-if="auth.isImpersonating"
                          class="inline-flex items-center gap-1 mt-1.5 px-2 py-0.5 rounded-full bg-amber-100 text-amber-700 text-xs font-semibold">
                        Impersonating
                    </span>
                </div>
            </div>

            <div class="mt-5 pt-5 border-t border-gray-100 grid grid-cols-2 gap-4">
                <div>
                    <p class="text-xs font-semibold text-gray-400 uppercase tracking-wide">Organization</p>
                    <p class="text-sm font-medium text-gray-900 mt-0.5 truncate">{{ auth.user?.org_name || '—' }}</p>
                </div>
                <div>
                    <p class="text-xs font-semibold text-gray-400 uppercase tracking-wide">Scanner Kit</p>
                    <p class="text-sm font-medium text-gray-900 mt-0.5 truncate">{{ auth.kit?.name || '—' }}</p>
                </div>
            </div>
        </div>

        <!-- Current shift -->
        <div class="bg-white rounded-2xl shadow-sm shadow-gray-200/60 p-5 mb-6 border border-gray-100">
            <p class="text-sm font-semibold text-gray-400 uppercase tracking-wide mb-3">Current Shift</p>
            <div class="flex items-center gap-3">
                <span class="relative flex h-3 w-3 flex-shrink-0">
                    <span v-if="shift.open.value"
                          class="animate-ping absolute inline-flex h-full w-full rounded-full bg-emerald-400 opacity-75"></span>
                    <span class="relative inline-flex rounded-full h-3 w-3"
                          :class="shift.open.value ? 'bg-emerald-500' : 'bg-gray-300'"></span>
                </span>
                <div>
                    <p class="text-base font-semibold text-gray-900">
                        {{ shift.open.value ? 'On shift' : (shift.shiftId.value ? 'Shift ended' : 'No active shift') }}
                    </p>
                    <p v-if="shiftDetail" class="text-sm text-gray-500 mt-0.5">{{ shiftDetail }}</p>
                </div>
            </div>
        </div>

        <!-- This week activity -->
        <div class="bg-white rounded-2xl shadow-sm shadow-gray-200/60 p-5 mb-6 border border-gray-100">
            <p class="text-sm font-semibold text-gray-400 uppercase tracking-wide mb-3">This Week</p>
            <!-- Repos pulled until the direct/staging split is sorted (DEV-2138). -->
            <div class="grid grid-cols-3 gap-4 text-center">
                <div>
                    <p class="text-2xl font-bold text-gray-900">{{ dashboard.stats?.scans_today ?? 0 }}</p>
                    <p class="text-xs text-gray-500 mt-0.5">Scans</p>
                </div>
                <div>
                    <p class="text-2xl font-bold text-gray-900">{{ dashboard.stats?.direct_hits ?? 0 }}</p>
                    <p class="text-xs text-gray-500 mt-0.5">Direct Hits</p>
                </div>
                <div>
                    <p class="text-2xl font-bold text-gray-900">{{ dashboard.stats?.staging_hits ?? 0 }}</p>
                    <p class="text-xs text-gray-500 mt-0.5">Staging Hits</p>
                </div>
            </div>
        </div>

        <!-- Recent trips — gated behind the CLARITY_SCOUT_TRIPS flag, like the Trips stat/quick link. -->
        <div v-if="auth.tripsEnabled" class="bg-white rounded-2xl shadow-sm shadow-gray-200/60 p-5 mb-6 border border-gray-100">
            <div class="flex items-center justify-between mb-3">
                <p class="text-sm font-semibold text-gray-400 uppercase tracking-wide">Recent Trips</p>
                <button @click="goTrips" class="text-sm font-medium" style="color: #FF6666">
                    View all
                </button>
            </div>

            <div v-if="recentDays.length" class="divide-y divide-gray-100">
                <button
                    v-for="day in recentDays"
                    :key="day.date"
                    @click="goDay(day.date)"
                    class="w-full flex items-center justify-between py-3 -mx-1 px-1 rounded-lg hover:bg-gray-50 active:bg-gray-100 transition-colors min-h-[44px] text-left"
                >
                    <div>
                        <p class="text-base font-medium text-gray-900">{{ formatDateFriendly(day.date) }}</p>
                        <p class="text-xs text-gray-500 mt-0.5">
                            {{ day.trips.length }} {{ day.trips.length === 1 ? 'trip' : 'trips' }}
                            · {{ formatDuration(day.totalDuration) }}
                        </p>
                    </div>
                    <div class="flex items-center gap-4 text-right">
                        <div>
                            <p class="text-sm font-semibold text-gray-900">{{ day.totalScans }}</p>
                            <p class="text-[10px] text-gray-400 uppercase tracking-wide">Scans</p>
                        </div>
                        <div>
                            <p class="text-sm font-semibold text-gray-900">{{ day.totalHits }}</p>
                            <p class="text-[10px] text-gray-400 uppercase tracking-wide">Hits</p>
                        </div>
                        <svg class="w-4 h-4 text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7" />
                        </svg>
                    </div>
                </button>
            </div>
            <p v-else class="text-sm text-gray-400 py-2">No trips recorded yet.</p>
        </div>

        <!-- Quick links -->
        <p class="text-sm font-semibold text-gray-400 uppercase tracking-wide mb-3">Quick Links</p>
        <div class="grid grid-cols-2 gap-4 mb-8">
            <button
                v-for="link in quickLinks"
                :key="link.label"
                @click="link.action"
                class="bg-white rounded-2xl p-5 text-left shadow-sm shadow-gray-200/60 border border-gray-100 transition-shadow hover:shadow-md hover:shadow-gray-300/40 active:scale-[0.99]"
            >
                <div class="w-10 h-10 rounded-xl flex items-center justify-center mb-2" :class="link.iconBg">
                    <svg class="w-5 h-5" :class="link.iconColor" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" :d="link.icon" />
                    </svg>
                </div>
                <p class="text-base font-semibold text-gray-900">{{ link.label }}</p>
                <p class="text-sm text-gray-500 mt-0.5">{{ link.hint }}</p>
            </button>
        </div>

        <!-- Logout -->
        <button
            @click="handleLogout"
            class="w-full py-3.5 rounded-2xl border-2 border-rose-200 text-rose-600 bg-white hover:bg-rose-50 active:scale-[0.99] transition font-semibold text-sm min-h-[44px] flex items-center justify-center gap-2"
        >
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 9V5.25A2.25 2.25 0 0013.5 3h-6a2.25 2.25 0 00-2.25 2.25v13.5A2.25 2.25 0 007.5 21h6a2.25 2.25 0 002.25-2.25V15M12 9l-3 3m0 0l3 3m-3-3h12.75" />
            </svg>
            Log Out
        </button>
    </SidePanel>
</template>

<script setup>
import { computed, watch } from 'vue';
import { useRouter } from 'vue-router';
import { useAuthStore } from '../stores/auth';
import { useDashboardStore } from '../stores/dashboard';
import { useMapStore } from '../stores/map';
import { useTripsStore } from '../stores/trips';
import { useShift } from '../composables/useShift';
import SidePanel from './SidePanel.vue';
import { openTrips, openDay, openHelp, goDashboard } from '../lib/scoutNav';
import { formatDateFriendly, formatDuration, formatTime } from '../lib/formatters';

const props = defineProps({
    open: { type: Boolean, default: false },
});
defineEmits(['close']);

const router = useRouter();
const auth = useAuthStore();
const dashboard = useDashboardStore();
const mapStore = useMapStore();
const trips = useTripsStore();
const shift = useShift();

const initials = computed(() => {
    const name = (auth.user?.name || '').trim();
    if (!name) return 'U';
    return name
        .split(/\s+/)
        .slice(0, 2)
        .map((part) => part.charAt(0).toUpperCase())
        .join('');
});

const shiftDetail = computed(() => {
    if (shift.open.value && shift.startedAt.value) {
        return `Started ${formatTime(shift.startedAt.value)}`;
    }
    if (!shift.open.value && shift.endedAt.value) {
        return `Ended ${formatTime(shift.endedAt.value)}`;
    }
    return '';
});

const recentDays = computed(() => trips.groupedTripList.slice(0, 5));

function goTrips() {
    openTrips(router);
}

function goDay(date) {
    openDay(router, date);
}

const quickLinks = [
    {
        label: 'Map',
        hint: 'Live coverage map',
        iconBg: 'bg-blue-100',
        iconColor: 'text-blue-600',
        icon: 'M15 10.5a3 3 0 11-6 0 3 3 0 016 0zM19.5 10.5c0 7.142-7.5 11.25-7.5 11.25S4.5 17.642 4.5 10.5a7.5 7.5 0 1115 0z',
        action: () => goDashboard(router),
    },
    {
        label: 'Help',
        hint: 'Guide & glossary',
        iconBg: 'bg-amber-100',
        iconColor: 'text-amber-600',
        icon: 'M9.879 7.519c1.171-1.025 3.071-1.025 4.242 0 1.172 1.025 1.172 2.687 0 3.712-.203.179-.43.326-.67.442-.745.361-1.45.999-1.45 1.827v.75M21 12a9 9 0 11-18 0 9 9 0 0118 0zm-9 5.25h.008v.008H12v-.008z',
        action: () => openHelp(router),
    },
];

async function handleLogout() {
    // Bird's Eye (admin) has no driver token/router 'login' route — auth.logout()
    // doesn't reset adminMode, so router.push('login') is a no-op here. Exit to the
    // admin panel instead. Branch BEFORE auth.logout: node W9 makes auth.logout()
    // hard-reload to /scout/login only when NOT adminMode, so admin must not rely on
    // it for the redirect.
    if (mapStore.adminMode) {
        window.location.href = '/app';
        return;
    }
    await auth.logout();
    router.push({ name: 'login' });
}

// Refresh identity + activity each time the panel opens.
watch(() => props.open, (isOpen) => {
    if (!isOpen) return;
    auth.fetchUser().catch(() => {});
    shift.fetchStatus();
    if (!dashboard.stats) dashboard.fetchStats().catch(() => {});
    if (!trips.groupedTripList.length) trips.fetchAll();
}, { immediate: true });
</script>
