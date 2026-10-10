<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Models\Zone;
use InsightLPR\BlackAdam\Enum\RoleEnum;

class ZonePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasRole(RoleEnum::ADMIN->value);
    }

    public function view(User $user, Zone $zone): bool
    {
        return $user->hasRole(RoleEnum::ADMIN->value) && $user->org_id === $zone->org_id;
    }

    public function create(User $user): bool
    {
        return $user->hasRole(RoleEnum::ADMIN->value);
    }

    public function update(User $user, Zone $zone): bool
    {
        return $user->hasRole(RoleEnum::ADMIN->value) && $user->org_id === $zone->org_id;
    }

    public function delete(User $user, Zone $zone): bool
    {
        return $user->hasRole(RoleEnum::ADMIN->value) && $user->org_id === $zone->org_id;
    }

    public function restore(User $user, Zone $zone): bool
    {
        return $user->hasRole(RoleEnum::ADMIN->value) && $user->org_id === $zone->org_id;
    }

    public function forceDelete(User $user, Zone $zone): bool
    {
        return false;
    }
}
