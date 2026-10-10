<?php

namespace Tests\Feature\Http\Controllers;

use App\Jobs\FlushPennant;
use App\Jobs\Org\MaterializeOrgRunArea;
use App\Models\Org;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Queue;
use InsightLPR\BlackAdam\BlackAdam;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

class BackyardControllerTest extends TestCase
{
    #[Group('controllers')]
    #[Group('backyard')]
    public function test_can_dispatch_flush_pennant_job(): void
    {
        Queue::fake();

        Config::set('black-adam.INSIGHT_LPR_KEY', 'testing');

        $signedUrl = BlackAdam::generateSignedUrl(route('backyard.pennant.clear'));

        $this->get($signedUrl)
            ->assertStatus(200);

        Queue::assertPushed(FlushPennant::class);
    }

    #[Group('controllers')]
    #[Group('backyard')]
    public function test_cannot_dispatch_pennant_with_invalid_signature(): void
    {
        Queue::fake();

        Config::set('black-adam.INSIGHT_LPR_KEY', 'invalid');

        $signedUrl = BlackAdam::generateSignedUrl(route('backyard.pennant.clear'));

        Config::set('black-adam.INSIGHT_LPR_KEY', 'testing');

        $this->get($signedUrl)
            ->assertStatus(401);

        Queue::assertNothingPushed();
    }

    #[Group('controllers')]
    #[Group('backyard')]
    public function test_cannot_dispatch_pennant_without_signature(): void
    {
        Queue::fake();

        $this->get(route('backyard.pennant.clear'))
            ->assertStatus(401);

        Queue::assertNothingPushed();
    }

    #[Group('controllers')]
    #[Group('backyard')]
    public function test_can_dispatch_materialize_run_area_job(): void
    {
        Queue::fake();

        Config::set('black-adam.INSIGHT_LPR_KEY', 'testing');

        $org = Org::factory()->create();

        $signedUrl = BlackAdam::generateSignedUrl(route('backyard.run-area.materialize'), [
            'org_id' => $org->id,
        ]);

        $this->get($signedUrl)
            ->assertStatus(200);

        Queue::assertPushed(MaterializeOrgRunArea::class, fn (MaterializeOrgRunArea $job) => $job->org->is($org));
    }

    #[Group('controllers')]
    #[Group('backyard')]
    public function test_cannot_dispatch_materialize_run_area_with_invalid_signature(): void
    {
        Queue::fake();

        Config::set('black-adam.INSIGHT_LPR_KEY', 'invalid');

        $org = Org::factory()->create();

        $signedUrl = BlackAdam::generateSignedUrl(route('backyard.run-area.materialize'), [
            'org_id' => $org->id,
        ]);

        Config::set('black-adam.INSIGHT_LPR_KEY', 'testing');

        $this->get($signedUrl)
            ->assertStatus(401);

        Queue::assertNothingPushed();
    }

    #[Group('controllers')]
    #[Group('backyard')]
    public function test_cannot_dispatch_materialize_run_area_without_signature(): void
    {
        Queue::fake();

        $org = Org::factory()->create();

        $this->get(route('backyard.run-area.materialize', ['org_id' => $org->id]))
            ->assertStatus(401);

        Queue::assertNothingPushed();
    }

    #[Group('controllers')]
    #[Group('backyard')]
    public function test_materialize_run_area_with_unknown_org_pushes_nothing(): void
    {
        Queue::fake();

        Config::set('black-adam.INSIGHT_LPR_KEY', 'testing');

        $signedUrl = BlackAdam::generateSignedUrl(route('backyard.run-area.materialize'), [
            'org_id' => 999999,
        ]);

        $this->get($signedUrl)
            ->assertStatus(200);

        Queue::assertNothingPushed();
    }

    #[Group('controllers')]
    #[Group('backyard')]
    public function test_can_view_horizon_with_insightlpr_email(): void
    {
        Config::set('black-adam.INSIGHT_LPR_KEY', 'testing');

        $signedUrl = BlackAdam::generateSignedUrl(route('backyard.horizon'), [
            'email' => 'test@insightlpr.com',
        ]);

        $this->get($signedUrl)
            ->assertRedirect('/horizon');

        $this->assertTrue(session()->has('backyard_horizon'));
    }

    #[Group('controllers')]
    #[Group('backyard')]
    public function test_cannot_view_horizon_with_other_email(): void
    {
        Config::set('black-adam.INSIGHT_LPR_KEY', 'testing');

        $signedUrl = BlackAdam::generateSignedUrl(route('backyard.horizon'), [
            'email' => fake()->safeEmail(),
        ]);

        $this->get($signedUrl)
            ->assertRedirect('/horizon');

        $this->assertFalse(session()->has('backyard_horizon'));
    }

    #[Group('controllers')]
    #[Group('backyard')]
    public function test_cannot_view_horizon_without_any_email(): void
    {
        Config::set('black-adam.INSIGHT_LPR_KEY', 'testing');

        $signedUrl = BlackAdam::generateSignedUrl(route('backyard.horizon'));

        $this->get($signedUrl)
            ->assertRedirect('/horizon');

        $this->assertFalse(session()->has('backyard_horizon'));
    }
}
