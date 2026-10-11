<?php

namespace Tests\Feature\Console;

use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Pins the overrun signal. Deliberately ClickHouse-free: with no Scout-enabled
 * drivers the org loop never runs, so the cycle still reaches the signal
 * without touching ClickHouse.
 */
class ScoutRecordCheckInsOverrunSignalTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['app.experimental.clarity_scout' => true]);
    }

    #[Test]
    public function a_cycle_under_the_threshold_emits_nothing(): void
    {
        config(['scout.check_ins.overrun_ms' => 3_600_000]);

        Log::spy();
        Exceptions::fake();

        $this->artisan('scout:record-check-ins')->assertSuccessful();

        Log::shouldNotHaveReceived('warning');
        Exceptions::assertNothingReported();
    }

    #[Test]
    public function an_overrun_logs_the_magnitude_and_reports_it(): void
    {
        config(['scout.check_ins.overrun_ms' => 1]);

        Log::spy();
        Exceptions::fake();

        $this->artisan('scout:record-check-ins')->assertSuccessful();

        Log::shouldHaveReceived('warning')->withArgs(function (string $message, array $context = []) {
            return str_contains($message, 'exceeded its duration budget')
                && $context['threshold_ms'] === 1
                && $context['over_threshold_multiple'] >= 1
                && array_key_exists('duration_ms', $context);
        });

        // The log alone reaches no alerting channel here, so report() is the
        // load-bearing half of the signal.
        Exceptions::assertReported(function (\RuntimeException $e) {
            return str_contains($e->getMessage(), 'exceeded its duration budget');
        });
    }

    #[Test]
    public function a_non_positive_threshold_disables_the_signal(): void
    {
        config(['scout.check_ins.overrun_ms' => 0]);

        Log::spy();
        Exceptions::fake();

        $this->artisan('scout:record-check-ins')->assertSuccessful();

        Log::shouldNotHaveReceived('warning');
        Exceptions::assertNothingReported();
    }
}
