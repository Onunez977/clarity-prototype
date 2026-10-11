<?php

declare(strict_types=1);

namespace Tests\Models;

use App\Enums\VehicleTypeEnum;
use App\Models\Kit;
use App\Models\KitSettings;
use App\Models\Org;
use App\Models\User;
use PHPUnit\Framework\Attributes\Group;
use Tests\Filament\Resources\ScoutUsersResourceTest;
use Tests\TestCase;

/**
 * Regression coverage for the `kit_id` -> `kit_settings` refactor (DEV-1916 §2).
 *
 * `users.kit_id` is gone; the kit<->user link now lives in the `kit_settings`
 * table and is surfaced through derived accessors on the User and Kit models.
 * The 1:1 enforcement (assigning a user to a second kit detaches the first)
 * is driven by the Scout Users resource and is covered in
 * {@see ScoutUsersResourceTest}.
 */
class KitSettingsTest extends TestCase
{
    private function makeKitWithUser(Org $org, ?VehicleTypeEnum $vehicleType = null): array
    {
        $kit = Kit::factory()->forOrg($org)->create();
        $user = User::factory()->forOrg($org)->create();

        KitSettings::updateOrCreate(
            ['kit_id' => $kit->id],
            ['user_id' => $user->id, 'vehicle_type' => $vehicleType?->value],
        );

        $kit->refresh();
        $user->refresh();

        return [$kit, $user];
    }

    #[Group('clarity-scout')]
    public function test_user_kit_accessor_resolves_assigned_kit_via_settings(): void
    {
        $org = Org::factory()->create();
        [$kit, $user] = $this->makeKitWithUser($org);

        $this->assertInstanceOf(Kit::class, $user->kit);
        $this->assertSame($kit->id, $user->kit->id);
    }

    #[Group('clarity-scout')]
    public function test_user_kit_id_accessor_returns_kit_id(): void
    {
        $org = Org::factory()->create();
        [$kit, $user] = $this->makeKitWithUser($org);

        $this->assertSame($kit->id, $user->kit_id);
    }

    #[Group('clarity-scout')]
    public function test_user_kit_and_kit_id_are_null_when_unassigned(): void
    {
        $org = Org::factory()->create();
        $user = User::factory()->forOrg($org)->create();

        $this->assertNull($user->kit);
        $this->assertNull($user->kit_id);
    }

    #[Group('clarity-scout')]
    public function test_kit_assigned_user_accessor_returns_user(): void
    {
        $org = Org::factory()->create();
        [$kit, $user] = $this->makeKitWithUser($org);

        $this->assertInstanceOf(User::class, $kit->assigned_user);
        $this->assertSame($user->id, $kit->assigned_user->id);
    }

    #[Group('clarity-scout')]
    public function test_kit_assigned_user_is_null_without_settings(): void
    {
        $org = Org::factory()->create();
        $kit = Kit::factory()->forOrg($org)->create();

        $this->assertNull($kit->assigned_user);
    }

    #[Group('clarity-scout')]
    public function test_kit_vehicle_type_accessor_returns_enum_from_settings(): void
    {
        $org = Org::factory()->create();
        [$kit] = $this->makeKitWithUser($org, VehicleTypeEnum::RepoTruck);

        $this->assertSame(VehicleTypeEnum::RepoTruck, $kit->vehicle_type);
    }

    #[Group('clarity-scout')]
    public function test_kit_vehicle_type_is_null_without_settings(): void
    {
        $org = Org::factory()->create();
        $kit = Kit::factory()->forOrg($org)->create();

        $this->assertNull($kit->vehicle_type);
    }

    #[Group('clarity-scout')]
    public function test_vehicle_type_casts_to_enum_round_trip(): void
    {
        $org = Org::factory()->create();
        $kit = Kit::factory()->forOrg($org)->create();

        KitSettings::updateOrCreate(
            ['kit_id' => $kit->id],
            ['vehicle_type' => VehicleTypeEnum::Flatbed->value],
        );

        $settings = KitSettings::query()->where('kit_id', $kit->id)->first();

        $this->assertInstanceOf(VehicleTypeEnum::class, $settings->vehicle_type);
        $this->assertSame(VehicleTypeEnum::Flatbed, $settings->vehicle_type);
    }

    #[Group('clarity-scout')]
    public function test_kit_settings_keeps_one_row_per_kit(): void
    {
        // kit_id is the primary key, so updateOrCreate replaces rather than
        // duplicating — a kit can never accumulate two settings rows.
        $org = Org::factory()->create();
        $kit = Kit::factory()->forOrg($org)->create();
        $userA = User::factory()->forOrg($org)->create();
        $userB = User::factory()->forOrg($org)->create();

        KitSettings::updateOrCreate(['kit_id' => $kit->id], ['user_id' => $userA->id]);
        KitSettings::updateOrCreate(['kit_id' => $kit->id], ['user_id' => $userB->id]);

        $this->assertSame(1, KitSettings::query()->where('kit_id', $kit->id)->count());
        $this->assertSame($userB->id, $kit->fresh()->assigned_user?->id);
    }
}
