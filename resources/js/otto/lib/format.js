export function driverSeenAt(item) {
    return item?.last_seen_at_utc || item?.scanned_at || '';
}

export function minutesAgoFrom(ts) {
    if (!ts) {
        return null;
    }
    const date = new Date(ts);
    if (Number.isNaN(date.getTime())) {
        return null;
    }

    return Math.max(0, Math.floor((Date.now() - date.getTime()) / 60000));
}

export function fmtRelative(ts) {
    const minutes = minutesAgoFrom(ts);
    if (minutes === null) {
        return '';
    }
    if (minutes <= 1) {
        return 'about a minute ago';
    }
    if (minutes < 60) {
        return minutes + ' minutes ago';
    }

    const hours = Math.floor(minutes / 60);
    if (hours === 1) {
        return 'about 1 hour ago';
    }

    return 'about ' + hours + ' hours ago';
}

export function fmtTime(ts) {
    return ts || '';
}

export function matchesSearchId(item, searchId) {
    if (!searchId) {
        return false;
    }
    if (item.searchId === searchId) {
        return true;
    }

    return Array.isArray(item.searchIds) && item.searchIds.includes(searchId);
}

export function itemKey(item) {
    return `${item.type}-${item.id}`;
}

export function limitTitle(title, max = 35) {
    const text = (title || 'Untitled chat').trim() || 'Untitled chat';
    if (text.length <= max) {
        return text;
    }

    return text.slice(0, max) + '…';
}
