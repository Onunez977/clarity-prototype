<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A completed model reply was replaced by authoritative server text.
 *
 * The model's final round may already be visible when run-completion checks
 * tool state. Broadcasting the replacement on the thread channel updates every
 * live viewer to the same content that was persisted.
 */
class OttoReplyReplaced implements ShouldBroadcastNow
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(
        public string $conversationId,
        public string $invocationId,
        public string $content,
    ) {}

    public function broadcastAs(): string
    {
        return 'otto.reply_replaced';
    }

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('otto-thread.'.$this->conversationId),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function broadcastWith(): array
    {
        return [
            'invocation_id' => $this->invocationId,
            'content' => $this->content,
        ];
    }
}
