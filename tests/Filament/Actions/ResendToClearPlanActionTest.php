<?php

namespace Tests\Filament\Actions;

use App\Enums\FeatureEnum;
use App\Enums\HotlistStatusEnum;
use App\Filament\Actions\ResendToClearPlanAction;
use App\Jobs\ResendHitsToClearPlanJob;
use App\Models\Hit;
use App\Services\ClearPlan;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Queue;
use InsightLPR\BlackAdam\Enum\RoleEnum;
use Laravel\Pennant\Feature;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('clearplan')]
#[Group('actions')]
class ResendToClearPlanActionTest extends TestCase
{
    public function test_action_has_correct_properties(): void
    {
        $action = ResendToClearPlanAction::make('resend-to-clearplan');

        $this->assertEquals('Resend to ClearPlan', $action->getLabel());
        $this->assertEquals('heroicon-m-arrow-path', $action->getIcon());
        $this->assertTrue($action->shouldDeselectRecordsAfterCompletion());
    }

    public function test_action_is_disabled_for_non_pro_users(): void
    {
        $user = $this->createUser(role: RoleEnum::ADMIN->value);

        $this->actingAs($user);

        Feature::define(FeatureEnum::CLEARPLAN_RESEND->slug(), fn () => false);

        $action = ResendToClearPlanAction::make('resend-to-clearplan');

        $this->assertTrue($action->isDisabled());
        $this->assertEquals('Upgrade to Pro to resend hits to ClearPlan', $action->getTooltip());
    }

    public function test_action_is_disabled_for_non_admin_users(): void
    {
        $user = $this->createUser(role: RoleEnum::CLIENT->value, pro: true);

        $this->actingAs($user);

        Feature::define(FeatureEnum::CLEARPLAN_RESEND->slug(), fn () => true);
        Feature::flushCache();

        $action = ResendToClearPlanAction::make('resend-to-clearplan');

        $this->assertTrue($action->isDisabled());
        $this->assertEquals('Admin only', $action->getTooltip());
    }

    public function test_action_is_enabled_for_pro_admin_users(): void
    {
        $user = $this->createUser(role: RoleEnum::ADMIN->value, pro: true);

        $this->actingAs($user);

        Feature::define(FeatureEnum::CLEARPLAN_RESEND->slug(), fn () => true);
        Feature::flushCache();

        $action = ResendToClearPlanAction::make('resend-to-clearplan');

        $this->assertFalse($action->isDisabled());
        $this->assertNull($action->getTooltip());
    }

    public function test_action_dispatches_job_for_open_hits_when_pro(): void
    {
        Queue::fake();

        $user = $this->createUser(role: RoleEnum::ADMIN->value, pro: true);
        $org = $user->org;

        $this->actingAs($user);

        Feature::define(FeatureEnum::CLEARPLAN_RESEND->slug(), fn () => true);
        Feature::flushCache();

        $openHit = Hit::factory()->forOrg($org, hotlistAttributes: ['status' => HotlistStatusEnum::Open])->create();
        $closedHit = Hit::factory()->forOrg($org, hotlistAttributes: ['status' => HotlistStatusEnum::Closed])->create();

        $openRecord = new Hit(array_merge($openHit->toArray(), ['hotlist_status' => HotlistStatusEnum::Open->value]));
        $openRecord->id = $openHit->id;
        $closedRecord = new Hit(array_merge($closedHit->toArray(), ['hotlist_status' => HotlistStatusEnum::Closed->value]));
        $closedRecord->id = $closedHit->id;

        $records = new Collection([$openRecord, $closedRecord]);

        $actionClosure = ResendToClearPlanAction::make('resend-to-clearplan')->getActionFunction();
        $actionClosure($records);

        Queue::assertPushed(ResendHitsToClearPlanJob::class, function ($job) use ($openHit) {
            return $job->hitIds === [$openHit->id];
        });
    }

    public function test_non_pro_org_gets_notification_instead_of_dispatch(): void
    {
        Queue::fake();

        $user = $this->createUser(role: RoleEnum::ADMIN->value);

        $this->actingAs($user);

        Feature::define(FeatureEnum::CLEARPLAN_RESEND->slug(), fn () => false);

        $org = $user->org;
        $hit = Hit::factory()->forOrg($org, hotlistAttributes: ['status' => HotlistStatusEnum::Open])->create();

        $record = new Hit(array_merge($hit->toArray(), ['hotlist_status' => HotlistStatusEnum::Open->value]));
        $record->id = $hit->id;

        $records = new Collection([$record]);

        $actionClosure = ResendToClearPlanAction::make('resend-to-clearplan')->getActionFunction();
        $actionClosure($records);

        Queue::assertNotPushed(ResendHitsToClearPlanJob::class);
    }

    public function test_no_open_hits_does_not_dispatch_job(): void
    {
        Queue::fake();

        $user = $this->createUser(role: RoleEnum::ADMIN->value, pro: true);
        $org = $user->org;

        $this->actingAs($user);

        Feature::define(FeatureEnum::CLEARPLAN_RESEND->slug(), fn () => true);
        Feature::flushCache();

        $closedHit = Hit::factory()->forOrg($org, hotlistAttributes: ['status' => HotlistStatusEnum::Closed])->create();

        $record = new Hit(array_merge($closedHit->toArray(), ['hotlist_status' => HotlistStatusEnum::Closed->value]));
        $record->id = $closedHit->id;

        $records = new Collection([$record]);

        $actionClosure = ResendToClearPlanAction::make('resend-to-clearplan')->getActionFunction();
        $actionClosure($records);

        Queue::assertNotPushed(ResendHitsToClearPlanJob::class);
    }

    public function test_job_sends_success_notification_when_all_hits_resent(): void
    {
        $user = $this->createUser(role: RoleEnum::ADMIN->value, pro: true);
        $org = $user->org;

        $hit = Hit::factory()->forOrg($org, hotlistAttributes: ['status' => HotlistStatusEnum::Open])->create();

        $clearPlan = $this->mock(ClearPlan::class);
        $clearPlan->shouldReceive('sendHit')->once()->andReturn(true);

        $job = new ResendHitsToClearPlanJob([$hit->id], $user);
        $job->handle($clearPlan);

        $this->assertDatabaseHas('notifications', [
            'notifiable_id' => $user->id,
            'notifiable_type' => 'user',
        ]);

        $notification = DatabaseNotification::where('notifiable_id', $user->id)->first();
        $this->assertStringContainsString('resent to ClearPlan', $notification->data['title']);
    }

    public function test_job_sends_danger_notification_when_all_hits_fail(): void
    {
        $user = $this->createUser(role: RoleEnum::ADMIN->value, pro: true);
        $org = $user->org;

        $hit = Hit::factory()->forOrg($org, hotlistAttributes: ['status' => HotlistStatusEnum::Open])->create();

        $clearPlan = $this->mock(ClearPlan::class);
        $clearPlan->shouldReceive('sendHit')->once()->andThrow(new \RuntimeException('API error'));

        $job = new ResendHitsToClearPlanJob([$hit->id], $user);
        $job->handle($clearPlan);

        $notification = DatabaseNotification::where('notifiable_id', $user->id)->first();
        $this->assertStringContainsString('Resend failed', $notification->data['title']);
    }

    public function test_job_sends_warning_notification_on_partial_failure(): void
    {
        $user = $this->createUser(role: RoleEnum::ADMIN->value, pro: true);
        $org = $user->org;

        $hit1 = Hit::factory()->forOrg($org, hotlistAttributes: ['status' => HotlistStatusEnum::Open])->create();
        $hit2 = Hit::factory()->forOrg($org, hotlistAttributes: ['status' => HotlistStatusEnum::Open])->create();

        $clearPlan = $this->mock(ClearPlan::class);
        $clearPlan->shouldReceive('sendHit')->once()->with(\Mockery::on(fn ($h) => $h->id === $hit1->id))->andReturn(true);
        $clearPlan->shouldReceive('sendHit')->once()->with(\Mockery::on(fn ($h) => $h->id === $hit2->id))->andReturn(false);

        $job = new ResendHitsToClearPlanJob([$hit1->id, $hit2->id], $user);
        $job->handle($clearPlan);

        $notification = DatabaseNotification::where('notifiable_id', $user->id)->first();
        $this->assertStringContainsString('failed to resend', $notification->data['title']);
    }
}
