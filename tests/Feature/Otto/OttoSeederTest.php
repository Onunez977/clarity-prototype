<?php

declare(strict_types=1);

namespace Tests\Feature\Otto;

use App\Enums\FeatureEnum;
use App\Enums\HitTypeEnum;
use App\Enums\HotlistStatusEnum;
use App\Models\Hit;
use App\Models\Hotlist;
use App\Models\Kit;
use App\Models\KitSettings;
use App\Models\Org;
use App\Models\Scan;
use App\Models\User;
use Database\Seeders\OttoSeeder;
use Illuminate\Support\Carbon;
use InsightLPR\BlackAdam\Enum\RoleEnum;
use Laravel\Pennant\Feature;
use Tests\TestCase;

class OttoSeederTest extends TestCase
{
    public function test_skips_outside_local(): void
    {
        $this->seed(OttoSeeder::class);

        $this->assertFalse(Org::query()->where('name', OttoSeeder::ORG_NAME)->exists());
        $this->assertFalse(Kit::query()->where('name', 'maria.garcia')->exists());
        $this->assertFalse(User::query()->where('email', OttoSeeder::ADMIN_EMAIL)->exists());
    }

    public function test_seeds_otto_lookups_and_scout_drivers_when_local(): void
    {
        $this->app['env'] = 'local';

        $this->seed(OttoSeeder::class);

        $org = Org::query()->where('name', OttoSeeder::ORG_NAME)->first();
        $this->assertNotNull($org);
        $this->assertTrue($org->fresh()->load('meta')->ottoEnabled());

        $admin = User::query()->where('email', OttoSeeder::ADMIN_EMAIL)->first();
        $this->assertNotNull($admin);
        $this->assertSame((int) $org->id, (int) $admin->org_id);

        setPermissionsTeamId($org->id);
        $admin->unsetRelation('roles');
        $admin->unsetRelation('permissions');
        $this->assertTrue($admin->hasRole(RoleEnum::ADMIN->value));
        $this->assertTrue($admin->hasPermissionTo('use-otto'));
        $this->assertTrue($admin->hasPermissionTo('clarity-scout'));
        $this->assertTrue(Feature::for($admin->fresh())->active(FeatureEnum::CLARITY_SCOUT->slug()));

        $this->assertGreaterThanOrEqual(OttoSeeder::SEARCH_VOLUME, count(OttoSeeder::drivers()));
        $this->assertGreaterThanOrEqual(OttoSeeder::SEARCH_VOLUME, count(OttoSeeder::plates()));

        foreach (OttoSeeder::heroDrivers() as $driver) {
            $kit = Kit::query()->where('org_id', $org->id)->where('name', $driver['kit'])->first();
            $this->assertNotNull($kit, $driver['kit'].' kit missing');
            $this->assertSame($driver['kit'], $kit->kit_mapping);

            $settings = KitSettings::query()->where('kit_id', $kit->id)->first();
            $this->assertNotNull($settings);
            $this->assertNotNull($settings->user);
            $this->assertSame($driver['name'], $settings->user->name);
        }

        $this->assertSame(
            count(OttoSeeder::drivers()),
            Kit::query()->where('org_id', $org->id)->where(function ($query): void {
                $query->whereIn('name', collect(OttoSeeder::heroDrivers())->pluck('kit'))
                    ->orWhere('name', 'like', 'otto.driver.%');
            })->count(),
        );

        foreach (OttoSeeder::heroPlates() as $plate) {
            $driver = collect(OttoSeeder::drivers())->firstWhere('kit', $plate['kit']);
            $this->assertNotNull($driver, $plate['kit'].' driver missing');

            $kit = Kit::query()->where('org_id', $org->id)->where('name', $plate['kit'])->first();
            $this->assertNotNull($kit, $plate['kit'].' kit missing');

            $scan = Scan::query()
                ->where('ocr', $plate['ocr'])
                ->where('camera_name', OttoSeeder::CAMERA_NAME)
                ->first();
            $this->assertNotNull($scan, $plate['ocr'].' scan missing');
            $this->assertSame((int) $kit->id, (int) $scan->kit_id);
            $this->assertTrue(
                Carbon::parse($scan->scanned_at)->between(
                    now()->utc()->subMinutes($driver['minutes'] + 5),
                    now()->utc()->subMinutes(max(0, $driver['minutes'] - 1)),
                ),
                $plate['ocr'].' scan time should align with driver GPS ping',
            );

            $hotlist = Hotlist::query()
                ->where('org_id', $org->id)
                ->where('remote_order_id', 'OTTO-'.$plate['ocr'])
                ->first();
            $this->assertNotNull($hotlist, $plate['ocr'].' hotlist missing');
            $this->assertSame(HotlistStatusEnum::Open->value, $hotlist->status);
            $this->assertSame($plate['ocr'], $hotlist->plate);

            $hit = Hit::query()->where('scan_id', $scan->id)->first();
            $this->assertNotNull($hit, $plate['ocr'].' hit missing');
            $this->assertSame(HitTypeEnum::Live->value, $hit->hit_type);
            $this->assertSame($hotlist->id, $hit->hotlist_id);
            $this->assertSame((int) $kit->id, (int) $hit->kit_id);

            $settings = KitSettings::query()->where('kit_id', $kit->id)->first();
            $this->assertNotNull($settings);
            $this->assertSame($driver['name'], $settings->user->name);
        }

        $this->assertSame(
            count(OttoSeeder::plates()),
            Scan::query()->where('camera_name', OttoSeeder::CAMERA_NAME)->count(),
        );
        $this->assertSame(
            count(OttoSeeder::plates()),
            Hotlist::query()->where('org_id', $org->id)->where('remote_order_id', 'like', 'OTTO-%')->count(),
        );
        $this->assertSame(
            count(OttoSeeder::plates()),
            Hit::query()
                ->whereIn('scan_id', Scan::query()->where('camera_name', OttoSeeder::CAMERA_NAME)->pluck('id'))
                ->count(),
        );

        $adminKit = Kit::query()->where('org_id', $org->id)->where('name', 'mertz.kimberly')->first();
        $this->assertNotNull($adminKit);
        $admin->unsetRelation('kitSettings');
        $this->assertSame((int) $adminKit->id, (int) $admin->fresh()->kit_id);
    }

    public function test_is_idempotent_for_named_kits_and_seed_scans(): void
    {
        $this->app['env'] = 'local';

        $this->seed(OttoSeeder::class);
        $this->seed(OttoSeeder::class);

        $org = Org::query()->where('name', OttoSeeder::ORG_NAME)->first();
        $this->assertNotNull($org);

        $this->assertSame(1, User::query()->where('email', OttoSeeder::ADMIN_EMAIL)->count());
        $this->assertSame(1, Kit::query()->where('org_id', $org->id)->where('name', 'maria.garcia')->count());
        $this->assertSame(
            count(OttoSeeder::plates()),
            Scan::query()->where('camera_name', OttoSeeder::CAMERA_NAME)->count(),
        );
        $this->assertSame(
            count(OttoSeeder::drivers()),
            Kit::query()->where('org_id', $org->id)->where(function ($query): void {
                $query->whereIn('name', collect(OttoSeeder::heroDrivers())->pluck('kit'))
                    ->orWhere('name', 'like', 'otto.driver.%');
            })->count(),
        );
    }

    public function test_seeds_into_a_custom_org_when_org_id_is_passed(): void
    {
        $this->app['env'] = 'local';

        $org = Org::factory()->create(['name' => 'Custom Otto Org']);

        $this->app->make(OttoSeeder::class)->__invoke(['orgId' => $org->id]);

        $this->assertFalse(Org::query()->where('name', OttoSeeder::ORG_NAME)->exists());
        $this->assertTrue($org->fresh()->load('meta')->ottoEnabled());

        $admin = User::query()->where('email', OttoSeeder::ADMIN_EMAIL)->first();
        $this->assertNotNull($admin);
        $this->assertSame((int) $org->id, (int) $admin->org_id);
        $this->assertSame(1, Kit::query()->where('org_id', $org->id)->where('name', 'maria.garcia')->count());
    }

    public function test_skips_when_custom_org_id_is_missing(): void
    {
        $this->app['env'] = 'local';

        $missingOrgId = (int) (Org::query()->max('id') ?? 0) + 9999;

        $this->app->make(OttoSeeder::class)->__invoke(['orgId' => $missingOrgId]);

        $this->assertFalse(Org::query()->where('name', OttoSeeder::ORG_NAME)->exists());
        $this->assertFalse(User::query()->where('email', OttoSeeder::ADMIN_EMAIL)->exists());
        $this->assertFalse(Kit::query()->where('name', 'maria.garcia')->exists());
    }
}
