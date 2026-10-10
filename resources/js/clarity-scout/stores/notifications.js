import { defineStore } from 'pinia';
import { ref, computed } from 'vue';
import { authHeaders, handleUnauthorized, buildUrl } from '../lib/fetch';

export const useNotificationsStore = defineStore('notifications', () => {
    const items = ref([]);
    const loading = ref(false);
    const error = ref(null);

    const unreadCount = computed(() => items.value.filter((n) => !n.read_at).length);

    async function fetch() {
        loading.value = true;
        error.value = null;
        try {
            const response = await window.fetch(buildUrl('/notifications'), {
                headers: authHeaders(),
            });
            handleUnauthorized(response);
            if (!response.ok) throw new Error('Failed to load notifications');
            const data = await response.json();
            items.value = data.notifications ?? [];
        } catch (e) {
            error.value = e;
            throw e;
        } finally {
            loading.value = false;
        }
    }

    // Insert a notification that just arrived over the websocket. De-dupes on
    // id so a refetch racing the realtime push can't show it twice.
    function prepend(record) {
        if (!record?.id || items.value.some((n) => n.id === record.id)) return;
        items.value.unshift(record);
    }

    async function markRead(id) {
        if (!id) return;
        const target = items.value.find((n) => n.id === id);
        if (target && target.read_at) return;

        // Optimistic: flip locally so the badge updates instantly.
        if (target) target.read_at = new Date().toISOString();

        // The /notifications/{id}/read endpoint records the analytics event
        // server-side (one row per action) — no client-side track here.
        try {
            const response = await window.fetch(buildUrl(`/notifications/${id}/read`), {
                method: 'POST',
                headers: authHeaders(),
            });
            handleUnauthorized(response);
        } catch {
            // Leave the optimistic state in place — a later fetch reconciles.
        }
    }

    async function markAllRead() {
        const now = new Date().toISOString();
        items.value.forEach((n) => {
            if (!n.read_at) n.read_at = now;
        });

        // Tracked server-side by /notifications/read-all (one row per action).
        try {
            const response = await window.fetch(buildUrl('/notifications/read-all'), {
                method: 'POST',
                headers: authHeaders(),
            });
            handleUnauthorized(response);
        } catch {
            // ignore — optimistic state stands
        }
    }

    function reset() {
        items.value = [];
        error.value = null;
    }

    return { items, loading, error, unreadCount, fetch, prepend, markRead, markAllRead, reset };
});
