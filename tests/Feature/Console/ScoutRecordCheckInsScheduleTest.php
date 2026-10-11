<?php

namespace Tests\Feature\Console;

use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ScoutRecordCheckInsScheduleTest extends TestCase
{
    #[Test]
    public function the_gps_lookback_comes_from_config(): void
    {
        config([
            'app.experimental.clarity_scout' => true,
            'scout.check_ins.lookback_minutes' => 45,
        ]);

        Log::spy();

        $this->artisan('scout:record-check-ins')->assertSuccessful();

        Log::shouldHaveReceived('info')->withArgs(function (string $message, array $context = []) {
            return str_contains($message, 'completed') && ($context['lookback_minutes'] ?? null) === 45;
        });
    }
}
