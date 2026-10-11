<?php

namespace Tests;

use App\Enums\FeatureEnum;
use App\Enums\ProTierEnum;
use App\Models\ClientOrgAccess;
use App\Models\Org;
use App\Models\User;
use Filament\Support\Concerns\EvaluatesClosures;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Artisan;
use InsightLPR\BlackAdam\Enum\RoleEnum;
use Laravel\Pennant\Feature;
use Tests\Support\HasZodDatabase;

abstract class TestCase extends BaseTestCase
{
    use EvaluatesClosures;
    use HasZodDatabase;
    use RefreshDatabase;

    protected function connectionsToTransact(): array
    {
        return ['zod', 'platform'];
    }

    /**
     * Abort before migrate/truncate if PHPUnit loaded the local QA database.
     * Missing `.env.testing` previously pointed tests at `zod` and wiped `/__qa/login`.
     */
    protected function beforeRefreshingDatabase(): void
    {
        foreach (['platform', 'zod'] as $connection) {
            $database = (string) config("database.connections.{$connection}.database");

            if ($database === '' || $database === 'zod' || ! str_contains($database, 'testing')) {
                throw new \RuntimeException(
                    "Refusing to run tests against {$connection} database '{$database}'. Use .env.testing with DB_DATABASE=zod_testing (or a zod_testing_* isolate)."
                );
            }
        }
    }

    protected function setUp(): void
    {
        date_default_timezone_set('UTC');

        parent::setUp();

        // Seed timezones.
        // TODO

        Model::unguard();

        Feature::flushCache();

        $this->withoutVite();
    }

    /**
     * Runs once per process, immediately after the database is migrated and
     * before the per-test transaction opens — so the seeded roles/permissions
     * are committed and persist across every test instead of being re-seeded
     * (and cache-flushed) in every setUp().
     */
    protected function afterRefreshingDatabase(): void
    {
        Artisan::call('app:seed-permissions');
    }

    public function createUser(array $attributes = [], mixed $role = null, mixed $permissions = null, mixed $pro = false): User
    {
        $org = Org::factory()->create();

        if ($pro) {
            $this->makeOrgPro($org);
        }

        $user = User::factory()
            ->forOrg($org)
            ->create($attributes);

        setPermissionsTeamId($user->org_id);

        if ($role) {
            $user->assignRole($role);
        }

        if ($permissions) {
            $user->syncPermissions($permissions);
        }

        return $user;
    }

    public function createClientPortalUser(
        array $attributes = [],
        mixed $role = null,
        bool $portalEnabled = true,
        bool $withAccessGrant = true,
    ): User {
        $clientOrg = Org::factory()->client()->create();

        if (! $portalEnabled) {
            $clientOrg->meta->updateProperties(['enable_client_portal' => false]);
            $clientOrg->refresh();
            $clientOrg->load('meta');
        }

        if ($withAccessGrant) {
            ClientOrgAccess::factory()->create([
                'client_org_id' => $clientOrg->id,
                'repo_org_id' => Org::factory()->create()->id,
            ]);
        }

        $user = User::factory()
            ->forOrg($clientOrg)
            ->create($attributes);

        setPermissionsTeamId($user->org_id);

        if ($role) {
            $user->assignRole($role);
        }

        return $user;
    }

    public function makeOrgPro(Org $org, ?string $feature = null): void
    {
        // ClickHouse is opt-in and should not be enabled by default when making org pro
        $excludedFeatures = [FeatureEnum::CLICKHOUSE_SCANS->value];

        $features = collect(FeatureEnum::values())
            ->when($feature, fn ($collection) => $collection->filter(fn ($enum) => $enum === $feature))
            ->when(! $feature, fn ($collection) => $collection->reject(fn ($enum) => in_array($enum, $excludedFeatures)))
            ->mapWithKeys(fn ($feature) => [$feature => true])
            ->toArray();

        $org->meta()->create([
            'properties' => \array_merge([
                'platform_repo_pro' => true,
            ], $features),
        ]);

        $org->refresh();
    }

    public function makeUserPro(User $user, ?string $feature = null): void
    {
        // ClickHouse is opt-in and should not be enabled by default when making user pro
        $excludedFeatures = [FeatureEnum::CLICKHOUSE_SCANS->value];

        $user->removeRole(RoleEnum::CLIENT->value);

        $features = collect(FeatureEnum::values())
            ->when($feature, fn ($collection) => $collection->filter(fn ($enum) => $enum === $feature))
            ->when(! $feature, fn ($collection) => $collection->reject(fn ($enum) => in_array($enum, $excludedFeatures)))
            ->mapWithKeys(fn ($feature) => [$feature => true])
            ->toArray();

        $user->org->meta()->create([
            'properties' => \array_merge([
                'platform_repo_pro' => true,
            ], $features),
        ]);

        cache()->forget('org_pro_enabled_'.$user->org_id);
        cache()->forget('org_pro_features_'.$user->org_id);

        $user->refresh();
        $user->load('org.meta');
    }

    /**
     * Put an organization on a Clarity Pro tier. Otto qualifies at Pro Lite and above.
     */
    public function setOrgProTier(Org $org, ProTierEnum $tier = ProTierEnum::PRO_LITE): void
    {
        $meta = $org->meta()->firstOrCreate(
            ['id' => $org->id],
            ['properties' => []],
        );

        $meta->updateProperties([
            'platform_repo_pro' => true,
            'platform_repo_pro_tier' => $tier->value,
        ]);

        cache()->forget('org_pro_enabled_'.$org->id);
        cache()->forget('org_pro_features_'.$org->id);
        Feature::flushCache();

        $org->unsetRelation('meta');
    }
}
