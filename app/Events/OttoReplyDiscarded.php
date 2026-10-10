<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A completed reply described search results or a filter proposal that this run
 * did not actually create, and has been discarded. Live tabs drop that bubble;
 * a corrective re-run streams the real answer in its place.
 */
class OttoReplyDiscarded implements ShouldBroadcastNow
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(
        public int $userId,
        public string $conversationId,
        public string $invocationId,
    ) {}

    public function broadcastAs(): string
    {
        return 'otto.reply_discarded';
    }

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('otto.'.$this->userId.'.'.$this->conversationId),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function broadcastWith(): array
    {
        return ['invocation_id' => $this->invocationId];
    }
}
