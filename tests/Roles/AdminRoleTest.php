<?php

namespace Tests\Roles;

use App\Filament\Client\Resources\ClientSearchScansResource;
use App\Filament\Pages\Dashboard;
use App\Filament\Pages\KitUserPath;
use App\Filament\Pages\KitUserRecoveries;
use App\Filament\Pages\Roles;
use App\Filament\Resources\ActivityResource;
use App\Filament\Resources\AffiliateStatsResource;
use App\Filament\Resources\HitsResource;
use App\Filament\Resources\HotlistResource;
use App\Filament\Resources\InviteResource;
use App\Filament\Resources\ScansResource;
use App\Filament\Resources\UsersResource;
use InsightLPR\BlackAdam\Enum\RoleEnum;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\RequiresClickhouse;
use Tests\RoleTestCase;

class AdminRoleTest extends RoleTestCase
{
    use RequiresClickhouse;

    protected RoleEnum $role = RoleEnum::ADMIN;

    protected bool $pro = true;

    #[Group('admin')]
    public function test_can_see_everything(): void
    {
        collect([
            Dashboard::getUrl(panel: 'app'),
            ScansResource::getUrl(panel: 'app'),
            HitsResource::getUrl(panel: 'app'),
            HotlistResource::getUrl(panel: 'app'),
            KitUserPath::getUrl(panel: 'app'),
            AffiliateStatsResource::getUrl(panel: 'app'),
            KitUserRecoveries::getUrl(panel: 'app'),
            UsersResource::getUrl(panel: 'app'),
            Roles::getUrl(panel: 'app'),
            InviteResource::getUrl(panel: 'app'),
            ActivityResource::getUrl(panel: 'app'),
        ])->each(function ($resource) {
            $this->get($resource)->assertSuccessful();
        });

        collect([
            ClientSearchScansResource::getUrl(panel: 'client'),
        ])->each(function ($resource) {
            // ClientMiddleware
            $this->get($resource)->assertRedirect(
                Dashboard::getUrl(panel: 'app'),
            );
        });
    }
}
