<?php

declare(strict_types=1);

namespace App\Services\Scout;

use App\Models\User;
use App\Models\Zone;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Str;

/**
 * Persists Scout realtime messages (zone assignment changes and edits) as
 * database notifications so the Scout PWA's bell can show read/unread state —
 * including messages dispatched while the driver was offline. Each method
 * returns the created record so callers can broadcast its id alongside the
 * existing realtime event.
 */
class ScoutNotifier
{
    public function zoneAdded(int $userId, Zone $zone): DatabaseNotification
    {
        return $this->create(
            $userId,
            'zone_added',
            'Zone assigned',
            "You were added to {$zone->name}.",
            $zone->id,
            $zone->name,
        );
    }

    public function zoneRemoved(int $userId, Zone $zone): DatabaseNotification
    {
        return $this->create(
            $userId,
            'zone_removed',
            'Zone unassigned',
            "You were removed from {$zone->name}.",
            $zone->id,
            $zone->name,
        );
    }

    public function zoneUpdated(int $userId, Zone $zone): DatabaseNotification
    {
        return $this->create(
            $userId,
            'zone_updated',
            'Zone updated',
            "{$zone->name} was updated.",
            $zone->id,
            $zone->name,
        );
    }

    public function zoneDeleted(int $userId, string $zoneName): DatabaseNotification
    {
        return $this->create(
            $userId,
            'zone_deleted',
            'Zone removed',
            "{$zoneName} was removed.",
            null,
            $zoneName,
        );
    }

    /**
     * Shape a persisted notification into the payload the Scout client needs to
     * render a toast and mark the right row read on click.
     *
     * @return array<string, mixed>
     */
    public function broadcastPayload(DatabaseNotification $notification): array
    {
        return [
            'notification_id' => $notification->id,
            'kind' => $notification->data['kind'] ?? null,
            'title' => $notification->data['title'] ?? null,
            'body' => $notification->data['body'] ?? null,
            'zone_id' => $notification->data['zone_id'] ?? null,
        ];
    }

    private function create(int $userId, string $kind, string $title, string $body, ?int $zoneId, ?string $zoneName): DatabaseNotification
    {
        /** @var DatabaseNotification $notification */
        $notification = DatabaseNotification::create([
            'id' => (string) Str::uuid(),
            'type' => 'scout.zone',
            // Use the morph alias ('user'), not the FQCN — the User morph map
            // entry means the notifications relationship queries by the alias.
            'notifiable_type' => (new User)->getMorphClass(),
            'notifiable_id' => $userId,
            'data' => [
                'kind' => $kind,
                'title' => $title,
                'body' => $body,
                'zone_id' => $zoneId,
                'zone_name' => $zoneName,
            ],
            'read_at' => null,
        ]);

        return $notification;
    }
}
