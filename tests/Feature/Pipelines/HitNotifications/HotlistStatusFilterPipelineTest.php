<?php

namespace Tests\Feature\Pipelines\HitNotifications;

use App\Enums\HitTypeEnum;
use App\Enums\HotlistStatusEnum;
use App\Models\AlertSetting;
use App\Pipelines\HitNotifications\HotlistStatusFilterPipeline;
use Illuminate\Support\Facades\Pipeline;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\Feature\Pipelines\HitNotifications\Support\PipelineSupport;
use Tests\TestCase;

class HotlistStatusFilterPipelineTest extends TestCase
{
    use PipelineSupport;

    public static function terminalStatuses(): array
    {
        return [
            'repossessed' => [HotlistStatusEnum::Repossessed],
            'closed positive' => [HotlistStatusEnum::ClosedPositive],
            'closed' => [HotlistStatusEnum::Closed],
            'invalid' => [HotlistStatusEnum::Invalid],
        ];
    }

    public static function nonTerminalStatuses(): array
    {
        return [
            'open' => [HotlistStatusEnum::Open],
            'hold' => [HotlistStatusEnum::Hold],
            'new from client' => [HotlistStatusEnum::NewFromClient],
            'other' => [HotlistStatusEnum::Other],
        ];
    }

    #[Group('pipelines')]
    #[DataProvider('terminalStatuses')]
    public function test_suppresses_missed_opportunity_on_terminal_hotlist(HotlistStatusEnum $status): void
    {
        $data = $this->createHitNotificationData(function ($missedOpportunity, $user) use ($status) {
            $missedOpportunity->hotlist->update(['status' => $status->value]);

            return AlertSetting::factory()
                ->forUser($user)
                ->forTypes($missedOpportunity)
                ->create();
        });

        $result = Pipeline::send($data)
            ->through([
                HotlistStatusFilterPipeline::class,
            ])
            ->thenReturn();

        $this->assertNull($result);
    }

    #[Group('pipelines')]
    #[DataProvider('nonTerminalStatuses')]
    public function test_passes_missed_opportunity_on_non_terminal_hotlist(HotlistStatusEnum $status): void
    {
        $data = $this->createHitNotificationData(function ($missedOpportunity, $user) use ($status) {
            $missedOpportunity->hotlist->update(['status' => $status->value]);

            return AlertSetting::factory()
                ->forUser($user)
                ->forTypes($missedOpportunity)
                ->create();
        });

        $result = Pipeline::send($data)
            ->through([
                HotlistStatusFilterPipeline::class,
            ])
            ->thenReturn();

        $this->assertNotNull($result);
    }

    #[Group('pipelines')]
    public function test_passes_non_missed_opportunity_hit_on_terminal_hotlist(): void
    {
        $data = $this->createHitNotificationData(function ($missedOpportunity, $user) {
            $missedOpportunity->hotlist->update(['status' => HotlistStatusEnum::Closed->value]);

            return AlertSetting::factory()
                ->forUser($user)
                ->forTypes($missedOpportunity)
                ->create();
        });

        // A live hit against a closed hotlist is out of scope for this filter.
        $data->hit->hit_type = HitTypeEnum::Live->value;

        $result = Pipeline::send($data)
            ->through([
                HotlistStatusFilterPipeline::class,
            ])
            ->thenReturn();

        $this->assertNotNull($result);
    }

    #[Group('pipelines')]
    public function test_passes_when_hotlist_cannot_be_resolved(): void
    {
        $data = $this->createHitNotificationData(function ($missedOpportunity, $user) {
            return AlertSetting::factory()
                ->forUser($user)
                ->forTypes($missedOpportunity)
                ->create();
        });

        // Fail open: an unknown hotlist status must not silently drop the alert.
        $data->hit->setRelation('hotlist', null);

        $result = Pipeline::send($data)
            ->through([
                HotlistStatusFilterPipeline::class,
            ])
            ->thenReturn();

        $this->assertNotNull($result);
    }
}
