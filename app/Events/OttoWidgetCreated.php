<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class OttoWidgetCreated implements ShouldBroadcastNow
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(
        public string $conversationId,
        public string $messageId,
        public string $widgetType,
        public string $searchId,
        public string $title = '',
    ) {}

    public function broadcastAs(): string
    {
        return 'otto.widget';
    }

    public function broadcastOn(): array
    {
        return [new PrivateChannel('otto-thread.'.$this->conversationId)];
    }

    public function broadcastWith(): array
    {
        return [
            'message_id' => $this->messageId,
            'widget_type' => $this->widgetType,
            'search_id' => $this->searchId,
            'title' => $this->title,
        ];
    }
}
