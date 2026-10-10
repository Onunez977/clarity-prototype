<?php

namespace App\Listeners;

use App\Events\HitEvent;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;

class HitEventListener implements ShouldQueue
{
    public function middleware(HitEvent $event): array
    {
        return [
            (new WithoutOverlapping('hit-notify:'.$event->hit->id))
                ->dontRelease()
                ->expireAfter(60),
        ];
    }

    /**
     * Handle the event.
     */
    public function handle(HitEvent $event): void
    {
        $event->hit->notifySubscribers(scans: $event->scans);
    }
}
