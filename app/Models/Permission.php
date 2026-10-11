<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use InsightLPR\BlackAdam\Enum\PermissionEnum;
use Spatie\Permission\Models\Role;

/**
 * @codeCoverageIgnore
 *
 * @property int $id
 * @property string $name
 * @property string $guard_name
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read mixed $description
 * @property-read mixed $group
 * @property-read Collection<int, \Spatie\Permission\Models\Permission> $permissions
 * @property-read int|null $permissions_count
 * @property-read Collection<int, Role> $roles
 * @property-read int|null $roles_count
 * @property-read mixed $title
 * @property-read Collection<int, User> $users
 * @property-read int|null $users_count
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Permission newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Permission newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Permission orderByName()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Permission permission($permissions, $without = false)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Permission query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Permission role($roles, $guard = null, $without = false)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Permission whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Permission whereGuardName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Permission whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Permission whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Permission whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Permission withoutPermission($permissions)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Permission withoutRole($roles, $guard = null)
 *
 * @mixin \Eloquent
 */
class Permission extends \Spatie\Permission\Models\Permission
{
    public function scopeOrderByName($query)
    {
        return $query->orderBy('permissions.name');
    }

    public function title(): Attribute
    {
        return Attribute::make(get: fn () => PermissionEnum::tryFrom($this->name)?->title() ?? str($this->name)->title()->replace('-', ' ')->toString());
    }

    public function group(): Attribute
    {
        return Attribute::make(get: fn () => PermissionEnum::tryFrom($this->name)?->group() ?? 'Other');
    }

    public function description(): Attribute
    {
        return Attribute::make(get: fn () => PermissionEnum::tryFrom($this->name)?->description() ?? '');
    }
}
