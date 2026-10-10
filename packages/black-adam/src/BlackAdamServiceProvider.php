<?php

namespace InsightLPR\BlackAdam;

use InsightLPR\BlackAdam\Commands\Deploy;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;
use InsightLPR\BlackAdam\Commands\InsightLPRCommand;

class BlackAdamServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        /*
         * This class is a Package Service Provider
         *
         * More info: https://github.com/spatie/laravel-package-tools
         */
        $package
            ->name('black-adam')
            ->hasTranslations()
            ->hasConfigFile()
            //->hasViews()
            //->hasMigration('create_insightlpr_table')
            ->hasCommand(Deploy::class);
    }
}
