<?php

namespace Tests\Feature\Console\Commands;

use App\Models\AlertSetting;
use Illuminate\Support\Facades\Cache;
use InsightLPR\BlackAdam\Enum\RoleEnum;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('alerts')]
#[Group('alert-settings')]
class CreateMissedOpportunityAlertSettingsTest extends TestCase
{
    public function test_creates_an_m_alert_for_an_admin_without_one(): void
    {
        $this->freezeTime();
        $admin = $this->createUser(role: RoleEnum::ADMIN->value);

        Cache::put('hotlist-clients:'.$admin->org_id, collect([7 => 'Client Seven']), 3600);
        Cache::put('illuminate:cache:flexible:created:hotlist-clients:'.$admin->org_id, now()->getTimestamp(), 3600);

        $this->artisan('app:admin-alerts', ['--org' => $admin->org_id])->assertSuccessful();

        $alert = AlertSetting::query()->where('alertable_type', 'user')->where('alertable_id', $admin->id)->sole();

        $this->assertSame(['M'], $alert->settings->types);
        $this->assertSame([7], $alert->settings->clients);
        $this->assertSame([$admin->email], $alert->settings->notifications['emails']);
        $this->assertSame($admin->org_id, $alert->org_id);
    }
}
