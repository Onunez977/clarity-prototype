<?php

namespace Database\Seeders;

use App\Models\ClientOrgAccess;
use App\Models\Org;
use App\Models\User;
use Illuminate\Database\Seeder;
use InsightLPR\BlackAdam\Enum\RoleEnum;

/**
 * Fixed personas for isolated cloud / QA-runner environments, layered on top of
 * LocalDataSeeder's "Test Company" + test@tester.com. Idempotent by name/email.
 */
class CloudSmokeSeeder extends Seeder
{
    public const PRIMARY_ORG_NAME = 'Test Company';

    public const PRIMARY_EMAIL = 'test@tester.com';

    public const PEER_ORG_NAME = 'Cloud Smoke Peer Org';

    public const PEER_ADMIN_EMAIL = 'peer-admin@cloud-smoke.test';

    public const CLIENT_ORG_NAME = 'Cloud Smoke Client Org';

    public const CLIENT_EMAIL = 'client@cloud-smoke.test';

    public function run(): void
    {
        $primaryOrg = Org::query()->where('name', self::PRIMARY_ORG_NAME)->firstOrFail();

        $this->seedPeerAdmin();
        $this->seedClientPortalUser($primaryOrg);
    }

    private function seedPeerAdmin(): void
    {
        if (User::query()->where('email', self::PEER_ADMIN_EMAIL)->exists()) {
            return;
        }

        $peerOrg = Org::factory()->create(['name' => self::PEER_ORG_NAME]);

        setPermissionsTeamId($peerOrg->id);

        User::factory()
            ->forOrg($peerOrg)
            ->create(['name' => 'Peer Admin', 'email' => self::PEER_ADMIN_EMAIL])
            ->assignRole(RoleEnum::ADMIN->value);
    }

    private function seedClientPortalUser(Org $primaryOrg): void
    {
        if (User::query()->where('email', self::CLIENT_EMAIL)->exists()) {
            return;
        }

        $clientOrg = Org::factory()->client()->create(['name' => self::CLIENT_ORG_NAME]);

        ClientOrgAccess::factory()->create([
            'client_org_id' => $clientOrg->id,
            'repo_org_id' => $primaryOrg->id,
        ]);

        setPermissionsTeamId($clientOrg->id);

        User::factory()
            ->forOrg($clientOrg)
            ->create(['name' => 'Client Portal User', 'email' => self::CLIENT_EMAIL])
            ->assignRole(RoleEnum::CLIENT->value);
    }
}
