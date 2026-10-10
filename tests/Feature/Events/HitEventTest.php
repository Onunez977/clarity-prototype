<?php

namespace Tests\Feature\Events;

use App\Events\HitEvent;
use App\Models\Hit;
use App\Models\MissedOpportunity;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

class HitEventTest extends TestCase
{
    #[Group('events')]
    #[Group('listener')]
    #[Group('listeners')]
    public function test_class_stays_hit(): void
    {
        $hit = Hit::factory()->create();

        $this->assertEquals(Hit::class, \get_class($hit));

        $event = new HitEvent($hit);

        $this->assertEquals(Hit::class, \get_class($event->hit));
    }

    #[Group('events')]
    #[Group('listener')]
    #[Group('listeners')]
    public function test_can_change_to_missed_opportunity(): void
    {
        $hit = Hit::factory()->missedOpportunity()->create();

        $this->assertEquals(Hit::class, \get_class($hit));

        $event = new HitEvent($hit);

        $this->assertEquals(MissedOpportunity::class, \get_class($event->hit));
    }
}
