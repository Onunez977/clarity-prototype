<?php

declare(strict_types=1);

namespace App\Mail\Scout;

use App\Dto\Scout\ShiftSummaryDto;
use App\Enums\ShiftEndTypeEnum;
use App\Jobs\Scout\SendEndOfShiftSummary;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * End-of-shift summary email sent to the driver (To:) and CC'd to all
 * company admins. Dispatched by {@see SendEndOfShiftSummary}
 * from both the confirmed-end controller and the scheduled assumed-end
 * detector.
 *
 * The subject differentiates Confirmed vs. Assumed shift ends per the
 * ticket's acceptance criteria ("The report clearly labels which type of
 * shift end triggered it").
 */
class EndOfShiftSummary extends Mailable implements ShouldQueue
{
    use Dispatchable;
    use Queueable;
    use SerializesModels;

    public function __construct(
        public ShiftSummaryDto $summary,
        public bool $driverEmailMissing = false,
    ) {
        $label = $summary->endType === ShiftEndTypeEnum::Confirmed ? 'Confirmed' : 'Assumed';
        $date = $summary->endedAt->format('Y-m-d');
        $kit = $summary->kitName;

        $this->subject = "Shift Summary — {$label} — {$kit} — {$date}";
        $this->onQueue('{low}');
    }

    public function build(): self
    {
        return $this->markdown('mail.scout.end-of-shift-summary', [
            'summary' => $this->summary,
            'driverEmailMissing' => $this->driverEmailMissing,
        ]);
    }
}
