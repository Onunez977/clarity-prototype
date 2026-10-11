<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Ai\Agents\Otto;
use App\Ai\PageAction;
use App\Ai\PageActionChange;
use App\Ai\Tools\ProposeCreateZoneTool;
use App\Data\AgentInvestigation\AgentInvestigationProperties;
use App\Data\AgentInvestigation\Search;
use App\Data\AgentInvestigation\SearchResult;
use App\Datatype\SearchType;
use App\Enums\FeatureEnum;
use App\Enums\HitTypeEnum;
use App\Enums\HotlistStatusEnum;
use App\Enums\ProTierEnum;
use App\Models\AgentConversation;
use App\Models\AgentInvestigation;
use App\Models\Hit;
use App\Models\Hotlist;
use App\Models\Kit;
use App\Models\KitSettings;
use App\Models\Org;
use App\Models\Scan;
use App\Models\ScanMetaData;
use App\Models\User;
use App\Models\Zone;
use App\Support\Otto\OttoScoutAccess;
use App\Support\Otto\PageActionCopy;
use Clickbar\Magellan\Data\Geometries\Point;
use Database\Factories\ZoneFactory;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InsightLPR\BlackAdam\Enum\IntegrationEnum;
use InsightLPR\BlackAdam\Enum\OrgType;
use InsightLPR\BlackAdam\Enum\RoleEnum;
use Laravel\Ai\Models\ConversationMessage;
use Laravel\Pennant\Feature;
use Spatie\Permission\Models\Permission;
use Throwable;

/**
 * Local-only Otto QA data: Scout drivers with GPS, plus scans/hits/assignments
 * Otto can search. Idempotent. Writes ClickHouse for local QA (PeerDB is not running).
 *
 * Seeds at least {@see SEARCH_VOLUME} of each searchable type (drivers, scans,
 * hits, assignments) so Otto's per-search caps (e.g. 50) can be exercised.
 *
 * Also seeds the three Dallas Scout zones from the DEV-2320 canvas and demo
 * Otto threads so QA can screenshot real widgets without calling Bedrock.
 *
 * Optional `$orgId` seeds into an existing org; omit it to use Test Company /
 * {@see ADMIN_EMAIL} as before.
 */
class OttoSeeder extends Seeder
{
    public const ORG_NAME = 'Test Company';

    public const ADMIN_EMAIL = 'test@tester.com';

    public const MARIA_EMAIL = 'maria.garcia@tester.com';

    public const ZONE_DOWNTOWN = 'Downtown Dallas';

    public const ZONE_DFW = 'DFW Airport';

    public const ZONE_NORTH = 'North loop';

    /** @var array<string, string> */
    private const ZONE_CONVERSATION_IDS = [
        'list' => 'aaaaaaaa-0000-4000-8000-000000000001',
        'maria' => 'aaaaaaaa-0000-4000-8000-000000000002',
        'noscan' => 'aaaaaaaa-0000-4000-8000-000000000003',
        'create-zip' => 'aaaaaaaa-0000-4000-8000-000000000004',
        'draw' => 'aaaaaaaa-0000-4000-8000-000000000005',
        'no-scout' => 'aaaaaaaa-0000-4000-8000-000000000006',
        'not-admin' => 'aaaaaaaa-0000-4000-8000-000000000007',
    ];

    public const CAMERA_NAME = 'OTTO-SEED';

    public const CLIENT_ORG_NAME = 'Otto QA Client';

    public const LIENHOLDER_ORG_NAME = 'Otto QA Lienholder';

    /** Minimum rows per searchable surface (drivers / scans / hits / assignments). */
    public const SEARCH_VOLUME = 100;

    /**
     * Named drivers kept for known Otto prompts ("where is Maria?", plate ABC1234).
     *
     * @return list<array{name: string, email: string, kit: string, lng: float, lat: float, minutes: int}>
     */
    public static function heroDrivers(): array
    {
        return [
            ['name' => 'Admin', 'email' => self::ADMIN_EMAIL, 'kit' => 'mertz.kimberly', 'lng' => -86.1581, 'lat' => 39.7684, 'minutes' => 3],
            ['name' => 'Maria Garcia', 'email' => 'maria.garcia@tester.com', 'kit' => 'maria.garcia', 'lng' => -96.797, 'lat' => 32.7767, 'minutes' => 6],
            ['name' => 'James Chen', 'email' => 'james.chen@tester.com', 'kit' => 'james.chen', 'lng' => -87.6298, 'lat' => 41.8781, 'minutes' => 14],
            ['name' => 'Priya Patel', 'email' => 'priya.patel@tester.com', 'kit' => 'priya.patel', 'lng' => -86.7816, 'lat' => 36.1627, 'minutes' => 2],
        ];
    }

    /**
     * @return list<array{name: string, email: string, kit: string, lng: float, lat: float, minutes: int}>
     */
    public static function drivers(): array
    {
        return array_merge(self::heroDrivers(), self::volumeDrivers());
    }

    /**
     * Extra Scout drivers so fleet summaries hit {@see SEARCH_VOLUME}.
     *
     * @return list<array{name: string, email: string, kit: string, lng: float, lat: float, minutes: int}>
     */
    public static function volumeDrivers(): array
    {
        $cities = [
            ['lng' => -86.1581, 'lat' => 39.7684],  // Indianapolis
            ['lng' => -96.797, 'lat' => 32.7767],   // Dallas
            ['lng' => -87.6298, 'lat' => 41.8781],  // Chicago
            ['lng' => -86.7816, 'lat' => 36.1627],  // Nashville
            ['lng' => -84.3880, 'lat' => 33.7490],  // Atlanta
            ['lng' => -80.1918, 'lat' => 25.7617],  // Miami
            ['lng' => -104.9903, 'lat' => 39.7392], // Denver
            ['lng' => -122.4194, 'lat' => 37.7749], // San Francisco
        ];

        $needed = max(0, self::SEARCH_VOLUME - count(self::heroDrivers()));
        $drivers = [];

        for ($i = 1; $i <= $needed; $i++) {
            $city = $cities[($i - 1) % count($cities)];
            $slug = sprintf('otto.driver.%03d', $i);

            $drivers[] = [
                'name' => sprintf('Otto Driver %03d', $i),
                'email' => $slug.'@tester.com',
                'kit' => $slug,
                'lng' => $city['lng'] + (($i % 7) * 0.001),
                'lat' => $city['lat'] + (($i % 5) * 0.001),
                'minutes' => 1 + (($i - 1) % 20),
            ];
        }

        return $drivers;
    }

    /**
     * @return array<string, array{name: string, email: string, kit: string, lng: float, lat: float, minutes: int}>
     */
    public static function driversByKit(): array
    {
        $byKit = [];

        foreach (self::drivers() as $driver) {
            $byKit[$driver['kit']] = $driver;
        }

        return $byKit;
    }

    /**
     * Named plates kept for known Otto prompts.
     *
     * @return list<array{ocr: string, state: string, kit: string, vin: string, year: string, make: string, model: string, lng: float, lat: float, address: string}>
     */
    public static function heroPlates(): array
    {
        return [
            ['ocr' => 'ABC1234', 'state' => 'IN', 'kit' => 'mertz.kimberly', 'vin' => '1HGCM82633A00001', 'year' => '2018', 'make' => 'Honda', 'model' => 'Civic', 'lng' => -86.1581, 'lat' => 39.7684, 'address' => 'Indianapolis, IN'],
            ['ocr' => 'IND9999', 'state' => 'IN', 'kit' => 'mertz.kimberly', 'vin' => '1GKS2CKJ0HR00002', 'year' => '2017', 'make' => 'GMC', 'model' => 'Sierra', 'lng' => -86.1575, 'lat' => 39.7692, 'address' => 'Indianapolis, IN'],
            ['ocr' => 'DAL4455', 'state' => 'TX', 'kit' => 'maria.garcia', 'vin' => '1FTFW1E50LFA00003', 'year' => '2020', 'make' => 'Ford', 'model' => 'F-150', 'lng' => -96.797, 'lat' => 32.7767, 'address' => 'Dallas, TX'],
            ['ocr' => 'CHI7788', 'state' => 'IL', 'kit' => 'james.chen', 'vin' => '4T1B11HK5KU000004', 'year' => '2019', 'make' => 'Toyota', 'model' => 'Camry', 'lng' => -87.6298, 'lat' => 41.8781, 'address' => 'Chicago, IL'],
            ['ocr' => 'NSH2211', 'state' => 'TN', 'kit' => 'priya.patel', 'vin' => 'JM3KFBCM5M0000005', 'year' => '2021', 'make' => 'Mazda', 'model' => 'CX-5', 'lng' => -86.7816, 'lat' => 36.1627, 'address' => 'Nashville, TN'],
        ];
    }

    /**
     * All plates Otto can search (heroes + volume fill to {@see SEARCH_VOLUME}).
     *
     * @return list<array{ocr: string, state: string, kit: string, vin: string, year: string, make: string, model: string, lng: float, lat: float, address: string}>
     */
    public static function plates(): array
    {
        return array_merge(self::heroPlates(), self::volumePlates());
    }

    /**
     * Extra plates so scan/hit/assignment searches reach {@see SEARCH_VOLUME}.
     *
     * @return list<array{ocr: string, state: string, kit: string, vin: string, year: string, make: string, model: string, lng: float, lat: float, address: string}>
     */
    public static function volumePlates(): array
    {
        $states = ['IN', 'TX', 'IL', 'TN', 'OH', 'GA', 'CO', 'CA', 'FL', 'NY'];
        $makes = [
            ['year' => '2018', 'make' => 'Honda', 'model' => 'Civic'],
            ['year' => '2019', 'make' => 'Toyota', 'model' => 'Camry'],
            ['year' => '2020', 'make' => 'Ford', 'model' => 'F-150'],
            ['year' => '2021', 'make' => 'Mazda', 'model' => 'CX-5'],
            ['year' => '2017', 'make' => 'GMC', 'model' => 'Sierra'],
        ];
        $addresses = [
            'Indianapolis, IN', 'Dallas, TX', 'Chicago, IL', 'Nashville, TN',
            'Atlanta, GA', 'Miami, FL', 'Denver, CO', 'San Francisco, CA',
        ];

        $drivers = self::drivers();
        $needed = max(0, self::SEARCH_VOLUME - count(self::heroPlates()));
        $plates = [];

        for ($i = 1; $i <= $needed; $i++) {
            $driver = $drivers[($i - 1) % count($drivers)];
            $vehicle = $makes[($i - 1) % count($makes)];
            $state = $states[($i - 1) % count($states)];
            $ocr = sprintf('OTV%04d', $i);

            $plates[] = [
                'ocr' => $ocr,
                'state' => $state,
                'kit' => $driver['kit'],
                'vin' => sprintf('1HGCM82633A%05d', $i),
                'year' => $vehicle['year'],
                'make' => $vehicle['make'],
                'model' => $vehicle['model'],
                'lng' => $driver['lng'] + (($i % 9) * 0.0001),
                'lat' => $driver['lat'] + (($i % 6) * 0.0001),
                'address' => $addresses[($i - 1) % count($addresses)],
            ];
        }

        return $plates;
    }

    public function run(?int $orgId = null): void
    {
        if (! app()->environment('local')) {
            $this->command?->warn('OttoSeeder is local-only. Skipping.');

            return;
        }

        $resolved = $this->ensureOrgAndAdmin($orgId);

        if ($resolved === null) {
            return;
        }

        [$org, $admin] = $resolved;

        setPermissionsTeamId($org->id);
        $this->grantOttoAccess($org, $admin);

        $kits = $this->seedScoutDrivers($org, $admin);
        $this->seedLookups($org, $kits);
        $zones = $this->seedHeroZones($org, $admin);
        $this->seedZoneConversations($org, $admin, $zones);

        Cache::forget('scanner:birds-eye-org-kits:'.$org->id);
        Cache::forget('org-kits:'.$org->id.'-all');

        $plateCount = count(self::plates());
        $driverCount = count(self::drivers());
        $loginBase = rtrim((string) config('app.url'), '').'/__qa/login/';

        $this->command?->info('Otto QA data ready for '.$admin->email.' / org '.$org->id.' ('.$org->name.').');
        $this->command?->info("Seeded {$driverCount} drivers and {$plateCount} scans/hits/assignments.");
        $this->command?->info('Seeded zones: '.self::ZONE_DOWNTOWN.', '.self::ZONE_DFW.', '.self::ZONE_NORTH.'.');
        $this->command?->info('QA login (admin / zone widgets): '.$loginBase.$admin->id);
        $maria = User::query()->where('email', self::MARIA_EMAIL)->first();
        if ($maria) {
            $this->command?->info('QA login (Maria / not-admin): '.$loginBase.$maria->id);
        }
        $this->command?->info('Try: “where are my drivers?”, “what zones do we have?”, “search plate ABC1234”.');
    }

    /**
     * @return array{0: Org, 1: User}|null
     */
    private function ensureOrgAndAdmin(?int $orgId = null): ?array
    {
        if ($orgId !== null) {
            $org = Org::query()->find($orgId);

            if (! $org) {
                $this->command?->error("OttoSeeder: org {$orgId} not found. Skipping.");

                return null;
            }
        } else {
            $admin = User::query()->where('email', self::ADMIN_EMAIL)->first();
            $org = $admin?->org ?? Org::query()->where('name', self::ORG_NAME)->first();

            if (! $org) {
                $org = Org::factory()->create(['name' => self::ORG_NAME]);
            }
        }

        $admin = User::query()->where('email', self::ADMIN_EMAIL)->first();

        if (! $admin) {
            $attributes = [
                'name' => 'Admin',
                'email' => self::ADMIN_EMAIL,
            ];

            if (User::query()->whereKey(1)->doesntExist()) {
                $attributes['id'] = 1;
            }

            $admin = User::factory()->forOrg($org)->create($attributes);
            $this->syncUserIdSequence();
        } elseif ((int) $admin->org_id !== (int) $org->id) {
            $admin->forceFill(['org_id' => $org->id])->save();
        }

        return [$org, $admin];
    }

    private function syncUserIdSequence(): void
    {
        try {
            DB::connection('platform')->statement(
                "SELECT setval(pg_get_serial_sequence('users', 'id'), COALESCE((SELECT MAX(id) FROM users), 1))"
            );
        } catch (Throwable) {
            // sqlite or a connection without a serial sequence
        }
    }

    private function grantOttoAccess(Org $org, User $admin): void
    {
        $org->meta()->firstOrCreate(['id' => $org->id])->updateProperties([
            'platform_repo_pro' => true,
            'platform_repo_pro_tier' => ProTierEnum::CLARITY_PRO->value,
            FeatureEnum::CLARITY_SCOUT->value => true,
        ]);

        Cache::forget('org_pro_enabled_'.$org->id);
        Cache::forget('org_pro_features_'.$org->id);

        Permission::findOrCreate('use-otto', 'web');
        Permission::findOrCreate('clarity-scout', 'web');

        if (! $admin->hasRole(RoleEnum::ADMIN->value)) {
            $admin->assignRole(RoleEnum::ADMIN->value);
        }

        $admin->givePermissionTo(['use-otto', 'clarity-scout']);

        Feature::for($admin)->activate(FeatureEnum::CLARITY_SCOUT->slug());
    }

    /**
     * @return array<string, Kit>
     */
    private function seedScoutDrivers(Org $org, User $admin): array
    {
        $kits = [];

        foreach (self::drivers() as $row) {
            $kit = Kit::query()->firstOrCreate(
                ['org_id' => $org->id, 'name' => $row['kit']],
                [
                    'kit_type' => 1,
                    'text_phone' => '',
                    'kit_mapping' => $row['kit'],
                ],
            );

            $kit->forceFill([
                'kit_mapping' => $row['kit'],
                'kit_type' => 1,
            ])->save();

            $user = $row['email'] === self::ADMIN_EMAIL
                ? $admin
                : (User::query()->where('email', $row['email'])->first()
                    ?? User::factory()->forOrg($org)->create([
                        'name' => $row['name'],
                        'email' => $row['email'],
                    ]));

            if ((int) $user->org_id !== (int) $org->id) {
                $user->forceFill(['org_id' => $org->id, 'name' => $row['name']])->save();
            } elseif ($row['email'] !== self::ADMIN_EMAIL && $user->name !== $row['name']) {
                $user->forceFill(['name' => $row['name']])->save();
            }

            KitSettings::query()->updateOrCreate(
                ['user_id' => $user->id],
                ['kit_id' => $kit->id],
            );

            if ($row['email'] !== self::ADMIN_EMAIL) {
                $user->givePermissionTo('clarity-scout');
            }

            $this->insertClickhouse('clarity.sc_positions', [[
                'kit_mapping' => $row['kit'],
                'geo' => sprintf('(%s, %s)', $row['lng'], $row['lat']),
                'timestamp_utc' => now()->utc()->subMinutes($row['minutes'])->toDateTimeString(),
                'speed' => 28.0,
                'heading' => 180.0,
            ]]);

            $kits[$row['kit']] = $kit->fresh();
        }

        return $kits;
    }

    /**
     * @param  array<string, Kit>  $kits
     */
    private function seedLookups(Org $org, array $kits): void
    {
        [$client, $lienholder] = $this->ensureClientAndLienholder();
        $driversByKit = self::driversByKit();
        $scanOffsetsByKit = [];

        foreach (self::plates() as $row) {
            $kit = $kits[$row['kit']] ?? null;
            $driver = $driversByKit[$row['kit']] ?? null;

            if (! $kit || ! $driver) {
                continue;
            }

            $offset = $scanOffsetsByKit[$row['kit']] ?? 0;
            $scanOffsetsByKit[$row['kit']] = $offset + 1;

            // Spread volume across recent hours so wildcard searches stay within 30 days
            // and ordered results exercise the per-search cap.
            $scannedAt = now()->utc()
                ->subMinutes($driver['minutes'] + $offset)
                ->subHours(intdiv($offset, max(1, count($kits))));

            $hotlist = $this->seedHotlist($org, $row, $client, $lienholder);
            $scan = $this->seedScan($kit, $row, $scannedAt);
            $hit = $this->seedHit($kit, $hotlist, $scan, $scannedAt);

            $this->seedClickhouseScan($org, $kit, $scan, $row);
            $this->seedClickhouseHit($org, $kit, $hotlist, $scan, $hit, $row);
            $this->seedClickhouseHotlist($org, $hotlist, $row);
            $this->seedKitScanPath($org, $kit, $row, $scannedAt);
        }
    }

    /**
     * @return array{0: Org, 1: Org}
     */
    private function ensureClientAndLienholder(): array
    {
        $client = Org::query()->where('name', self::CLIENT_ORG_NAME)->first()
            ?? Org::factory()->create([
                'name' => self::CLIENT_ORG_NAME,
                'type' => OrgType::Client->value,
            ]);

        $lienholder = Org::query()->where('name', self::LIENHOLDER_ORG_NAME)->first()
            ?? Org::factory()->create([
                'name' => self::LIENHOLDER_ORG_NAME,
                'type' => OrgType::LienHolder->value,
            ]);

        return [$client, $lienholder];
    }

    /**
     * @param  array{ocr: string, state: string, vin: string, year: string, make: string, model: string}  $row
     */
    private function seedHotlist(Org $org, array $row, Org $client, Org $lienholder): Hotlist
    {
        $orderId = 'OTTO-'.$row['ocr'];

        $existing = Hotlist::query()
            ->where('org_id', $org->id)
            ->where('remote_order_id', $orderId)
            ->first();

        if ($existing) {
            $existing->forceFill([
                'plate' => $row['ocr'],
                'us_state' => $row['state'],
                'vin' => $row['vin'],
                'status' => HotlistStatusEnum::Open,
                'vehicle_year' => $row['year'],
                'vehicle_make' => $row['make'],
                'vehicle_model' => $row['model'],
                'client_org_id' => $client->id,
                'lienholder_org_id' => $lienholder->id,
                'is_staged' => false,
            ])->save();

            return $existing;
        }

        return Hotlist::factory()
            ->forOrg($org)
            ->forClientOrg($client)
            ->notStaged()
            ->plate($row['ocr'], $row['state'])
            ->create([
                'status' => HotlistStatusEnum::Open,
                'remote_order_id' => $orderId,
                'vin' => $row['vin'],
                'vehicle_year' => $row['year'],
                'vehicle_make' => $row['make'],
                'vehicle_model' => $row['model'],
                'lienholder_org_id' => $lienholder->id,
            ]);
    }

    /**
     * @param  array{ocr: string, state: string, lat: float, lng: float}  $row
     */
    private function seedScan(Kit $kit, array $row, Carbon $scannedAt): Scan
    {
        $scan = Scan::query()
            ->where('kit_id', $kit->id)
            ->where('ocr', $row['ocr'])
            ->where('camera_name', self::CAMERA_NAME)
            ->first();

        if ($scan) {
            $scan->forceFill([
                'us_state' => $row['state'],
                'scanned_at' => $scannedAt,
                'geo' => Point::makeGeodetic($row['lat'], $row['lng']),
            ])->save();

            return $scan;
        }

        $scan = Scan::factory()
            ->for($kit)
            ->create([
                'ocr' => $row['ocr'],
                'us_state' => $row['state'],
                'camera_name' => self::CAMERA_NAME,
                'scanned_at' => $scannedAt,
                'geo' => Point::makeGeodetic($row['lat'], $row['lng']),
            ]);

        if (! $scan->scanMetaData()->exists()) {
            ScanMetaData::factory()->for($scan)->create();
        }

        return $scan;
    }

    private function seedHit(Kit $kit, Hotlist $hotlist, Scan $scan, Carbon $scannedAt): Hit
    {
        $hit = Hit::query()->firstOrNew(['scan_id' => $scan->id]);
        $hit->forceFill([
            'hotlist_id' => $hotlist->id,
            'kit_id' => $kit->id,
            'hit_type' => HitTypeEnum::Live->value,
            'created_at' => $scannedAt,
        ])->save();

        return $hit;
    }

    /**
     * @param  array{ocr: string, state: string, lat: float, lng: float, address: string}  $row
     */
    private function seedClickhouseScan(Org $org, Kit $kit, Scan $scan, array $row): void
    {
        $payload = [
            'id' => $scan->id,
            'kit_id' => $kit->id,
            'ocr' => $row['ocr'],
            'us_state' => $row['state'],
            'camera_name' => self::CAMERA_NAME,
            'guid' => $scan->guid,
            'scanned_at' => Carbon::parse($scan->scanned_at)->toDateTimeString(),
            'created_at' => Carbon::parse($scan->scanned_at)->toDateTimeString(),
            'lng' => $row['lng'],
            'lat' => $row['lat'],
            'kit_name' => $kit->name,
            'org_id' => $org->id,
            'disposition' => null,
            'address' => $row['address'],
            '_peerdb_synced_at' => now()->toDateTimeString(),
            '_version' => time(),
            '_peerdb_is_deleted' => 0,
        ];

        if ($this->isRealClickhouse()) {
            $payload['geo'] = sprintf('(%s, %s)', $row['lng'], $row['lat']);
        }

        $this->replaceClickhouse('clarity.scans_hot', 'id', $scan->id, $payload);
        $this->replaceClickhouse('clarity.scans_cold', 'id', $scan->id, $payload);
    }

    /**
     * @param  array{ocr: string, state: string, vin: string, year: string, make: string, model: string, lat: float, lng: float, address: string}  $row
     */
    private function seedClickhouseHit(Org $org, Kit $kit, Hotlist $hotlist, Scan $scan, Hit $hit, array $row): void
    {
        $scannedAt = Carbon::parse($scan->scanned_at)->toDateTimeString();

        $payload = [
            'id' => $hit->id,
            'hotlist_id' => $hotlist->id,
            'scan_id' => $scan->id,
            'hit_type' => HitTypeEnum::Live->value,
            'hit_created_at' => $scannedAt,
            'ocr' => $row['ocr'],
            'scanned_at' => $scannedAt,
            'camera_name' => self::CAMERA_NAME,
            'scan_us_state' => $row['state'],
            'scan_guid' => $scan->guid,
            'kit_id' => $kit->id,
            'kit_name' => $kit->name,
            'kit_org_id' => $org->id,
            'hotlist_us_state' => $row['state'],
            'hotlist_plate' => $row['ocr'],
            'hotlist_status' => HotlistStatusEnum::Open->value,
            'hotlist_vin' => $row['vin'],
            'hotlist_org_id' => $org->id,
            'lat' => $row['lat'],
            'lng' => $row['lng'],
            '_peerdb_is_deleted' => 0,
            '_version' => time(),
        ];

        if ($this->isRealClickhouse()) {
            $payload = array_merge($payload, [
                'remote_order_id' => (string) $hotlist->remote_order_id,
                'integration_id' => IntegrationEnum::RDN->value,
                'client_org_id' => $hotlist->client_org_id,
                'lienholder_org_id' => $hotlist->lienholder_org_id,
                'client_org_name' => $hotlist->client?->name,
                'lienholder_org_name' => $hotlist->lienholder?->name,
                'vehicle_year' => $row['year'],
                'vehicle_make' => $row['make'],
                'vehicle_model' => $row['model'],
                'address' => $row['address'],
                'disposition' => null,
                '_peerdb_synced_at' => $scannedAt,
                '_peerdb_hotlist_synced_at' => $scannedAt,
                '_peerdb_scan_synced_at' => $scannedAt,
            ]);
        }

        $this->replaceClickhouse('clarity.hits_hot', 'id', $hit->id, $payload);
        $this->replaceClickhouse('clarity.hits_cold', 'id', $hit->id, $payload);
    }

    /**
     * Scan-derived path point at the same time/place as the seeded scan so tier-2
     * driver positions stay aligned with scan/hit rows for the same kit.
     *
     * @param  array{ocr: string, lat: float, lng: float}  $row
     */
    private function seedKitScanPath(Org $org, Kit $kit, array $row, Carbon $scannedAt): void
    {
        $timestamp = $scannedAt->toDateTimeString();

        try {
            DB::connection('clarity_clickhouse')
                ->table('clarity.kit_scan_paths')
                ->where('kit_id', $kit->id)
                ->where('timestamp', $timestamp)
                ->delete();
        } catch (Throwable) {
            // Real ClickHouse may not support DELETE.
        }

        $lng = round($row['lng'], 6);
        $lat = round($row['lat'], 6);
        $pathPoints = $this->isRealClickhouse()
            ? "[({$lng}, {$lat})]"
            : json_encode([[$lng, $lat]]);

        $this->insertClickhouse('clarity.kit_scan_paths', [[
            'kit_id' => $kit->id,
            'org_id' => $org->id,
            'timestamp' => $timestamp,
            'scan_count' => 1,
            'path_points' => $pathPoints,
            'kit_name' => $kit->name,
            '_version' => 1,
        ]]);
    }

    /**
     * @param  array{ocr: string, state: string, vin: string, year: string, make: string, model: string}  $row
     */
    private function seedClickhouseHotlist(Org $org, Hotlist $hotlist, array $row): void
    {
        $now = now()->toDateTimeString();

        $this->replaceClickhouse('clarity.hotlist', 'id', $hotlist->id, [
            'id' => $hotlist->id,
            'org_id' => $org->id,
            'integration_id' => $hotlist->integration_id ?? IntegrationEnum::RDN->value,
            'remote_order_id' => (string) $hotlist->remote_order_id,
            'client_org_id' => $hotlist->client_org_id,
            'client_org_name' => $hotlist->client?->name,
            'lienholder_org_id' => $hotlist->lienholder_org_id,
            'lienholder_org_name' => $hotlist->lienholder?->name,
            'status' => HotlistStatusEnum::Open->value,
            'order_type' => $hotlist->order_type ?? 1,
            'is_staged' => 0,
            'plate' => $row['ocr'],
            'us_state' => $row['state'],
            'vin' => $row['vin'],
            'vehicle_year' => $row['year'],
            'vehicle_make' => $row['make'],
            'vehicle_model' => $row['model'],
            'lookback_days' => $hotlist->lookback_days ?? 270,
            'created_at' => $now,
            'updated_at' => $now,
            '_peerdb_synced_at' => $now,
            '_version' => time(),
            '_peerdb_is_deleted' => 0,
        ]);
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function replaceClickhouse(string $table, string $key, mixed $id, array $row): void
    {
        try {
            $connection = DB::connection('clarity_clickhouse');
            $connection->table($table)->where($key, $id)->delete();
        } catch (Throwable) {
            // Real ClickHouse may not support DELETE; ReplacingMergeTree will take the insert.
        }

        $this->insertClickhouse($table, [$row]);
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function insertClickhouse(string $table, array $rows): void
    {
        if ($rows === []) {
            return;
        }

        try {
            DB::connection('clarity_clickhouse')->table($table)->insert($rows);
        } catch (Throwable $exception) {
            $this->command?->warn("OttoSeeder skipped {$table}: ".$exception->getMessage());
        }
    }

    private function isRealClickhouse(): bool
    {
        return config('database.connections.clarity_clickhouse.driver') !== 'pgsql';
    }

    /**
     * @return array<string, Zone>
     */
    private function seedHeroZones(Org $org, User $admin): array
    {
        $maria = User::query()->where('email', self::MARIA_EMAIL)->first();
        $james = User::query()->where('email', 'james.chen@tester.com')->first();
        $priya = User::query()->where('email', 'priya.patel@tester.com')->first();

        $specs = [
            [
                'name' => self::ZONE_NORTH,
                'zips' => ['75206', '75214'],
                'lng' => -96.770,
                'lat' => 32.821,
                'color' => '#0d9488',
                'no_scan' => false,
                'user_ids' => array_values(array_filter([(int) ($priya?->id ?? 0)])),
                'created_at' => now()->subDays(3),
            ],
            [
                'name' => self::ZONE_DFW,
                'zips' => ['75261'],
                'lng' => -97.040,
                'lat' => 32.899,
                'color' => Zone::NO_SCAN_COLOR,
                'no_scan' => true,
                'user_ids' => [],
                'created_at' => now()->subDays(2),
            ],
            [
                'name' => self::ZONE_DOWNTOWN,
                'zips' => ['75201', '75202'],
                'lng' => -96.797,
                'lat' => 32.7767,
                'color' => Zone::DEFAULT_COLOR,
                'no_scan' => false,
                'user_ids' => array_values(array_filter([
                    (int) ($maria?->id ?? 0),
                    (int) ($james?->id ?? 0),
                ])),
                'created_at' => now()->subDay(),
            ],
        ];

        $zones = [];

        foreach ($specs as $spec) {
            $zone = Zone::query()->firstOrNew([
                'org_id' => $org->id,
                'name' => $spec['name'],
            ]);

            $zone->forceFill([
                'description' => null,
                'color' => $spec['no_scan'] ? Zone::NO_SCAN_COLOR : $spec['color'],
                'is_no_scan_zone' => $spec['no_scan'],
                'definition' => [
                    'polygons' => [ZoneFactory::squarePolygon($spec['lng'], $spec['lat'], 0.03)],
                    'zip_codes' => $spec['zips'],
                ],
                'created_by_user_id' => $admin->id,
                'created_at' => $spec['created_at'],
                'updated_at' => $spec['created_at'],
            ])->save();

            $zone->rebuildGeometryFromDefinition();
            $zone->users()->sync($spec['user_ids']);

            $zones[$spec['name']] = $zone->fresh(['users']) ?? $zone;
        }

        return $zones;
    }

    /**
     * @param  array<string, Zone>  $zones
     */
    private function seedZoneConversations(Org $org, User $admin, array $zones): void
    {
        $maria = User::query()->where('email', self::MARIA_EMAIL)->first();

        if ($maria) {
            setPermissionsTeamId($org->id);
            if (! $maria->hasRole(RoleEnum::USER->value) && ! $maria->hasRole(RoleEnum::ADMIN->value)) {
                $maria->assignRole(RoleEnum::USER->value);
            }
            $maria->givePermissionTo(['use-otto', 'clarity-scout']);
        }

        $downtown = $zones[self::ZONE_DOWNTOWN] ?? null;
        $dfw = $zones[self::ZONE_DFW] ?? null;
        $north = $zones[self::ZONE_NORTH] ?? null;

        if (! $downtown || ! $dfw || ! $north) {
            $this->command?->warn('OttoSeeder skipped zone conversations: hero zones missing.');

            return;
        }

        $this->seedZoneThread(
            $admin,
            'list',
            'What zones do we have?',
            'Here are the 3 most recent zones for Test Company. Two are patrol areas and one is a no-scan zone around the airport. Downtown Dallas has two drivers assigned. Open a row on the Zones map if you want to see the shape.',
            'Zone search',
            'zonelist0001',
            [$downtown, $dfw, $north],
            now()->subMinutes(6),
        );

        $this->seedZoneThread(
            $admin,
            'maria',
            'What zones is Maria covering?',
            'Here is the most recent zone assigned to Maria Garcia. Downtown Dallas is a patrol area for ZIPs 75201 and 75202. She shares it with James Chen.',
            'Zone search: assigned to Maria',
            'zonemaria001',
            [$downtown],
            now()->subMinutes(5),
        );

        $this->seedZoneThread(
            $admin,
            'noscan',
            'Show me our no-scan zones',
            'Here is the most recent no-scan zone. DFW Airport is marked off-limits and shows in gray on driver maps. Nobody is assigned to patrol it.',
            'Zone search: no-scan',
            'zonenoscan01',
            [$dfw],
            now()->subMinutes(4),
        );

        $createAction = new PageAction(
            pageType: ProposeCreateZoneTool::PAGE_TYPE,
            name: ProposeCreateZoneTool::ACTION_CREATE,
            summary: 'Create zone DFW Airport',
            changes: [
                new PageActionChange('name', 'Name', PageActionCopy::zoneName(self::ZONE_DFW), self::ZONE_DFW),
                new PageActionChange('color', 'Color', PageActionCopy::zoneColor(Zone::NO_SCAN_COLOR), Zone::NO_SCAN_COLOR),
                new PageActionChange('is_no_scan_zone', 'No-scan', PageActionCopy::zoneNoScan(true), true),
                new PageActionChange('zip_codes', 'ZIP codes', PageActionCopy::zoneZipCodes(['75261']), ['75261']),
            ],
        );

        $this->seedZoneThread(
            $admin,
            'create-zip',
            'Create a no-scan zone for ZIP 75261 called DFW Airport',
            'Suggested creating this zone. Review the card — nothing is saved until you Accept.',
            null,
            null,
            [],
            now()->subMinutes(3),
            [$createAction],
        );

        $drawAction = new PageAction(
            pageType: ProposeCreateZoneTool::PAGE_TYPE,
            name: ProposeCreateZoneTool::ACTION_OPEN_EDITOR,
            summary: 'Open the Zones map to draw Downtown lots',
            changes: [
                new PageActionChange('name', 'Name', PageActionCopy::zoneName('Downtown lots'), 'Downtown lots'),
                new PageActionChange('color', 'Color', PageActionCopy::zoneColor(Zone::DEFAULT_COLOR), Zone::DEFAULT_COLOR),
                new PageActionChange('is_no_scan_zone', 'No-scan', PageActionCopy::zoneNoScan(false), false),
            ],
        );

        $this->seedZoneThread(
            $admin,
            'draw',
            'Make a patrol zone around the downtown lots — I’ll draw it on the map',
            'I can start a zone named Downtown lots and open the map editor so you can draw the shape. Nothing is saved until you click Save on that page. I will not invent a polygon from chat.',
            null,
            null,
            [],
            now()->subMinutes(2),
            [$drawAction],
        );

        $this->seedZoneThread(
            $admin,
            'no-scout',
            'What zones do we have?',
            OttoScoutAccess::ZONE_NEEDS_SCOUT,
            null,
            null,
            [],
            now()->subMinutes(1),
            title: 'Zone search without Scout',
        );

        if ($maria) {
            $this->seedZoneThread(
                $maria,
                'not-admin',
                'Create a zone for 75201',
                OttoScoutAccess::ZONE_NEEDS_ADMIN,
                null,
                null,
                [],
                now(),
                title: 'Create a zone (not an admin)',
            );
        }
    }

    /**
     * @param  list<Zone>  $resultZones
     * @param  list<PageAction>  $pageActions
     */
    private function seedZoneThread(
        User $user,
        string $key,
        string $prompt,
        string $reply,
        ?string $searchTitle,
        ?string $searchId,
        array $resultZones,
        Carbon $at,
        array $pageActions = [],
        ?string $title = null,
    ): void {
        $conversationId = self::ZONE_CONVERSATION_IDS[$key];
        $userMessageId = str_replace('00000000000', '11111111111', $conversationId);
        $assistantMessageId = str_replace('00000000000', '22222222222', $conversationId);

        $conversation = AgentConversation::query()->firstOrNew(['id' => $conversationId]);
        $conversation->forceFill([
            'user_id' => $user->id,
            'title' => $title ?? Str::limit($prompt, 60),
            'updated_at' => $at,
            'created_at' => $at,
        ])->save();

        ConversationMessage::query()->where('conversation_id', $conversationId)->delete();

        ConversationMessage::query()->create([
            'id' => $userMessageId,
            'conversation_id' => $conversationId,
            'user_id' => $user->id,
            'agent' => Otto::class,
            'role' => 'user',
            'content' => $prompt,
            'attachments' => [],
            'tool_calls' => [],
            'tool_results' => [],
            'usage' => [],
            'meta' => [],
            'created_at' => $at,
            'updated_at' => $at,
        ]);

        $content = $reply;
        if ($searchId !== null) {
            $content .= "\n\n<!--otto_table:zone:{$searchId}-->";
        }

        $meta = [];
        if ($pageActions !== []) {
            $meta['page_actions'] = array_map(
                fn (PageAction $action): array => $action->toArray(),
                $pageActions,
            );
        }

        ConversationMessage::query()->create([
            'id' => $assistantMessageId,
            'conversation_id' => $conversationId,
            'user_id' => $user->id,
            'agent' => Otto::class,
            'role' => 'assistant',
            'content' => $content,
            'attachments' => [],
            'tool_calls' => [],
            'tool_results' => [],
            'usage' => [],
            'meta' => $meta === [] ? [] : $meta,
            'created_at' => $at->copy()->addSecond(),
            'updated_at' => $at->copy()->addSecond(),
        ]);

        if ($searchId === null) {
            AgentInvestigation::query()
                ->where('user_id', $user->id)
                ->where('conversation_id', $conversationId)
                ->delete();

            return;
        }

        $searches = [
            new Search(
                searchId: $searchId,
                messageId: $assistantMessageId,
                parameters: [],
                searchType: SearchType::Zone,
                title: (string) $searchTitle,
                createdBy: $user->id,
                conversationId: $conversationId,
            ),
        ];

        $results = [];
        foreach ($resultZones as $zone) {
            $results[] = new SearchResult(searchId: $searchId, resultId: (int) $zone->id);
        }

        AgentInvestigation::query()->updateOrCreate(
            [
                'user_id' => $user->id,
                'conversation_id' => $conversationId,
            ],
            [
                'properties' => new AgentInvestigationProperties(
                    searches: $searches,
                    searchResults: $results,
                ),
            ],
        );
    }
}
