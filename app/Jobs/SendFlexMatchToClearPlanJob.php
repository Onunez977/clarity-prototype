<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\HitTypeEnum;
use App\Models\Hit;
use App\Models\Hotlist;
use App\Models\Scan;
use App\Models\User;
use App\Services\ClearPlan;
use Filament\Notifications\Notification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SendFlexMatchToClearPlanJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * @param  array<int>  $hotlistIds
     */
    public function __construct(
        public int $scanId,
        public array $hotlistIds,
        public User $user,
    ) {}

    public function handle(ClearPlan $clearPlan): void
    {
        $scan = Scan::with(['scanMetaData', 'kit.org'])->find($this->scanId);

        if (! $scan) {
            Notification::make()
                ->danger()
                ->title('Flex match ClearPlan send failed: scan not found.')
                ->sendToDatabase($this->user);

            return;
        }

        $hotlists = Hotlist::with('org')
            ->whereIn('id', $this->hotlistIds)
            ->get()
            ->keyBy('id');

        $succeeded = 0;
        $failed = 0;

        foreach ($this->hotlistIds as $hotlistId) {
            $hotlist = $hotlists->get($hotlistId);

            if (! $hotlist) {
                $failed++;

                continue;
            }

            $hit = new Hit;
            $hit->forceFill([
                'hotlist_id' => $hotlistId,
                'scan_id' => $scan->id,
                'kit_id' => $scan->kit_id,
                'hit_type' => HitTypeEnum::Probable_Match->value,
                'created_at' => now(),
            ]);
            $hit->setRelation('scan', $scan);
            $hit->setRelation('kit', $scan->kit);
            $hit->setRelation('hotlist', $hotlist);

            try {
                if ($clearPlan->sendHit($hit)) {
                    $succeeded++;
                } else {
                    $failed++;
                }
            } catch (\Throwable $e) {
                $failed++;

                activity('flex-match-alert')
                    ->causedBy($this->user)
                    ->withProperties([
                        'scan_id' => $this->scanId,
                        'hotlist_id' => $hotlistId,
                        'error' => $e->getMessage(),
                    ])
                    ->log('Failed to send flex match hit to ClearPlan');
            }
        }

        if ($failed === 0) {
            Notification::make()
                ->success()
                ->title("{$succeeded} flex match hit(s) sent to ClearPlan")
                ->sendToDatabase($this->user);
        } elseif ($succeeded === 0) {
            Notification::make()
                ->danger()
                ->title('Flex match ClearPlan send failed. Please try again.')
                ->sendToDatabase($this->user);
        } else {
            $total = $succeeded + $failed;
            Notification::make()
                ->warning()
                ->title("{$failed} of {$total} flex match hit(s) failed to send. Try again later.")
                ->sendToDatabase($this->user);
        }
    }
}
