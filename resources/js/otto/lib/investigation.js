import { reactive } from 'vue';
import { getInvestigation } from './api.js';

export const investigation = reactive({
    conversationId: null,
    scans: [],
    hits: [],
    drivers: [],
    zones: [],
    hotlists: [],
    convoys: [],
    searches: [],
    loaded: false,
});

/**
 * Publish hydration JSON for chat widgets + the results pane.
 * Also writes `window._ottoInvestigationData` so any leftover Alpine islands
 * (and source-contract greps) still see the same feed.
 *
 * @param {Record<string, unknown>|null} data
 * @param {string|null} conversationId
 */
export function setInvestigationData(data, conversationId = null) {
    investigation.conversationId = conversationId;
    investigation.scans = [...(data?.scans || [])];
    investigation.hits = [...(data?.hits || [])];
    investigation.drivers = [...(data?.drivers || [])];
    investigation.zones = [...(data?.zones || [])];
    investigation.hotlists = [...(data?.hotlists || [])];
    investigation.convoys = [...(data?.convoys || [])];
    investigation.searches = [...(data?.searches || [])];
    investigation.loaded = true;

    window._ottoInvestigationData = data || {
        scans: [],
        hits: [],
        drivers: [],
        zones: [],
        hotlists: [],
        convoys: [],
        searches: [],
    };
    window.dispatchEvent(new Event('otto-investigation-loaded'));
}

export function clearInvestigation() {
    setInvestigationData(null, null);
    investigation.loaded = false;
}

export async function loadInvestigation(conversationId) {
    if (!conversationId) {
        clearInvestigation();

        return null;
    }

    try {
        const data = await getInvestigation(conversationId);
        setInvestigationData(data, conversationId);

        return data;
    } catch (e) {
        console.error('Failed to load Otto investigation data', e);

        return null;
    }
}

export function buildMentionItems() {
    const data = window._ottoInvestigationData;
    if (!data) {
        return [];
    }
    const items = [];
    const seen = new Set();

    [...(data.scans ?? []), ...(data.hits ?? []), ...(data.hotlists ?? [])].forEach((item) => {
        if (item.plate && !seen.has(`plate:${item.plate}`)) {
            seen.add(`plate:${item.plate}`);
            items.push({ type: 'plate', value: item.plate, label: item.plate, sub: item.state ?? '' });
        }
    });

    [...(data.scans ?? []), ...(data.hits ?? [])].forEach((item) => {
        if (item.camera && !seen.has(`camera:${item.camera}`)) {
            seen.add(`camera:${item.camera}`);
            items.push({ type: 'camera', value: item.camera, label: item.camera, sub: 'Camera' });
        }
    });

    return items;
}
