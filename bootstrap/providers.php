<?php

use App\Providers\AiServiceProvider;
use App\Providers\AppServiceProvider;
use App\Providers\EventServiceProvider;
use App\Providers\Filament\Client;
use App\Providers\FilamentServiceProvider;
use App\Providers\GateServiceProvider;
use App\Providers\HorizonServiceProvider;
use App\Providers\NightWatchServiceProvider;
use App\Providers\OttoServiceProvider;
use App\Providers\PennantServiceProvider;
use LaravelClickhouseEloquent\ClickhouseServiceProvider;

return [
    AppServiceProvider::class,
    FilamentServiceProvider::class,
    App\Providers\Filament\App::class,
    Client::class,
    PennantServiceProvider::class,
    GateServiceProvider::class,
    HorizonServiceProvider::class,
    NightWatchServiceProvider::class,
    ClickhouseServiceProvider::class,
    EventServiceProvider::class,
    AiServiceProvider::class,
    OttoServiceProvider::class,
];
