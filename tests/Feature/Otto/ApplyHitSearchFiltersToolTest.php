<?php

namespace Tests\Feature\Otto;

use App\Ai\PageContext;
use App\Ai\Tools\ApplyHitSearchFiltersTool;
use App\Ai\Tools\HitSearchTool;
use App\Data\AgentInvestigation\AgentInvestigationProperties;
use App\Enums\HitTypeEnum;
use App\Events\OttoPageActionProposed;
use App\Models\Investigation;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use InsightLPR\BlackAdam\Enum\PermissionEnum;
use Laravel\Ai\Tools\Request;
use Tests\TestCase;

class ApplyHitSearchFiltersToolTest extends TestCase
{
    private function makeInvestigation(?string $conversationId = 'conv-hit'): Investigation
    {
        $investigation = new Investigation;
        $investigation->data = new AgentInvestigationProperties;
        $investigation->setActiveConversation($conversationId);

        return $investigation;
    }

    private function tool(?User $user = null, ?Investigation $investigation = null): ApplyHitSearchFiltersTool
    {
        $user ??= $this->createUser(permissions: PermissionEnum::ViewAny_Hit->value);

        return new ApplyHitSearchFiltersTool($user, $investigation ?? $this->makeInvestigation());
    }

    public function test_it_is_offered_only_on_the_hit_search_page(): void
    {
        $tool = $this->tool();

        $this->assertTrue($tool->availableOnPage(new PageContext('hit-search')));
        $this->assertFalse($tool->availableOnPage(new PageContext('scan-search')));
        $this->assertFalse($tool->availableOnPage(null));
    }

    public function test_it_requires_view_hits_permission(): void
    {
        $allowed = $this->createUser(permissions: PermissionEnum::ViewAny_Hit->value);
        $this->assertTrue($this->tool($allowed)->authorizedFor($allowed));

        $denied = $this->createUser();
        $this->assertFalse($this->tool($denied)->authorizedFor($denied));
    }

    public function test_it_proposes_plate_hit_type_and_preset_without_yesterday(): void
    {
        Event::fake([OttoPageActionProposed::class]);

        $conversationId = (string) Str::uuid();
        (string) $this->tool(investigation: $this->makeInvestigation($conversationId))
            ->handle(new Request([
                'plate' => 'abc1234',
                'hit_type' => 'live',
                'time_range' => 'last_30_days',
            ]));

        Event::assertDispatched(OttoPageActionProposed::class, function (OttoPageActionProposed $event) use ($conversationId): bool {
            $summary = (string) collect($event->action->changes)->firstWhere('field', 'time_range')?->summary;
            $hitTypeSummary = (string) collect($event->action->changes)->firstWhere('field', 'hit_type')?->summary;

            return $event->conversationId === $conversationId
                && $event->action->name === ApplyHitSearchFiltersTool::ACTION
                && $this->changeValue($event->action, 'plate') === 'ABC1234'
                && $this->changeValue($event->action, 'hit_type') === HitTypeEnum::Live->value
                && $this->changeValue($event->action, 'time_range') === 'last_30d'
                && str_contains($summary, 'last 30 days')
                && ! str_contains($summary, 'last_30d')
                && ! str_contains($summary, 'last_30_days')
                && ! str_contains($summary, 'time_range')
                && str_contains($hitTypeSummary, 'Live')
                && ! str_contains($hitTypeSummary, 'Staging_Live');
        });
    }

    public function test_yesterday_becomes_a_custom_range_because_hits_lack_that_toggle(): void
    {
        Event::fake([OttoPageActionProposed::class]);
        Carbon::setTestNow(Carbon::parse('2026-07-16 15:00:00', 'America/New_York'));

        try {
            $user = $this->createUser([
                'dti' => ['timeZone' => 'America/New_York'],
            ], permissions: PermissionEnum::ViewAny_Hit->value);

            (string) $this->tool($user)->handle(new Request(['time_range' => 'yesterday']));

            Event::assertDispatched(OttoPageActionProposed::class, function (OttoPageActionProposed $event): bool {
                $value = $this->changeValue($event->action, 'time_range');
                $summary = (string) collect($event->action->changes)->firstWhere('field', 'time_range')?->summary;

                return is_array($value)
                    && isset($value['start'], $value['end'])
                    && str_contains($summary, 'yesterday')
                    && ! str_contains($summary, 'time_range')
                    && ! str_contains($summary, 'last_7d');
            });
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_hit_search_tool_forwards_into_a_proposal_on_the_hits_page(): void
    {
        Event::fake([OttoPageActionProposed::class]);

        $user = $this->createUser(permissions: PermissionEnum::ViewAny_Hit->value);
        $investigation = $this->makeInvestigation('conv-forward-hit');

        $result = (string) (new HitSearchTool($user, $investigation))
            ->onPage(new PageContext('hit-search', 'Hits', []))
            ->handle(new Request(['plate' => 'HITPLT']));

        $this->assertStringContainsString('Proposed to the user', $result);
        Event::assertDispatched(OttoPageActionProposed::class);
        $this->assertSame(ApplyHitSearchFiltersTool::ACTION, $investigation->pullProposedPageActions()[0]->name);
    }

    public function test_hit_search_tool_forwards_today_without_plate_on_the_hits_page(): void
    {
        Event::fake([OttoPageActionProposed::class]);

        $user = $this->createUser(permissions: PermissionEnum::ViewAny_Hit->value);
        $investigation = $this->makeInvestigation('conv-forward-today-hit');

        $result = (string) (new HitSearchTool($user, $investigation))
            ->onPage(new PageContext('hit-search', 'Hits', []))
            ->handle(new Request(['time_range' => 'today']));

        $this->assertStringContainsString('Proposed to the user', $result);
        Event::assertDispatched(OttoPageActionProposed::class);

        $action = $investigation->pullProposedPageActions()[0];
        $this->assertSame(ApplyHitSearchFiltersTool::ACTION, $action->name);
        $this->assertSame('time_range', $action->changes[0]->field);
        $this->assertSame('today', $action->changes[0]->value);
    }

    public function test_latest_without_a_time_range_proposes_the_last_twelve_hours(): void
    {
        Event::fake([OttoPageActionProposed::class]);
        Carbon::setTestNow(Carbon::parse('2026-07-16 15:00:00', 'America/New_York'));

        try {
            $user = $this->createUser([
                'dti' => ['timeZone' => 'America/New_York'],
            ], permissions: PermissionEnum::ViewAny_Hit->value);
            $investigation = $this->makeInvestigation();
            $investigation->setActivePrompt('show me the latest hits');

            (string) $this->tool($user, $investigation)->handle(new Request([
                'time_range' => 'last_30_days',
            ]));

            Event::assertDispatched(OttoPageActionProposed::class, function (OttoPageActionProposed $event): bool {
                $value = $this->changeValue($event->action, 'time_range');
                $summary = (string) collect($event->action->changes)->firstWhere('field', 'time_range')?->summary;

                if (! is_array($value)) {
                    return false;
                }

                $start = Carbon::parse($value['start'])->timezone('America/New_York');
                $end = Carbon::parse($value['end'])->timezone('America/New_York');

                return $start->format('Y-m-d H:i:s') === '2026-07-16 03:00:00'
                    && $end->format('Y-m-d H:i:s') === '2026-07-16 15:00:00'
                    && str_contains($summary, 'last 12 hours')
                    && ! str_contains($summary, 'last_12_hours')
                    && ! str_contains($summary, 'last 30 days');
            });
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_latest_with_today_keeps_the_today_toggle(): void
    {
        Event::fake([OttoPageActionProposed::class]);

        $investigation = $this->makeInvestigation();
        $investigation->setActivePrompt('latest hits today');

        (string) $this->tool(investigation: $investigation)->handle(new Request([
            'time_range' => 'last_30_days',
        ]));

        Event::assertDispatched(OttoPageActionProposed::class, function (OttoPageActionProposed $event): bool {
            return $this->changeValue($event->action, 'time_range') === 'today';
        });
    }

    public function test_probable_match_chip_uses_the_human_hit_type_title(): void
    {
        Event::fake([OttoPageActionProposed::class]);

        (string) $this->tool()->handle(new Request(['hit_type' => 'probable match']));

        Event::assertDispatched(OttoPageActionProposed::class, function (OttoPageActionProposed $event): bool {
            $summary = (string) collect($event->action->changes)->firstWhere('field', 'hit_type')?->summary;

            return $this->changeValue($event->action, 'hit_type') === HitTypeEnum::Probable_Match->value
                && str_contains($summary, 'Probable Match')
                && ! str_contains($summary, 'Probable_Match');
        });
    }

    /**
     * @return mixed
     */
    private function changeValue(object $action, string $field)
    {
        return collect($action->changes)->firstWhere('field', $field)?->value;
    }
}
