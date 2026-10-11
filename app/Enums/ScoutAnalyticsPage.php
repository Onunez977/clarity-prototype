<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Canonical `page` tags for Scout analytics events.
 *
 * The page tag is how analytics consumers segment events by surface. Values match
 * what the Scout controllers already passed as the `page:` argument to track().
 *
 * @see ScoutAnalyticsEvent
 */
enum ScoutAnalyticsPage: string
{
    case App = 'clarity-scout';
    case Dashboard = 'clarity-scout-dashboard';
    case Map = 'clarity-scout-map';
    case Trips = 'clarity-scout-trips';
    case Shift = 'clarity-scout-shift';
    case Notifications = 'clarity-scout-notifications';
    case BirdsEye = 'clarity-scout-birds-eye';

    case Zones = 'zones';
    case ManageZones = 'manage-zones';
    case Otto = 'otto';
    case ScoutUsers = 'scout-users';
    case EditScoutUser = 'edit-scout-user';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $case): string => $case->value, self::cases());
    }
}
