<?php

namespace Tests\Feature\Horizon;

use Illuminate\Support\Str;
use Laravel\Horizon\ProvisioningPlan;
use Laravel\Horizon\SupervisorOptions;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * Guards the Horizon supervisor configuration.
 *
 * `php artisan horizon` builds SupervisorOptions for *every* environment in
 * `config('horizon.environments')` at boot — not just the one it will run.
 * SupervisorOptions::fromArray() dereferences `connection` directly and Horizon
 * throws when `minProcesses < 1`, so a single malformed supervisor in any
 * environment block crash-loops the worker container in all environments.
 */
class HorizonConfigTest extends TestCase
{
    #[Group('horizon')]
    public function test_provisioning_plan_builds_for_every_environment(): void
    {
        $plan = ProvisioningPlan::get('horizon-config-test');

        $this->assertNotEmpty($plan->environments());

        foreach ($plan->parsed as $environment => $supervisors) {
            foreach ($supervisors as $name => $options) {
                $this->assertInstanceOf(SupervisorOptions::class, $options);
                $this->assertNotEmpty(
                    $options->connection,
                    "Supervisor [{$name}] in environment [{$environment}] has no queue connection; ".
                    'it must mirror a `defaults` key so connection/queue settings are inherited.'
                );

                $this->assertGreaterThanOrEqual(
                    1,
                    $options->minProcesses,
                    "Supervisor [{$name}] in environment [{$environment}] has minProcesses < 1; Horizon refuses to boot."
                );
            }
        }
    }

    #[Group('horizon')]
    public function test_preview_environment_provisions_an_otto_worker(): void
    {
        $this->assertArrayHasKey('preview', config('horizon.environments'));

        $options = $this->supervisorConsuming('preview', '{otto}');

        $this->assertNotNull(
            $options,
            'The preview Horizon environment must inherit supervisor-otto so RunOttoChat drains on `{otto}`.'
        );
        $this->assertSame('redis', $options->connection);
        $this->assertGreaterThanOrEqual(1, $options->maxProcesses);
    }

    #[Group('horizon')]
    public function test_preview_environment_is_selected_before_the_wildcard(): void
    {
        $plan = ProvisioningPlan::get('horizon-config-test');

        $matched = collect($plan->parsed)->first(
            fn (mixed $_, string $name): bool => Str::is($name, 'preview')
        );

        $this->assertNotEmpty($matched);

        $queues = collect($matched)
            ->map(fn (SupervisorOptions $options): string => $options->queue)
            ->flatMap(fn (string $queue): array => array_map('trim', explode(',', $queue)))
            ->all();

        $this->assertContains('{otto}', $queues);
        $this->assertNotSame(
            $plan->parsed['*'] ?? null,
            $matched,
            'Horizon deploy() uses Str::is($key, $environment); `preview` must be listed above `*` or the wildcard swallows it.'
        );
    }

    #[Group('horizon')]
    public function test_preview_kamal_role_selects_the_preview_horizon_environment(): void
    {
        $deploy = file_get_contents(base_path('config/deploy.yml'));

        $this->assertNotFalse($deploy);
        $this->assertStringContainsString(
            "horizon<%= preview ? ' --environment=preview' : '' %>",
            $deploy
        );
        $this->assertStringContainsString('KAMAL_PREVIEW:', $deploy);
    }

    #[Group('horizon')]
    public function test_preview_app_name_is_safe_for_horizon_supervisor_names(): void
    {
        $workflow = file_get_contents(base_path('.github/workflows/kamal-pr-preview.yml'));

        $this->assertNotFalse($workflow);
        $this->assertMatchesRegularExpression(
            '/APP_NAME:\s*Platform-PR-\$\{\{/',
            $workflow
        );
        $this->assertDoesNotMatchRegularExpression(
            '/APP_NAME:\s*Platform\s+\(/',
            $workflow
        );
        $this->assertStringContainsString('/horizon', $workflow);
    }

    private function supervisorConsuming(string $environment, string $queue): ?SupervisorOptions
    {
        $plan = ProvisioningPlan::get('horizon-config-test');

        foreach ($plan->parsed[$environment] ?? [] as $options) {
            $queues = array_map('trim', explode(',', $options->queue));

            if (in_array($queue, $queues, true)) {
                return $options;
            }
        }

        return null;
    }
}
