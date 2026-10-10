<?php

namespace Tests\Feature\Jobs;

use App\Enums\HotlistStatusEnum;
use App\Jobs\ResendHitsToClearPlanJob;
use App\Models\Hit;
use App\Services\ClearPlan;
use InsightLPR\BlackAdam\Enum\RoleEnum;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\Group;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

#[Group('clearplan')]
#[Group('jobs')]
class ResendHitsToClearPlanJobTest extends TestCase
{
    public function test_processes_all_hits_passed_in(): void
    {
        $user = $this->createUser();
        $org = $user->org;

        $hits = Hit::factory()->forOrg($org, hotlistAttributes: ['status' => HotlistStatusEnum::Open])->count(2)->create();

        $this->mock(ClearPlan::class, function (MockInterface $mock) {
            $mock->shouldReceive('sendHit')
                ->twice()
                ->andReturn(true);
        });

        $job = new ResendHitsToClearPlanJob(
            hitIds: $hits->pluck('id')->all(),
            user: $user,
        );

        $job->handle(app(ClearPlan::class));

        $activity = Activity::where('log_name', 'clearplan-resend')->latest()->first();
        $this->assertNotNull($activity);
        $this->assertEquals(2, $activity->properties['submitted']);
    }

    public function test_success_counting_works_correctly(): void
    {
        $user = $this->createUser();
        $org = $user->org;

        $hits = Hit::factory()->forOrg($org, hotlistAttributes: ['status' => HotlistStatusEnum::Open])->count(3)->create();

        $this->mock(ClearPlan::class, function (MockInterface $mock) {
            $mock->shouldReceive('sendHit')
                ->times(3)
                ->andReturn(true);
        });

        $job = new ResendHitsToClearPlanJob(
            hitIds: $hits->pluck('id')->all(),
            user: $user,
        );

        $job->handle(app(ClearPlan::class));

        $activity = Activity::where('log_name', 'clearplan-resend')->latest()->first();
        $this->assertNotNull($activity);
        $this->assertEquals(3, $activity->properties['submitted']);
        $this->assertEquals(3, $activity->properties['succeeded']);
        $this->assertEquals(0, $activity->properties['failed']);
    }

    public function test_failure_counting_works_correctly(): void
    {
        $user = $this->createUser();
        $org = $user->org;

        $hits = Hit::factory()->forOrg($org, hotlistAttributes: ['status' => HotlistStatusEnum::Open])->count(2)->create();

        $this->mock(ClearPlan::class, function (MockInterface $mock) {
            $mock->shouldReceive('sendHit')
                ->times(2)
                ->andReturn(true, false);
        });

        $job = new ResendHitsToClearPlanJob(
            hitIds: $hits->pluck('id')->all(),
            user: $user,
        );

        $job->handle(app(ClearPlan::class));

        $activity = Activity::where('log_name', 'clearplan-resend')->latest()->first();
        $this->assertNotNull($activity);
        $this->assertEquals(2, $activity->properties['submitted']);
        $this->assertEquals(1, $activity->properties['succeeded']);
        $this->assertEquals(1, $activity->properties['failed']);
    }

    public function test_activity_log_created_with_correct_properties(): void
    {
        $user = $this->createUser(role: RoleEnum::ADMIN->value);
        $org = $user->org;

        $hit = Hit::factory()->forOrg($org, hotlistAttributes: ['status' => HotlistStatusEnum::Open])->create();

        $this->mock(ClearPlan::class, function (MockInterface $mock) {
            $mock->shouldReceive('sendHit')
                ->once()
                ->andReturn(true);
        });

        $job = new ResendHitsToClearPlanJob(
            hitIds: [$hit->id],
            user: $user,
        );

        $job->handle(app(ClearPlan::class));

        $activity = Activity::where('log_name', 'clearplan-resend')->latest()->first();
        $this->assertNotNull($activity);
        $this->assertEquals($user->id, $activity->causer_id);
        $this->assertEquals('Resent hits to ClearPlan', $activity->description);
        $this->assertEquals([$hit->id], $activity->properties['hit_ids']);
        $this->assertEquals(1, $activity->properties['submitted']);
        $this->assertEquals(1, $activity->properties['succeeded']);
        $this->assertEquals(0, $activity->properties['failed']);
        $this->assertEquals($user->name, $activity->properties['user_name']);
        $this->assertEquals('Administrator', $activity->properties['user_role']);
    }

    public function test_activity_log_created_even_when_all_fail(): void
    {
        $user = $this->createUser();
        $org = $user->org;

        $hits = Hit::factory()->forOrg($org, hotlistAttributes: ['status' => HotlistStatusEnum::Open])->count(2)->create();

        $this->mock(ClearPlan::class, function (MockInterface $mock) {
            $mock->shouldReceive('sendHit')
                ->times(2)
                ->andReturn(false);
        });

        $job = new ResendHitsToClearPlanJob(
            hitIds: $hits->pluck('id')->all(),
            user: $user,
        );

        $job->handle(app(ClearPlan::class));

        $activity = Activity::where('log_name', 'clearplan-resend')->latest()->first();
        $this->assertNotNull($activity);
        $this->assertEquals(2, $activity->properties['submitted']);
        $this->assertEquals(0, $activity->properties['succeeded']);
        $this->assertEquals(2, $activity->properties['failed']);
    }

    public function test_activity_log_written_when_job_throws_unexpected_exception(): void
    {
        $user = $this->createUser(role: RoleEnum::ADMIN->value);
        $org = $user->org;

        $hits = Hit::factory()->forOrg($org, hotlistAttributes: ['status' => HotlistStatusEnum::Open])->count(3)->create();

        $this->mock(ClearPlan::class, function (MockInterface $mock) {
            $mock->shouldReceive('sendHit')
                ->times(3)
                ->andThrow(new \RuntimeException('ClearPlan API timeout'));
        });

        $job = new ResendHitsToClearPlanJob(
            hitIds: $hits->pluck('id')->all(),
            user: $user,
        );

        $job->handle(app(ClearPlan::class));

        // Summary activity log should still be written despite all hits failing
        $activity = Activity::where('log_name', 'clearplan-resend')
            ->where('description', 'Resent hits to ClearPlan')
            ->latest()
            ->first();
        $this->assertNotNull($activity);
        $this->assertEquals(0, $activity->properties['succeeded']);
        $this->assertEquals(3, $activity->properties['failed']);
        $this->assertEquals($user->name, $activity->properties['user_name']);
        $this->assertEquals('Administrator', $activity->properties['user_role']);
    }
}
