<?php

declare(strict_types=1);

namespace Tests\Feature\Otto;

use App\Ai\PageAction;
use App\Ai\PageActionChange;
use App\Ai\Tools\ApplyScanSearchFiltersTool;
use App\Data\AgentInvestigation\AgentInvestigationProperties;
use App\Data\AgentInvestigation\Search;
use App\Datatype\SearchType;
use App\Enums\FeatureEnum;
use App\Enums\ScoutAnalyticsPage;
use App\Events\OttoInvestigationUpdated;
use App\Events\OttoUserMessagePosted;
use App\Events\OttoWidgetCreated;
use App\Jobs\RunOttoChat;
use App\Jobs\RunsOttoStream;
use App\Models\AgentConversation;
use App\Models\AgentInvestigation;
use App\Models\Org;
use App\Models\User;
use App\Models\Zone;
use App\Services\Otto\OttoConversation;
use App\Services\Zones\PersistZone;
use App\Services\Zones\ZipCodeLookup;
use App\Support\Otto\OttoAccess;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use InsightLPR\BlackAdam\Enum\RoleEnum;
use Mockery;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class OttoConversationHttpTest extends TestCase
{
    private int $insertedMessageCount = 0;

    protected function setUp(): void
    {
        parent::setUp();

        Permission::findOrCreate('use-otto', 'web');
        Permission::findOrCreate('clarity-scout', 'web');
        config()->set('app.experimental.clarity_scout', true);
    }

    public function test_guest_cannot_list_conversations(): void
    {
        $this->getJson(route('otto.conversations.index'))
            ->assertUnauthorized();
    }

    public function test_user_without_otto_access_is_forbidden(): void
    {
        $user = $this->createUser();
        $this->actingAs($user);

        $this->getJson(route('otto.conversations.index'))
            ->assertForbidden();
        $this->postJson(route('otto.conversations.store'), ['message' => 'hello'])
            ->assertForbidden();
    }

    public function test_send_creates_a_conversation_and_queues_run_otto_chat(): void
    {
        Queue::fake();
        Event::fake([OttoUserMessagePosted::class]);

        $user = $this->ottoUser();
        $this->actingAs($user);

        $response = $this->postJson(route('otto.conversations.store'), [
            'message' => 'Find scans for ABC1234',
        ]);

        $response->assertSuccessful()
            ->assertJsonStructure(['conversationId']);

        $conversationId = $response->json('conversationId');
        $this->assertIsString($conversationId);
        $this->assertNotEmpty($conversationId);

        Queue::assertPushedOn('{otto}', RunOttoChat::class);
        Queue::assertPushed(RunOttoChat::class, function (RunOttoChat $job) use ($user): bool {
            return $job->user->is($user)
                && $job->prompt === 'Find scans for ABC1234'
                && $job->adminImpersonator === null
                && $job->pageContextPayload === null;
        });

        $this->assertTrue(
            RunsOttoStream::turnCache()->has(RunOttoChat::pendingPromptKey($conversationId)),
        );
        $this->assertSame(0, app(OttoConversation::class)->cachedTurnBaseline($conversationId));

        $this->getJson(route('otto.conversations.index'))
            ->assertJsonPath('activeConversationId', $conversationId);
    }

    public function test_send_to_existing_conversation_returns_the_same_id(): void
    {
        Queue::fake();
        Event::fake([OttoUserMessagePosted::class]);

        $user = $this->ottoUser();
        $conversation = $this->conversationFor($user);
        $this->actingAs($user);

        $this->postJson(route('otto.conversations.messages.store', ['id' => $conversation->id]), [
            'message' => 'second question',
        ])
            ->assertSuccessful()
            ->assertJson(['conversationId' => $conversation->id]);
    }

    public function test_send_copies_admin_impersonator_onto_the_job(): void
    {
        Queue::fake();
        Event::fake([OttoUserMessagePosted::class]);

        $user = $this->createUser();
        $this->setOrgProTier($user->org);
        $impersonator = ['id' => 42, 'name' => 'Staff'];
        session(['admin_impersonator' => $impersonator]);
        $this->actingAs($user);

        $this->postJson(route('otto.conversations.store'), [
            'message' => 'Where are my drivers?',
        ])->assertSuccessful();

        Queue::assertPushed(RunOttoChat::class, function (RunOttoChat $job) use ($user, $impersonator): bool {
            return $job->user->is($user)
                && $job->prompt === 'Where are my drivers?'
                && $job->adminImpersonator === $impersonator;
        });
    }

    public function test_send_forwards_page_context_and_strips_search_instead(): void
    {
        Queue::fake();
        Event::fake([OttoUserMessagePosted::class]);

        $user = $this->ottoUser();
        $this->actingAs($user);

        $page = ['pageType' => 'scan-search', 'description' => 'Scans', 'state' => ['plate' => 'ABC*']];

        $this->postJson(route('otto.conversations.store'), [
            'message' => 'find ABC*',
            'page_context' => $page,
        ])->assertSuccessful();

        Queue::assertPushed(RunOttoChat::class, function (RunOttoChat $job) use ($page): bool {
            return $job->pageContextPayload === $page;
        });

        $conversation = AgentConversation::query()->where('user_id', $user->id)->first();
        $this->assertNotNull($conversation);
        RunsOttoStream::turnCache()->forget(RunOttoChat::pendingPromptKey($conversation->id));

        $this->postJson(route('otto.conversations.messages.store', ['id' => $conversation->id]), [
            'message' => OttoConversation::SEARCH_INSTEAD_PROMPT_FALLBACK,
            'page_context' => $page,
        ])->assertSuccessful();

        Queue::assertPushed(RunOttoChat::class, function (RunOttoChat $job): bool {
            return $job->prompt === OttoConversation::SEARCH_INSTEAD_PROMPT_FALLBACK
                && $job->pageContextPayload === null;
        });

        RunsOttoStream::turnCache()->forget(RunOttoChat::pendingPromptKey($conversation->id));

        $this->postJson(route('otto.conversations.messages.store', ['id' => $conversation->id]), [
            'message' => 'search yourself for plate ABC123',
            'page_context' => $page,
        ])->assertSuccessful();

        Queue::assertPushed(RunOttoChat::class, function (RunOttoChat $job): bool {
            return $job->prompt === 'search yourself for plate ABC123'
                && $job->pageContextPayload === null;
        });
    }

    public function test_send_while_turn_in_flight_dispatches_once(): void
    {
        Queue::fake();
        Event::fake([OttoUserMessagePosted::class]);

        $user = $this->ottoUser();
        $this->actingAs($user);

        $first = $this->postJson(route('otto.conversations.store'), ['message' => 'last 4 days']);
        $conversationId = $first->json('conversationId');

        $this->postJson(route('otto.conversations.messages.store', ['id' => $conversationId]), [
            'message' => 'last 4 days',
        ])->assertSuccessful()->assertJson(['conversationId' => $conversationId]);

        $this->postJson(route('otto.conversations.messages.store', ['id' => $conversationId]), [
            'message' => 'another',
        ])->assertSuccessful()->assertJson(['conversationId' => $conversationId]);

        Queue::assertPushed(RunOttoChat::class, 1);
        $this->assertSame(1, AgentConversation::query()->where('user_id', $user->id)->count());
    }

    public function test_poll_returns_pending_while_run_in_flight(): void
    {
        Queue::fake();
        Event::fake([OttoUserMessagePosted::class]);

        $user = $this->ottoUser();
        $this->actingAs($user);

        $conversationId = $this->postJson(route('otto.conversations.store'), ['message' => 'hello'])
            ->json('conversationId');

        $this->postJson(route('otto.conversations.poll', ['id' => $conversationId]))
            ->assertSuccessful()
            ->assertJson([
                'status' => 'pending',
                'conversationId' => $conversationId,
                'messages' => [],
            ]);
    }

    public function test_poll_returns_complete_with_structured_messages(): void
    {
        Queue::fake();
        Event::fake([OttoUserMessagePosted::class]);

        $user = $this->ottoUser();
        $this->actingAs($user);

        $conversationId = $this->postJson(route('otto.conversations.store'), ['message' => 'hello'])
            ->json('conversationId');

        RunsOttoStream::turnCache()->forget(RunOttoChat::pendingPromptKey($conversationId));
        $this->insertMessage($user, $conversationId, 'user', 'hello');
        $this->insertMessage($user, $conversationId, 'assistant', 'Hello, how can I help?');

        $this->postJson(route('otto.conversations.poll', ['id' => $conversationId]))
            ->assertSuccessful()
            ->assertJsonPath('status', 'complete')
            ->assertJsonPath('conversationId', $conversationId)
            ->assertJsonPath('hasMessages', true)
            ->assertJsonPath('messages.0.role', 'user')
            ->assertJsonPath('messages.0.content', 'hello')
            ->assertJsonPath('messages.1.role', 'assistant')
            ->assertJsonPath('messages.1.content', 'Hello, how can I help?')
            ->assertJsonMissingPath('messages.0.html');
    }

    public function test_poll_returns_idle_when_job_finished_without_a_new_assistant(): void
    {
        Queue::fake();
        Event::fake([OttoUserMessagePosted::class]);

        $user = $this->ottoUser();
        $this->actingAs($user);

        $conversation = $this->conversationFor($user);
        $this->insertMessage($user, $conversation->id, 'user', 'first question');
        $this->insertMessage($user, $conversation->id, 'assistant', 'first answer');

        $this->postJson(route('otto.conversations.messages.store', ['id' => $conversation->id]), [
            'message' => 'second question',
        ])->assertSuccessful();

        RunsOttoStream::turnCache()->forget(RunOttoChat::pendingPromptKey($conversation->id));

        $this->postJson(route('otto.conversations.poll', ['id' => $conversation->id]))
            ->assertSuccessful()
            ->assertJsonPath('status', 'idle');

        $this->insertMessage($user, $conversation->id, 'assistant', 'second answer');

        $this->postJson(route('otto.conversations.poll', ['id' => $conversation->id]))
            ->assertSuccessful()
            ->assertJsonPath('status', 'complete')
            ->assertJsonPath('messages.2.content', 'second answer');
    }

    public function test_poll_completes_when_an_earlier_assistant_row_has_no_visible_text(): void
    {
        Queue::fake();
        Event::fake([OttoUserMessagePosted::class]);

        $user = $this->ottoUser();
        $this->actingAs($user);

        $conversation = $this->conversationFor($user);
        $this->insertMessage($user, $conversation->id, 'user', 'first question');
        $this->insertMessage($user, $conversation->id, 'assistant', '   ');

        $this->postJson(route('otto.conversations.messages.store', ['id' => $conversation->id]), [
            'message' => 'second question',
        ])->assertSuccessful();

        RunsOttoStream::turnCache()->forget(RunOttoChat::pendingPromptKey($conversation->id));
        $this->insertMessage($user, $conversation->id, 'assistant', 'second answer');

        $this->postJson(route('otto.conversations.poll', ['id' => $conversation->id]))
            ->assertSuccessful()
            ->assertJsonPath('status', 'complete')
            ->assertJsonPath('messages.1.content', 'second answer');
    }

    public function test_show_remembers_the_active_conversation_and_reset_forgets_it(): void
    {
        $user = $this->ottoUser();
        $this->actingAs($user);
        $conversation = $this->conversationFor($user);

        $this->getJson(route('otto.conversations.show', ['id' => $conversation->id]))
            ->assertSuccessful();

        $this->assertSame($conversation->id, session('otto_active_conversation'));
        $this->getJson(route('otto.conversations.index'))
            ->assertSuccessful()
            ->assertJsonPath('activeConversationId', $conversation->id);

        $this->postJson(route('otto.conversations.reset'))
            ->assertSuccessful()
            ->assertJson(['ok' => true]);

        $this->assertNull(session('otto_active_conversation'));
        $this->getJson(route('otto.conversations.index'))
            ->assertJsonPath('activeConversationId', null);

        session(['otto_active_conversation' => $conversation->id]);
        $this->postJson(route('otto.conversations.reset'), [
            'conversationId' => (string) Str::uuid(),
        ])->assertSuccessful();
        $this->assertSame($conversation->id, session('otto_active_conversation'));
    }

    public function test_history_search_treats_like_wildcards_as_literals(): void
    {
        $user = $this->ottoUser();
        $this->actingAs($user);

        AgentConversation::query()->create([
            'id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'title' => '100% done',
        ]);
        AgentConversation::query()->create([
            'id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'title' => 'Plate ABC',
        ]);
        AgentConversation::query()->create([
            'id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'title' => 'A_B kit',
        ]);

        $this->getJson(route('otto.conversations.index', ['search' => '%']))
            ->assertSuccessful()
            ->assertJsonCount(1, 'conversations')
            ->assertJsonPath('conversations.0.title', '100% done');

        $this->getJson(route('otto.conversations.index', ['search' => '_']))
            ->assertSuccessful()
            ->assertJsonCount(1, 'conversations')
            ->assertJsonPath('conversations.0.title', 'A_B kit');
    }

    public function test_feedback_merges_into_message_meta_and_can_be_cleared(): void
    {
        $user = $this->ottoUser();
        $this->actingAs($user);
        $conversation = $this->conversationFor($user);
        $messageId = (string) Str::uuid7();
        $this->insertMessageAt(
            $user,
            $conversation->id,
            $messageId,
            'assistant',
            'Seven hits.',
            now(),
        );
        DB::table('agent_conversation_messages')->where('id', $messageId)->update([
            'meta' => json_encode(['page_actions' => [['name' => 'apply-scan-filters']]]),
        ]);

        $this->postJson(route('otto.conversations.messages.feedback', [
            'id' => $conversation->id,
            'messageId' => $messageId,
        ]), [
            'rating' => false,
            'comment' => 'Wrong plate',
            'surface' => 'chat',
        ])->assertSuccessful()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('feedback.rating', false)
            ->assertJsonPath('feedback.comment', 'Wrong plate');

        $this->getJson(route('otto.conversations.show', ['id' => $conversation->id]))
            ->assertSuccessful()
            ->assertJsonPath('messages.0.feedback.rating', false)
            ->assertJsonPath('messages.0.page_actions.0.name', 'apply-scan-filters');

        $this->postJson(route('otto.conversations.messages.feedback', [
            'id' => $conversation->id,
            'messageId' => $messageId,
        ]), [
            'rating' => null,
        ])->assertSuccessful()
            ->assertJsonPath('feedback', null);

        $userMessageId = (string) Str::uuid7();
        $this->insertMessageAt($user, $conversation->id, $userMessageId, 'user', 'hello', now());
        $this->postJson(route('otto.conversations.messages.feedback', [
            'id' => $conversation->id,
            'messageId' => $userMessageId,
        ]), [
            'rating' => true,
        ])->assertNotFound();
    }

    public function test_stop_sets_the_turn_cache_key(): void
    {
        Queue::fake();
        Event::fake([OttoUserMessagePosted::class]);

        $user = $this->ottoUser();
        $this->actingAs($user);

        $conversationId = $this->postJson(route('otto.conversations.store'), ['message' => 'hello'])
            ->json('conversationId');

        $this->postJson(route('otto.conversations.stop', ['id' => $conversationId]))
            ->assertSuccessful()
            ->assertJson(['ok' => true]);

        $this->assertTrue(RunsOttoStream::turnCache()->has(RunsOttoStream::stopCacheKey($conversationId)));
    }

    public function test_other_user_cannot_read_or_mutate_a_conversation(): void
    {
        Queue::fake();
        Event::fake([OttoUserMessagePosted::class]);

        $owner = $this->ottoUser();
        $conversation = $this->conversationFor($owner);
        $other = $this->ottoUser();
        $this->actingAs($other);

        $this->getJson(route('otto.conversations.show', ['id' => $conversation->id]))
            ->assertForbidden();
        $this->postJson(route('otto.conversations.messages.store', ['id' => $conversation->id]), [
            'message' => 'hello',
        ])->assertForbidden();
        $this->postJson(route('otto.conversations.poll', ['id' => $conversation->id]))
            ->assertForbidden();
        $this->postJson(route('otto.conversations.stop', ['id' => $conversation->id]))
            ->assertForbidden();
        $this->postJson(route('otto.conversations.disclaimer', ['id' => $conversation->id]))
            ->assertForbidden();
        $this->postJson(route('otto.conversations.messages.feedback', [
            'id' => $conversation->id,
            'messageId' => (string) Str::uuid7(),
        ]), [
            'rating' => true,
        ])->assertForbidden();
        $this->postJson(route('otto.conversations.zone-create', ['id' => $conversation->id]), [
            'name' => 'Downtown',
            'zip_codes' => ['75201'],
        ])->assertForbidden();
    }

    public function test_disclaimer_is_session_scoped(): void
    {
        $user = $this->ottoUser();
        $this->actingAs($user);

        $this->getJson(route('otto.conversations.index'))
            ->assertSuccessful()
            ->assertJsonPath('showDisclaimer', true)
            ->assertJsonPath('activeConversationId', null);

        $this->postJson(route('otto.disclaimer'))
            ->assertSuccessful()
            ->assertJson([
                'ok' => true,
                'showDisclaimer' => false,
            ]);

        $this->assertTrue(session('otto_disclaimer_accepted'));

        $this->getJson(route('otto.conversations.index'))
            ->assertJsonPath('showDisclaimer', false);
    }

    public function test_index_lists_and_searches_conversation_titles(): void
    {
        $user = $this->ottoUser();
        $this->actingAs($user);

        AgentConversation::query()->create([
            'id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'title' => 'Recent hits in Dallas',
        ]);
        AgentConversation::query()->create([
            'id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'title' => 'Plate ABC123',
        ]);
        $this->conversationFor($this->ottoUser());

        $this->getJson(route('otto.conversations.index'))
            ->assertSuccessful()
            ->assertJsonCount(2, 'conversations');

        $this->getJson(route('otto.conversations.index', ['search' => 'Dallas']))
            ->assertSuccessful()
            ->assertJsonCount(1, 'conversations')
            ->assertJsonPath('conversations.0.title', 'Recent hits in Dallas');
    }

    public function test_show_returns_raw_messages_page_actions_and_widget_markers(): void
    {
        $user = $this->ottoUser();
        $this->actingAs($user);

        $conversation = $this->conversationFor($user);

        $this->insertMessage($user, $conversation->id, 'user', OttoConversation::SEARCH_INSTEAD_PROMPT_FALLBACK);

        $assistantId = (string) Str::uuid7();
        $this->insertMessageAt(
            $user,
            $conversation->id,
            $assistantId,
            'assistant',
            '7 hits were recorded.',
            now()->addSecond(),
        );

        $action = (new PageAction(
            pageType: 'scan-search',
            name: ApplyScanSearchFiltersTool::ACTION,
            summary: 'Apply 1 change',
            changes: [new PageActionChange('plate', 'Plate', 'Set the plate to ABC123', 'ABC123')],
        ))->toArray();

        DB::table('agent_conversation_messages')
            ->where('id', $assistantId)
            ->update([
                'meta' => json_encode(['page_actions' => [$action]]),
            ]);

        AgentInvestigation::query()->create([
            'user_id' => $user->id,
            'conversation_id' => $conversation->id,
            'properties' => new AgentInvestigationProperties(
                searches: [
                    new Search(
                        searchId: 'hitsearch01',
                        messageId: $assistantId,
                        parameters: ['plate' => null],
                        searchType: SearchType::Hit,
                        title: 'Hit search',
                        createdBy: $user->id,
                        conversationId: $conversation->id,
                    ),
                ],
            ),
        ]);

        $response = $this->getJson(route('otto.conversations.show', ['id' => $conversation->id]));

        $response->assertSuccessful()
            ->assertJsonPath('conversationId', $conversation->id)
            ->assertJsonPath('messages.0.role', 'event')
            ->assertJsonPath('messages.0.event', 'search_instead')
            ->assertJsonPath('messages.1.role', 'assistant')
            ->assertJsonPath('messages.1.page_actions.0.name', ApplyScanSearchFiltersTool::ACTION)
            ->assertJsonPath('messages.1.page_actions.0.changes.0.field', 'plate');

        $this->assertStringContainsString(
            '<!--otto_table:hit:hitsearch01-->',
            (string) $response->json('messages.1.content'),
        );
        $this->assertArrayNotHasKey('html', $response->json('messages.1'));
    }

    public function test_show_orders_same_second_messages_by_id(): void
    {
        $user = $this->ottoUser();
        $this->actingAs($user);

        $conversation = $this->conversationFor($user);
        $at = now();
        $userMessageId = (string) Str::uuid7();
        $assistantMessageId = (string) Str::uuid7();

        $this->insertMessageAt(
            $user,
            $conversation->id,
            $assistantMessageId,
            'assistant',
            'Here are the 50 most recent scans from today.',
            $at,
        );
        $this->insertMessageAt(
            $user,
            $conversation->id,
            $userMessageId,
            'user',
            'Show me the most recent scans from today',
            $at,
        );

        $this->getJson(route('otto.conversations.show', ['id' => $conversation->id]))
            ->assertSuccessful()
            ->assertJsonPath('messages.0.role', 'user')
            ->assertJsonPath('messages.0.content', 'Show me the most recent scans from today')
            ->assertJsonPath('messages.1.role', 'assistant');
    }

    public function test_zone_create_persists_via_persist_zone(): void
    {
        Event::fake([OttoInvestigationUpdated::class, OttoWidgetCreated::class]);

        [$admin] = $this->createScoutAdmin();
        $this->setOrgProTier($admin->org);
        setPermissionsTeamId($admin->org_id);
        $admin->givePermissionTo(OttoAccess::PERMISSION);
        $this->actingAs($admin);
        $this->mockZips(['75201']);

        // applyZoneCreate updates when the org already has this name — do not
        // insert a same-name row or PersistZone::create is never called.
        $saved = Zone::factory()->forOrg($admin->org)->make([
            'id' => 424_242,
            'name' => 'Downtown Dallas',
        ]);
        $persist = Mockery::mock(PersistZone::class);
        $persist->shouldReceive('create')
            ->once()
            ->withArgs(function (User $actor, array $attributes, ScoutAnalyticsPage $page) use ($admin): bool {
                return $actor->is($admin)
                    && $attributes['name'] === 'Downtown Dallas'
                    && $attributes['definition']['zip_codes'] === ['75201']
                    && $page === ScoutAnalyticsPage::Otto;
            })
            ->andReturn($saved);
        $this->app->instance(PersistZone::class, $persist);

        $conversation = $this->conversationFor($admin);

        $this->postJson(route('otto.conversations.zone-create', ['id' => $conversation->id]), [
            'name' => 'Downtown Dallas',
            'zip_codes' => ['75201'],
            'color' => Zone::DEFAULT_COLOR,
            'is_no_scan_zone' => false,
        ])
            ->assertSuccessful()
            ->assertJson([
                'ok' => true,
                'zone_id' => $saved->id,
            ]);

        Event::assertDispatched(OttoInvestigationUpdated::class);
    }

    public function test_open_zone_editor_returns_the_map_url(): void
    {
        [$admin] = $this->createScoutAdmin();
        $this->setOrgProTier($admin->org);
        setPermissionsTeamId($admin->org_id);
        $admin->givePermissionTo(OttoAccess::PERMISSION);
        $this->actingAs($admin);
        $this->mockZips([]);

        $conversation = $this->conversationFor($admin);

        $response = $this->postJson(route('otto.conversations.open-zone-editor', ['id' => $conversation->id]), [
            'name' => 'North loop',
            'open_map_editor' => true,
        ]);

        $response->assertSuccessful()->assertJsonPath('ok', true);
        $this->assertNotEmpty($response->json('url'));

        $query = [];
        parse_str((string) parse_url((string) $response->json('url'), PHP_URL_QUERY), $query);
        $this->assertSame('North loop', $query['name'] ?? null);
    }

    public function test_send_rejects_an_empty_message(): void
    {
        $user = $this->ottoUser();
        $this->actingAs($user);

        $this->postJson(route('otto.conversations.store'), ['message' => '   '])
            ->assertUnprocessable();
    }

    private function ottoUser(): User
    {
        $user = $this->createUser();
        $this->setOrgProTier($user->org);
        setPermissionsTeamId($user->org_id);
        $user->givePermissionTo('use-otto');

        return $user->fresh();
    }

    private function conversationFor(User $user): AgentConversation
    {
        return AgentConversation::query()->create([
            'id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'title' => 'Test thread',
        ]);
    }

    private function insertMessage(User $user, string $conversationId, string $role, string $content): void
    {
        $this->insertMessageAt(
            $user,
            $conversationId,
            (string) Str::uuid7(),
            $role,
            $content,
            now()->addSeconds($this->insertedMessageCount++),
        );
    }

    private function insertMessageAt(
        User $user,
        string $conversationId,
        string $id,
        string $role,
        string $content,
        DateTimeInterface $at,
    ): void {
        DB::table('agent_conversation_messages')->insert([
            'id' => $id,
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
            'created_at' => $at,
            'updated_at' => $at,
        ]);
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

    /**
     * @param  list<string>  $existing
     */
    private function mockZips(array $existing): void
    {
        $lookup = Mockery::mock(ZipCodeLookup::class);
        $lookup->shouldReceive('normalize')->andReturnUsing(function (mixed $zipCodes) {
            $items = is_array($zipCodes) ? $zipCodes : [$zipCodes];
            $out = [];

            foreach ($items as $item) {
                if (! is_string($item) && ! is_numeric($item)) {
                    continue;
                }

                if (preg_match_all('/\b(\d{5})\b/', (string) $item, $matches) < 1) {
                    continue;
                }

                foreach ($matches[1] as $zip) {
                    $out[] = $zip;
                }
            }

            return array_values(array_unique($out));
        });
        $lookup->shouldReceive('existing')->andReturn($existing);
        $this->app->instance(ZipCodeLookup::class, $lookup);
    }
}
