<?php

namespace App\Filament\Resources\ScoutUsersResource\Pages;

use App\Enums\FeatureEnum;
use App\Enums\ScoutAnalyticsEvent;
use App\Enums\ScoutAnalyticsPage;
use App\Facades\Analytics;
use App\Filament\Concerns\HasProFeature;
use App\Filament\Concerns\LogsScoutActivity;
use App\Filament\Resources\ScoutUsersResource;
use App\Models\KitSettings;
use App\Models\User;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use InsightLPR\BlackAdam\Enum\RoleEnum;

class EditScoutUser extends EditRecord
{
    use HasProFeature;
    use LogsScoutActivity;

    protected static string $resource = ScoutUsersResource::class;

    protected ?int $assignedUserId = null;

    protected bool $assignedWebAccess = false;

    public function mount(int|string $record): void
    {
        parent::mount($record);

        Analytics::track('page-view', auth()->user(), ['action' => 'view'], page: ScoutAnalyticsPage::EditScoutUser->value);
    }

    public function getFeatureName(): string|array
    {
        return FeatureEnum::CLARITY_SCOUT->slug();
    }

    public function getTitle(): string|Htmlable
    {
        return 'Edit: '.$this->record->name;
    }

    protected function getHeaderActions(): array
    {
        return [];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $orgId = auth()->user()->org_id;
        $createNew = $data['create_new_user'] ?? false;
        $vehicleType = $data['vehicle_type'] ?? null;

        if ($createNew) {
            $webAccessEnabled = $data['web_access_enabled'] ?? false;

            $user = User::create([
                'name' => $this->record->name,
                'email' => $data['new_user_email'],
                'mobile_phone' => $this->normalizePhone($data['mobile_phone'] ?? null),
                'org_id' => $orgId,
                'password' => Hash::make(Str::random(40)),
            ]);

            setPermissionsTeamId($orgId);
            $user->assignRole(RoleEnum::USER->value);

            if ($webAccessEnabled) {
                $user->givePermissionTo('clarity-scout');
                $user->sendScoutOnboardingNotification();
            }

            $userId = $user->id;
        } else {
            $userId = $data['assigned_user_id'] ?? null;
            $trashedLinked = ScoutUsersResource::trashedAssignedUser($this->record);

            // Soft-deleted driver still on this kit: keep kit_settings.user_id so Restore stays reachable.
            if ($trashedLinked && ! $userId) {
                $userId = $trashedLinked->id;
            } elseif ($userId) {
                $user = User::where('org_id', $orgId)->find($userId);

                if ($user) {
                    $newEmail = $data['existing_user_email'] ?? null;
                    $newPhone = $this->normalizePhone($data['mobile_phone'] ?? null);

                    $updates = [];
                    if ($newEmail && $newEmail !== $user->email) {
                        $updates['email'] = $newEmail;
                    }
                    if ($newPhone && $newPhone !== $user->mobile_phone) {
                        $updates['mobile_phone'] = $newPhone;
                    }
                    if ($updates) {
                        $user->forceFill($updates)->save();
                    }

                    $webAccessEnabled = $data['web_access_enabled'] ?? false;

                    setPermissionsTeamId($orgId);

                    if ($user->roles->isEmpty()) {
                        $user->assignRole(RoleEnum::USER->value);
                    }

                    $hadPortalAccess = $user->hasPermissionTo('clarity-scout');

                    if ($webAccessEnabled && ! $hadPortalAccess) {
                        $user->givePermissionTo('clarity-scout');

                        if (! $user->email_verified_at) {
                            $user->sendScoutOnboardingNotification();
                        }
                    } elseif (! $webAccessEnabled && $hadPortalAccess) {
                        $user->revokePermissionTo('clarity-scout');
                    }
                } else {
                    $userId = null;
                }
            }
        }

        // Detach the chosen user from any other kit (1:1 enforcement)
        if ($userId) {
            KitSettings::where('user_id', $userId)
                ->where('kit_id', '!=', $this->record->id)
                ->update(['user_id' => null]);
        }

        KitSettings::updateOrCreate(
            ['kit_id' => $this->record->id],
            [
                'user_id' => $userId,
                'vehicle_type' => $vehicleType,
            ],
        );

        $this->assignedUserId = $userId;
        $this->assignedWebAccess = (bool) ($data['web_access_enabled'] ?? false);

        // Remove virtual fields so they don't try to save to kits table
        unset(
            $data['create_new_user'],
            $data['assigned_user_id'],
            $data['new_user_email'],
            $data['existing_user_email'],
            $data['mobile_phone'],
            $data['web_access_enabled'],
            $data['vehicle_type'],
        );

        return $data;
    }

    protected function afterSave(): void
    {
        $kit = $this->record;
        $vehicleType = $this->data['vehicle_type'] ?? null;

        Analytics::track(
            ScoutAnalyticsEvent::ScoutUserAssign->value,
            auth()->user(),
            ['action' => 'assign', 'kit_id' => $kit->id, 'web_access' => $this->assignedWebAccess],
            ScoutAnalyticsPage::EditScoutUser->value,
        );

        activity('scout-user')
            ->performedOn($kit)
            ->causedBy(auth()->user())
            ->withProperties(static::scoutActivityProperties([
                'action' => 'assign',
                'kit_id' => $kit->id,
                'target_user_id' => $this->assignedUserId,
                'vehicle_type' => $vehicleType,
                'web_access' => $this->assignedWebAccess,
            ]))
            ->log('Scout user assigned');
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }

    private function normalizePhone(?string $phone): ?string
    {
        if ($phone === null) {
            return null;
        }

        $digits = trim(preg_replace('/\D/', '', $phone));

        return $digits === '' ? null : $digits;
    }
}
