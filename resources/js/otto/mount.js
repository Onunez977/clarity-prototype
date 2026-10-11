/**
 * Shared Vue mount for the Otto island (Filament `/app` and Scout Org Map).
 *
 * Filament auto-mounts from filament.js. Org Map calls `mountOtto` from
 * birdseye.js so that bundle does not need a second Vite tag.
 */

import { createApp } from 'vue';
import './page-context.js';
import OttoPopout from './OttoPopout.vue';
import { getSessionEcho } from './lib/echo.js';

/**
 * @typedef {object} OttoConfig
 * @property {number|string} userId
 * @property {string} userInitials
 * @property {string} userColor
 * @property {string} ottoAvatar
 * @property {string} logo
 * @property {string} logoDark
 * @property {string|null} [conversationId]
 * @property {boolean} [hasMessages]
 * @property {boolean} [showDisclaimer]
 */

/**
 * Mount the Otto popout on an element.
 *
 * @param {Element} el
 * @param {OttoConfig} config
 * @returns {import('vue').App|null}
 */
export function mountOtto(el, config) {
    if (!el || !config) {
        return null;
    }

    if (el._ottoApp) {
        return el._ottoApp;
    }

    getSessionEcho();

    const app = createApp(OttoPopout, { config });
    app.mount(el);
    el._ottoApp = app;

    return app;
}
