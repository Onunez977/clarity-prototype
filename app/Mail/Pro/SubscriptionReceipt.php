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
use Illuminate\Support\Carbon;

class SubscriptionReceipt extends Mailable implements ShouldQueue
{
    use Dispatchable;
    use Queueable;
    use SerializesModels;

    public string $previousTierLabel;

    public string $newTierLabel;

    public int $previousPrice;

    public int $newPrice;

    public function __construct(
        public User $user,
        public string $changeType,
        public string $previousTier,
        public string $newTier,
        public ?Carbon $effectiveDate = null,
    ) {
        $previous = ProTierEnum::tryFrom($previousTier);
        $new = ProTierEnum::tryFrom($newTier);

        $this->previousTierLabel = $previous?->label() ?? $previousTier;
        $this->newTierLabel = $new?->label() ?? $newTier;
        $this->previousPrice = $previous?->price() ?? 0;
        $this->newPrice = $new?->price() ?? 0;

        $this->subject = 'Your Clarity Subscription Has Been Updated';
        $this->onQueue('{low}');
    }

    public function build(): self
    {
        return $this->markdown('mail.pro.subscription-receipt');
    }
}
