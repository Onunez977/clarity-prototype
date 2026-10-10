<template>
    <SidePanel :open="open" title="Notifications" width="420px" @update:open="$emit('close')">
        <div v-if="notifications.unreadCount > 0" class="flex justify-end mb-3">
            <button
                type="button"
                class="text-sm font-medium text-blue-600 hover:text-blue-700 min-h-[44px] px-1"
                @click="notifications.markAllRead()"
            >Mark all read</button>
        </div>

        <div v-if="notifications.error" class="px-4 py-8 flex flex-col items-center gap-3 text-center">
            <p class="text-sm text-gray-500">Couldn't load notifications.</p>
            <button
                type="button"
                class="text-sm font-medium text-blue-600 hover:text-blue-700 min-h-[44px] px-1"
                @click="reload"
            >Try again</button>
        </div>

        <p v-else-if="notifications.items.length === 0" class="px-4 py-8 text-center text-sm text-gray-400">
            You're all caught up.
        </p>

        <div v-else class="bg-white rounded-2xl shadow-sm shadow-gray-200/60 border border-gray-100 overflow-hidden">
            <button
                v-for="item in notifications.items"
                :key="item.id"
                type="button"
                @click="openItem(item)"
                class="w-full text-left px-4 py-3 flex items-start gap-3 hover:bg-gray-50 transition-colors border-b border-gray-50 last:border-b-0"
                :class="!item.read_at && 'bg-blue-50/40'"
            >
                <span
                    class="mt-1.5 w-2 h-2 rounded-full flex-shrink-0"
                    :class="item.read_at ? 'bg-transparent' : 'bg-blue-500'"
                ></span>
                <span class="flex-1 min-w-0">
                    <span class="block text-sm font-medium text-gray-900 truncate">{{ item.title || 'Notification' }}</span>
                    <span v-if="item.body" class="block text-sm text-gray-500">{{ item.body }}</span>
                    <span class="block text-xs text-gray-400 mt-0.5">{{ formatRelativeTime(item.created_at) }}</span>
                </span>
            </button>
        </div>
    </SidePanel>
</template>

<script setup>
import { watch } from 'vue';
import { useRouter } from 'vue-router';
import { useNotificationsStore } from '../stores/notifications';
import { useMapStore } from '../stores/map';
import { goDashboard } from '../lib/scoutNav';
import { formatRelativeTime } from '../lib/formatters';
import SidePanel from './SidePanel.vue';

const props = defineProps({
    open: { type: Boolean, default: false },
});
const emit = defineEmits(['close']);

const router = useRouter();
const notifications = useNotificationsStore();
const mapStore = useMapStore();

function openItem(item) {
    notifications.markRead(item.id);
    if (item.zone_id != null && mapStore.findZoneFeature(item.zone_id)) {
        // Clearing the query closes this panel; then focus the zone on the map.
        goDashboard(router);
        mapStore.requestZoneFocus(item.zone_id);
        return;
    }
    emit('close');
}

function reload() {
    notifications.fetch().catch(() => {});
}

// Refresh the list each time the panel opens.
watch(() => props.open, (isOpen) => {
    if (isOpen) notifications.fetch().catch(() => {});
});
</script>
