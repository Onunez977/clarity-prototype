<?php

declare(strict_types=1);

/**
 * Isolated-cloud / local seed for DEV-2521 Playwright.
 *
 * Ensures:
 * - Pro app-panel email (default test@tester.com)
 * - Existing but panel-ineligible user (qa-ineligible@tester.com)
 * - Client-org user eligible for /client (client@tester.com)
 *
 * Refuses to run when app()->isProduction(). Prints one JSON object on stdout.
 */

use App\Models\ClientOrgAccess;
use App\Models\Org;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Panel;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use InsightLPR\BlackAdam\Enum\OrgType;

$root = dirname(__DIR__, 3);

require $root.'/vendor/autoload.php';

$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (app()->isProduction()) {
    fwrite(STDERR, "DEV-2521 seed refuses to run in production.\n");
    exit(1);
}

$adminEmail = getenv('E2E_ADMIN_EMAIL') ?: 'test@tester.com';
$ineligibleEmail = getenv('E2E_INELIGIBLE_EMAIL') ?: 'qa-ineligible@tester.com';
$clientEmail = getenv('E2E_CLIENT_EMAIL') ?: 'client@tester.com';

advanceUsersSequence();

$appPanel = Filament::getPanel('app');
$clientPanel = Filament::getPanel('client');

$admin = ensureAppEligible($adminEmail, $appPanel);
$ineligible = ensureIneligible($ineligibleEmail, $appPanel, $clientPanel);
$client = ensureClientEligible($clientEmail, $clientPanel);

echo json_encode([
    'adminEmail' => $admin?->email,
    'ineligibleEmail' => $ineligible?->email,
    'clientEmail' => $client?->email,
    'adminCanAccessApp' => $admin ? $admin->canAccessPanel($appPanel) : false,
    'ineligibleCanAccessAny' => $ineligible
        ? ($ineligible->canAccessPanel($appPanel) || $ineligible->canAccessPanel($clientPanel))
        : false,
    'clientCanAccessClient' => $client ? $client->canAccessPanel($clientPanel) : false,
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

function ensureAppEligible(string $email, Panel $appPanel): ?User
{
    $user = User::query()->where('email', $email)->first();

    if ($user) {
        if (! $user->canAccessPanel($appPanel) && $user->org) {
            $user->org->update([
                'type' => OrgType::Repo->value,
                'enable_genetech_hotlist' => true,
            ]);
            $user = $user->fresh();
        }

        return $user;
    }

    $org = Org::query()->where('name', 'Test Company')->first()
        ?? Org::factory()->create([
            'name' => 'Test Company',
            'type' => OrgType::Repo->value,
            'enable_genetech_hotlist' => true,
        ]);

    return User::factory()->forOrg($org)->create([
        'email' => $email,
        'name' => 'Admin',
    ]);
}

function ensureIneligible(string $email, Panel $appPanel, Panel $clientPanel): User
{
    $user = User::query()->where('email', $email)->first();

    if ($user) {
        $needsMove = ! $user->org
            || $user->canAccessPanel($appPanel)
            || $user->canAccessPanel($clientPanel);

        if ($needsMove) {
            $org = Org::factory()->create([
                'name' => 'DEV-2521 Ineligible Org',
                'type' => OrgType::Repo->value,
                'enable_genetech_hotlist' => false,
                'enable_matrix_hotlist' => false,
            ]);
            $user->update(['org_id' => $org->id]);
            $user = $user->fresh();
        }

        return $user;
    }

    $org = Org::factory()->create([
        'name' => 'DEV-2521 Ineligible Org',
        'type' => OrgType::Repo->value,
        'enable_genetech_hotlist' => false,
        'enable_matrix_hotlist' => false,
    ]);

    return User::factory()->forOrg($org)->create([
        'email' => $email,
        'name' => 'DEV-2521 Ineligible',
    ]);
}

function ensureClientEligible(string $email, Panel $clientPanel): User
{
    $user = User::query()->where('email', $email)->first();

    if ($user && $user->canAccessPanel($clientPanel)) {
        return $user;
    }

    $clientOrg = ($user?->org && $user->org->isClient())
        ? $user->org
        : Org::factory()->client()->create([
            'name' => 'DEV-2521 Client Org',
        ]);

    if ($user) {
        if ($user->org_id !== $clientOrg->id) {
            $user->update(['org_id' => $clientOrg->id]);
        }
    } else {
        $user = User::factory()->forOrg($clientOrg)->create([
            'email' => $email,
            'name' => 'DEV-2521 Client',
        ]);
    }

    $user = $user->fresh();

    if ($user && $user->accessible_repo_org_ids === []) {
        $repo = Org::query()
            ->where('type', OrgType::Repo->value)
            ->where('enable_genetech_hotlist', true)
            ->first()
            ?? Org::factory()->create([
                'type' => OrgType::Repo->value,
                'enable_genetech_hotlist' => true,
            ]);

        ClientOrgAccess::factory()->create([
            'client_org_id' => $clientOrg->id,
            'repo_org_id' => $repo->id,
        ]);

        $user = $user->fresh();
    }

    return $user;
}
