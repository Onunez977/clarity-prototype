<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $name
 * @property string $status
 * @property int|null $batch_id
 * @property int $mapping_id
 * @property string|null $mapping_type
 * @property-read Model|\Eloquent $mapping
 * @property-read Collection<int, Hotlist> $plates
 * @property-read int|null $plates_count
 *
 * @method static \Database\Factories\IntegrationsFactory factory($count = null, $state = [])
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Integrations newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Integrations newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Integrations query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Integrations whereBatchId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Integrations whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Integrations whereMappingId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Integrations whereMappingType($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Integrations whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Integrations whereStatus($value)
 *
 * @mixin \Eloquent
 *
 * @codeCoverageIgnore
 */
class Integrations extends Model
{
    use HasFactory;

    protected $connection = 'zod';

    protected $table = 'integrations';

    public $timestamps = false;

    protected $fillable = [
        'name',
        'status',
        'mapping_id',
        'mapping_type',
    ];

    public function hotlists(): HasMany
    {
        return $this->hasMany(Hotlist::class, 'integration_id', 'id');
    }

    public function mapping(): BelongsTo
    {
        return $this->morphTo('mapping', 'mapping_type', 'mapping_id', 'id');
    }
}
