<?php

namespace InsightLPR\ZodMigrations;

use Illuminate\Database\Console\Migrations\FreshCommand as BaseFreshCommand;
use Illuminate\Database\Console\Migrations\MigrateCommand as BaseMigrateCommand;
use Illuminate\Database\Console\Migrations\RefreshCommand as BaseRefreshCommand;
use InsightLPR\ZodMigrations\Console\Commands\Migrations\FreshCommand;
use InsightLPR\ZodMigrations\Console\Commands\Migrations\MigrateCommand;
use InsightLPR\ZodMigrations\Console\Commands\Migrations\RefreshCommand;
use InsightLPR\ZodMigrations\Database\ZodSchemaMigrator;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class ZodMigrationsServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('zod-migrations')
            ->hasConfigFile();
    }

    public function packageRegistered(): void
    {
        $this->app->singleton(ZodSchemaMigrator::class);
    }

    public function packageBooted(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->app->extend(BaseFreshCommand::class, fn ($command, $app) => new FreshCommand($app['migrator']));
        $this->app->extend(BaseRefreshCommand::class, fn ($command, $app) => new RefreshCommand);
        $this->app->extend(BaseMigrateCommand::class, fn ($command, $app) => new MigrateCommand($app['migrator'], $app['events']));
    }
}
