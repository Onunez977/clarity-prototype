<?php

namespace Tests\Feature;

use App\Models\Kit;
use App\Models\Org;
use App\Models\Shared;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\Config;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * DEV-2591 — the ClickHouse branch of ScanVehicleController.
 *
 * POST /scan/vehicle carries no OrgMiddleware, and the ClickHouse statement used to
 * filter on scan_id alone. That was latent while the feature literal could never match;
 * routing on config alone would have made it a live cross-tenant read for every
 * authenticated user the moment the flag was turned on.
 */
class ScanVehicleTenancyTest extends TestCase
{
    #[Group('tenancy')]
    #[Group('clickhouse')]
    public function test_the_clickhouse_branch_is_scoped_to_the_callers_kits(): void
    {
        Config::set('app.experimental.clickhouse.scans', true);

        $user = $this->createUser();
        $own = Kit::factory()->forOrg($user->org)->create();

        $other = $this->createUser();
        $foreign = Kit::factory()->forOrg($other->org)->create();

        // DB::listen does not fire for the ClickHouse connection, so spy on the
        // connection itself and capture the statement the controller actually sends.
        $sent = [];
        $spy = \Mockery::mock(ConnectionInterface::class);
        $spy->shouldReceive('selectOne')->andReturnUsing(function (string $sql) use (&$sent) {
            $sent[] = $sql;

            return null;
        });
        \DB::shouldReceive('connection')->with('clarity_clickhouse')->andReturn($spy);
        \DB::shouldReceive('connection')->withAnyArgs()->passthru();

        $this->actingAs($user)
            ->postJson(route('scan.vehicle'), ['scan_id' => 123456])
            ->assertOk();

        $this->assertNotEmpty($sent, 'the ClickHouse branch should have been taken');

        foreach ($sent as $sql) {
            $this->assertMatchesRegularExpression('/kit_id IN \(([\d,]+)\)/', $sql, 'must constrain by kit');

            preg_match('/kit_id IN \(([\d,]+)\)/', $sql, $m);
            $scoped = array_map('intval', explode(',', $m[1]));

            $this->assertContains($own->id, $scoped);
            $this->assertNotContains($foreign->id, $scoped, 'another org\'s kit must never be in scope');
        }
    }

    #[Group('tenancy')]
    #[Group('clickhouse')]
    public function test_shared_kits_are_in_scope_so_their_scans_resolve_a_vehicle(): void
    {
        Config::set('app.experimental.clickhouse.scans', true);

        $viewer = $this->createUser();
        $own = Kit::factory()->forOrg($viewer->org)->create();

        // A kit another org shares with the viewer. ListScans renders its scans, so the
        // vehicle lookup must cover it too - scoping by getUserKits() without withShared
        // silently returned no vehicle for exactly these rows (review round 2, H-2).
        $sharingOrg = $this->createUser()->org;
        $sharedKit = Kit::factory()->forOrg($sharingOrg)->create();
        Shared::create([
            'shared_type' => Kit::class,
            'shared_id' => $sharedKit->id,
            'shared_with_type' => Org::class,
            'shared_with_id' => $viewer->org_id,
        ]);
        Kit::forgetCachedKits((int) $viewer->org_id);

        $sent = [];
        $spy = \Mockery::mock(ConnectionInterface::class);
        $spy->shouldReceive('selectOne')->andReturnUsing(function (string $sql) use (&$sent) {
            $sent[] = $sql;

            return null;
        });
        \DB::shouldReceive('connection')->with('clarity_clickhouse')->andReturn($spy);
        \DB::shouldReceive('connection')->withAnyArgs()->passthru();

        $this->actingAs($viewer)
            ->postJson(route('scan.vehicle'), ['scan_id' => 987654])
            ->assertOk();

        $this->assertNotEmpty($sent);

        preg_match('/kit_id IN \(([\d,]+)\)/', $sent[0], $m);
        $scoped = array_map('intval', explode(',', $m[1]));

        $this->assertContains($own->id, $scoped);
        $this->assertContains($sharedKit->id, $scoped, 'a shared kit must be in scope');
    }

    #[Group('tenancy')]
    #[Group('clickhouse')]
    public function test_a_user_with_no_kits_never_reaches_clickhouse(): void
    {
        Config::set('app.experimental.clickhouse.scans', true);

        $user = $this->createUser();

        $sent = [];
        $spy = \Mockery::mock(ConnectionInterface::class);
        $spy->shouldReceive('selectOne')->andReturnUsing(function (string $sql) use (&$sent) {
            $sent[] = $sql;

            return null;
        });
        \DB::shouldReceive('connection')->with('clarity_clickhouse')->andReturn($spy);
        \DB::shouldReceive('connection')->withAnyArgs()->passthru();

        $this->actingAs($user)
            ->postJson(route('scan.vehicle'), ['scan_id' => 123456])
            ->assertOk();

        // An empty kit list must not degrade into an unscoped read.
        $this->assertSame([], $sent);
    }
}
