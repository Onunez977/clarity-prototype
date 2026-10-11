<?php

declare(strict_types=1);

namespace InsightLPR\BlackAdam\Providers;

use Faker\Generator;
use Faker\Provider\Fakecar;
use Illuminate\Support\ServiceProvider;

class FakerServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     *
     * @return void
     */
    public function register()
    {
        $this->app->bind(
            Generator::class,
            function () {
                $faker = \Faker\Factory::create();
                $faker->addProvider(new Fakecar($faker));
                return $faker;
            }
        );
    }

    /**
     * Bootstrap services.
     *
     * @return void
     */
    public function boot()
    {
        //
    }
}
