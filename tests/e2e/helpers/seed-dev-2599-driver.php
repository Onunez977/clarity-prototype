<?php

declare(strict_types=1);

/**
 * DEV-2599 Playwright helper: grant the seeded driver Clarity Scout access
 * so the shared MapShell can be opened through the real /scout login form.
 *
 * Refuses production. Prints one JSON object on stdout.
 */

use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Spatie\Permission\Models\Permission;

$root = dirname(__DIR__, 3);

require $root.'/vendor/autoload.php';

$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (app()->isProduction()) {
    fwrite(STDERR, "DEV-2599 driver seed refuses to run in production.\n");
    exit(1);
}

$email = 'qa-dev-2597-driver@tester.com';
$user = User::query()->where('email', $email)->first();

if ($user === null) {
    fwrite(STDERR, "Driver {$email} is missing. Run seed-dev-2597-org-map-hits.php users first.\n");
    exit(1);
}

setPermissionsTeamId($user->org_id);
Permission::findOrCreate('clarity-scout', 'web');
$user->givePermissionTo('clarity-scout');

echo json_encode([
    'email' => $user->email,
    'id' => $user->id,
    'kitId' => $user->kit_id,
], JSON_THROW_ON_ERROR).PHP_EOL;
