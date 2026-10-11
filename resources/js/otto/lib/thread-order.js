/**
 * Reconcile the live Otto thread with the server thread.
 *
 * Refresh (`normalizeServerMessages` of `structuredMessages()`, ordered by
 * message id) is the source of truth: each user message, then that turn's
 * assistant reply and its results table. A follow-up poll used to see the
 * previous assistant already on screen and graft the new table onto it, which
 * painted the table above the follow-up user bubble. This merge appends a
 * reply the live thread does not already have, in server order, after that
 * turn's user message.
 */

function sameText(left, right) {
    return String(left ?? '').trim() === String(right ?? '').trim();
}

function sameId(left, right) {
    return left != null && right != null && String(left) === String(right);
}

function cloneMessage(message) {
    return {
        localId: message.localId || (message.id ? String(message.id) : null),
        id: message.id ?? null,
        role: message.role,
        content: message.content ?? '',
        html: message.html ?? '',
        widgets: [...(message.widgets || [])],
        details: [...(message.details || [])],
        pageActions: (message.pageActions || []).map((action) => ({ ...action })),
        feedback: message.feedback ?? null,
        error: message.error ?? null,
        invocationId: message.invocationId ?? null,
        author: message.author ?? null,
        event: message.event,
    };
}

function graftOnto(live, polledMessage) {
    if (!Array.isArray(live.widgets)) {
        live.widgets = [];
    }
    if (!Array.isArray(live.pageActions)) {
        live.pageActions = [];
    }

    (polledMessage.widgets || []).forEach((widget) => {
        if (!live.widgets.some((existing) => existing.searchId === widget.searchId)) {
            live.widgets.push(widget);
        }
    });
    (polledMessage.pageActions || []).forEach((action) => {
        if (!action.actionId || !live.pageActions.some((existing) => existing.actionId === action.actionId)) {
            live.pageActions.push(action);
        }
    });
    if (!live.html && polledMessage.html) {
        live.html = polledMessage.html;
        live.content = polledMessage.content;
    }
    if (!live.id && polledMessage.id) {
        live.id = polledMessage.id;
    }
}

/**
 * @param {Array<Record<string, unknown>>} live
 * @param {Array<Record<string, unknown>>} polled server messages in refresh order
 * @returns {Array<Record<string, unknown>>}
 */
export function mergePolledThread(live, polled) {
    const next = Array.isArray(live) ? live : [];
    const server = Array.isArray(polled) ? polled : [];
    if (server.length === 0) {
        return next.slice();
    }

    const consumed = new Set();
    const ordered = [];

    const take = (predicate) => {
        for (let index = 0; index < next.length; index += 1) {
            if (consumed.has(index) || !predicate(next[index])) {
                continue;
            }
            consumed.add(index);

            return next[index];
        }

        return null;
    };

    server.forEach((polledMessage) => {
        const role = polledMessage?.role;

        if (role === 'user') {
            const liveUser = take((message) => message.role === 'user' && (
                sameId(message.id, polledMessage.id)
                || (!message.id && sameText(message.content, polledMessage.content))
            ));
            if (liveUser) {
                if (!liveUser.id && polledMessage.id) {
                    liveUser.id = polledMessage.id;
                }
                ordered.push(liveUser);
            } else {
                ordered.push(cloneMessage(polledMessage));
            }

            return;
        }

        if (role === 'event') {
            const liveEvent = take((message) => message.role === 'event' && message.event === polledMessage.event);
            ordered.push(liveEvent || cloneMessage(polledMessage));

            return;
        }

        if (role !== 'assistant') {
            ordered.push(cloneMessage(polledMessage));

            return;
        }

        const liveAssistant = (polledMessage.id
            ? take((message) => message.role === 'assistant' && sameId(message.id, polledMessage.id))
            : null)
            || take((message) => message.role === 'assistant' && !message.id);

        if (liveAssistant) {
            graftOnto(liveAssistant, polledMessage);
            ordered.push(liveAssistant);

            return;
        }

        ordered.push(cloneMessage(polledMessage));
    });

    next.forEach((message, index) => {
        if (!consumed.has(index)) {
            ordered.push(message);
        }
    });

    return ordered;
}
