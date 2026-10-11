<?php

declare(strict_types=1);

namespace App\Services\Zones;

use App\Events\Scout\ZoneUpdated;
use App\Models\Zone;
use App\Services\Scout\ScoutNotifier;

/**
 * Owns every `ZoneUpdated` broadcast so the map editor (ManageZones) and the
 * admin Zones list fire identical real-time messages to drivers. Assignment
 * roster changes stay in ZoneAssignmentSyncer (it broadcasts ZoneAssignmentsChanged);
 * this service only covers the metadata-edit and delete fan-outs.
 */
class ZoneBroadcaster
{
    public function __construct(private ScoutNotifier $notifier) {}

    /**
     * Notify every currently-assigned driver that a zone was edited, skipping
     * users already notified by the assignment syncer (the "affected" set from
     * a save that also reassigned drivers).
     *
     * @param  array<int, int>  $skipUserIds
     * @return array<int, int> user IDs that received a ZoneUpdated
     */
    public function broadcastUpdated(Zone $zone, array $skipUserIds = []): array
    {
        $notified = [];

        foreach ($zone->users()->pluck('users.id') as $userId) {
            $userId = (int) $userId;

            if (in_array($userId, $skipUserIds, true)) {
                continue;
            }

            $notification = $this->notifier->zoneUpdated($userId, $zone);
            event(new ZoneUpdated($userId, $zone->id, $this->notifier->broadcastPayload($notification)));

            $notified[] = $userId;
        }

        return $notified;
    }

    /**
     * Notify an explicit set of drivers that a zone was edited — used where the
     * assigned roster is unchanged (Quick Edit) or being reinstated (Restore),
     * so there is no syncer step to coordinate with.
     *
     * @param  array<int, int>  $userIds
     * @return array<int, int> user IDs that received a ZoneUpdated
     */
    public function broadcastUpdatedTo(Zone $zone, array $userIds): array
    {
        $notified = [];

        foreach ($userIds as $userId) {
            $userId = (int) $userId;

            $notification = $this->notifier->zoneUpdated($userId, $zone);
            event(new ZoneUpdated($userId, $zone->id, $this->notifier->broadcastPayload($notification)));

            $notified[] = $userId;
        }

        return $notified;
    }

    /**
     * Notify every previously-assigned driver that a zone was deleted. Takes the
     * id + name rather than a live model because the zone is already soft-deleted.
     *
     * @param  array<int, int>  $userIds
     */
    public function broadcastDeleted(int $zoneId, string $zoneName, array $userIds): void
    {
        foreach ($userIds as $userId) {
            $userId = (int) $userId;

            $notification = $this->notifier->zoneDeleted($userId, $zoneName);
            event(new ZoneUpdated($userId, $zoneId, $this->notifier->broadcastPayload($notification)));
        }
    }
}
