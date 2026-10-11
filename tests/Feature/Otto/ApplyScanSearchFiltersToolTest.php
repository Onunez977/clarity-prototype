<?php

namespace Tests\Feature\Otto;

use App\Ai\PageAction;
use App\Ai\PageActionChange;
use App\Ai\PageContext;
use App\Ai\Tools\ApplyScanSearchFiltersTool;
use App\Ai\Tools\ScanSearchTool;
use App\Data\AgentInvestigation\AgentInvestigationProperties;
use App\Events\OttoPageActionProposed;
use App\Models\Investigation;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use InsightLPR\BlackAdam\Enum\PermissionEnum;
use Laravel\Ai\Tools\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ApplyScanSearchFiltersToolTest extends TestCase
{
    private function makeInvestigation(?string $conversationId = 'conv-scan'): Investigation
    {
        $investigation = new Investigation;
        $investigation->data = new AgentInvestigationProperties;
        $investigation->setActiveConversation($conversationId);

        return $investigation;
    }

    private function tool(?User $user = null, ?Investigation $investigation = null): ApplyScanSearchFiltersTool
    {
        $user ??= $this->createUser(permissions: PermissionEnum::ViewAny_SCAN->value);

        return new ApplyScanSearchFiltersTool($user, $investigation ?? $this->makeInvestigation());
    }

    public function test_it_is_offered_only_on_the_scan_search_page(): void
    {
        $tool = $this->tool();

        $this->assertTrue($tool->availableOnPage(new PageContext('scan-search')));
        $this->assertFalse($tool->availableOnPage(new PageContext('hit-search')));
        $this->assertFalse($tool->availableOnPage(null));
    }

    public function test_it_requires_view_scans_permission(): void
    {
        $allowed = $this->createUser(permissions: PermissionEnum::ViewAny_SCAN->value);
        $this->assertTrue($this->tool($allowed)->authorizedFor($allowed));

        $denied = $this->createUser();
        $this->assertFalse($this->tool($denied)->authorizedFor($denied));
    }

    public function test_it_proposes_granular_changes_and_broadcasts(): void
    {
        Event::fake([OttoPageActionProposed::class]);

        $conversationId = (string) Str::uuid();
        $result = (string) $this->tool(investigation: $this->makeInvestigation($conversationId))
            ->handle(new Request([
                'plate' => 'abc1234',
                'time_range' => 'last_7_days',
            ]));

        $this->assertStringContainsString('Proposed', $result);
        $this->assertStringContainsString('Do NOT list or restate the individual filters', $result);

        Event::assertDispatched(OttoPageActionProposed::class, function (OttoPageActionProposed $event) use ($conversationId): bool {
            $action = $event->action;
            $summary = $this->changeSummary($action, 'time_range');

            return $event->conversationId === $conversationId
                && $action->pageType === ApplyScanSearchFiltersTool::PAGE_TYPE
                && $action->name === ApplyScanSearchFiltersTool::ACTION
                && $this->changeValue($action, 'plate') === 'ABC1234'
                && $this->changeValue($action, 'time_range') === 'last_7d'
                && $this->chipSummaryIsHuman($summary, 'last 7 days');
        });
    }

    #[DataProvider('scanPresetProvider')]
    public function test_preset_chip_copy_is_human_and_payload_uses_filament_keys(
        string $argument,
        mixed $expectedPayload,
        string $expectedPhrase,
    ): void {
        Event::fake([OttoPageActionProposed::class]);

        (string) $this->tool()->handle(new Request(['time_range' => $argument]));

        Event::assertDispatched(OttoPageActionProposed::class, function (OttoPageActionProposed $event) use ($expectedPayload, $expectedPhrase): bool {
            $value = $this->changeValue($event->action, 'time_range');
            $summary = $this->changeSummary($event->action, 'time_range');

            if (is_array($expectedPayload)) {
                return is_array($value)
                    && isset($value['start'], $value['end'])
                    && $this->chipSummaryIsHuman($summary, $expectedPhrase);
            }

            return $value === $expectedPayload
                && $this->chipSummaryIsHuman($summary, $expectedPhrase);
        });
    }

    /**
     * @return array<string, array{0: string, 1: mixed, 2: string}>
     */
    public static function scanPresetProvider(): array
    {
        return [
            'today' => ['today', 'today', 'today'],
            'yesterday' => ['yesterday', 'yesterday', 'yesterday'],
            'last 7 days otto slug' => ['last_7_days', 'last_7d', 'last 7 days'],
            '7d alias' => ['7d', 'last_7d', 'last 7 days'],
            'filament last_7d alias' => ['last_7d', 'last_7d', 'last 7 days'],
            'last 30 days' => ['last_30_days', 'last_30d', 'last 30 days'],
            'last 90 days is custom payload' => ['last_90_days', ['start' => true, 'end' => true], 'last 90 days'],
        ];
    }

    public function test_custom_date_range_chip_uses_user_timezone_not_iso(): void
    {
        Event::fake([OttoPageActionProposed::class]);

        $user = $this->createUser([
            'dti' => ['timeZone' => 'America/New_York'],
        ], permissions: PermissionEnum::ViewAny_SCAN->value);

        (string) $this->tool($user)->handle(new Request([
            'start_date' => '2026-07-01T09:44:00',
            'end_date' => '2026-07-04T18:00:00',
        ]));

        Event::assertDispatched(OttoPageActionProposed::class, function (OttoPageActionProposed $event): bool {
            $value = $this->changeValue($event->action, 'time_range');
            $summary = $this->changeSummary($event->action, 'time_range');

            return is_array($value)
                && isset($value['start'], $value['end'])
                && str_contains((string) $value['start'], 'T')
                && $this->chipSummaryIsHuman($summary, 'Jul 1, 2026')
                && str_contains($summary, 'Jul 4, 2026')
                && ! str_contains($summary, '2026-07-01T')
                && ! str_contains($summary, '09:44:00');
        });
    }

    public function test_it_proposes_a_custom_date_range_with_offset(): void
    {
        Event::fake([OttoPageActionProposed::class]);

        $user = $this->createUser([
            'dti' => ['timeZone' => 'America/New_York'],
        ], permissions: PermissionEnum::ViewAny_SCAN->value);

        (string) $this->tool($user)->handle(new Request([
            'start_date' => '2026-07-01T09:44:00-07:00',
            'end_date' => '2026-05-04T21:00:00-07:00',
        ]));

        Event::assertDispatched(OttoPageActionProposed::class, function (OttoPageActionProposed $event): bool {
            $value = $this->changeValue($event->action, 'time_range');
            if (! is_array($value) || ! isset($value['start'], $value['end'])) {
                return false;
            }

            $start = Carbon::parse($value['start']);
            $end = Carbon::parse($value['end']);

            return $start->lessThan($end)
                && str_contains((string) $value['start'], '-')
                && $start->diffInDays($end) > 50;
        });
    }

    public function test_naive_custom_dates_are_interpreted_as_the_users_wall_clock(): void
    {
        Event::fake([OttoPageActionProposed::class]);

        $user = $this->createUser([
            'dti' => ['timeZone' => 'America/New_York'],
        ], permissions: PermissionEnum::ViewAny_SCAN->value);

        (string) $this->tool($user)->handle(new Request([
            'start_date' => '2026-07-14T14:13:00',
            'end_date' => '2026-07-20T14:13:00',
        ]));

        Event::assertDispatched(OttoPageActionProposed::class, function (OttoPageActionProposed $event): bool {
            $value = $this->changeValue($event->action, 'time_range');
            if (! is_array($value) || ! isset($value['end'])) {
                return false;
            }

            $end = Carbon::parse($value['end'])->timezone('America/New_York');

            return $end->format('Y-m-d g:i A T') === '2026-07-20 2:13 PM EDT'
                && ! str_contains((string) collect($event->action->changes)->firstWhere('field', 'time_range')?->summary, '10:13 AM');
        });
    }

    public function test_scan_search_tool_forwards_into_a_proposal_on_the_scans_page(): void
    {
        Event::fake([OttoPageActionProposed::class]);

        $user = $this->createUser(permissions: PermissionEnum::ViewAny_SCAN->value);
        $investigation = $this->makeInvestigation('conv-forward-scan');

        $result = (string) (new ScanSearchTool($user, $investigation))
            ->onPage(new PageContext('scan-search', 'Scans', []))
            ->handle(new Request(['plate' => 'Y61SCA']));

        $this->assertStringContainsString('Proposed to the user', $result);
        $this->assertStringNotContainsString('do NOT run it in the chat', $result);
        Event::assertDispatched(OttoPageActionProposed::class);

        $actions = $investigation->pullProposedPageActions();
        $this->assertCount(1, $actions);
        $this->assertSame(ApplyScanSearchFiltersTool::ACTION, $actions[0]->name);
        $this->assertSame('Y61SCA', $actions[0]->changes[0]->value);
    }

    public function test_scan_search_tool_forwards_today_without_plate_on_the_scans_page(): void
    {
        Event::fake([OttoPageActionProposed::class]);

        $user = $this->createUser(permissions: PermissionEnum::ViewAny_SCAN->value);
        $investigation = $this->makeInvestigation('conv-forward-today');

        $result = (string) (new ScanSearchTool($user, $investigation))
            ->onPage(new PageContext('scan-search', 'Scans', []))
            ->handle(new Request(['time_range' => 'today']));

        $this->assertStringContainsString('Proposed to the user', $result);
        $this->assertStringNotContainsString('do NOT run it in the chat', $result);
        Event::assertDispatched(OttoPageActionProposed::class);

        $actions = $investigation->pullProposedPageActions();
        $this->assertCount(1, $actions);
        $this->assertSame(ApplyScanSearchFiltersTool::ACTION, $actions[0]->name);
        $this->assertSame('time_range', $actions[0]->changes[0]->field);
        $this->assertSame('today', $actions[0]->changes[0]->value);
    }

    public function test_scan_search_tool_nudges_when_on_page_with_nothing_mappable(): void
    {
        $user = $this->createUser(permissions: PermissionEnum::ViewAny_SCAN->value);

        $nudge = (string) (new ScanSearchTool($user, $this->makeInvestigation()))
            ->onPage(new PageContext('scan-search', 'Scans', []))
            ->handle(new Request([]));

        $this->assertStringContainsString('do NOT run it in the chat', $nudge);
    }

    /**
     * @return mixed
     */
    private function changeValue(object $action, string $field)
    {
        return $this->change($action, $field)?->value;
    }

    private function changeSummary(object $action, string $field): string
    {
        return (string) $this->change($action, $field)?->summary;
    }

    private function change(object $action, string $field): ?PageActionChange
    {
        $found = collect($action instanceof PageAction ? $action->changes : [])->firstWhere('field', $field);

        return $found instanceof PageActionChange ? $found : null;
    }

    /**
     * Chip copy the user sees: a human phrase, never a slug/key.
     */
    private function chipSummaryIsHuman(string $summary, string $phrase): bool
    {
        foreach (['last_7d', 'last_7_days', 'last_30d', 'last_30_days', 'last_90d', 'last_90_days', 'time_range'] as $leak) {
            if (str_contains($summary, $leak)) {
                return false;
            }
        }

        return str_contains($summary, $phrase);
    }
}
