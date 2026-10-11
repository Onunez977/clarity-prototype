import { ref, onMounted, onUnmounted } from 'vue';

export function useInstallPrompt() {
    const canInstall = ref(false);
    let deferredPrompt = null;

    function onBeforeInstallPrompt(event) {
        event.preventDefault();
        deferredPrompt = event;

        const dismissed = localStorage.getItem('scanner_install_dismissed');
        const isStandalone = window.matchMedia('(display-mode: standalone)').matches;

        if (!dismissed && !isStandalone) {
            canInstall.value = true;
        }
    }

    async function promptInstall() {
        if (!deferredPrompt) return;

        deferredPrompt.prompt();
        const { outcome } = await deferredPrompt.userChoice;

        if (outcome === 'accepted') {
            canInstall.value = false;
        }

        deferredPrompt = null;
    }

    function dismiss() {
        canInstall.value = false;
        localStorage.setItem('scanner_install_dismissed', '1');
    }

    function onAppInstalled() {
        canInstall.value = false;
        deferredPrompt = null;
    }

    onMounted(() => {
        window.addEventListener('beforeinstallprompt', onBeforeInstallPrompt);
        window.addEventListener('appinstalled', onAppInstalled);
    });

    onUnmounted(() => {
        window.removeEventListener('beforeinstallprompt', onBeforeInstallPrompt);
        window.removeEventListener('appinstalled', onAppInstalled);
    });

    return { canInstall, promptInstall, dismiss };
}
