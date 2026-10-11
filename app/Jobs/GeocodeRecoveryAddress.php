<?php

namespace App\Jobs;

use App\Models\Address;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class GeocodeRecoveryAddress implements ShouldQueue
{
    use Batchable;
    use Queueable;

    public function __construct(
        public ?Address $address = null,
        public ?int $addressId = null,
    ) {
        $this->onQueue('{low}');
    }

    public function handle(): void
    {
        if ($address = $this->address ?? Address::find($this->addressId)) {
            $address->geocode();
        } else {
            $this->fail('Address not found');
        }
    }
}
