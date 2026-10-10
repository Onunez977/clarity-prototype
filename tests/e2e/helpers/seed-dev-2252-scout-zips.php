<?php

declare(strict_types=1);

/**
 * Isolated-cloud / local seed for DEV-2252 Playwright (Scout ZIP zone creation).
 *
 * Ensures:
 * - ADMIN user with Feature CLARITY_SCOUT (explicit grant) on a repo org
 * - ADMIN on a Pro org with CLARITY_SCOUT explicitly off (negative nav)
 * - Non-admin on the Scout org (ZonePolicy deny)
 * - tiger_data.zcta5_all fixtures on the shared zod/platform database:
 *     75201 / 75202  resolvable MultiPolygon (NAD83 / SRID 4269)
 *     75203          row exists, the_geom NULL (schema-permitted)
 *     75209 / 99999  never inserted — guaranteed no-boundary tokens
 *
 * Idempotent. Refuses to run when app()->isProduction(). Touches only the
 * bootstrapped app database (local `zod` / isolated-cloud compose). Prints
 * one JSON object on stdout. Extra argv:
 *   zone-by-name {name}  — look up an e2e zone (id, zips, hasGeom)
 */

use App\Enums\FeatureEnum;
use App\Filament\Pages\ManageZones;
use App\Models\Org;
use App\Models\User;
use App\Models\Zone;
use Filament\Facades\Filament;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
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
    fwrite(STDERR, "DEV-2252 seed refuses to run in production.\n");
    exit(1);
}

$command = $argv[1] ?? 'seed';

if ($command === 'zone-by-name') {
    $name = $argv[2] ?? '';
    echo json_encode(lookupZoneByName($name), JSON_THROW_ON_ERROR).PHP_EOL;
    exit(0);
}

advanceUsersSequence();

if (! Role::query()->where('name', RoleEnum::ADMIN->value)->where('guard_name', 'web')->exists()) {
    Artisan::call('app:seed-permissions');
}

Permission::findOrCreate('clarity-scout', 'web');

ensureSpatialRefSys4269();

$validZip = '75201';
$secondValidZip = '75202';
$nullGeomZip = '75203';
$missingZip = tigerZipExists('75209') ? '99999' : '75209';

ensureTigerZip($validZip, 'POLYGON((-96.81 32.78, -96.80 32.78, -96.80 32.79, -96.81 32.79, -96.81 32.78))');
ensureTigerZip($secondValidZip, 'POLYGON((-96.80 32.78, -96.79 32.78, -96.79 32.79, -96.80 32.79, -96.80 32.78))');
ensureTigerZipNull($nullGeomZip);

Zone::query()->where('name', 'like', 'DEV-2252 e2e%')->delete();

$adminEmail = getenv('E2E_ADMIN_EMAIL') ?: 'qa-dev-2252-scout-admin@tester.com';
$noScoutEmail = getenv('E2E_NO_SCOUT_EMAIL') ?: 'qa-dev-2252-no-scout@tester.com';
$nonAdminEmail = getenv('E2E_NON_ADMIN_EMAIL') ?: 'qa-dev-2252-non-admin@tester.com';

$scoutOrg = ensureOrg('DEV-2252 Scout Org', scoutGrant: true);
$noScoutOrg = ensureOrg('DEV-2252 No Scout Org', scoutGrant: false);

$admin = ensureAdmin($adminEmail, $scoutOrg, 'DEV-2252 Scout Admin');
$noScoutAdmin = ensureAdmin($noScoutEmail, $noScoutOrg, 'DEV-2252 No Scout Admin');
$nonAdmin = ensurePlainUser($nonAdminEmail, $scoutOrg, 'DEV-2252 Non Admin');

Feature::flushCache();

$appPanel = Filament::getPanel('app');
$experimental = (bool) config('app.experimental.clarity_scout', false);

Auth::login($admin);
$adminCanAccess = ManageZones::canAccess();
Auth::logout();

Auth::login($noScoutAdmin);
$noScoutCanAccess = ManageZones::canAccess();
Auth::logout();

Auth::login($nonAdmin);
$nonAdminCanAccess = ManageZones::canAccess();
Auth::logout();

echo json_encode([
    'adminEmail' => $admin->email,
    'adminUserId' => $admin->id,
    'adminCanAccessApp' => $admin->canAccessPanel($appPanel),
    'adminCanAccessManageZones' => $adminCanAccess,
    'noScoutEmail' => $noScoutAdmin->email,
    'noScoutUserId' => $noScoutAdmin->id,
    'noScoutCanAccessManageZones' => $noScoutCanAccess,
    'nonAdminEmail' => $nonAdmin->email,
    'nonAdminUserId' => $nonAdmin->id,
    'nonAdminCanAccessManageZones' => $nonAdminCanAccess,
    'experimentalClarityScout' => $experimental,
    'validZip' => $validZip,
    'secondValidZip' => $secondValidZip,
    'nullGeomZip' => $nullGeomZip,
    'missingZip' => $missingZip,
    'fragmentZip' => '7520',
    'tiger75201' => tigerZipExists($validZip),
    'tiger75209' => tigerZipExists('75209'),
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

function ensureOrg(string $name, bool $scoutGrant): Org
{
    $org = Org::query()->where('name', $name)->first()
        ?? Org::factory()->create([
            'name' => $name,
            'type' => OrgType::Repo->value,
            'enable_genetech_hotlist' => true,
        ]);

    $props = [
        'platform_repo_pro' => true,
        FeatureEnum::CLARITY_SCOUT->value => $scoutGrant,
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

function ensurePlainUser(string $email, Org $org, string $name): User
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

    if ($user->hasRole(RoleEnum::ADMIN->value)) {
        $user->removeRole(RoleEnum::ADMIN->value);
    }

    return $user->fresh();
}

function ensureSpatialRefSys4269(): void
{
    DB::connection('zod')->statement(
        "INSERT INTO spatial_ref_sys (srid, auth_name, auth_srid, srtext, proj4text)
         VALUES (4269, 'EPSG', 4269,
                 'GEOGCS[\"NAD83\",DATUM[\"North_American_Datum_1983\",SPHEROID[\"GRS 1980\",6378137,298.257222101,AUTHORITY[\"EPSG\",\"7019\"]],TOWGS84[0,0,0,0,0,0,0],AUTHORITY[\"EPSG\",\"6269\"]],PRIMEM[\"Greenwich\",0,AUTHORITY[\"EPSG\",\"8901\"]],UNIT[\"degree\",0.0174532925199433,AUTHORITY[\"EPSG\",\"9122\"]],AUTHORITY[\"EPSG\",\"4269\"]]',
                 '+proj=longlat +datum=NAD83 +no_defs')
         ON CONFLICT (srid) DO NOTHING"
    );
}

function tigerZipExists(string $zip): bool
{
    try {
        return DB::connection('zod')
            ->table('tiger_data.zcta5_all')
            ->where('zcta5ce', $zip)
            ->exists();
    } catch (Throwable) {
        return false;
    }
}

function ensureTigerZip(string $zip, string $wkt): void
{
    if (tigerZipExists($zip)) {
        return;
    }

    DB::connection('zod')->statement(
        'INSERT INTO tiger_data.zcta5_all (zcta5ce, statefp, the_geom)
         VALUES (?, ?, ST_Multi(ST_GeomFromText(?::text, 4269)))',
        [$zip, '48', $wkt],
    );
}

function ensureTigerZipNull(string $zip): void
{
    if (tigerZipExists($zip)) {
        return;
    }

    DB::connection('zod')->statement(
        'INSERT INTO tiger_data.zcta5_all (zcta5ce, statefp, the_geom)
         VALUES (?, ?, NULL)',
        [$zip, '48'],
    );
}

/**
 * @return array{found: bool, id: ?int, name: ?string, zipCodes: list<string>, hasGeom: bool, definition: ?array}
 */
function lookupZoneByName(string $name): array
{
    if ($name === '') {
        return [
            'found' => false,
            'id' => null,
            'name' => null,
            'zipCodes' => [],
            'hasGeom' => false,
            'definition' => null,
        ];
    }

    $row = DB::connection('platform')
        ->table('zones')
        ->where('name', $name)
        ->whereNull('deleted_at')
        ->selectRaw('id, name, definition, ((geom IS NOT NULL) AND NOT public.ST_IsEmpty(geom))::int AS has_geom')
        ->first();

    if (! $row) {
        return [
            'found' => false,
            'id' => null,
            'name' => $name,
            'zipCodes' => [],
            'hasGeom' => false,
            'definition' => null,
        ];
    }

    $definition = is_string($row->definition)
        ? json_decode($row->definition, true)
        : (array) $row->definition;

    return [
        'found' => true,
        'id' => (int) $row->id,
        'name' => (string) $row->name,
        'zipCodes' => array_values(array_map('strval', $definition['zip_codes'] ?? [])),
        'hasGeom' => (int) $row->has_geom === 1,
        'definition' => $definition,
    ];
}
