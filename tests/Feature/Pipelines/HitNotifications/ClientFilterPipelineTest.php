<?php

namespace Tests\Feature\Pipelines\HitNotifications;

use App\Enums\HitTypeEnum;
use App\Models\AlertSetting;
use App\Pipelines\HitNotifications\ClientFilterPipeline;
use Illuminate\Support\Facades\Pipeline;
use PHPUnit\Framework\Attributes\Group;
use Tests\Feature\Pipelines\HitNotifications\Support\PipelineSupport;
use Tests\TestCase;

class ClientFilterPipelineTest extends TestCase
{
    use PipelineSupport;

    #[Group('pipelines')]
    public function test_can_pass_with_no_clients(): void
    {
        $data = $this->createHitNotificationData(function ($missedOpportunity, $user) {
            return AlertSetting::factory()
                ->forUser($user)
                ->forTypes($missedOpportunity)
                ->forClients([
                    // Empty
                ])
                ->create();
        });

        $result = Pipeline::send($data)
            ->through([
                ClientFilterPipeline::class,
            ])
            ->thenReturn();

        $this->assertNotNull($result);
    }

    #[Group('pipelines')]
    public function test_can_pass_when_client_exists(): void
    {
        $data = $this->createHitNotificationData(function ($missedOpportunity, $user) {
            return AlertSetting::factory()
                ->forUser($user)
                ->forTypes($missedOpportunity)
                ->forClients([
                    $missedOpportunity->hotlist->client_org_id,
                ])
                ->create();
        });

        $result = Pipeline::send($data)
            ->through([
                ClientFilterPipeline::class,
            ])
            ->thenReturn();

        $this->assertNotNull($result);
    }

    #[Group('pipelines')]
    public function test_cannot_pass_when_client_doesnt_exists(): void
    {
        $data = $this->createHitNotificationData(function ($missedOpportunity, $user) {
            return AlertSetting::factory()
                ->forUser($user)
                ->forTypes($missedOpportunity)
                ->forClients([
                    // Random, other Client
                    fake()->uuid(),
                ])
                ->create();
        });

        $result = Pipeline::send($data)
            ->through([
                ClientFilterPipeline::class,
            ])
            ->thenReturn();

        $this->assertNull($result);
    }

    #[Group('pipelines')]
    public function test_staging_live_bypasses_client_filter(): void
    {
        $data = $this->createHitNotificationData(function ($missedOpportunity, $user) {
            return AlertSetting::factory()
                ->forUser($user)
                ->forTypes($missedOpportunity)
                ->forClients([
                    fake()->uuid(),
                ])
                ->create();
        });

        $data->hit->hit_type = HitTypeEnum::Staging_Live->value;

        $result = Pipeline::send($data)
            ->through([
                ClientFilterPipeline::class,
            ])
            ->thenReturn();

        $this->assertNotNull($result);
    }

    #[Group('pipelines')]
    public function test_staging_historical_bypasses_client_filter(): void
    {
        $data = $this->createHitNotificationData(function ($missedOpportunity, $user) {
            return AlertSetting::factory()
                ->forUser($user)
                ->forTypes($missedOpportunity)
                ->forClients([
                    fake()->uuid(),
                ])
                ->create();
        });

        $data->hit->hit_type = HitTypeEnum::Staging_Historical->value;

        $result = Pipeline::send($data)
            ->through([
                ClientFilterPipeline::class,
            ])
            ->thenReturn();

        $this->assertNotNull($result);
    }
}
