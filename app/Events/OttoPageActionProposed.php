<?php

namespace App\Events;

use App\Ai\PageAction;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Otto proposes an action on the page the user is currently viewing.
 *
 * Broadcast on the same per-thread channel as the result-widget events so it
 * reaches the open chat with no extra subscription. The streaming client
 * (Otto Vue chat) renders it as an Accept/Dismiss chip; on Accept it dispatches
 * a same-tab `otto-page-action` Livewire event the page's adapter applies.
 */
class OttoPageActionProposed implements ShouldBroadcastNow
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(
        public string $conversationId,
        public PageAction $action,
    ) {}

    public function broadcastAs(): string
    {
        return 'otto.page_action';
    }

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('otto-thread.'.$this->conversationId)];
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return $this->action->toArray();
    }
}
