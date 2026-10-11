<?php

namespace Tests\Filament\Resources;

use App\Filament\Resources\ActivityResource\Pages\ListActivities;
use App\Models\User;
use InsightLPR\BlackAdam\Enum\PermissionEnum;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Group;
use Spatie\Activitylog\Models\Activity;
use Tests\ResourceTestCase;

class ActivityResourceTest extends ResourceTestCase
{
    protected mixed $userPermissions = PermissionEnum::VIEW_ACCOUNT_ACTIVITY->value;

    #[Group('resources')]
    #[Group('activity')]
    public function test_can_render_table(): void
    {
        $activity = Activity::create([
            'causer_id' => $this->user->id,
            'causer_type' => 'user',
            'log_name' => 'Scans Search',
            'event' => 'Scans Search',
            'description' => fake()->sentence(),
            'properties' => [],
            'created_at' => now()->subHour(),
        ]);

        Livewire::test(ListActivities::class)
            ->assertSuccessful()
            ->assertCanSeeTableRecords([$activity]);
    }

    #[Group('resources')]
    #[Group('activity')]
    public function test_cannot_render_without_permissions(): void
    {
        $this->actingAs(
            $this->createUser(permissions: [])
        );

        Livewire::test(ListActivities::class)
            ->assertForbidden();
    }

    #[Group('resources')]
    #[Group('activity')]
    public function test_can_render_table_columns(): void
    {
        $activity = Activity::create([
            'causer_id' => $this->user->id,
            'causer_type' => 'user',
            'log_name' => 'Scans Search',
            'event' => 'Scans Search',
            'description' => fake()->sentence(),
            'properties' => [],
            'created_at' => now()->subHour(),
        ]);

        Livewire::test(ListActivities::class)
            ->assertSuccessful()
            ->assertCanRenderTableColumn('description')
            ->assertCanRenderTableColumn('causer_id')
            ->assertCanRenderTableColumn('created_at')
            ->assertTableColumnHasDescription('created_at', $activity->created_at->diffForHumans(), $activity);
    }

    #[Group('resources')]
    #[Group('activity')]
    public function test_cannot_render_activity_from_other_org_user(): void
    {
        $activity = Activity::create([
            'causer_id' => User::factory()->create()->id,
            'causer_type' => 'user',
            'log_name' => 'Scans Search',
            'event' => 'Scans Search',
            'description' => fake()->sentence(),
            'properties' => [],
            'created_at' => now()->subHour(),
        ]);

        Livewire::test(ListActivities::class)
            ->assertSuccessful()
            ->assertCanNotSeeTableRecords([$activity]);
    }

    #[Group('resources')]
    #[Group('activity')]
    public function test_cannot_render_activity_not_caused_by_user(): void
    {
        $activity = Activity::create([
            'causer_id' => $this->user->id,
            'causer_type' => fake()->words(3, true),
            'log_name' => 'Scans Search',
            'event' => 'Scans Search',
            'description' => fake()->sentence(),
            'properties' => [],
            'created_at' => now()->subHour(),
        ]);

        Livewire::test(ListActivities::class)
            ->assertSuccessful()
            ->assertCanNotSeeTableRecords([$activity]);
    }

    #[Group('resources')]
    #[Group('activity')]
    public function test_cannot_render_activity_that_is_not_a_logged_search(): void
    {
        $loggedActivities = [];
        foreach (['Scans Search', 'Hits Search', 'Hotlist Search'] as $log) {
            $loggedActivities[] = Activity::create([
                'causer_id' => $this->user->id,
                'causer_type' => 'user',
                'log_name' => $log,
                'event' => $log,
                'description' => fake()->sentence(),
                'properties' => [],
                'created_at' => now()->subHour(),
            ]);
        }

        $otherActivity = Activity::create([
            'causer_id' => $this->user->id,
            'causer_type' => 'user',
            'log_name' => fake()->words(3, true),
            'event' => fake()->words(3, true),
            'description' => fake()->sentence(),
            'properties' => [],
            'created_at' => now()->subHour(),
        ]);

        Livewire::test(ListActivities::class)
            ->assertSuccessful()
            ->assertCanSeeTableRecords($loggedActivities)
            ->assertCanNotSeeTableRecords([$otherActivity]);
    }

    #[Group('resources')]
    #[Group('activity')]
    public function test_date_filters(): void
    {
        $activityThisMonth = Activity::create([
            'causer_id' => $this->user->id,
            'causer_type' => 'user',
            'log_name' => 'Scans Search',
            'event' => 'Scans Search',
            'description' => fake()->sentence(),
            'properties' => [],
            'created_at' => now()->subHour(),
        ]);

        $activityLastMonth = Activity::create([
            'causer_id' => $this->user->id,
            'causer_type' => 'user',
            'log_name' => 'Scans Search',
            'event' => 'Scans Search',
            'description' => fake()->sentence(),
            'properties' => [],
            'created_at' => now()->startOfMonth()->subMonth(),
        ]);

        Livewire::test(ListActivities::class)
            ->assertSuccessful()
            ->assertCanSeeTableRecords([$activityThisMonth])
            ->assertCanNotSeeTableRecords([$activityLastMonth])
            ->set('tableFilters.filters.start_date', now()->startOfMonth()->subMonths(2)->startOfDay())
            ->set('tableFilters.filters.end_date', now()->startOfMonth()->subMonth()->endOfDay())
            ->assertCanNotSeeTableRecords([$activityThisMonth])
            ->assertCanSeeTableRecords([$activityLastMonth]);
    }

    #[Group('resources')]
    #[Group('activity')]
    public function test_log_filter(): void
    {
        $scanActivity = Activity::create([
            'causer_id' => $this->user->id,
            'causer_type' => 'user',
            'log_name' => 'Scans Search',
            'event' => 'Scans Search',
            'description' => fake()->sentence(),
            'properties' => [],
            'created_at' => now()->subHour(),
        ]);

        $hitActivity = Activity::create([
            'causer_id' => $this->user->id,
            'causer_type' => 'user',
            'log_name' => 'Hits Search',
            'event' => 'Hits Search',
            'description' => fake()->sentence(),
            'properties' => [],
            'created_at' => now()->subHour(),
        ]);

        $hotlistActivity = Activity::create([
            'causer_id' => $this->user->id,
            'causer_type' => 'user',
            'log_name' => 'Hotlist Search',
            'event' => 'Hotlist Search',
            'description' => fake()->sentence(),
            'properties' => [],
            'created_at' => now()->subHour(),
        ]);

        Livewire::test(ListActivities::class)
            ->assertSuccessful()
            ->assertCanSeeTableRecords([$scanActivity, $hitActivity, $hotlistActivity])
            // Scans
            ->set('tableFilters.filters.log', $scanActivity->log_name)
            ->assertCanSeeTableRecords([$scanActivity])
            ->assertCanNotSeeTableRecords([$hitActivity, $hotlistActivity])
            // Hits
            ->set('tableFilters.filters.log', $hitActivity->log_name)
            ->assertCanSeeTableRecords([$hitActivity])
            ->assertCanNotSeeTableRecords([$scanActivity, $hotlistActivity])
            // Hotlist
            ->set('tableFilters.filters.log', $hotlistActivity->log_name)
            ->assertCanSeeTableRecords([$hotlistActivity])
            ->assertCanNotSeeTableRecords([$scanActivity, $hitActivity]);
    }

    #[Group('resources')]
    #[Group('activity')]
    public function test_user_filter(): void
    {
        $userActivity = Activity::create([
            'causer_id' => $this->user->id,
            'causer_type' => 'user',
            'log_name' => 'Scans Search',
            'event' => 'Scans Search',
            'description' => fake()->sentence(),
            'properties' => [],
            'created_at' => now()->subHour(),
        ]);

        $otherUserActivity = Activity::create([
            'causer_id' => User::factory()
                ->forOrg($this->user->org)
                ->create()
                ->id,
            'causer_type' => 'user',
            'log_name' => 'Scans Search',
            'event' => 'Scans Search',
            'description' => fake()->sentence(),
            'properties' => [],
            'created_at' => now()->subHour(),
        ]);

        Livewire::test(ListActivities::class)
            ->assertSuccessful()
            ->assertCanSeeTableRecords([$userActivity, $otherUserActivity])
            ->set('tableFilters.filters.user', $this->user->id)
            ->assertCanSeeTableRecords([$userActivity])
            ->assertCanNotSeeTableRecords([$otherUserActivity]);
    }

    #[Group('resources')]
    #[Group('activity')]
    public function test_plate_filter(): void
    {
        $userActivity = Activity::create([
            'causer_id' => $this->user->id,
            'causer_type' => 'user',
            'log_name' => 'Scans Search',
            'event' => 'Scans Search',
            'description' => fake()->sentence(),
            'properties' => [
                'plateNumber' => [
                    'ABC123',
                ],
            ],
            'created_at' => now()->subHour(),
        ]);

        $otherUserActivity = Activity::create([
            'causer_id' => $this->user->id,
            'causer_type' => 'user',
            'log_name' => 'Scans Search',
            'event' => 'Scans Search',
            'description' => fake()->sentence(),
            'properties' => [],
            'created_at' => now()->subHour(),
        ]);

        Livewire::test(ListActivities::class)
            ->assertSuccessful()
            ->assertCanSeeTableRecords([$userActivity, $otherUserActivity])
            ->set('tableFilters.filters.plate', 'ABC123')
            ->assertCanSeeTableRecords([$userActivity])
            ->assertCanNotSeeTableRecords([$otherUserActivity]);
    }

    #[Group('resources')]
    #[Group('activity')]
    public function test_simple_pagination(): void
    {
        $activity = Activity::create([
            'causer_id' => $this->user->id,
            'causer_type' => 'user',
            'log_name' => 'Scans Search',
            'event' => 'Scans Search',
            'description' => fake()->sentence(),
            'properties' => [],
            'created_at' => now()->subHour(),
        ]);

        Livewire::test(ListActivities::class)
            ->assertSuccessful()
            ->assertDontSeeText('Showing 1 to 1 of 1 results');
    }
}
