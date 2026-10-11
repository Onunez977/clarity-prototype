<?php

namespace Tests\Filament\Widgets\Dashboard;

use App\Clickhouse\Models\MissedOpportunity;
use App\Filament\Widgets\Dashboard\MissedOpportunityClientGraph;
use App\Models\Org;
use InsightLPR\BlackAdam\Enum\IntegrationEnum;
use InsightLPR\BlackAdam\Enum\RoleEnum;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\RequiresClickhouse;
use Tests\TestCase;

/**
 * MissedOpportunityClientGraph is orphaned (reachable from nothing today -- see DEV-2462
 * prep-context S15), which is how its raw count() grain bug survived unnoticed. These
 * tests exercise it directly rather than through the UI.
 */
class MissedOpportunityClientGraphTest extends TestCase
{
    use RequiresClickhouse;

    #[Group('widgets')]
    #[Group('dashboard')]
    #[Group('missed-opportunities')]
    public function test_three_rows_sharing_one_hotlist_count_as_one(): void
    {
        $user = $this->createUser(role: RoleEnum::ADMIN->value);
        $this->actingAs($user);

        $org = $user->org;
        $inWindow = now()->startOfYear()->addDay();

        MissedOpportunity::factory()
            ->forOrg($org)
            ->count(3)
            ->create([
                'hotlist_id' => 111,
                'client_org_name' => 'Acme Recovery',
                'scanned_at' => $inWindow->toDateTimeString(),
            ]);

        $widget = new MissedOpportunityClientGraph;
        $clients = $widget->clients();

        $this->assertNotNull($clients);
        $this->assertSame(1, $clients['data'][array_search('Acme Recovery', $clients['labels'], true)]);
    }

    #[Group('widgets')]
    #[Group('dashboard')]
    #[Group('missed-opportunities')]
    public function test_two_different_hotlists_for_one_client_still_accumulate(): void
    {
        $user = $this->createUser(role: RoleEnum::ADMIN->value);
        $this->actingAs($user);

        $org = $user->org;
        $inWindow = now()->startOfYear()->addDay();

        // Guards against an over-correction that collapses to 1-per-client instead of
        // 1-per-distinct-hotlist-per-client.
        MissedOpportunity::factory()
            ->forOrg($org)
            ->create([
                'hotlist_id' => 222,
                'client_org_name' => 'Acme Recovery',
                'scanned_at' => $inWindow->toDateTimeString(),
            ]);

        MissedOpportunity::factory()
            ->forOrg($org)
            ->create([
                'hotlist_id' => 333,
                'client_org_name' => 'Acme Recovery',
                'scanned_at' => $inWindow->toDateTimeString(),
            ]);

        $widget = new MissedOpportunityClientGraph;
        $clients = $widget->clients();

        $this->assertNotNull($clients);
        $this->assertSame(2, $clients['data'][array_search('Acme Recovery', $clients['labels'], true)]);
    }

    #[Group('widgets')]
    #[Group('dashboard')]
    #[Group('missed-opportunities')]
    public function test_nicb_rows_are_excluded(): void
    {
        $user = $this->createUser(role: RoleEnum::ADMIN->value);
        $this->actingAs($user);

        $org = $user->org;
        $inWindow = now()->startOfYear()->addDay();

        MissedOpportunity::factory()
            ->forOrg($org)
            ->create([
                'hotlist_id' => 444,
                'client_org_name' => 'NICB Client',
                'scanned_at' => $inWindow->toDateTimeString(),
                'integration_id' => IntegrationEnum::NICB->value,
            ]);

        $widget = new MissedOpportunityClientGraph;

        $this->assertNull($widget->clients());
    }

    #[Group('widgets')]
    #[Group('dashboard')]
    #[Group('missed-opportunities')]
    public function test_returns_null_when_there_is_no_data(): void
    {
        $user = $this->createUser(role: RoleEnum::ADMIN->value);
        $this->actingAs($user);

        $widget = new MissedOpportunityClientGraph;

        $this->assertNull($widget->clients());
        $this->assertSame('No Data for Client Graph', $widget->getHeading());
    }

    #[Group('widgets')]
    #[Group('dashboard')]
    #[Group('missed-opportunities')]
    public function test_cache_key_is_bumped_to_v2(): void
    {
        $user = $this->createUser(role: RoleEnum::ADMIN->value);
        $this->actingAs($user);

        $orgId = $user->org_id;
        $timezone = $user->timezone ?? 'UTC';

        // Seed the OLD (un-versioned) key with a sentinel a real fetch could never
        // produce, proving the widget no longer reads it after the bump.
        cache()->put('missed-opportunity:client-graph:org='.$orgId.':tz='.$timezone, ['sentinel' => true]);

        $org = $user->org;
        $inWindow = now()->startOfYear()->addDay();

        MissedOpportunity::factory()
            ->forOrg($org)
            ->create([
                'hotlist_id' => 555,
                'client_org_name' => 'Acme Recovery',
                'scanned_at' => $inWindow->toDateTimeString(),
            ]);

        $widget = new MissedOpportunityClientGraph;
        $clients = $widget->clients();

        $this->assertNotNull($clients);
        $this->assertArrayNotHasKey('sentinel', $clients);

        $this->assertNotNull(cache()->get('missed-opportunity:client-graph:org='.$orgId.':tz='.$timezone.':v2'));
    }

    #[Group('widgets')]
    #[Group('dashboard')]
    #[Group('missed-opportunities')]
    public function test_other_orgs_data_is_excluded(): void
    {
        $user = $this->createUser(role: RoleEnum::ADMIN->value);
        $this->actingAs($user);

        $otherOrg = Org::factory()->create();
        $inWindow = now()->startOfYear()->addDay();

        MissedOpportunity::factory()
            ->forOrg($otherOrg)
            ->create([
                'hotlist_id' => 666,
                'client_org_name' => 'Other Org Client',
                'scanned_at' => $inWindow->toDateTimeString(),
            ]);

        $widget = new MissedOpportunityClientGraph;

        $this->assertNull($widget->clients());
    }
}
