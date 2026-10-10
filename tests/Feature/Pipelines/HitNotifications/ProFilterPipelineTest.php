<?php

namespace Tests\Feature\Pipelines\HitNotifications;

use App\Models\AlertSetting;
use App\Pipelines\HitNotifications\ProFilterPipeline;
use Illuminate\Support\Facades\Pipeline;
use PHPUnit\Framework\Attributes\Group;
use Tests\Feature\Pipelines\HitNotifications\Support\PipelineSupport;
use Tests\TestCase;

class ProFilterPipelineTest extends TestCase
{
    use PipelineSupport;

    #[Group('pipelines')]
    public function test_can_pass_as_pro(): void
    {
        $user = $this->createUser(pro: true);

        $data = $this->createHitNotificationData(function ($missedOpportunity, $user) {
            return AlertSetting::factory()
                ->forUser($user)
                ->forTypes($missedOpportunity)
                ->create();
        }, $user);

        $result = Pipeline::send($data)
            ->through([
                ProFilterPipeline::class,
            ])
            ->thenReturn();

        $this->assertNotNull($result);
    }

    #[Group('pipelines')]
    public function test_cannot_pass_without_pro(): void
    {
        $user = $this->createUser(pro: false);

        $data = $this->createHitNotificationData(function ($missedOpportunity, $user) {
            return AlertSetting::factory()
                ->forUser($user)
                ->forTypes($missedOpportunity)
                ->create();
        }, $user);

        $result = Pipeline::send($data)
            ->through([
                ProFilterPipeline::class,
            ])
            ->thenReturn();

        $this->assertNull($result);
    }
}
