/**
 * First-party Otto HTTP helpers. Session cookie + CSRF, same as other
 * same-origin fetches (Scout org-map / Filament axios).
 *
 * X-Socket-ID lets broadcast()->toOthers() skip this tab. Livewire used to
 * attach it; a raw fetch does not.
 */

import { getSessionEcho } from './echo.js';

function readCookie(name) {
    const match = document.cookie.match(new RegExp(`(?:^|; )${name}=([^;]*)`));

    return match ? decodeURIComponent(match[1]) : '';
}

/**
 * @returns {Record<string, string>}
 */
export function csrfHeaders() {
    const headers = {
        Accept: 'application/json',
        'Content-Type': 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
    };

    const meta = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
    if (meta) {
        headers['X-CSRF-TOKEN'] = meta;
    }

    const xsrf = readCookie('XSRF-TOKEN');
    if (xsrf) {
        headers['X-XSRF-TOKEN'] = xsrf;
    }

    const socketId = getSessionEcho()?.socketId?.();
    if (socketId) {
        headers['X-Socket-ID'] = socketId;
    }

    return headers;
}

/**
 * @param {string} url
 * @param {RequestInit} [init]
 * @returns {Promise<any>}
 */
export async function ottoFetch(url, init = {}) {
    const response = await fetch(url, {
        credentials: 'same-origin',
        ...init,
        headers: {
            ...csrfHeaders(),
            ...(init.headers || {}),
        },
    });

    if (!response.ok) {
        const error = new Error(`Otto request failed (${response.status})`);
        error.status = response.status;
        try {
            error.body = await response.json();
        } catch {
            error.body = null;
        }
        throw error;
    }

    const text = await response.text();
    if (text === '') {
        return null;
    }

    try {
        return JSON.parse(text);
    } catch {
        return text;
    }
}

function jsonBody(payload) {
    return { method: 'POST', body: JSON.stringify(payload ?? {}) };
}

/**
 * @param {string} [search]
 * @returns {Promise<{ conversations: Array<{ id: string, title: string|null }>, showDisclaimer: boolean, activeConversationId: string|null }>}
 */
export function listConversations(search = '') {
    const query = search ? `?search=${encodeURIComponent(search)}` : '';

    return ottoFetch(`/otto/conversations${query}`);
}

/**
 * @param {string} id
 * @returns {Promise<{ conversationId: string, title: string|null, messages: Array<Record<string, unknown>> }>}
 */
export function getConversation(id) {
    return ottoFetch(`/otto/conversations/${encodeURIComponent(id)}`);
}

/**
 * First send — mints a conversation.
 *
 * @param {{ message: string, pageContext: Record<string, unknown>|null }} payload
 * @returns {Promise<{ conversationId: string }>}
 */
export function createConversation(payload) {
    return ottoFetch('/otto/conversations', jsonBody(payload));
}

/**
 * Follow-up send on an existing thread.
 *
 * @param {string} id
 * @param {{ message: string, pageContext: Record<string, unknown>|null }} payload
 * @returns {Promise<{ conversationId: string }>}
 */
export function sendMessage(id, payload) {
    return ottoFetch(`/otto/conversations/${encodeURIComponent(id)}/messages`, jsonBody(payload));
}

/**
 * Hang-net while thinking. Pending-key last; complete vs the raw assistant
 * row count stored at send. `idle` means the key is gone but no new assistant
 * row is visible yet — keep polling until `complete` or the client timeout.
 *
 * @param {string} id
 * @param {{ turn_baseline_assistant_count?: number }} [payload]
 * @returns {Promise<{ status: 'idle'|'pending'|'complete', conversationId: string|null, messages: Array<Record<string, unknown>>, hasMessages: boolean }>}
 */
export function pollTurn(id, payload = {}) {
    return ottoFetch(`/otto/conversations/${encodeURIComponent(id)}/poll`, jsonBody(payload));
}

/**
 * @param {string} id
 */
export function stopTurn(id) {
    return ottoFetch(`/otto/conversations/${encodeURIComponent(id)}/stop`, jsonBody({}));
}

/**
 * Clear the session's active conversation (close / new chat).
 * Pass the id being left so a reset that loses the race with a new send
 * does not forget the conversation that send just stored.
 *
 * @param {string|null} [conversationId]
 */
export function resetActiveConversation(conversationId = null) {
    return ottoFetch('/otto/conversations/reset', jsonBody(
        conversationId ? { conversationId } : {},
    ));
}

/**
 * Thumbs on a persisted assistant row. `rating` null clears it.
 *
 * @param {string} conversationId
 * @param {string} messageId
 * @param {{ rating: boolean|null, comment?: string|null, surface?: string }} payload
 */
export function submitMessageFeedback(conversationId, messageId, payload) {
    return ottoFetch(
        `/otto/conversations/${encodeURIComponent(conversationId)}/messages/${encodeURIComponent(messageId)}/feedback`,
        jsonBody(payload),
    );
}

/**
 * Beta disclaimer acceptance for this login session. Always the session
 * route — it must not wait for a conversation id, or the modal only
 * appears after the first send.
 *
 * @param {string|null} [_conversationId]
 */
export function acceptDisclaimer(_conversationId = null) {
    return ottoFetch('/otto/disclaimer', jsonBody({}));
}

/**
 * @param {string} id
 * @param {Record<string, unknown>} payload
 * @returns {Promise<{ ok: boolean, zone_id?: number, error?: string }>}
 */
export function zoneCreate(id, payload) {
    return ottoFetch(`/otto/conversations/${encodeURIComponent(id)}/zone-create`, jsonBody(payload));
}

/**
 * @param {string} id
 * @param {Record<string, unknown>} payload
 * @returns {Promise<{ ok: boolean, zone_id?: number, error?: string }>}
 */
export function zoneUpdate(id, payload) {
    return ottoFetch(`/otto/conversations/${encodeURIComponent(id)}/zone-update`, jsonBody(payload));
}

/**
 * @param {string} id
 * @param {Record<string, unknown>} payload
 * @returns {Promise<{ ok: boolean, url?: string, error?: string }>}
 */
export function openZoneEditor(id, payload) {
    return ottoFetch(`/otto/conversations/${encodeURIComponent(id)}/open-zone-editor`, jsonBody(payload));
}

/**
 * @param {string} conversationId
 */
export function getInvestigation(conversationId) {
    return ottoFetch(`/otto/investigation/${encodeURIComponent(conversationId)}`);
}

/**
 * @returns {Record<string, unknown>|null}
 */
export function currentPageContext() {
    try {
        return window.Otto?.currentPageContext?.() ?? null;
    } catch {
        return null;
    }
}
