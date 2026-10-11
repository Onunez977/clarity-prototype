<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasTimestamps;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $scan_id
 * @property string|null $correct_ocr
 * @property string|null $correct_us_state
 * @property bool|null $not_a_plate
 * @property bool|null $not_readable
 * @property int|null $validated_by_user_id
 * @property Carbon|null $validated_at
 * @property Carbon $created_at
 * @property Carbon|null $updated_at
 * @property int|null $parent_scan_id
 * @property int|null $source App where the validation occurred. 1 for backyard, 2 for validation standalone
 * @property string|null $batch Origination notes, not required
 * @property int|null $in_progress_by_user_id
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ScanValidation newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ScanValidation newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ScanValidation query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ScanValidation whereBatch($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ScanValidation whereCorrectOcr($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ScanValidation whereCorrectUsState($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ScanValidation whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ScanValidation whereInProgressByUserId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ScanValidation whereNotAPlate($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ScanValidation whereNotReadable($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ScanValidation whereParentScanId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ScanValidation whereScanId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ScanValidation whereSource($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ScanValidation whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ScanValidation whereValidatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ScanValidation whereValidatedByUserId($value)
 *
 * @mixin \Eloquent
 */
class ScanValidation extends Model
{
    use HasTimestamps;

    protected $connection = 'zod';

    protected $table = 'scan_validations';

    protected $primaryKey = 'scan_id';

    public $incrementing = false;

    protected $casts = [
        'validated_at' => 'datetime',
    ];
}
