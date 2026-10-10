<?php

declare(strict_types=1);

namespace App\Mail\Pro;

use App\Enums\ProTierEnum;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class DowngradeCancellation extends Mailable implements ShouldQueue
{
    use Dispatchable;
    use Queueable;
    use SerializesModels;

    public function __construct(
        public User $user,
        public string $currentTier,
        public string $cancelledTargetTier,
    ) {
        $currentLabel = ProTierEnum::tryFrom($currentTier)?->label() ?? $currentTier;
        $cancelledLabel = ProTierEnum::tryFrom($cancelledTargetTier)?->label() ?? $cancelledTargetTier;
        $this->subject = "Downgrade Cancellation from {$cancelledLabel} back to {$currentLabel} from {$user->name}";
        $this->onQueue('{low}');
    }

    public function build(): self
    {
        return $this->markdown('mail.pro.downgrade-cancellation');
    }
}
