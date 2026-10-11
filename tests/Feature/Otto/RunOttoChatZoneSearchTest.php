<?php

namespace Tests\Feature\Otto;

use App\Data\AgentInvestigation\AgentInvestigationProperties;
use App\Datatype\SearchType;
use App\Enums\FeatureEnum;
use App\Events\OttoInvestigationUpdated;
use App\Events\OttoReplyReplaced;
use App\Events\OttoWidgetCreated;
use App\Jobs\RunOttoChat;
use App\Models\AgentConversation;
use App\Models\AgentInvestigation;
use App\Models\Investigation;
use App\Models\Org;
use App\Models\User;
use App\Models\Zone;
use App\Support\Otto\OttoScoutAccess;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use InsightLPR\BlackAdam\Enum\RoleEnum;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\ToolCall as ToolCallData;
use Laravel\Ai\Responses\StreamedAgentResponse;
use Laravel\Ai\Streaming\Events\TextDelta;
use Laravel\Ai\Streaming\Events\ToolCall;
use ReflectionMethod;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class RunOttoChatZoneSearchTest extends TestCase
{
    private const REFUSAL = 'I don’t have a zone search available in this session, so I can’t look up your Scout patrol zones right now. If you want, I can still help with scans, hits, assignments, or a product how-to.';

    protected function setUp(): void
    {
        parent::setUp();

        Event::fake([
            OttoWidgetCreated::class,
            OttoReplyReplaced::class,
            OttoInvestigationUpdated::class,
        ]);
        Queue::fake();
        config()->set('app.experimental.clarity_scout', true);
        config()->set('ai.conversations.generate_title', false);
        Permission::findOrCreate('clarity-scout', 'web');
        Permission::findOrCreate('use-otto', 'web');
    }

    public function test_a_zone_question_cannot_finish_without_a_zone_search_tool_call(): void
    {
        [$admin] = $this->createScoutAdmin();
        $downtown = Zone::factory()->forOrg($admin->org)->create(['name' => 'Downtown Dallas']);
        $north = Zone::factory()->forOrg($admin->org)->create(['name' => 'North loop']);
        $conversationId = $this->makeConversation($admin);
        $this->insertMessage($admin, $conversationId, 'user', 'what zones do i have');
        $this->insertMessage($admin, $conversationId, 'assistant', self::REFUSAL);

        $investigation = $this->freshInvestigation($conversationId);
        $response = $this->refusalResponse('inv-zone');

        $this->finishTurn($admin, 'what zones do i have', $conversationId, $investigation, $response);

        $stored = AgentInvestigation::query()->where('conversation_id', $conversationId)->first();
        $this->assertNotNull($stored);

        $searches = collect($stored->properties->searches)
            ->filter(fn ($search): bool => $search->searchType === SearchType::Zone)
            ->values();

        $this->assertCount(1, $searches);
        $this->assertSame('Zone search', $searches[0]->title);
        $this->assertNull($searches[0]->parameters['name'] ?? null);
        $this->assertNull($searches[0]->parameters['is_no_scan_zone'] ?? null);
        $this->assertNull($searches[0]->parameters['assigned_driver'] ?? null);
        $this->assertEqualsCanonicalizing(
            [$downtown->id, $north->id],
            collect($stored->properties->searchResults)->pluck('resultId')->all(),
        );

        $content = $this->assistantContent($conversationId);
        $this->assertStringContainsString('Here are your Scout patrol zones.', $content);
        $this->assertMatchesRegularExpression('/<!--otto_table:zone:[a-zA-Z0-9]+-->/', $content);
        $this->assertStringNotContainsString('ZoneSearch', $content);
        $this->assertStringNotContainsString('available in this session', $content);
        $this->assertStringNotContainsString(self::REFUSAL, $content);

        Event::assertDispatched(OttoReplyReplaced::class, function (OttoReplyReplaced $event) use ($conversationId): bool {
            return $event->conversationId === $conversationId
                && str_contains($event->content, '<!--otto_table:zone:')
                && ! str_contains($event->content, 'ZoneSearch');
        });
        Queue::assertNotPushed(RunOttoChat::class);
    }

    public function test_a_no_scan_zone_question_runs_zone_search_with_that_filter(): void
    {
        [$admin] = $this->createScoutAdmin();
        $noScan = Zone::factory()->forOrg($admin->org)->noScan()->create(['name' => 'DFW Airport']);
        Zone::factory()->forOrg($admin->org)->create(['name' => 'Downtown Dallas']);
        $conversationId = $this->makeConversation($admin);
        $prompt = 'which zones are no-scan?';
        $this->insertMessage($admin, $conversationId, 'user', $prompt);
        $this->insertMessage($admin, $conversationId, 'assistant', self::REFUSAL);

        $this->finishTurn($admin, $prompt, $conversationId, $this->freshInvestigation($conversationId), $this->refusalResponse('inv-noscan'));

        $stored = AgentInvestigation::query()->where('conversation_id', $conversationId)->first();
        $search = collect($stored->properties->searches)->first(fn ($row): bool => $row->searchType === SearchType::Zone);

        $this->assertNotNull($search);
        $this->assertTrue($search->parameters['is_no_scan_zone']);
        $this->assertSame([$noScan->id], collect($stored->properties->searchResults)->pluck('resultId')->all());
        $this->assertStringContainsString('Here are your no-scan zones.', $this->assistantContent($conversationId));
        $this->assertStringNotContainsString('ZoneSearch', $this->assistantContent($conversationId));
    }

    public function test_a_zone_question_that_already_called_zone_search_is_not_run_again(): void
    {
        [$admin] = $this->createScoutAdmin();
        Zone::factory()->forOrg($admin->org)->create(['name' => 'Downtown Dallas']);
        $conversationId = $this->makeConversation($admin);
        $kept = 'Here are your Scout patrol zones.';
        $this->insertMessage($admin, $conversationId, 'user', 'what zones do i have');
        $this->insertMessage($admin, $conversationId, 'assistant', $kept);

        $response = new StreamedAgentResponse('inv-called', new Collection([
            new ToolCall('e1', new ToolCallData('call-1', 'ZoneSearchTool', []), 1),
            new TextDelta('d1', 'm1', $kept, 1),
        ]), new Meta);

        $this->finishTurn($admin, 'what zones do i have', $conversationId, $this->freshInvestigation($conversationId), $response);

        $this->assertSame($kept, $this->assistantContent($conversationId));
        $this->assertSame([], $this->storedSearches($conversationId));
        Event::assertNotDispatched(OttoReplyReplaced::class);
    }

    public function test_a_non_zone_question_does_not_run_zone_search(): void
    {
        [$admin] = $this->createScoutAdmin();
        $conversationId = $this->makeConversation($admin);
        $kept = 'Which plate should I look up?';
        $this->insertMessage($admin, $conversationId, 'user', 'show me today\'s scans');
        $this->insertMessage($admin, $conversationId, 'assistant', $kept);

        $this->finishTurn(
            $admin,
            'show me today\'s scans',
            $conversationId,
            $this->freshInvestigation($conversationId),
            new StreamedAgentResponse('inv-scans', new Collection([
                new TextDelta('d1', 'm1', $kept, 1),
            ]), new Meta),
        );

        $this->assertSame($kept, $this->assistantContent($conversationId));
        $this->assertSame([], $this->storedSearches($conversationId));
    }

    public function test_a_zone_question_without_scout_relays_the_denial_instead_of_claiming_search_is_unavailable(): void
    {
        $admin = $this->createAdminWithoutScout();
        $conversationId = $this->makeConversation($admin);
        $this->insertMessage($admin, $conversationId, 'user', 'what zones do i have');
        $this->insertMessage($admin, $conversationId, 'assistant', self::REFUSAL);

        $this->finishTurn($admin, 'what zones do i have', $conversationId, $this->freshInvestigation($conversationId), $this->refusalResponse('inv-denied'));

        $this->assertSame(OttoScoutAccess::ZONE_NEEDS_SCOUT, $this->assistantContent($conversationId));
        $this->assertStringNotContainsString('available in this session', $this->assistantContent($conversationId));
        $this->assertSame([], $this->storedSearches($conversationId));
    }

    private function finishTurn(
        User $user,
        string $prompt,
        string $conversationId,
        Investigation $investigation,
        StreamedAgentResponse $response,
    ): void {
        $job = new RunOttoChat($user, $prompt, $conversationId);
        $method = new ReflectionMethod(RunOttoChat::class, 'onStreamComplete');
        $method->invoke($job, $investigation, $response);
    }

    private function freshInvestigation(string $conversationId): Investigation
    {
        $investigation = new Investigation;
        $investigation->data = new AgentInvestigationProperties;
        $investigation->beginToolOutcomeTracking();
        $investigation->setActiveConversation($conversationId);

        return $investigation;
    }

    private function refusalResponse(string $invocationId): StreamedAgentResponse
    {
        return new StreamedAgentResponse($invocationId, new Collection([
            new TextDelta('d1', 'm1', self::REFUSAL, 1),
        ]), new Meta);
    }

    private function makeConversation(User $user): string
    {
        $conversationId = (string) Str::uuid7();

        AgentConversation::query()->create([
            'id' => $conversationId,
            'user_id' => $user->id,
            'title' => 'Existing conversation',
        ]);

        return $conversationId;
    }

    private function insertMessage(User $user, string $conversationId, string $role, string $content): void
    {
        DB::table('agent_conversation_messages')->insert([
            'id' => (string) Str::uuid7(),
            'conversation_id' => $conversationId,
            'user_id' => $user->id,
            'agent' => 'otto',
            'role' => $role,
            'content' => $content,
            'attachments' => '[]',
            'tool_calls' => '[]',
            'tool_results' => '[]',
            'usage' => '{}',
            'meta' => '{}',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function assistantContent(string $conversationId): string
    {
        return (string) DB::table('agent_conversation_messages')
            ->where('conversation_id', $conversationId)
            ->where('role', 'assistant')
            ->value('content');
    }

    /**
     * @return list<mixed>
     */
    private function storedSearches(string $conversationId): array
    {
        $stored = AgentInvestigation::query()->where('conversation_id', $conversationId)->first();

        return $stored?->properties->searches ?? [];
    }

    /**
     * @return array{0: User}
     */
    private function createScoutAdmin(): array
    {
        $org = Org::factory()->create();
        $this->makeOrgPro($org, FeatureEnum::CLARITY_SCOUT->value);

        $admin = User::factory()->forOrg($org)->create();
        setPermissionsTeamId($org->id);
        $admin->assignRole(RoleEnum::ADMIN->value);

        return [$admin->fresh()];
    }

    private function createAdminWithoutScout(): User
    {
        $org = Org::factory()->create();
        $user = User::factory()->forOrg($org)->create();
        setPermissionsTeamId($org->id);
        $user->assignRole(RoleEnum::ADMIN->value);

        return $user;
    }
}
