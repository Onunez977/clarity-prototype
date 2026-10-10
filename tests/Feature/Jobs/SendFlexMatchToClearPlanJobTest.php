<?php

namespace Tests\Feature\Jobs;

use App\Enums\HotlistStatusEnum;
use App\Jobs\SendFlexMatchToClearPlanJob;
use App\Models\Hotlist;
use App\Models\Kit;
use App\Models\Scan;
use App\Services\ClearPlan;
use InsightLPR\BlackAdam\Enum\RoleEnum;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('clearplan')]
#[Group('flex-match')]
#[Group('jobs')]
class SendFlexMatchToClearPlanJobTest extends TestCase
{
    /**
     * @return array{scan: Scan, hotlist: Hotlist}
     */
    private function createFlexMatchFixtures($org): array
    {
        $kit = Kit::factory()->forOrg($org)->create();

        $hotlist = Hotlist::factory()
            ->forOrg($org)
            ->create(['status' => HotlistStatusEnum::Open]);

        $scan = Scan::factory()
            ->belongsToKit($kit)
            ->scannedToday()
            ->create();

        return ['scan' => $scan, 'hotlist' => $hotlist];
    }

    public function test_sends_probable_match_hits_to_clearplan(): void
    {
        $user = $this->createUser(role: RoleEnum::ADMIN->value);

        ['scan' => $scan, 'hotlist' => $hotlist] = $this->createFlexMatchFixtures($user->org);

        $this->mock(ClearPlan::class, function (MockInterface $mock) {
            $mock->shouldReceive('sendHit')
                ->once()
                ->andReturn(true);
        });

        $job = new SendFlexMatchToClearPlanJob(
            scanId: $scan->id,
            hotlistIds: [$hotlist->id],
            user: $user,
        );

        $job->handle(app(ClearPlan::class));
    }

    public function test_handles_multiple_hits(): void
    {
        $user = $this->createUser(role: RoleEnum::ADMIN->value);

        ['scan' => $scan, 'hotlist' => $hotlist1] = $this->createFlexMatchFixtures($user->org);
        $hotlist2 = Hotlist::factory()
            ->forOrg($user->org)
            ->create(['status' => HotlistStatusEnum::Open]);

        $this->mock(ClearPlan::class, function (MockInterface $mock) {
            $mock->shouldReceive('sendHit')
                ->times(2)
                ->andReturn(true);
        });

        $job = new SendFlexMatchToClearPlanJob(
            scanId: $scan->id,
            hotlistIds: [$hotlist1->id, $hotlist2->id],
            user: $user,
        );

        $job->handle(app(ClearPlan::class));
    }

    public function test_sends_success_notification(): void
    {
        $user = $this->createUser(role: RoleEnum::ADMIN->value);

        ['scan' => $scan, 'hotlist' => $hotlist] = $this->createFlexMatchFixtures($user->org);

        $this->mock(ClearPlan::class, function (MockInterface $mock) {
            $mock->shouldReceive('sendHit')
                ->once()
                ->andReturn(true);
        });

        $job = new SendFlexMatchToClearPlanJob(
            scanId: $scan->id,
            hotlistIds: [$hotlist->id],
            user: $user,
        );

        $job->handle(app(ClearPlan::class));

        $this->assertDatabaseHas('notifications', [
            'notifiable_id' => $user->id,
        ]);
    }

    public function test_sends_failure_notification_when_all_fail(): void
    {
        $user = $this->createUser(role: RoleEnum::ADMIN->value);

        ['scan' => $scan, 'hotlist' => $hotlist] = $this->createFlexMatchFixtures($user->org);

        $this->mock(ClearPlan::class, function (MockInterface $mock) {
            $mock->shouldReceive('sendHit')
                ->once()
                ->andReturn(false);
        });

        $job = new SendFlexMatchToClearPlanJob(
            scanId: $scan->id,
            hotlistIds: [$hotlist->id],
            user: $user,
        );

        $job->handle(app(ClearPlan::class));

        $this->assertDatabaseHas('notifications', [
            'notifiable_id' => $user->id,
        ]);
    }

    public function test_handles_exceptions_gracefully(): void
    {
        $user = $this->createUser(role: RoleEnum::ADMIN->value);

        ['scan' => $scan, 'hotlist' => $hotlist] = $this->createFlexMatchFixtures($user->org);

        $this->mock(ClearPlan::class, function (MockInterface $mock) {
            $mock->shouldReceive('sendHit')
                ->once()
                ->andThrow(new \RuntimeException('SQS timeout'));
        });

        $job = new SendFlexMatchToClearPlanJob(
            scanId: $scan->id,
            hotlistIds: [$hotlist->id],
            user: $user,
        );

        // Should not throw
        $job->handle(app(ClearPlan::class));

        $this->assertDatabaseHas('notifications', [
            'notifiable_id' => $user->id,
        ]);
    }
}
