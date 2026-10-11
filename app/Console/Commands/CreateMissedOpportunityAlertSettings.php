<?php

namespace App\Console\Commands;

use App\Dto\AlertSettingSettingsDto;
use App\Models\AlertSetting;
use App\Models\Org;
use App\Models\User;
use Illuminate\Console\Command;
use InsightLPR\BlackAdam\Enum\RoleEnum;

class CreateMissedOpportunityAlertSettings extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:admin-alerts {--dry-run : Preview changes without creating} {--org= : Optional organization ID to target a specific org}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create Missed Opportunity Alert Settings for all admin users across all organizations';

    protected int $totalCreated = 0;

    protected int $totalSkipped = 0;

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $dryRun = $this->option('dry-run');
        $orgId = $this->option('org');

        if ($dryRun) {
            $this->info('Running in dry-run mode - no changes will be made.');
            $this->newLine();
        }

        if ($orgId) {
            $org = Org::active()->find($orgId);
            if (! $org) {
                $this->error("Organization with ID {$orgId} not found or is not active.");

                return Command::FAILURE;
            }
        }

        $query = Org::active();

        if ($orgId) {
            $query->where('id', $orgId);
        }

        $query->cursor()->each(function ($org) use ($dryRun) {
            $created = 0;
            $skipped = 0;

            // Set team context for Spatie Permission's role() scope
            setPermissionsTeamId($org->id);

            $adminUsers = User::where('org_id', $org->id)
                ->role(RoleEnum::ADMIN->value)
                ->get();

            foreach ($adminUsers as $user) {
                if (
                    AlertSetting::where('alertable_type', 'user')
                        ->where('alertable_id', $user->id)
                        ->whereJsonContains('settings->types', 'M')
                        ->exists()
                ) {
                    $skipped++;

                    continue;
                }

                if (! $dryRun) {
                    AlertSetting::create([
                        'name' => 'Missed Opportunity Alert',
                        'alertable_type' => 'user',
                        'alertable_id' => $user->id,
                        'org_id' => $user->org_id,
                        'settings' => new AlertSettingSettingsDto(
                            types: ['M'],
                            clients: $org->getHotlistClients()->keys()->toArray(),
                            notifications: [
                                'sms' => [],
                                'emails' => [$user->email],
                                'slack' => [],
                            ],
                        ),
                    ]);
                }

                $created++;
            }

            if ($adminUsers->count() > 0) {
                $this->line("  Org: {$org->name} (ID: {$org->id})");
                $this->line("    Admin users: {$adminUsers->count()}");
                $this->line('    '.($dryRun ? 'Would create' : 'Created').": {$created}");
                $this->line("    Skipped (already have Missed Opportunity alert): {$skipped}");
                $this->newLine();
            }

            $this->totalCreated += $created;
            $this->totalSkipped += $skipped;
        });

        $this->newLine();
        $this->info('Summary:');
        $this->line('  Total '.($dryRun ? 'would be created' : 'created').": {$this->totalCreated}");
        $this->line("  Total skipped: {$this->totalSkipped}");

        if ($dryRun && $this->totalCreated > 0) {
            $this->newLine();
            $this->warn('Run without --dry-run to create the alert settings.');
        }

        return Command::SUCCESS;
    }
}
