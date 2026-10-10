<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * @property int $id
 * @property int $user_id
 * @property int $org_id
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserOrg newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserOrg newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserOrg query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserOrg whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserOrg whereOrgId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserOrg whereUserId($value)
 *
 * @mixin \Eloquent
 */
class UserOrg extends Pivot
{
    protected $connection = 'zod';

    public $timestamps = false;

    public $table = 'user_orgs';
}
