<?php

namespace App\Support\Scout;

use App\Enums\FeatureEnum;
use App\Models\Kit;
use App\Models\KitSettings;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use InsightLPR\BlackAdam\Enum\RoleEnum;
use Laravel\Pennant\Feature;

/**
 * Grants clarity-scout + ties a Kit + onboards, for the Users resource's Scout option
 * (shared by CreateUsers/EditUsers). Zones/vehicle/force-login stay in ScoutUsersResource.
 */
class ScoutUserProvisioner
{
    /**
     * Mirrors ScoutUsersResource::canAccess() so the Scout controls (and the related
     * table column) are only ever visible/actionable for Scout-enabled orgs' admins.
     */
    public static function canAccess(): bool
    {
        return (Feature::active(FeatureEnum::CLARITY_SCOUT->slug()) || session()->has('admin_impersonator'))
            && auth()->user()->hasRole(RoleEnum::ADMIN->value);
    }

    /**
     * Kits available for assignment: unassigned kits (no KitSettings row, or a
     * KitSettings row with a null user_id), plus - on edit - the kit currently tied
     * to this user so it stays selectable.
     *
     * @return array<int, string>
     */
    public static function availableKitOptions(int $orgId, ?int $currentKitId = null): array
    {
        return Kit::query()
            ->where('org_id', $orgId)
            ->where(function (Builder $query) use ($currentKitId): void {
                $query->whereDoesntHave('settings', fn ($settings) => $settings->whereNotNull('user_id'));

                if ($currentKitId) {
                    $query->orWhere('id', $currentKitId);
                }
            })
            ->orderBy('name')
            ->get(['id', 'name', 'kit_mapping'])
            ->mapWithKeys(fn (Kit $kit) => [$kit->id => $kit->name.' ('.$kit->kit_mapping.')'])
            ->all();
    }

    /**
     * Pulls the virtual `scout_access`/`scout_kit_id` fields out of the form data
     * before it reaches the User model (they aren't fillable columns). Returns the
     * extracted Scout intent (empty when gating fails or the fields are absent).
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function extract(array &$data): array
    {
        $scoutData = [];

        if (static::canAccess() && array_key_exists('scout_access', $data)) {
            $scoutData['scout_access'] = (bool) $data['scout_access'];
            $scoutData['scout_kit_id'] = $data['scout_kit_id'] ?? null;
        }

        unset($data['scout_access'], $data['scout_kit_id']);

        return $scoutData;
    }

    /**
     * Applies the extracted Scout provisioning intent to the saved user: grants/revokes
     * the `clarity-scout` permission, ties/clears the Kit (1:1 enforcement), and sends
     * the onboarding email on first grant.
     *
     * @param  array<string, mixed>  $scoutData
     */
    public static function reconcile(User $user, array $scoutData): void
    {
        if (! static::canAccess()) {
            return;
        }

        if (! array_key_exists('scout_access', $scoutData)) {
            return;
        }

        setPermissionsTeamId($user->org_id);

        $scoutAccess = $scoutData['scout_access'];
        $kitId = $scoutData['scout_kit_id'] ?? null;

        // Re-resolve the submitted kit against the acting org + availability; drop it if invalid.
        if ($scoutAccess && $kitId) {
            $currentKitId = KitSettings::where('user_id', $user->id)->value('kit_id');
            $availableKitIds = array_keys(static::availableKitOptions($user->org_id, $currentKitId));

            $kit = Kit::query()->where('org_id', $user->org_id)->find($kitId);

            $kitId = ($kit && in_array($kit->id, $availableKitIds, true)) ? $kit->id : null;
        }

        $shouldNotify = false;

        DB::connection($user->getConnectionName())->transaction(function () use ($user, $scoutAccess, $kitId, &$shouldNotify) {
            if ($scoutAccess) {
                if ($user->roles->isEmpty()) {
                    $user->assignRole(RoleEnum::USER->value);
                }

                if (! $user->hasPermissionTo('clarity-scout')) {
                    $user->givePermissionTo('clarity-scout');

                    $shouldNotify = ! $user->email_verified_at;
                }
            } elseif ($user->hasPermissionTo('clarity-scout')) {
                $user->revokePermissionTo('clarity-scout');
            }

            if ($scoutAccess && $kitId) {
                KitSettings::where('user_id', $user->id)
                    ->where('kit_id', '!=', $kitId)
                    ->update(['user_id' => null]);

                KitSettings::updateOrCreate(
                    ['kit_id' => $kitId],
                    ['user_id' => $user->id],
                );
            } else {
                KitSettings::where('user_id', $user->id)->update(['user_id' => null]);
            }
        });

        if ($shouldNotify) {
            $user->sendScoutOnboardingNotification();
        }
    }
}
