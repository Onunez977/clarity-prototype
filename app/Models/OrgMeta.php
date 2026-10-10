<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property array<array-key, mixed> $properties
 * @property-read Org $org
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|OrgMeta newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|OrgMeta newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|OrgMeta query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|OrgMeta whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|OrgMeta whereProperties($value)
 *
 * @mixin \Eloquent
 */
class OrgMeta extends Model
{
    protected $connection = 'zod';

    protected $table = 'org_meta';

    public $timestamps = false;

    protected $fillable = [
        'id',
        'properties',
    ];

    protected $casts = [
        'properties' => 'json',
    ];

    public function org(): BelongsTo
    {
        return $this->belongsTo(Org::class, 'id', 'id');
    }

    public function updateProperties(array $newProperties): void
    {
        $properties = $this->properties ?? [];

        foreach ($newProperties as $key => $property) {
            $properties[$key] = $property;
        }

        $this->update(['properties' => $properties]);
    }
}
