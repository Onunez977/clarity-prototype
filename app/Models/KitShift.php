<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ShiftEndTypeEnum;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Represents a single continuous activity window for a kit / driver.
 *
 * A shift is bounded by either (a) the moment the driver taps "End Shift"
 * in the PWA (`end_type = confirmed`), or (b) at least three hours of
 * GPS + scan inactivity detected by the scheduled close-inactive command
 * (`end_type = assumed`).
 *
 * The partial unique index `kit_shifts_one_open_per_kit` guarantees that
 * each kit has at most one open (ended_at IS NULL) shift at any time.
 *
 * @property int $id
 * @property int $kit_id
 * @property int $org_id
 * @property Carbon $started_at
 * @property Carbon|null $ended_at
 * @property ShiftEndTypeEnum|null $end_type
 * @property Carbon|null $summary_sent_at
 * @property string|null $summary_skipped_reason
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Kit|null $kit
 * @property-read Org|null $org
 *
 * @mixin \Eloquent
 */
class KitShift extends Model
{
    use HasFactory;

    protected $connection = 'platform';

    protected $table = 'kit_shifts';

    protected $fillable = [
        'kit_id',
        'org_id',
        'started_at',
        'ended_at',
        'end_type',
        'summary_sent_at',
        'summary_skipped_reason',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
            'summary_sent_at' => 'datetime',
            'end_type' => ShiftEndTypeEnum::class,
        ];
    }

    public function kit(): BelongsTo
    {
        return $this->belongsTo(Kit::class);
    }

    public function org(): BelongsTo
    {
        return $this->belongsTo(Org::class);
    }

    public function isOpen(): bool
    {
        return $this->ended_at === null;
    }

    public function isClosed(): bool
    {
        return $this->ended_at !== null;
    }
}
