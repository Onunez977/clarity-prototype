<?php

namespace Tests\Feature\Jobs;

use App\Jobs\FlushPennant;
use Laravel\Pennant\Feature;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

class FlushPennantTest extends TestCase
{
    #[Group('jobs')]
    public function test_can_share_org_kits(): void
    {
        $feature1 = fake()->words(3, true);
        $feature2 = fake()->words(3, true);

        Feature::define('test-feature', fn () => $feature1);

        $this->assertSame($feature1, Feature::value('test-feature'));

        // Still same (stored in memory cache)
        Feature::define('test-feature', fn () => $feature2);
        $this->assertSame($feature1, Feature::value('test-feature'));

        (new FlushPennant)->handle();

        // Now it's different (cleared memory cache)
        $this->assertSame($feature2, Feature::value('test-feature'));
    }
}
