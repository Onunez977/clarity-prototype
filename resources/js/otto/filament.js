/**
 * Filament /app Vite entry for the Otto Vue island.
 *
 * Auto-mounts OttoPopout on `#otto-root` when `window.__OTTO_CONFIG__` is present.
 * Filament SPA navigations can replace that node without re-running this module,
 * so `livewire:navigated` mounts again when the new root has no app.
 * Org Map imports `mountOtto` from `./mount.js` instead of this entry.
 */

export { mountOtto } from './mount.js';
import { mountOtto } from './mount.js';

let mountedRoot = null;

function mountFromDom() {
    const root = document.getElementById('otto-root');
    if (!root || !window.__OTTO_CONFIG__) {
        return;
    }

    if (mountedRoot && mountedRoot !== root) {
        mountedRoot._ottoApp?.unmount();
        mountedRoot._ottoApp = undefined;
    }

    mountOtto(root, window.__OTTO_CONFIG__);
    mountedRoot = root;
}

mountFromDom();
document.addEventListener('livewire:navigated', mountFromDom);
