<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int|null $agent_org_id
 * @property int|null $staging_org_id
 * @property-read Org|null $org
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ApprovalList newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ApprovalList newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ApprovalList query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ApprovalList whereAgentOrgId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ApprovalList whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ApprovalList whereStagingOrgId($value)
 *
 * @mixin \Eloquent
 *
 * @codeCoverageIgnore
 */
class ApprovalList extends Model
{
    protected $connection = 'zod';

    protected $table = 'approval_list';

    public $timestamps = false;

    public function org(): BelongsTo
    {
        return $this->belongsTo(Org::class, 'agent_org_id', 'id');
    }
}
