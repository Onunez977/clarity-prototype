<?php

declare(strict_types=1);

namespace App\Services\Zones;

use App\Events\Scout\ZoneAssignmentsChanged;
use App\Models\Zone;
use App\Services\Scout\ScoutNotifier;

class ZoneAssignmentSyncer
{
    public function __construct(private ScoutNotifier $notifier) {}

    public const MODE_REPLACE = 'replace';

    public const MODE_ADD = 'add';

    /**
     * Sync zone-user assignments and broadcast a per-user real-time event
     * to every user whose assignments changed. Returns the set of affected
     * user IDs (added + removed) so callers can surface admin-side feedback.
     *
     * @param  array<int, int>  $userIds
     * @return array<int, int>
     */
    public function syncZoneUsers(Zone $zone, array $userIds, string $mode = self::MODE_REPLACE): array
    {
        $userIds = array_values(array_unique(array_map('intval', $userIds)));

        $existing = $zone->users()->pluck('users.id')->map(fn ($id) => (int) $id)->all();

        if ($mode === self::MODE_ADD) {
            $next = array_values(array_unique(array_merge($existing, $userIds)));
        } else {
            $next = $userIds;
        }

        $added = array_values(array_diff($next, $existing));
        $removed = array_values(array_diff($existing, $next));
        $affected = array_values(array_unique(array_merge($added, $removed)));

        if ($mode === self::MODE_ADD) {
            if (! empty($added)) {
                $zone->users()->attach($added);
            }
        } else {
            $zone->users()->sync($next);
        }

        if ($affected !== []) {
            activity('zone')
                ->performedOn($zone)
                ->withProperties(['added' => $added, 'removed' => $removed, 'mode' => $mode])
                ->log('Zone drivers reassigned');
        }

        foreach ($added as $userId) {
            $notification = $this->notifier->zoneAdded($userId, $zone);
            event(new ZoneAssignmentsChanged($userId, $this->notifier->broadcastPayload($notification)));
        }

        foreach ($removed as $userId) {
            $notification = $this->notifier->zoneRemoved($userId, $zone);
            event(new ZoneAssignmentsChanged($userId, $this->notifier->broadcastPayload($notification)));
        }

        return $affected;
    }
}
