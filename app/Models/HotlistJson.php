<?php

namespace App\Models;

use App\Dto\HotlistJsonData;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HotlistJson extends Model
{
    use HasFactory;

    protected $connection = 'zod';

    public $incrementing = false;

    protected $primaryKey = 'hotlist_id';

    protected $table = 'public.hotlist_json';

    /**
     * WARNING - THIS CAST IS LOSSY ON WRITE.
     *
     * hotlist_json.json is owned by an external producer and carries ~21 keys.
     * HotlistJsonData declares only branch_id, so ANY Eloquent write through this model
     * (create/update/save/upsert) replaces the whole blob with {"branch_id": ...} and
     * silently destroys every other key.
     *
     * Reads are safe. Production writes MUST NOT go through this model - use targeted
     * jsonb_set SQL instead (see App\Jobs\Rdn\GetOrgHotlistCaseInfo::writeBranchId()).
     * Writing through the model is acceptable only in factories and seeders.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'json' => HotlistJsonData::class,
    ];

    public function hotlist(): BelongsTo
    {
        return $this->belongsTo(Hotlist::class, 'hotlist_id');
    }
}
