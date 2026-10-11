<?php

namespace Tests\Feature\Console\Commands;

use App\Console\Commands\GeocodeRecoveryAddresses;
use App\Jobs\RefreshRecoveryViews;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

class GeocodeRecoveryAddressesTest extends TestCase
{
    #[Group('commands')]
    public function test_it_does_not_batch_refresh_recovery_views(): void
    {
        Bus::fake();

        Artisan::call(GeocodeRecoveryAddresses::class);

        Bus::assertBatched(function ($batch) {
            return $batch->name === 'Geocode Recovery Addresses'
                && $batch->jobs->doesntContain(fn ($job) => $job instanceof RefreshRecoveryViews);
        });
    }
}
