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

class UpgradeRequest extends Mailable implements ShouldQueue
{
    use Dispatchable;
    use Queueable;
    use SerializesModels;

    public function __construct(
        public User $user,
        public string $targetTier,
        public int $price,
    ) {
        $tierLabel = ProTierEnum::tryFrom($targetTier)?->label() ?? $targetTier;
        $this->subject = "Upgrade Request to {$tierLabel} from {$user->name}";
        $this->onQueue('{low}');
    }

    public function build(): self
    {
        return $this->markdown('mail.pro.upgrade-request');
    }
}
