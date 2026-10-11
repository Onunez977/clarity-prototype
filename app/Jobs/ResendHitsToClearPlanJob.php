<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Hit;
use App\Models\User;
use App\Services\ClearPlan;
use Filament\Notifications\Notification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ResendHitsToClearPlanJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * @param  array<int>  $hitIds
     */
    public function __construct(
        public array $hitIds,
        public User $user,
    ) {}

    public function handle(ClearPlan $clearPlan): void
    {
        $hits = Hit::query()
            ->whereIn('id', $this->hitIds)
            ->get();

        $succeeded = 0;
        $failed = 0;

        foreach ($hits as $hit) {
            try {
                if ($clearPlan->sendHit($hit)) {
                    $succeeded++;
                } else {
                    $failed++;
                }
            } catch (\Throwable $e) {
                $failed++;

                activity('clearplan-resend')
                    ->causedBy($this->user)
                    ->withProperties([
                        'hit_id' => $hit->id,
                        'error' => $e->getMessage(),
                    ])
                    ->log('Failed to resend hit to ClearPlan');
            }
        }

        $total = $succeeded + $failed;

        if ($failed === 0) {
            Notification::make()
                ->success()
                ->title("{$succeeded} hit(s) resent to ClearPlan")
                ->sendToDatabase($this->user);
        } elseif ($succeeded === 0) {
            Notification::make()
                ->danger()
                ->title('Resend failed. Please try again.')
                ->sendToDatabase($this->user);
        } else {
            Notification::make()
                ->warning()
                ->title("{$failed} of {$total} hit(s) failed to resend. Try again later.")
                ->sendToDatabase($this->user);
        }

        activity('clearplan-resend')
            ->causedBy($this->user)
            ->withProperties([
                'hit_ids' => $hits->pluck('id')->all(),
                'submitted' => $hits->count(),
                'succeeded' => $succeeded,
                'failed' => $failed,
                'user_name' => $this->user->name,
                'user_role' => $this->user->roles->pluck('name')->first(),
            ])
            ->log('Resent hits to ClearPlan');
    }
}
