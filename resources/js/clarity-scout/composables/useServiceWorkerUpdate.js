import { ref, onMounted, onUnmounted } from 'vue';

export function useServiceWorkerUpdate() {
    const updateAvailable = ref(false);
    let checkInterval = null;

    function onMessage(event) {
        if (event.data && event.data.type === 'SW_UPDATED') {
            updateAvailable.value = true;
        }
    }

    function reload() {
        window.location.reload();
    }

    function dismiss() {
        updateAvailable.value = false;
    }

    function startPeriodicCheck() {
        // Standalone PWAs don't trigger SW update checks on navigation.
        // Poll every 60 seconds so updates are discovered within a minute.
        const isStandalone = window.matchMedia('(display-mode: standalone)').matches
            || window.navigator.standalone === true;

        if (!isStandalone) return;

        checkInterval = setInterval(() => {
            navigator.serviceWorker?.getRegistration().then((reg) => {
                if (reg) reg.update();
            });
        }, 60_000);
    }

    onMounted(() => {
        navigator.serviceWorker?.addEventListener('message', onMessage);
        startPeriodicCheck();
    });

    onUnmounted(() => {
        navigator.serviceWorker?.removeEventListener('message', onMessage);
        if (checkInterval) clearInterval(checkInterval);
    });

    return { updateAvailable, reload, dismiss };
}
