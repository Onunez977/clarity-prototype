<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Symfony\Component\Process\Process;

use function Laravel\Prompts\pause;
use function Laravel\Prompts\spin;

/**
 * @codeCoverageIgnore
 */
class Deploy extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:deploy {--no-confirm : Skip the confirmation pause}';

    /**
     * Execute the console command.
     */
    public function handle(): void
    {
        $this->runProcessCommand('git fetch', output: false);
        $this->runProcessCommand('git status');

        if (! $this->option('no-confirm')) {
            pause('Press ENTER to deploy.');
        }

        $this->runProcessCommand('git pull');

        spin(
            callback: fn () => $this->runProcessCommand('composer install --no-dev --no-interaction --no-ansi', output: false),
            message: 'Running composer install...'
        );

        spin(
            callback: fn () => $this->runProcessCommand('composer post-deploy'),
            message: 'Running composer post-deploy...'
        );

        $this->info('Deployed!');
    }

    protected function runProcessCommand(string $command, bool $output = true): void
    {
        $process = new Process(\explode(' ', $command));
        $process->setWorkingDirectory(base_path());
        $process->mustRun();

        if ($output) {
            $this->line($process->getOutput());
        }
    }
}
