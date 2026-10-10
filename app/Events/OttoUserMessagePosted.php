<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A user posted a prompt into a thread. Broadcast to the thread channel so other
 * tabs viewing the same conversation see the message immediately — the sender
 * already rendered their own bubble optimistically, so OttoConversation::send uses ->toOthers().
 */
class OttoUserMessagePosted implements ShouldBroadcastNow
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(
        public string $conversationId,
        public string $content,
        public string $authorName,
        public string $authorInitials,
        public string $authorColor,
    ) {}

    public function broadcastAs(): string
    {
        return 'otto.user_message';
    }

    public function broadcastOn(): array
    {
        return [new PrivateChannel('otto-thread.'.$this->conversationId)];
    }

    /**
     * @return array<string, string>
     */
    public function broadcastWith(): array
    {
        return [
            'content' => $this->content,
            'author_name' => $this->authorName,
            'author_initials' => $this->authorInitials,
            'author_color' => $this->authorColor,
        ];
    }
}
