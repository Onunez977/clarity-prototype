<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Canonical Scout analytics event (feature) names written to clarity.analytics.
 *
 * Scope is deliberately narrow: we track **deliberate user actions**, one row per
 * action — never polled/auto-refresh data fetches (positions, paths, status, stats,
 * recent-hits, heatmap data, etc.), which would flood the table with heartbeat rows.
 * A handful of these are server-emitted (login/logout, shift start/end, notification
 * mark-read, trip drill-in); the rest are client-emitted from the PWA via
 * resources/js/clarity-scout/lib/track.js. Values are unchanged from the strings the
 * controllers historically emitted, so older data still lines up.
 *
 * @see ScoutAnalyticsPage for the companion page tags.
 * @see okf/concepts/scout-analytics-taxonomy.md for the full matrix + rationale.
 */
enum ScoutAnalyticsEvent: string
{
    // Server-emitted — once per action.
    case Login = 'clarity-scout-login';
    case Logout = 'clarity-scout-logout';
    case ShiftStart = 'clarity-scout-shift-start';
    case ShiftEnd = 'clarity-scout-shift-end';
    case DayDetail = 'clarity-scout-day-detail';
    case TripDetail = 'clarity-scout-trip-detail';
    case NotificationRead = 'clarity-scout-notification-read';
    case NotificationsReadAll = 'clarity-scout-notifications-read-all';

    // Client-emitted (PWA) — pure UI interactions with no dedicated backend action.
    case PanelToggle = 'clarity-scout-panel';
    case BackNavigation = 'clarity-scout-back-nav';
    case LiveHitsToggle = 'clarity-scout-live-hits-toggle';
    case HitFocus = 'clarity-scout-hit-focus';
    case MapLayerToggle = 'clarity-scout-map-layer-toggle';
    case MapZoneTap = 'clarity-scout-map-zone-tap';
    case MapRecenter = 'clarity-scout-map-recenter';
    case DateRange = 'clarity-scout-date-range';

    // Filament admin — mutating Scout management actions.
    case ZoneCreate = 'clarity-scout-zone-create';
    case ZoneEdit = 'clarity-scout-zone-edit';
    case ZoneDelete = 'clarity-scout-zone-delete';
    case ZoneRestore = 'clarity-scout-zone-restore';
    case ZoneReassign = 'clarity-scout-zone-reassign';
    case ZoneImport = 'clarity-scout-zone-import';
    case ScoutUserWebAccess = 'clarity-scout-user-web-access';
    case ScoutUserForceLogin = 'clarity-scout-user-force-login';
    case ScoutUserAssign = 'clarity-scout-user-assign';
    case ScoutUserResendOnboarding = 'clarity-scout-user-resend-onboarding';
    case ScoutUserPasswordReset = 'clarity-scout-user-password-reset';
    case ForgotPassword = 'clarity-scout-forgot-password';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $case): string => $case->value, self::cases());
    }
}
