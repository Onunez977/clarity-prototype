export const rangeOptions = [
    { label: 'Today', value: 'today', short: 'Today' },
    { label: 'Yesterday', value: 'yesterday', short: 'Yesterday' },
    { label: '7 Days', value: 'last_7', short: '7d' },
    { label: '15 Days', value: 'last_15', short: '15d' },
    { label: '30 Days', value: 'last_30', short: '30d' },
];

// DEV-2306 standardized both apps to the same five pills. Two exports are kept
// intentionally: rangeOptions = admin/Bird's Eye pills, driverRangeOptions =
// driver PWA pills — currently identical, but kept separate so the apps can
// diverge again without a shared-array refactor.
export const driverRangeOptions = [
    { label: 'Today', value: 'today', short: 'Today' },
    { label: 'Yesterday', value: 'yesterday', short: 'Yesterday' },
    { label: '7 Days', value: 'last_7', short: '7d' },
    { label: '15 Days', value: 'last_15', short: '15d' },
    { label: '30 Days', value: 'last_30', short: '30d' },
];

// Driver PWA stays mobile-light (DEV-2186/DEV-2306): it SHOWS all 5 pills but
// only background-prefetches these; 15/30 fetch on demand when tapped.
export const driverPrefetchRanges = ['today', 'yesterday', 'last_7'];

export function formatDate(dateStr) {
    if (!dateStr) return '';
    const date = new Date(dateStr + 'T00:00:00');
    return date.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
}

export function formatTime(isoStr) {
    if (!isoStr) return '';
    const date = new Date(isoStr);
    return date.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit', hour12: true });
}

export function formatDuration(minutes) {
    if (!minutes && minutes !== 0) return '—';
    if (minutes < 60) return `${minutes}m`;
    const h = Math.floor(minutes / 60);
    const m = minutes % 60;
    return m > 0 ? `${h}h ${m}m` : `${h}h`;
}

export function formatRelativeTime(isoStr) {
    if (!isoStr) return '';
    const then = new Date(isoStr).getTime();
    if (Number.isNaN(then)) return '';
    const diffSec = Math.round((Date.now() - then) / 1000);

    if (diffSec < 60) return 'just now';
    const diffMin = Math.round(diffSec / 60);
    if (diffMin < 60) return `${diffMin}m ago`;
    const diffHr = Math.round(diffMin / 60);
    if (diffHr < 24) return `${diffHr}h ago`;
    const diffDay = Math.round(diffHr / 24);
    if (diffDay < 7) return `${diffDay}d ago`;
    return new Date(isoStr).toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
}

export function formatDateFriendly(dateStr) {
    if (!dateStr) return '';
    const date = new Date(dateStr + 'T00:00:00');
    const now = new Date();
    const today = new Date(now.getFullYear(), now.getMonth(), now.getDate());
    const target = new Date(date.getFullYear(), date.getMonth(), date.getDate());
    const diffDays = Math.round((today - target) / 86400000);

    const monthDay = date.toLocaleDateString('en-US', { month: 'long', day: 'numeric' });

    if (diffDays === 0) return `Today, ${monthDay}`;
    if (diffDays === 1) return `Yesterday, ${monthDay}`;
    if (diffDays < 7) {
        const dayName = date.toLocaleDateString('en-US', { weekday: 'long' });
        return `${dayName}, ${monthDay}`;
    }
    return date.toLocaleDateString('en-US', { month: 'long', day: 'numeric', year: 'numeric' });
}
