<template>
    <div
        v-if="toasts.length > 0"
        class="fixed inset-x-0 z-50 flex flex-col items-center gap-2 pointer-events-none px-4"
        style="top: calc(env(safe-area-inset-top) + 12px)"
    >
        <div
            v-for="toast in toasts"
            :key="toast.id"
            class="pointer-events-auto max-w-md w-full rounded-lg shadow-lg px-4 py-3 text-sm flex items-start gap-3"
            :class="[
                toast.type === 'success' && 'bg-emerald-600 text-white',
                toast.type === 'error' && 'bg-red-600 text-white',
                toast.type === 'info' && 'bg-gray-900 text-white',
            ]"
        >
            <!-- Clicking the body runs the action and marks the persisted
                 notification read; the ✕ only dismisses (leaves it unread). -->
            <div
                class="flex-1"
                :class="(toast.action || toast.notificationId) && 'cursor-pointer'"
                @click="runAction(toast)"
            >
                <span>{{ toast.message }}</span>
                <button
                    v-if="toast.action"
                    type="button"
                    class="ml-2 font-semibold underline underline-offset-2 whitespace-nowrap hover:opacity-80"
                    @click.stop="runAction(toast)"
                >{{ toast.action.label }}</button>
            </div>
            <button
                type="button"
                class="text-white/80 hover:text-white"
                aria-label="Dismiss"
                @click="dismiss(toast.id)"
            >
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                </svg>
            </button>
        </div>
    </div>
</template>

<script setup>
import { useToast } from '../composables/useToast';
import { useNotificationsStore } from '../stores/notifications';

const { toasts, dismiss } = useToast();
const notifications = useNotificationsStore();

function runAction(toast) {
    if (!toast.action && !toast.notificationId) return;
    try {
        toast.action?.onClick?.();
        if (toast.notificationId) {
            notifications.markRead(toast.notificationId);
        }
    } finally {
        dismiss(toast.id);
    }
}
</script>
