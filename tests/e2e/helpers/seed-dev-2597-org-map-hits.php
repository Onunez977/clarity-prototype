<?php

declare(strict_types=1);

/**
 * Isolated-cloud / local seed for DEV-2597 Playwright (Org Map Live Hits).
 *
 * Mirrors Feature BirdsEye / Map CH factories:
 * - ADMIN + Feature CLARITY_SCOUT on a dedicated repo org
 * - one enrolled kit (Kit + forKit user / kit_settings)
 * - clarity.hotlist OPEN + N clarity.hits_hot Live rows (plate prefix D2597)
 *
 * Modes (argv[1]):
 *   users          — org/admin/kit only
 *   hits {count}   — replace this org's D2597 hits with {count} open Live rows
 *   seed           — users + 101 hits (over-cap / BTS default)
 *
 * Idempotent. Refuses production. Prints one JSON object on stdout.
 */

use App\Enums\FeatureEnum;
use App\Enums\HotlistStatusEnum;
use App\Models\Kit;
use App\Models\Org;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use InsightLPR\BlackAdam\Enum\OrgType;
use InsightLPR\BlackAdam\Enum\RoleEnum;
use Laravel\Pennant\Feature;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

$root = dirname(__DIR__, 3);

require $root.'/vendor/autoload.php';

$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (app()->isProduction()) {
    fwrite(STDERR, "DEV-2597 seed refuses to run in production.\n");
    exit(1);
}

$command = $argv[1] ?? 'seed';
$hitCount = isset($argv[2]) ? max(0, (int) $argv[2]) : 101;

if ($command === 'hits') {
    // keep default $hitCount from argv[2]
} elseif ($command === 'users') {
    $hitCount = null;
} elseif ($command === 'seed') {
    $hitCount = 101;
} else {
    fwrite(STDERR, "Unknown mode {$command}. Use seed|users|hits {count}.\n");
    exit(1);
}

advanceUsersSequence();

if (! Role::query()->where('name', RoleEnum::ADMIN->value)->where('guard_name', 'web')->exists()) {
    Artisan::call('app:seed-permissions');
}

Permission::findOrCreate('clarity-scout', 'web');

$adminEmail = getenv('E2E_ADMIN_EMAIL') ?: 'qa-dev-2597-admin@tester.com';

$org = ensureOrg('DEV-2597 Org Map Hits');
$admin = ensureAdmin($adminEmail, $org, 'DEV-2597 Scout Admin');
$kit = ensureKit($org, 'DEV-2597 Kit');

Feature::flushCache();

$hotlistId = 92597;
ensureHotlist($hotlistId, $org->id);

$seededHits = null;
if ($hitCount !== null) {
    $seededHits = seedHits($org, $kit, $hotlistId, $hitCount);
}

Cache::flush();
Feature::flushCache();

echo json_encode([
    'adminEmail' => $admin->email,
    'adminUserId' => $admin->id,
    'orgId' => $org->id,
    'kitId' => $kit->id,
    'hotlistId' => $hotlistId,
    'hitCount' => $seededHits,
    'platePrefix' => 'D2597',
    'experimentalClarityScout' => (bool) config('app.experimental.clarity_scout', false),
    'maxMapHits' => 100,
], JSON_THROW_ON_ERROR).PHP_EOL;

function advanceUsersSequence(): void
{
    $maxId = (int) (User::withTrashed()->max('id') ?? 0);

    if ($maxId < 1) {
        return;
    }

    DB::connection('platform')->statement(
        'SELECT setval(pg_get_serial_sequence(\'users\', \'id\'), ?)',
        [$maxId],
    );
}

function ensureOrg(string $name): Org
{
    $org = Org::query()->where('name', $name)->first()
        ?? Org::factory()->create([
            'name' => $name,
            'type' => OrgType::Repo->value,
            'enable_genetech_hotlist' => true,
        ]);

    $props = [
        'platform_repo_pro' => true,
        FeatureEnum::CLARITY_SCOUT->value => true,
    ];

    if ($org->meta) {
        $org->meta->updateProperties($props);
    } else {
        $org->meta()->create(['properties' => $props]);
    }

    cache()->forget('org_pro_enabled_'.$org->id);
    cache()->forget('org_pro_features_'.$org->id);

    return $org->fresh(['meta']);
}

function ensureAdmin(string $email, Org $org, string $name): User
{
    $user = User::query()->where('email', $email)->first();

    if (! $user) {
        $user = User::factory()->forOrg($org)->create([
            'email' => $email,
            'name' => $name,
        ]);
    } elseif ((int) $user->org_id !== (int) $org->id) {
        $user->update(['org_id' => $org->id]);
        $user = $user->fresh();
    }

    setPermissionsTeamId($org->id);
    $user->assignRole(RoleEnum::ADMIN->value);

    return $user->fresh();
}

function ensureKit(Org $org, string $name): Kit
{
    $kit = Kit::query()->where('org_id', $org->id)->where('name', $name)->first();

    if (! $kit) {
        $kit = Kit::factory()->forOrg($org)->create(['name' => $name]);
        User::factory()->forOrg($org)->forKit($kit)->create([
            'email' => 'qa-dev-2597-driver@tester.com',
            'name' => 'DEV-2597 Driver',
        ]);
    }

    return $kit;
}

function ensureHotlist(int $id, int $orgId): void
{
    DB::connection('clarity_clickhouse')->statement(
        "ALTER TABLE clarity.hotlist DELETE WHERE id = {$id}",
    );

    DB::connection('clarity_clickhouse')->table('clarity.hotlist')->insert([
        'id' => $id,
        'org_id' => $orgId,
        'status' => HotlistStatusEnum::Open->value,
        'order_type' => 1,
        'is_staged' => false,
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
        '_peerdb_synced_at' => now()->toDateTimeString(),
        '_version' => 1,
        '_peerdb_is_deleted' => 0,
    ]);
}

/**
 * @return int seeded row count
 */
function seedHits(Org $org, Kit $kit, int $hotlistId, int $count): int
{
    $orgId = (int) $org->id;
    $kitId = (int) $kit->id;

    DB::connection('clarity_clickhouse')->statement(
        "ALTER TABLE clarity.hits_hot DELETE WHERE kit_org_id = {$orgId} AND ocr LIKE 'D2597%'",
    );

    if ($count === 0) {
        return 0;
    }

    $now = Carbon::now('UTC');
    $rows = [];

    for ($i = 0; $i < $count; $i++) {
        $at = $now->copy()->subMinutes($i)->toDateTimeString();
        $rows[] = [
            'id' => 92597000 + $i,
            'hotlist_id' => $hotlistId,
            'scan_id' => 92597000 + $i,
            'hit_type' => 'L',
            'hit_created_at' => $at,
            'ocr' => sprintf('D2597%03d', $i),
            'scanned_at' => $at,
            'camera_name' => 'CAM-2597',
            'scan_us_state' => 'TX',
            'kit_id' => $kitId,
            'kit_name' => $kit->name ?? 'DEV-2597 Kit',
            'kit_org_id' => $orgId,
            'hotlist_org_id' => $orgId,
            'hotlist_status' => HotlistStatusEnum::Open->value,
            'lat' => 32.7767 + ($i * 0.0001),
            'lng' => -96.7970 - ($i * 0.0001),
            'vehicle_year' => '2021',
            'vehicle_make' => 'Toyota',
            'vehicle_model' => 'Camry',
            'hotlist_us_state' => 'TX',
            '_peerdb_is_deleted' => 0,
            '_version' => 1,
        ];
    }

    foreach (array_chunk($rows, 50) as $chunk) {
        DB::connection('clarity_clickhouse')->table('clarity.hits_hot')->insert($chunk);
    }

    return $count;
}
