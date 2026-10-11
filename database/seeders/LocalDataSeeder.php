<?php

namespace Database\Seeders;

use App\Enums\FeatureEnum;
use App\Enums\HotlistStatusEnum;
use App\Models\Address;
use App\Models\AggregateScanCountByHour;
use App\Models\Hit;
use App\Models\Hotlist;
use App\Models\HotlistAddress;
use App\Models\HotlistMeta;
use App\Models\Kit;
use App\Models\KitSettings;
use App\Models\Org;
use App\Models\PlateLog;
use App\Models\RecoveriesKitUserScansReport;
use App\Models\Scan;
use App\Models\ScanMetaData;
use App\Models\User;
use Clickbar\Magellan\Data\Geometries\Point;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use InsightLPR\BlackAdam\Enum\PermissionEnum;
use InsightLPR\BlackAdam\Enum\RoleEnum;
use Schema;
use Spatie\Activitylog\Models\Activity;

class LocalDataSeeder extends Seeder
{
    public function run(): void
    {
        $org = Org::query()
            ->where('name', 'Test Company')
            ->first();

        if ($org) {
            $this->createAll($org);
        } else {
            dump('Creating Org...');

            Org::factory()
                ->afterCreating(function (Org $org) {
                    $features = collect(FeatureEnum::values())
                        ->mapWithKeys(fn ($feature) => [$feature => true])
                        ->toArray();

                    $org->meta()->create([
                        'properties' => \array_merge([
                            'platform_repo_pro' => true,
                        ], $features),
                    ]);

                    $this->createAll($org);
                })
                ->create([
                    'name' => 'Test Company',
                ]);
        }

        $this->call(OrgLocationSeeder::class);
        $this->call(OpportunitySeeder::class);

        dump('Done! Log in using the email: "test@tester.com" and the password: "password"');
    }

    private function createAll(Org $org): void
    {
        setPermissionsTeamId($org->id);

        $email = 'test@tester.com';
        if (User::where('email', $email)->doesntExist()) {
            dump('Creating User...');

            $user = User::factory()
                ->forOrg($org)
                ->create([
                    'id' => 1,
                    'name' => 'Admin',
                    'email' => $email,
                ]);

            DB::connection($user->getConnectionName())->statement(
                "SELECT setval(pg_get_serial_sequence('users', 'id'), (SELECT MAX(id) FROM users))"
            );

            $user->assignRole(RoleEnum::ADMIN->value);
            $user->syncPermissions(PermissionEnum::getValues());
            $user->givePermissionTo('clarity-scout');
        }

        // $this->reset($org);

        // Main Hotlist
        dump('Creating Hotlist...');

        $hotlist = Hotlist::factory()
            ->forOrg($org)
            ->withAddress(25)
            ->notStaged()
            ->create([
                'status' => HotlistStatusEnum::Open,
            ]);

        // Kits & Scans
        dump('Creating Kits...');

        Kit::factory()
            ->forOrg($org)
            ->count(15)
            ->afterCreating(function (Kit $kit) use ($org, $hotlist) {
                dump('Creating Scans for Kit #'.$kit->id.'...');

                Scan::factory()
                    ->for($kit)
                    ->scannedToday()
                    ->count(rand(10, 25))
                    ->sequence(
                        [
                            'scanned_at' => now(),
                            'created_at' => now(),
                        ],
                        [
                            'scanned_at' => now()->subHours(rand(1, 720)),
                            'created_at' => now()->subHours(rand(1, 720)),
                        ],
                    )
                    ->create([
                        'ocr' => $hotlist->plate,
                        'us_state' => $hotlist->us_state,
                    ]);

                RecoveriesKitUserScansReport::factory()
                    ->count(10)
                    ->sequence(fn ($sequence) => ['recovery_id' => 1000 + $sequence->index])
                    ->create([
                        'org_id' => $org->id,
                        'kit_id' => $kit->id,
                    ]);

                try {
                    AggregateScanCountByHour::factory()
                        ->count(5)
                        ->create([
                            'kit_id' => $kit->id,
                        ]);
                } catch (\Exception $exception) {
                    // Nothing.
                }
            })
            ->create();

        // Clarity Scout: assign first kit to test user and seed hits
        dump('Setting up Clarity Scout user...');

        $firstKit = Kit::where('org_id', $org->id)->first();
        $portalUser = User::where('email', 'test@tester.com')->first();

        if ($firstKit && $portalUser) {
            KitSettings::updateOrCreate(
                ['kit_id' => $firstKit->id],
                ['user_id' => $portalUser->id],
            );

            Hit::factory()
                ->count(5)
                ->forOrg($org)
                ->create([
                    'kit_id' => $firstKit->id,
                ]);
        }

        // Hotlists
        dump('Creating Other Hotlists...');

        Hotlist::factory()
            ->count(10)
            ->for($org)
            ->repossessed()
            ->rdn()
            ->create()
            ->each(function (Hotlist $hotlist) use ($org) {
                HotlistMeta::factory()
                    ->for($hotlist, 'hotlist')
                    ->create([
                        'repo_date' => now(),
                    ]);

                $address = Address::factory()
                    ->recoveryAddress()
                    ->create();

                $address2 = Address::factory()
                    ->create(collect($address->toArray())->except(['id'])->toArray());

                $address->hotlists()->attach($hotlist->id);
                $address2->hotlists()->attach($hotlist->id);

                Kit::factory()
                    ->count(rand(2, 5))
                    ->for($org)
                    ->create()
                    ->each(function (Kit $kit) use ($hotlist, $address) {
                        Scan::factory()
                            ->count(rand(10, 20))
                            ->for($kit, 'kit')
                            ->create([
                                'ocr' => $hotlist->plate,
                                'scanned_at' => now()->subDays(rand(1, 30)),
                                'geo' => Point::makeGeodetic($address->geo->getY(), $address->geo->getX()),
                            ]);
                    });
            });
    }

    private function reset(Org $org): void
    {
        if (config('app.env') === 'local') {
            Schema::disableForeignKeyConstraints();
            cache()->clear();

            Address::truncate();
            RecoveriesKitUserScansReport::truncate();

            Scan::truncate();
            ScanMetaData::truncate();

            Kit::where('org_id', $org->id)->truncate();

            Hotlist::where('org_id', $org->id)->truncate();
            HotlistMeta::truncate();
            HotlistAddress::truncate();
            PlateLog::truncate();

            Hit::truncate();

            Activity::truncate();

            cache()->clear();
            Schema::enableForeignKeyConstraints();
        }
    }
}
