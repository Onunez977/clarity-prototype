<?php

namespace App\Providers\Filament;

use App\Http\Middleware\ClientAuthenticate;
use App\Http\Middleware\ClientMiddleware;
use App\Support\Filament\PlatformPanelProvider;
use Filament\Panel;
use Filament\PanelProvider;

class Client extends PanelProvider
{
    use PlatformPanelProvider;

    public function panel(Panel $panel): Panel
    {
        return $this->makePanel($panel, authMiddleware: [ClientMiddleware::class], authenticator: ClientAuthenticate::class)
            ->id('client')
            ->path('client')
            ->pages([])
            ->discoverResources(in: app_path('Filament/Client/Resources'), for: 'App\\Filament\\Client\\Resources')
            ->discoverPages(in: app_path('Filament/Client/Pages'), for: 'App\\Filament\\Client\\Pages')
            ->discoverWidgets(in: app_path('Filament/Client/Widgets'), for: 'App\\Filament\\Client\\Widgets')
            ->resources([]);
    }
}
