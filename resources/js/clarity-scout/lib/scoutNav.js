// Single-page navigation helpers. The whole authenticated app lives at /scout;
// which panel / trip is open is encoded in the query string so the browser back
// button unwinds the stack and views stay shareable + refresh-safe.
//
// URL ⇄ state contract (path is always /scout):
//   (none)                  → dashboard
//   ?trips                  → trips list slide-out
//   ?day=YYYY-MM-DD         → day overview on the map
//   ?day=YYYY-MM-DD&trip=N  → single trip N on the map
//   ?help / ?profile        → help / profile slide-out
//   ?notifications          → notifications slide-out
//
// Opening uses push() so back() unwinds; closing an X uses back() when there's
// history, else replace() to the bare dashboard.

const SCOUT = '/scout';

export function goDashboard(router) {
    router.push({ path: SCOUT, query: {} });
}

// Which slide-out panel the current URL has open (null = none). Same priority
// order as ScoutHome's `panel` computed.
function activePanel(router) {
    const q = router.currentRoute.value.query;
    if (q.profile !== undefined) return 'profile';
    if (q.help !== undefined) return 'help';
    if (q.trips !== undefined) return 'trips';
    if (q.notifications !== undefined) return 'notifications';
    return null;
}

// Open a slide-out, keeping exactly one open at a time: re-clicking the active
// panel's trigger closes it; switching from another panel swaps via replace()
// so panels never stack in history (closing always lands on the dashboard);
// opening from the dashboard pushes so hardware-back closes the panel.
function openPanel(router, name) {
    const current = activePanel(router);
    if (current === name) {
        closePanel(router);
    } else if (current) {
        router.replace({ path: SCOUT, query: { [name]: null } });
    } else {
        router.push({ path: SCOUT, query: { [name]: null } });
    }
}

export function openTrips(router) {
    openPanel(router, 'trips');
}

export function openDay(router, date) {
    router.push({ path: SCOUT, query: { day: date } });
}

export function openTrip(router, date, index) {
    router.push({ path: SCOUT, query: { day: date, trip: String(index) } });
}

export function openHelp(router) {
    openPanel(router, 'help');
}

export function openProfile(router) {
    openPanel(router, 'profile');
}

export function openNotifications(router) {
    openPanel(router, 'notifications');
}

// Close the current panel/trip. Prefer the history stack (so it unwinds in the
// order the user opened things); fall back to the bare dashboard on a cold
// deep-link with no in-app history to pop.
export function closePanel(router) {
    if (window.history.length > 1) {
        router.back();
    } else {
        router.replace({ path: SCOUT, query: {} });
    }
}
