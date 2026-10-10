<template>
    <button
        type="button"
        @click="openNotifications(router)"
        title="Notifications"
        aria-label="Notifications"
        :class="['relative flex items-center justify-center min-h-[44px] min-w-[44px]', buttonClass]"
    >
        <svg :class="['text-gray-500', iconClass]" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
            <path stroke-linecap="round" stroke-linejoin="round" d="M14.857 17.082a23.848 23.848 0 005.454-1.31A8.967 8.967 0 0118 9.75v-.7V9A6 6 0 006 9v.75a8.967 8.967 0 01-2.312 6.022c1.733.64 3.56 1.085 5.455 1.31m5.714 0a24.255 24.255 0 01-5.714 0m5.714 0a3 3 0 11-5.714 0" />
        </svg>
        <span
            v-if="notifications.unreadCount > 0"
            class="absolute -top-0.5 -right-0.5 min-w-[18px] h-[18px] px-1 rounded-full bg-rose-600 text-white text-[10px] font-bold flex items-center justify-center"
        >{{ notifications.unreadCount > 99 ? '99+' : notifications.unreadCount }}</span>
    </button>
</template>

<script setup>
import { useRouter } from 'vue-router';
import { useNotificationsStore } from '../stores/notifications';
import { openNotifications } from '../lib/scoutNav';

// Button shape/skin, so the bell can match its surrounding row. Defaults to the
// desktop controls widget's gray pill; the mobile action row passes the white
// shadowed circle used by its sibling buttons.
defineProps({
    buttonClass: {
        type: String,
        default: 'w-10 h-10 rounded-full bg-gray-100',
    },
    iconClass: {
        type: String,
        default: 'w-5 h-5',
    },
});

const notifications = useNotificationsStore();
const router = useRouter();
</script>
