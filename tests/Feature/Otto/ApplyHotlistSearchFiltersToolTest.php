<?php

namespace Tests\Feature\Otto;

use App\Ai\PageContext;
use App\Ai\Tools\ApplyHotlistSearchFiltersTool;
use App\Ai\Tools\HotlistSearchTool;
use App\Data\AgentInvestigation\AgentInvestigationProperties;
use App\Enums\HotlistStatusEnum;
use App\Events\OttoPageActionProposed;
use App\Events\OttoWidgetCreated;
use App\Models\Investigation;
use App\Models\User;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use InsightLPR\BlackAdam\Enum\PermissionEnum;
use Laravel\Ai\Tools\Request;
use Tests\TestCase;

class ApplyHotlistSearchFiltersToolTest extends TestCase
{
    private function makeInvestigation(?string $conversationId = 'conv-hotlist'): Investigation
    {
        $investigation = new Investigation;
        $investigation->data = new AgentInvestigationProperties;
        $investigation->setActiveConversation($conversationId);

        return $investigation;
    }

    private function tool(?User $user = null, ?Investigation $investigation = null): ApplyHotlistSearchFiltersTool
    {
        $user ??= $this->createUser(permissions: PermissionEnum::ViewAny_Hotlist->value);

        return new ApplyHotlistSearchFiltersTool($user, $investigation ?? $this->makeInvestigation());
    }

    public function test_it_is_offered_only_on_the_hotlist_page(): void
    {
        $tool = $this->tool();

        $this->assertTrue($tool->availableOnPage(new PageContext('hotlist-search')));
        $this->assertFalse($tool->availableOnPage(new PageContext('scan-search')));
        $this->assertFalse($tool->availableOnPage(null));
    }

    public function test_it_requires_view_hotlist_permission(): void
    {
        $allowed = $this->createUser(permissions: PermissionEnum::ViewAny_Hotlist->value);
        $this->assertTrue($this->tool($allowed)->authorizedFor($allowed));

        $denied = $this->createUser();
        $this->assertFalse($this->tool($denied)->authorizedFor($denied));
    }

    public function test_it_proposes_plate_vin_and_status(): void
    {
        Event::fake([OttoPageActionProposed::class]);

        $conversationId = (string) Str::uuid();
        (string) $this->tool(investigation: $this->makeInvestigation($conversationId))
            ->handle(new Request([
                'plate' => 'abc1234',
                'vin' => '1hgcm',
                'status' => 'Open',
            ]));

        Event::assertDispatched(OttoPageActionProposed::class, function (OttoPageActionProposed $event) use ($conversationId): bool {
            $summary = (string) collect($event->action->changes)->firstWhere('field', 'status')?->summary;

            return $event->conversationId === $conversationId
                && $event->action->name === ApplyHotlistSearchFiltersTool::ACTION
                && $this->changeValue($event->action, 'plate') === 'ABC1234'
                && $this->changeValue($event->action, 'vin') === '1HGCM'
                && $this->changeValue($event->action, 'status') === HotlistStatusEnum::Open->value
                && str_contains($summary, 'Open')
                && ! str_contains($summary, (string) HotlistStatusEnum::Open->value);
        });
    }

    public function test_closed_positive_status_chip_is_human(): void
    {
        Event::fake([OttoPageActionProposed::class]);

        (string) $this->tool()->handle(new Request(['status' => 'ClosedPositive']));

        Event::assertDispatched(OttoPageActionProposed::class, function (OttoPageActionProposed $event): bool {
            $summary = (string) collect($event->action->changes)->firstWhere('field', 'status')?->summary;

            return $this->changeValue($event->action, 'status') === HotlistStatusEnum::ClosedPositive->value
                && str_contains($summary, 'Closed Positive')
                && ! str_contains($summary, 'ClosedPositive');
        });
    }

    public function test_hotlist_search_tool_forwards_into_a_proposal_on_the_hotlist_page(): void
    {
        Event::fake([OttoPageActionProposed::class]);

        $user = $this->createUser(permissions: PermissionEnum::ViewAny_Hotlist->value);
        $investigation = $this->makeInvestigation('conv-forward-hotlist');

        $result = (string) (new HotlistSearchTool($user, $investigation))
            ->onPage(new PageContext('hotlist-search', 'Hotlist', []))
            ->handle(new Request(['plate' => 'ASNPLT']));

        $this->assertStringContainsString('Proposed to the user', $result);
        Event::assertDispatched(OttoPageActionProposed::class);
    }

    public function test_hotlist_search_tool_still_runs_in_chat_for_date_only_requests(): void
    {
        Event::fake([OttoPageActionProposed::class, OttoWidgetCreated::class]);

        $user = $this->createUser(permissions: PermissionEnum::ViewAny_Hotlist->value);
        $investigation = $this->makeInvestigation('conv-hotlist-dates');

        $result = (string) (new HotlistSearchTool($user, $investigation))
            ->onPage(new PageContext('hotlist-search', 'Hotlist', []))
            ->handle(new Request(['time_range' => 'last_7_days']));

        $this->assertStringNotContainsString('Proposed to the user', $result);
        Event::assertNotDispatched(OttoPageActionProposed::class);
    }

    /**
     * @return mixed
     */
    private function changeValue(object $action, string $field)
    {
        return collect($action->changes)->firstWhere('field', $field)?->value;
    }
}
