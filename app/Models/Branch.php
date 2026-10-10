<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $org_id
 * @property int|null $branch_id
 * @property string|null $udbsaid
 * @property string|null $zip_code
 * @property string|null $branch_name
 * @property string|null $address
 * @property string|null $city
 * @property string|null $state
 * @property string|null $phone
 * @property string|null $fax
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Org $org
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Branch newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Branch newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Branch query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Branch whereAddress($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Branch whereBranchId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Branch whereBranchName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Branch whereCity($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Branch whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Branch whereFax($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Branch whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Branch whereOrgId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Branch wherePhone($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Branch whereState($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Branch whereUdbsaid($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Branch whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Branch whereZipCode($value)
 *
 * @mixin \Eloquent
 */
class Branch extends Model
{
    use HasFactory;

    protected $connection = 'platform';

    protected $fillable = [
        'org_id',
        'branch_id',
        'udbsaid',
        'zip_code',
        'branch_name',
        'address',
        'city',
        'state',
        'phone',
        'fax',
    ];

    protected $casts = [
        'org_id' => 'integer',
        'branch_id' => 'integer',
    ];

    public function org(): BelongsTo
    {
        return $this->belongsTo(Org::class);
    }
}
