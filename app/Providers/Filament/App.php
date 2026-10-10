<?php

namespace App\Providers\Filament;

use App\Enums\FeatureEnum;
use App\Filament\Pages;
use App\Http\Middleware\UserMiddleware;
use App\Support\Filament\PlatformPanelProvider;
use Filament\Navigation\MenuItem;
use Filament\Navigation\NavigationItem;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\View\PanelsRenderHook;
use Illuminate\Support\Facades\Blade;
use InsightLPR\BlackAdam\Enum\PermissionEnum;
use InsightLPR\BlackAdam\Enum\RoleEnum;
use Laravel\Pennant\Feature;

class App extends PanelProvider
{
    use PlatformPanelProvider;

    public function panel(Panel $panel): Panel
    {
        return $this->makePanel($panel, authMiddleware: [UserMiddleware::class])
            ->default()
            ->id('app')
            ->path('app')
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\\Filament\\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\\Filament\\Pages')
            ->discoverClusters(in: app_path('Filament/Clusters'), for: 'App\\Filament\\Clusters')
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\\Filament\\Widgets')
            ->pages([
                Pages\Dashboard::class,
            ])
            ->userMenuItems([
                'profile' => MenuItem::make()
                    ->url(fn () => Pages\Profile::getUrl()),
            ])
            ->navigationGroups([
                'Search',
                'Geo-Spatial',
                'Scout',
                'Reports',
                'Billing',
                'Account',
            ])
            ->navigationItems([
                NavigationItem::make()
                    ->label('Org Map')
                    ->url(fn () => route('clarity-scout.org-map'))
                    ->icon('heroicon-o-globe-americas')
                    // routes/web.php registers this route only under the same config flag, and
                    // the admin_impersonator bypass did not check it — flag off + impersonating
                    // 500'd the whole panel shell on route('clarity-scout.org-map').
                    ->visible(fn () => config('app.experimental.clarity_scout', false)
                        && (Feature::active(FeatureEnum::CLARITY_SCOUT->slug()) || session()->has('admin_impersonator'))
                        && auth()->user()?->hasRole(RoleEnum::ADMIN->value))
                    ->group('Scout')
                    ->badge('Pro')
                    ->sort(0),

                NavigationItem::make()
                    ->label('Manage Billing')
                    ->url(fn () => route('billing'))
                    ->openUrlInNewTab()
                    ->icon('heroicon-o-credit-card')
                    ->visible(fn () => auth()->user()->can(PermissionEnum::Account_Billing->value))
                    ->group('Billing')
                    ->sort(1),

                NavigationItem::make()
                    ->label('Manage Subscription')
                    ->url(fn () => Pages\Subscription::getUrl())
                    ->icon('heroicon-o-signal')
                    ->group('Billing')
                    ->sort(2),
            ])
            ->renderHook(
                PanelsRenderHook::GLOBAL_SEARCH_AFTER,
                fn (): string => Blade::render('filament.hooks.whats-new'),
            )
            // Panel-boot registration (not FilamentView scopes: App::class) — LAYOUT_END
            // only receives page Livewire class scopes, so PanelProvider scoping never ran.
            ->renderHook(
                PanelsRenderHook::LAYOUT_END,
                fn (): string => Blade::render('components.otto.popout-mount'),
            );
    }
}
