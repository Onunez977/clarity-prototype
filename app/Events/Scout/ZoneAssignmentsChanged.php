<?php

declare(strict_types=1);

namespace App\Events\Scout;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Contracts\Broadcasting\ShouldRescue;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ZoneAssignmentsChanged implements ShouldBroadcastNow, ShouldRescue
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    /**
     * @param  array<string, mixed>  $notification  The persisted notification's
     *                                              broadcast payload (id, kind,
     *                                              title, body, zone_id), if any.
     */
    public function __construct(public int $userId, public array $notification = []) {}

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('clarity.scout.user.'.$this->userId),
        ];
    }

    public function broadcastAs(): string
    {
        return 'zones.changed';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return array_merge([
            'user_id' => $this->userId,
            'changed_at' => now()->toIso8601String(),
        ], $this->notification);
    }
}
