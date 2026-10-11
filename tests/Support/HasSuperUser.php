<?php

namespace Tests\Support;

use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use InsightLPR\BlackAdam\Enum\PermissionEnum;
use InsightLPR\BlackAdam\Enum\RoleEnum;

trait HasSuperUser
{
    protected ?User $user = null;

    public function setupHasSuperUser(): void
    {
        $this->actingAs($this->user = User::factory()->create());

        Artisan::call('app:seed-permissions');

        setPermissionsTeamId($this->user->org_id);

        $this->user->syncRoles(RoleEnum::ADMIN->value, RoleEnum::CLIENT->value);
        $this->user->syncPermissions(PermissionEnum::getValues());
    }
}
