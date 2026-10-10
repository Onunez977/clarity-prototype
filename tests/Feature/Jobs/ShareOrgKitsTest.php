<?php

namespace Tests\Feature\Jobs;

use App\Jobs\ShareOrgKits;
use App\Models\Kit;
use App\Models\Org;
use App\Models\Shared;
use App\Models\User;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

class ShareOrgKitsTest extends TestCase
{
    #[Group('jobs')]
    public function test_can_share_org_kits(): void
    {
        $sharingUser = $this->createUser();

        $kit = Kit::factory()->forOrg($sharingUser->org)->create();

        $sharedWithUser = User::factory()->create();

        $job = new ShareOrgKits(
            sharingUser: $sharingUser,
            sharedWithUser: $sharedWithUser,
        );

        $this->assertCount(0, $sharedWithUser->getUserKits(withShared: true));

        $job->handle();

        $sharedWithUser->refresh();
        $sharedKits = $sharedWithUser->getUserKits(withShared: true);

        $this->assertCount(1, $sharedKits);
        $this->assertEquals($kit->id, $sharedKits->first()->id);
    }

    #[Group('jobs')]
    public function test_can_unshare_org_kits(): void
    {
        $sharingUser = $this->createUser();

        $kit = Kit::factory()->forOrg($sharingUser->org)->create();

        $sharedWithUser = User::factory()->create();

        Shared::factory()->create([
            'shared_type' => Kit::class,
            'shared_id' => $kit->id,
            'shared_with_type' => Org::class,
            'shared_with_id' => $sharedWithUser->org_id,
        ]);

        $job = new ShareOrgKits(
            sharingUser: $sharingUser,
            sharedWithUser: $sharedWithUser,
            unshare: true,
        );

        $this->assertCount(1, $sharedWithUser->fresh()->getUserKits(withShared: true));

        $job->handle();

        $this->assertCount(0, $sharedWithUser->fresh()->getUserKits(withShared: true));
    }
}
