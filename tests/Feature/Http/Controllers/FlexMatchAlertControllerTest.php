<?php

namespace Tests\Feature\Http\Controllers;

use App\Enums\FeatureEnum;
use App\Enums\HitTypeEnum;
use App\Enums\HotlistStatusEnum;
use App\Jobs\SendFlexMatchToClearPlanJob;
use App\Models\Hit;
use App\Models\Hotlist;
use App\Models\Kit;
use App\Models\Scan;
use Illuminate\Support\Facades\Bus;
use InsightLPR\BlackAdam\Enum\RoleEnum;
use Laravel\Pennant\Feature;
use PHPUnit\Framework\Attributes\Group;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

#[Group('controllers')]
#[Group('flex-match')]
#[Group('clearplan')]
class FlexMatchAlertControllerTest extends TestCase
{
    private function createProAdminUser()
    {
        $user = $this->createUser(role: RoleEnum::ADMIN->value, pro: true);

        Feature::define(FeatureEnum::CLEARPLAN_RESEND->slug(), fn () => true);
        Feature::flushCache();

        return $user;
    }

    public function test_dispatches_job_without_persisting_hits(): void
    {
        Bus::fake([SendFlexMatchToClearPlanJob::class]);

        $user = $this->createProAdminUser();
        $kit = Kit::factory()->forOrg($user->org)->create();

        $hotlist = Hotlist::factory()
            ->forOrg($user->org)
            ->plate('8KXR892', 'CA')
            ->create(['status' => HotlistStatusEnum::Open]);

        $scan = Scan::factory()
            ->belongsToKit($kit)
            ->create(['ocr' => 'BKXR892', 'us_state' => 'CA']);

        $response = $this->actingAs($user)->post(route('scan.flex-match-alert'), [
            'scan_id' => $scan->id,
            'hotlist_ids' => [$hotlist->id],
        ]);

        $response->assertSuccessful();
        $this->assertStringContainsString('1 hotlist(s) queued', $response->json('message'));

        // No probable-match hit should be persisted
        $this->assertEquals(0, Hit::withoutGlobalScope('only_basic')
            ->where('scan_id', $scan->id)
            ->where('hotlist_id', $hotlist->id)
            ->where('hit_type', HitTypeEnum::Probable_Match->value)
            ->count());

        Bus::assertDispatched(SendFlexMatchToClearPlanJob::class, function ($job) use ($scan, $hotlist, $user) {
            return $job->scanId === $scan->id
                && in_array($hotlist->id, $job->hotlistIds, true)
                && $job->user->id === $user->id;
        });
    }

    public function test_requires_admin_role(): void
    {
        $user = $this->createUser(pro: true);

        Feature::define(FeatureEnum::CLEARPLAN_RESEND->slug(), fn () => true);
        Feature::flushCache();

        $kit = Kit::factory()->forOrg($user->org)->create();

        $hotlist = Hotlist::factory()
            ->forOrg($user->org)
            ->create(['status' => HotlistStatusEnum::Open]);

        $scan = Scan::factory()
            ->belongsToKit($kit)
            ->create();

        $response = $this->actingAs($user)->postJson(route('scan.flex-match-alert'), [
            'scan_id' => $scan->id,
            'hotlist_ids' => [$hotlist->id],
        ]);

        $response->assertForbidden();
        $this->assertStringContainsString('Admin role', $response->json('message'));
    }

    public function test_requires_clearplan_pro_feature(): void
    {
        $user = $this->createUser(role: RoleEnum::ADMIN->value);

        Feature::define(FeatureEnum::CLEARPLAN_RESEND->slug(), fn () => false);

        $kit = Kit::factory()->forOrg($user->org)->create();

        $hotlist = Hotlist::factory()
            ->forOrg($user->org)
            ->create(['status' => HotlistStatusEnum::Open]);

        $scan = Scan::factory()
            ->belongsToKit($kit)
            ->create();

        $response = $this->actingAs($user)->postJson(route('scan.flex-match-alert'), [
            'scan_id' => $scan->id,
            'hotlist_ids' => [$hotlist->id],
        ]);

        $response->assertForbidden();
    }

    public function test_returns_404_for_scan_from_other_org(): void
    {
        $user = $this->createProAdminUser();
        $scan = Scan::factory()->create();

        $response = $this->actingAs($user)->postJson(route('scan.flex-match-alert'), [
            'scan_id' => $scan->id,
            'hotlist_ids' => [1],
        ]);

        $response->assertNotFound();
    }

    public function test_does_not_touch_existing_hit_rows(): void
    {
        Bus::fake([SendFlexMatchToClearPlanJob::class]);

        $user = $this->createProAdminUser();
        $kit = Kit::factory()->forOrg($user->org)->create();

        $hotlist = Hotlist::factory()
            ->forOrg($user->org)
            ->create(['status' => HotlistStatusEnum::Open]);

        $scan = Scan::factory()
            ->belongsToKit($kit)
            ->create();

        // Pre-existing P-type hit row
        Hit::withoutGlobalScope('only_basic')->create([
            'scan_id' => $scan->id,
            'hotlist_id' => $hotlist->id,
            'hit_type' => HitTypeEnum::Probable_Match->value,
            'kit_id' => $kit->id,
        ]);

        $response = $this->actingAs($user)->post(route('scan.flex-match-alert'), [
            'scan_id' => $scan->id,
            'hotlist_ids' => [$hotlist->id],
        ]);

        $response->assertSuccessful();

        // Count is unchanged — controller should not create, update, or duplicate
        $hitCount = Hit::withoutGlobalScope('only_basic')
            ->where('scan_id', $scan->id)
            ->where('hotlist_id', $hotlist->id)
            ->where('hit_type', HitTypeEnum::Probable_Match->value)
            ->count();

        $this->assertEquals(1, $hitCount);
    }

    public function test_logs_activity(): void
    {
        Bus::fake([SendFlexMatchToClearPlanJob::class]);

        $user = $this->createProAdminUser();
        $kit = Kit::factory()->forOrg($user->org)->create();

        $hotlist = Hotlist::factory()
            ->forOrg($user->org)
            ->create(['status' => HotlistStatusEnum::Open]);

        $scan = Scan::factory()
            ->belongsToKit($kit)
            ->create();

        $this->actingAs($user)->post(route('scan.flex-match-alert'), [
            'scan_id' => $scan->id,
            'hotlist_ids' => [$hotlist->id],
        ]);

        $activity = Activity::where('log_name', 'flex-match-alert')->latest()->first();
        $this->assertNotNull($activity);
        $this->assertEquals($user->id, $activity->causer_id);
        $this->assertEquals('Manual flex match alert sent to ClearPlan', $activity->description);
        $this->assertEquals($scan->id, $activity->properties['scan_id']);
        $this->assertEquals([$hotlist->id], $activity->properties['hotlist_ids']);
    }

    public function test_validates_request_input(): void
    {
        $user = $this->createProAdminUser();

        $this->actingAs($user)->postJson(route('scan.flex-match-alert'), [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['scan_id', 'hotlist_ids']);
    }

    public function test_does_not_write_probable_match_rows_to_hits_table(): void
    {
        Bus::fake([SendFlexMatchToClearPlanJob::class]);

        $user = $this->createProAdminUser();
        $kit = Kit::factory()->forOrg($user->org)->create();

        $hotlist = Hotlist::factory()
            ->forOrg($user->org)
            ->create(['status' => HotlistStatusEnum::Open]);

        $scan = Scan::factory()
            ->belongsToKit($kit)
            ->create();

        $this->actingAs($user)->post(route('scan.flex-match-alert'), [
            'scan_id' => $scan->id,
            'hotlist_ids' => [$hotlist->id],
        ]);

        // Flex-match alerts must never insert into the hits table
        $this->assertEquals(0, Hit::withoutGlobalScope('only_basic')
            ->where('hit_type', HitTypeEnum::Probable_Match->value)
            ->count());
        $this->assertEquals(0, Hit::where('scan_id', $scan->id)->count());
    }
}
