import { onMounted, onBeforeUnmount, watch } from 'vue';
import { useRouter } from 'vue-router';
import { getEcho } from '../lib/echo';
import { useAuthStore } from '../stores/auth';
import { useMapStore } from '../stores/map';
import { useNotificationsStore } from '../stores/notifications';
import { useToast } from './useToast';

/**
 * Subscribes the current user to their private Scout channel and refetches
 * zones whenever the backend dispatches `ZoneAssignmentsChanged`/`ZoneUpdated`.
 * The message text + persistence are owned server-side: the broadcast payload
 * carries a persisted notification, so we surface it as a toast and let the
 * bell pick it up. Clicking the toast marks that notification read.
 */
export function useZoneRealtime() {
    const auth = useAuthStore();
    const mapStore = useMapStore();
    const notifications = useNotificationsStore();
    const router = useRouter();
    const { show } = useToast();

    let channel = null;
    let inFlight = false;

    // Take the driver to the zone on the map. Clear any open panel/trip so the
    // dashboard map is visible, then hand ScannerMap a focus request to
    // fitBounds onto the zone (the map is persistent now, so it's always live).
    function focusZone(zoneId) {
        router.push({ path: '/scout', query: {} });
        mapStore.requestZoneFocus(zoneId);
    }

    function subscribe(userId) {
        const echo = getEcho();
        if (!echo || !userId) return;

        channel = echo.private(`clarity.scout.user.${userId}`);

        channel.listen('.zones.changed', async (e) => {
            if (inFlight) return;
            inFlight = true;
            try {
                // The map must always refresh — geometry/assignment may have changed.
                await mapStore.fetchZones();

                if (!e?.notification_id) return;

                // Persist into the bell store right away so the badge updates
                // even before the user opens the dropdown.
                notifications.prepend({
                    id: e.notification_id,
                    kind: e.kind ?? null,
                    title: e.title ?? null,
                    body: e.body ?? null,
                    zone_id: e.zone_id ?? null,
                    zone_name: e.zone_name ?? null,
                    read_at: null,
                    created_at: e.changed_at ?? new Date().toISOString(),
                });

                const canFocus = e.zone_id != null && mapStore.findZoneFeature(e.zone_id);
                show(e.body || e.title || 'A zone you cover changed.', {
                    type: 'info',
                    duration: 8000,
                    notificationId: e.notification_id,
                    action: canFocus ? { label: 'View zone', onClick: () => focusZone(e.zone_id) } : null,
                });
            } finally {
                inFlight = false;
            }
        });
    }

    function unsubscribe() {
        const echo = getEcho();
        if (echo && channel) {
            try {
                echo.leave(`clarity.scout.user.${auth.user?.id}`);
            } catch {
                // ignore
            }
        }
        channel = null;
    }

    onMounted(() => {
        if (auth.user?.id) subscribe(auth.user.id);
    });

    watch(
        () => auth.user?.id,
        (newId, oldId) => {
            if (oldId === newId) return;
            unsubscribe();
            if (newId) subscribe(newId);
        }
    );

    onBeforeUnmount(unsubscribe);
}
