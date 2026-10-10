// Client-emittable Scout analytics events + page tags for the PWA.
//
// Mirror of the PWA section of app/Enums/ScoutAnalyticsEvent.php / ScoutAnalyticsPage.php
// (DEV-2130). These are the deliberate UI interactions the driver app tracks — one
// row per action, never polling. The /api/scout/track endpoint validates the
// `feature`/`page` against the PHP enums, so a typo here is rejected server-side
// rather than polluting clarity.analytics. Login/logout, mark-read, shift, and trip
// drill-ins are tracked server-side and intentionally absent here.

export const SCOUT_EVENT = {
    PANEL_TOGGLE: 'clarity-scout-panel',
    BACK_NAVIGATION: 'clarity-scout-back-nav',
    LIVE_HITS_TOGGLE: 'clarity-scout-live-hits-toggle',
    HIT_FOCUS: 'clarity-scout-hit-focus',
    MAP_LAYER_TOGGLE: 'clarity-scout-map-layer-toggle',
    MAP_ZONE_TAP: 'clarity-scout-map-zone-tap',
    MAP_RECENTER: 'clarity-scout-map-recenter',
    DATE_RANGE: 'clarity-scout-date-range',
};

export const SCOUT_PAGE = {
    APP: 'clarity-scout',
    DASHBOARD: 'clarity-scout-dashboard',
    MAP: 'clarity-scout-map',
    TRIPS: 'clarity-scout-trips',
    NOTIFICATIONS: 'clarity-scout-notifications',
};
