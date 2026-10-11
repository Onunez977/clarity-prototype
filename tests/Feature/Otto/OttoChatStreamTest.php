<?php

namespace Tests\Feature\Otto;

use App\Data\AgentInvestigation\AgentInvestigationProperties;
use App\Data\AgentInvestigation\Search;
use App\Datatype\SearchType;
use App\Events\OttoUserMessagePosted;
use App\Jobs\RunOttoChat;
use App\Jobs\RunsOttoStream;
use App\Models\AgentConversation;
use App\Models\AgentInvestigation;
use App\Models\Investigation;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class OttoChatStreamTest extends TestCase
{
    private int $insertedMessageCount = 0;

    protected function setUp(): void
    {
        parent::setUp();

        Permission::findOrCreate('use-otto', 'web');
    }

    public function test_broadcasting_auth_is_not_swallowed_by_the_web_catch_all(): void
    {
        $user = $this->createUser();
        $this->actingAs($user);

        $response = $this->post('/broadcasting/auth', [
            'socket_id' => '1234.5678',
            'channel_name' => 'private-otto-thread.'.Str::uuid(),
        ]);

        $this->assertFalse($response->isRedirect(), 'POST /broadcasting/auth must not hit the web catch-all');
        $this->assertNotSame(302, $response->status());
    }

    public function test_broadcasting_auth_is_registered_before_the_web_catch_all(): void
    {
        $routes = collect(app('router')->getRoutes()->getRoutes());

        $broadcastIndex = $routes->search(
            fn ($route): bool => $route->uri() === 'broadcasting/auth'
        );
        $catchAllIndex = $routes->search(
            fn ($route): bool => $route->uri() === '{any}' && in_array('POST', $route->methods(), true)
        );

        $this->assertNotFalse($broadcastIndex);
        $this->assertNotFalse($catchAllIndex);
        $this->assertLessThan(
            $catchAllIndex,
            $broadcastIndex,
            'POST /broadcasting/auth must be registered before the web {any} catch-all',
        );
    }

    public function test_otto_thread_channel_authorizes_owner(): void
    {
        $this->forcePusherBroadcaster();

        $user = $this->ottoUser();
        $conversation = $this->conversationFor($user);

        $this->actingAs($user);

        $response = $this->post('/broadcasting/auth', [
            'socket_id' => '1234.5678',
            'channel_name' => 'private-otto-thread.'.$conversation->id,
        ]);

        $response->assertSuccessful();
        $response->assertJsonStructure(['auth']);
    }

    public function test_otto_thread_channel_denies_other_user(): void
    {
        $this->forcePusherBroadcaster();

        $owner = $this->ottoUser();
        $conversation = $this->conversationFor($owner);
        $other = $this->ottoUser();

        $this->actingAs($other);

        $response = $this->post('/broadcasting/auth', [
            'socket_id' => '1234.5678',
            'channel_name' => 'private-otto-thread.'.$conversation->id,
        ]);

        $response->assertForbidden();
    }

    public function test_poll_turn_returns_pending_while_run_in_flight(): void
    {
        Queue::fake();

        $user = $this->ottoUser();
        $conversationId = $this->startTurn($user, 'hello');

        $result = $this->poll($conversationId);

        $this->assertSame('pending', $result['status']);
        $this->assertSame($conversationId, $result['conversationId']);
        $this->assertTrue(RunsOttoStream::turnCache()->has(RunOttoChat::pendingPromptKey($conversationId)));
    }

    public function test_poll_turn_returns_complete_with_messages_when_assistant_persisted(): void
    {
        Queue::fake();

        $user = $this->ottoUser();
        $conversationId = $this->startTurn($user, 'hello');

        RunsOttoStream::turnCache()->forget(RunOttoChat::pendingPromptKey($conversationId));
        $this->insertMessage($user, $conversationId, 'user', 'hello');
        $this->insertMessage($user, $conversationId, 'assistant', 'Hello, how can I help?');

        $result = $this->poll($conversationId);

        $this->assertSame('complete', $result['status']);
        $this->assertSame($conversationId, $result['conversationId']);
        $this->assertTrue($result['hasMessages']);
        $this->assertSame('assistant', $result['messages'][1]['role'] ?? null);
        $this->assertSame('Hello, how can I help?', $result['messages'][1]['content'] ?? null);
        $this->assertArrayNotHasKey('html', $result['messages'][1]);
    }

    public function test_poll_turn_returns_idle_when_job_finished_without_assistant(): void
    {
        Queue::fake();

        $user = $this->ottoUser();
        $conversationId = $this->startTurn($user, 'hello');
        RunsOttoStream::turnCache()->forget(RunOttoChat::pendingPromptKey($conversationId));

        $result = $this->poll($conversationId);

        $this->assertSame('idle', $result['status']);
        $this->assertSame($conversationId, $result['conversationId']);
    }

    public function test_poll_turn_does_not_report_complete_from_an_earlier_turns_assistant_message(): void
    {
        Queue::fake();

        $user = $this->ottoUser();
        $this->actingAs($user);
        $this->acceptDisclaimer();

        $conversation = $this->conversationFor($user);
        $this->insertMessage($user, $conversation->id, 'user', 'first question');
        $this->insertMessage($user, $conversation->id, 'assistant', 'first answer');

        $this->postJson(route('otto.conversations.messages.store', ['id' => $conversation->id]), [
            'message' => 'second question',
        ])->assertSuccessful();

        RunsOttoStream::turnCache()->forget(RunOttoChat::pendingPromptKey($conversation->id));

        $this->assertSame('idle', $this->poll($conversation->id)['status']);

        $this->insertMessage($user, $conversation->id, 'assistant', 'second answer');

        $result = $this->poll($conversation->id);

        $this->assertSame('complete', $result['status']);
        $this->assertSame('second answer', $result['messages'][2]['content'] ?? null);
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

        $response = $this->getJson(route('otto.conversations.show', ['id' => $conversation->id]))
            ->assertSuccessful();

        $this->assertSame(['user', 'assistant'], array_column($response->json('messages'), 'role'));
        $this->assertSame('Show me the most recent scans from today', $response->json('messages.0.content'));
    }

    public function test_send_queues_run_otto_chat_on_the_otto_queue(): void
    {
        Queue::fake();
        Event::fake([OttoUserMessagePosted::class]);

        $user = $this->ottoUser();
        $this->actingAs($user);
        $this->withHeaders(['X-Socket-ID' => 'undefined']);

        $this->startTurn($user, 'last 4 days');

        Queue::assertPushedOn('{otto}', RunOttoChat::class);
    }

    public function test_send_after_pending_clears_dispatches_again(): void
    {
        Queue::fake();
        Event::fake([OttoUserMessagePosted::class]);

        $user = $this->ottoUser();
        $conversationId = $this->startTurn($user, 'first');
        RunsOttoStream::turnCache()->forget(RunOttoChat::pendingPromptKey($conversationId));

        $this->postJson(route('otto.conversations.messages.store', ['id' => $conversationId]), [
            'message' => 'second',
        ])->assertSuccessful();

        Queue::assertPushed(RunOttoChat::class, 2);
    }

    public function test_send_while_turn_in_flight_dispatches_once(): void
    {
        Queue::fake();
        Event::fake([OttoUserMessagePosted::class]);

        $user = $this->ottoUser();
        $conversationId = $this->startTurn($user, 'last 4 days');

        $this->postJson(route('otto.conversations.messages.store', ['id' => $conversationId]), [
            'message' => 'last 4 days',
        ])->assertSuccessful();
        $this->postJson(route('otto.conversations.messages.store', ['id' => $conversationId]), [
            'message' => 'last 4 days',
        ])->assertSuccessful();

        Queue::assertPushed(RunOttoChat::class, 1);
        Event::assertDispatched(OttoUserMessagePosted::class, 1);
        $this->assertTrue(RunsOttoStream::turnCache()->has(RunOttoChat::pendingPromptKey($conversationId)));
        $this->assertSame(1, AgentConversation::query()->where('user_id', $user->id)->count());
    }

    public function test_send_dispatches_again_when_pending_key_outlives_assistant_reply(): void
    {
        Queue::fake();
        Event::fake([OttoUserMessagePosted::class]);

        $user = $this->ottoUser();
        $conversationId = $this->startTurn($user, 'what is a hotlist');

        $this->insertMessage($user, $conversationId, 'user', 'what is a hotlist');
        $this->insertMessage($user, $conversationId, 'assistant', 'A hotlist is a book of wanted vehicles.');

        $this->assertTrue(RunsOttoStream::turnCache()->has(RunOttoChat::pendingPromptKey($conversationId)));

        $this->postJson(route('otto.conversations.messages.store', ['id' => $conversationId]), [
            'message' => 'What is a staged hotlist?',
        ])->assertSuccessful();

        Queue::assertPushed(RunOttoChat::class, 2);
        $this->assertSame(
            'What is a staged hotlist?',
            RunsOttoStream::turnCache()->get(RunOttoChat::pendingPromptKey($conversationId)),
        );
    }

    public function test_poll_turn_completes_when_pending_key_outlives_assistant_reply(): void
    {
        Queue::fake();

        $user = $this->ottoUser();
        $conversationId = $this->startTurn($user, 'what is a hotlist');

        $this->insertMessage($user, $conversationId, 'user', 'what is a hotlist');
        $this->insertMessage($user, $conversationId, 'assistant', 'A hotlist is a book of wanted vehicles.');

        $result = $this->poll($conversationId);

        $this->assertSame('complete', $result['status']);
        $this->assertFalse(RunsOttoStream::turnCache()->has(RunOttoChat::pendingPromptKey($conversationId)));
        $this->assertStringContainsString('wanted vehicles', $result['messages'][1]['content'] ?? '');
    }

    public function test_poll_turn_injects_hit_results_table_into_assistant_content(): void
    {
        Queue::fake();

        $user = $this->ottoUser();
        $conversationId = $this->startTurn($user, 'show me recent hits');

        RunsOttoStream::turnCache()->forget(RunOttoChat::pendingPromptKey($conversationId));
        $this->insertMessage($user, $conversationId, 'user', 'show me recent hits');
        $assistantId = (string) Str::uuid();
        DB::table('agent_conversation_messages')->insert([
            'id' => $assistantId,
            'conversation_id' => $conversationId,
            'user_id' => $user->id,
            'agent' => 'otto',
            'role' => 'assistant',
            'content' => '7 hits were recorded. An interactive results table is displayed below.',
            'attachments' => '[]',
            'tool_calls' => '[]',
            'tool_results' => '[]',
            'usage' => '{}',
            'meta' => '{}',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        AgentInvestigation::query()->create([
            'user_id' => $user->id,
            'conversation_id' => $conversationId,
            'properties' => new AgentInvestigationProperties(
                searches: [
                    new Search(
                        searchId: 'hitsearch01',
                        messageId: $assistantId,
                        parameters: ['plate' => null],
                        searchType: SearchType::Hit,
                        title: 'Hit search',
                        createdBy: $user->id,
                        conversationId: $conversationId,
                    ),
                ],
            ),
        ]);

        $result = $this->poll($conversationId);

        $this->assertSame('complete', $result['status']);
        $this->assertStringContainsString('<!--otto_table:hit:hitsearch01-->', $result['messages'][1]['content'] ?? '');
    }

    public function test_persist_investigation_stamps_empty_search_message_id_for_widget_markers(): void
    {
        Queue::fake();

        $user = $this->ottoUser();
        $conversationId = $this->startTurn($user, 'Show me the most recent scans from today');

        RunsOttoStream::turnCache()->forget(RunOttoChat::pendingPromptKey($conversationId));
        $this->insertMessage($user, $conversationId, 'user', 'Show me the most recent scans from today');

        $assistantId = (string) Str::uuid();
        DB::table('agent_conversation_messages')->insert([
            'id' => $assistantId,
            'conversation_id' => $conversationId,
            'user_id' => $user->id,
            'agent' => 'otto',
            'role' => 'assistant',
            'content' => 'Here are the 50 most recent scans from today.',
            'attachments' => '[]',
            'tool_calls' => '[]',
            'tool_results' => '[]',
            'usage' => '{}',
            'meta' => '{}',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $investigation = new Investigation;
        $investigation->data = new AgentInvestigationProperties(
            searches: [
                new Search(
                    searchId: 'scansearch01',
                    messageId: '',
                    parameters: ['time_range' => 'today'],
                    searchType: SearchType::Scan,
                    title: 'Scan search',
                    createdBy: $user->id,
                    conversationId: $conversationId,
                ),
            ],
        );

        $job = new RunOttoChat($user, 'Show me the most recent scans from today', $conversationId);
        (new \ReflectionMethod(RunOttoChat::class, 'persistInvestigation'))->invoke($job, $investigation);

        $stored = AgentInvestigation::query()
            ->where('conversation_id', $conversationId)
            ->where('user_id', $user->id)
            ->first();

        $this->assertNotNull($stored);
        $this->assertSame($assistantId, $stored->properties->searches[0]->messageId);
        $this->assertStringContainsString(
            '<!--otto_table:scan:scansearch01-->',
            (string) DB::table('agent_conversation_messages')->where('id', $assistantId)->value('content'),
        );

        $result = $this->poll($conversationId);

        $this->assertSame('complete', $result['status']);
        $this->assertStringContainsString('<!--otto_table:scan:scansearch01-->', $result['messages'][1]['content'] ?? '');
    }

    public function test_persist_investigation_drops_calling_scan_search_narration(): void
    {
        Queue::fake();

        $user = $this->ottoUser();
        $conversationId = $this->startTurn($user, 'Show me the most recent scans from today');

        RunsOttoStream::turnCache()->forget(RunOttoChat::pendingPromptKey($conversationId));
        $this->insertMessage($user, $conversationId, 'user', 'Show me the most recent scans from today');

        $narrationId = (string) Str::uuid();
        DB::table('agent_conversation_messages')->insert([
            'id' => $narrationId,
            'conversation_id' => $conversationId,
            'user_id' => $user->id,
            'agent' => 'otto',
            'role' => 'assistant',
            'content' => "Let me pull up today's scans for you right now!\n\n[Calling ScanSearch with preset: \"today\"]",
            'attachments' => '[]',
            'tool_calls' => '[]',
            'tool_results' => '[]',
            'usage' => '{}',
            'meta' => '{}',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $assistantId = (string) Str::uuid();
        DB::table('agent_conversation_messages')->insert([
            'id' => $assistantId,
            'conversation_id' => $conversationId,
            'user_id' => $user->id,
            'agent' => 'otto',
            'role' => 'assistant',
            'content' => 'Here are the most recent scans from today.',
            'attachments' => '[]',
            'tool_calls' => '[]',
            'tool_results' => '[]',
            'usage' => '{}',
            'meta' => '{}',
            'created_at' => now()->addSecond(),
            'updated_at' => now()->addSecond(),
        ]);

        $investigation = new Investigation;
        $investigation->data = new AgentInvestigationProperties(
            searches: [
                new Search(
                    searchId: 'scansearch03',
                    messageId: '',
                    parameters: ['time_range' => 'today'],
                    searchType: SearchType::Scan,
                    title: 'Scan search',
                    createdBy: $user->id,
                    conversationId: $conversationId,
                ),
            ],
        );

        $job = new RunOttoChat($user, 'Show me the most recent scans from today', $conversationId);
        (new \ReflectionMethod(RunOttoChat::class, 'persistInvestigation'))->invoke($job, $investigation);

        $this->assertNull(
            DB::table('agent_conversation_messages')->where('id', $narrationId)->first(),
        );

        $row = DB::table('agent_conversation_messages')->where('id', $assistantId)->first();
        $this->assertNotNull($row);
        $this->assertStringNotContainsString('[Calling', (string) $row->content);
        $this->assertStringNotContainsString('Calling ScanSearch', (string) $row->content);
        $this->assertStringContainsString('<!--otto_table:scan:scansearch03-->', (string) $row->content);
    }

    public function test_poll_turn_injects_scout_driver_map_into_assistant_content(): void
    {
        Queue::fake();

        $user = $this->ottoUser();
        $conversationId = $this->startTurn($user, 'where is Admin');

        RunsOttoStream::turnCache()->forget(RunOttoChat::pendingPromptKey($conversationId));
        $this->insertMessage($user, $conversationId, 'user', 'where is Admin');
        $assistantId = (string) Str::uuid();
        DB::table('agent_conversation_messages')->insert([
            'id' => $assistantId,
            'conversation_id' => $conversationId,
            'user_id' => $user->id,
            'agent' => 'otto',
            'role' => 'assistant',
            'content' => 'Admin was last seen near Indianapolis.',
            'attachments' => '[]',
            'tool_calls' => '[]',
            'tool_results' => '[]',
            'usage' => '{}',
            'meta' => '{}',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        AgentInvestigation::query()->create([
            'user_id' => $user->id,
            'conversation_id' => $conversationId,
            'properties' => new AgentInvestigationProperties(
                searches: [
                    new Search(
                        searchId: 'scoutsearch01',
                        messageId: $assistantId,
                        parameters: [
                            'positions' => [[
                                'kit_id' => 8,
                                'name' => 'mertz.kimberly',
                                'driver_name' => 'Admin',
                                'lat' => 39.7684,
                                'lng' => -86.1581,
                                'last_seen_at_utc' => '2026-08-19T14:00:00+00:00',
                                'place' => 'Indianapolis, IN',
                            ]],
                        ],
                        searchType: SearchType::ScoutDriver,
                        title: 'Scout driver location',
                        createdBy: $user->id,
                        conversationId: $conversationId,
                    ),
                ],
            ),
        ]);

        $result = $this->poll($conversationId);

        $this->assertSame('complete', $result['status']);
        $this->assertStringContainsString('<!--otto_table:scout_driver:scoutsearch01-->', $result['messages'][1]['content'] ?? '');
    }

    public function test_poll_turn_does_not_attach_prior_hit_widgets_to_a_driver_follow_up(): void
    {
        Queue::fake();

        $user = $this->ottoUser();
        $conversationId = $this->startTurn($user, 'show me recent hits');

        RunsOttoStream::turnCache()->forget(RunOttoChat::pendingPromptKey($conversationId));

        $this->insertMessage($user, $conversationId, 'user', 'show me recent hits');
        $hitAssistantId = (string) Str::uuid();
        DB::table('agent_conversation_messages')->insert([
            'id' => $hitAssistantId,
            'conversation_id' => $conversationId,
            'user_id' => $user->id,
            'agent' => 'otto',
            'role' => 'assistant',
            'content' => '7 hits were recorded.',
            'attachments' => '[]',
            'tool_calls' => '[]',
            'tool_results' => '[]',
            'usage' => '{}',
            'meta' => '{}',
            'created_at' => now()->addSecond(),
            'updated_at' => now()->addSecond(),
        ]);

        $this->insertMessage($user, $conversationId, 'user', 'where are my drivers');
        $driverAssistantId = (string) Str::uuid();
        DB::table('agent_conversation_messages')->insert([
            'id' => $driverAssistantId,
            'conversation_id' => $conversationId,
            'user_id' => $user->id,
            'agent' => 'otto',
            'role' => 'assistant',
            'content' => 'Admin was last seen near Indianapolis.',
            'attachments' => '[]',
            'tool_calls' => '[]',
            'tool_results' => '[]',
            'usage' => '{}',
            'meta' => '{}',
            'created_at' => now()->addSeconds(2),
            'updated_at' => now()->addSeconds(2),
        ]);

        AgentInvestigation::query()->create([
            'user_id' => $user->id,
            'conversation_id' => $conversationId,
            'properties' => new AgentInvestigationProperties(
                searches: [
                    new Search(
                        searchId: 'hitsearch01',
                        messageId: $hitAssistantId,
                        parameters: ['plate' => null],
                        searchType: SearchType::Hit,
                        title: 'Hit search',
                        createdBy: $user->id,
                        conversationId: $conversationId,
                    ),
                    new Search(
                        searchId: 'orphansearch',
                        messageId: (string) Str::uuid(),
                        parameters: ['plate' => null],
                        searchType: SearchType::Hit,
                        title: 'Orphan hit search',
                        createdBy: $user->id,
                        conversationId: $conversationId,
                    ),
                    new Search(
                        searchId: 'scoutsearch01',
                        messageId: $driverAssistantId,
                        parameters: ['positions' => []],
                        searchType: SearchType::ScoutDriver,
                        title: 'Scout driver locations',
                        createdBy: $user->id,
                        conversationId: $conversationId,
                    ),
                ],
            ),
        ]);

        $messages = $this->poll($conversationId)['messages'];

        $hitMessage = collect($messages)->first(
            fn (array $message): bool => str_contains((string) ($message['content'] ?? ''), '7 hits were recorded.'),
        );
        $driverMessage = collect($messages)->first(
            fn (array $message): bool => str_contains((string) ($message['content'] ?? ''), 'Indianapolis'),
        );

        $this->assertNotNull($hitMessage);
        $this->assertNotNull($driverMessage);
        $this->assertStringContainsString('<!--otto_table:hit:hitsearch01-->', $hitMessage['content']);
        $this->assertStringNotContainsString('<!--otto_table:hit:hitsearch01-->', $driverMessage['content']);
        $this->assertStringNotContainsString('<!--otto_table:hit:orphansearch-->', $driverMessage['content']);
        $this->assertStringContainsString('<!--otto_table:scout_driver:scoutsearch01-->', $driverMessage['content']);
        $this->assertStringNotContainsString('<!--otto_table:scout_driver:scoutsearch01-->', $hitMessage['content']);
    }

    public function test_chat_hover_does_not_clone_detail_cards_into_the_thread(): void
    {
        $chat = (string) file_get_contents(resource_path('js/otto/OttoChat.vue'));

        $this->assertStringNotContainsString('otto-show-detail.window="onDetail', $chat);
        $this->assertStringContainsString(".listen('.otto.detail'", $chat);
        $this->assertStringContainsString('onDetail(e)', $chat);
        $this->assertStringContainsString('otto-history-open', $chat);
        $this->assertStringContainsString('otto-dismiss-popups', $chat);
        $this->assertStringContainsString('otto-popups-resume', $chat);

        $popover = (string) file_get_contents(resource_path('js/otto/components/DetailPopover.vue'));
        $this->assertStringContainsString('popupsBlocked', $popover);
        $this->assertStringContainsString('otto-dismiss-popups', $popover);
    }

    public function test_user_message_broadcast_carries_author_chip_for_other_tabs(): void
    {
        Queue::fake();
        Event::fake([OttoUserMessagePosted::class]);

        $user = $this->ottoUser();
        $conversationId = $this->startTurn($user, 'last 4 days');

        Event::assertDispatched(OttoUserMessagePosted::class, function (OttoUserMessagePosted $event) use ($conversationId, $user): bool {
            return $event->conversationId === $conversationId
                && $event->content === 'last 4 days'
                && $event->authorName === $user->name
                && $event->authorInitials !== ''
                && $event->authorColor !== '';
        });
    }

    public function test_foreign_conversation_id_is_rejected_on_http(): void
    {
        $user = $this->ottoUser();
        $this->actingAs($user);
        $foreign = $this->conversationFor($this->ottoUser());

        $this->postJson(route('otto.conversations.messages.store', ['id' => $foreign->id]), [
            'message' => 'hello',
        ])->assertForbidden();
    }

    public function test_stop_is_forbidden_without_otto_access(): void
    {
        $user = $this->createUser();
        $this->actingAs($user);

        $this->postJson(route('otto.conversations.stop', ['id' => (string) Str::uuid()]))
            ->assertForbidden();
    }

    public function test_search_widget_matches_search_ids_arrays(): void
    {
        $html = view('otto.search-widget', [
            'type' => 'hit',
            'searchId' => 'abc123xyz',
        ])->render();

        $this->assertStringContainsString('searchIds', $html);
        $this->assertStringContainsString('abc123xyz', $html);
        $this->assertStringContainsString('data-otto-widget="search"', $html);
    }

    public function test_driver_map_widget_opens_fullscreen_when_compact(): void
    {
        $html = view('otto.driver-map-widget', [
            'type' => 'scout_driver',
            'searchId' => 'scoutsearch01',
        ])->render();
        $widget = (string) file_get_contents(resource_path('js/otto/components/widgets/DriverMapWidget.vue'));

        $this->assertStringContainsString('data-otto-widget="driver-map"', $html);
        $this->assertStringContainsString('scoutsearch01', $html);
        $this->assertStringNotContainsString('ottoDriverMap', $html);
        $this->assertStringNotContainsString('x-data', $html);
        $this->assertStringContainsString('openFullscreenIfNeeded', $widget);
        $this->assertStringContainsString('cursor-pointer', $widget);
        $this->assertStringContainsString('cursor-default', $widget);
        $this->assertStringNotContainsString('iframe', $widget);
    }

    public function test_vue_markdown_keeps_strip_tool_calls_in_lockstep(): void
    {
        $markdown = (string) file_get_contents(resource_path('js/otto/lib/markdown.js'));
        $chat = (string) file_get_contents(resource_path('js/otto/OttoChat.vue'));

        $this->assertStringContainsString('export function stripToolCalls', $markdown);
        $this->assertStringContainsString('[Calling\\s+[^\\]]+\\]', $markdown);
        $this->assertStringContainsString('isToolInvocationNarration', $markdown);
        $this->assertStringContainsString('stripToolCalls', $chat);
        $this->assertStringContainsString('graftWidgetsFromServer', $chat);
        $this->assertStringContainsString('pendingWidgets', $chat);
        $this->assertStringContainsString('showStreamResultWidgets', $chat);
        $this->assertStringContainsString('assistantRowVisible', $chat);
        $this->assertStringContainsString('liveTurnAvatarVisible', $chat);
        $this->assertStringContainsString('if (!currentInvocationId)', $chat);
        $searchWidget = (string) file_get_contents(resource_path('js/otto/components/widgets/SearchWidget.vue'));
        $this->assertStringNotContainsString('items.value.length > 0 || investigation.loaded', $searchWidget);
        $this->assertStringContainsString("result.status === 'pending' || result.status === 'idle'", $chat);
        $this->assertStringContainsString('resetActiveConversation', $chat);
        $this->assertStringContainsString('<h${level}>', $markdown);
        $this->assertStringContainsString('safeMarkdownUrl', $markdown);
        $this->assertStringContainsString('<pre><code>', $markdown);

        $api = (string) file_get_contents(resource_path('js/otto/lib/api.js'));
        $filament = (string) file_get_contents(resource_path('js/otto/filament.js'));
        $this->assertStringContainsString("headers['X-Socket-ID'] = socketId", $api);
        $this->assertStringContainsString('livewire:navigated', $filament);
    }

    public function test_vue_clones_driver_map_for_scout_widgets(): void
    {
        $chat = (string) file_get_contents(resource_path('js/otto/OttoChat.vue'));
        $popout = (string) file_get_contents(resource_path('js/otto/OttoPopout.vue'));
        $driverMap = (string) file_get_contents(resource_path('js/otto/components/widgets/DriverMapWidget.vue'));
        $app = (string) file_get_contents(resource_path('js/app.js'));
        $birdseye = (string) file_get_contents(resource_path('js/clarity-scout/birdseye.js'));

        $this->assertStringContainsString("widget.type === 'scout_driver'", $chat);
        $this->assertStringContainsString('DriverMapWidget', $chat);
        $this->assertStringContainsString('otto-open-fullscreen', $popout);
        $this->assertStringContainsString('openFullscreenFromWidget', $popout);
        $this->assertStringContainsString('_ottoFullscreen', $popout);
        $this->assertStringContainsString('google.maps.Map', $driverMap);
        $this->assertStringContainsString('retryCount', $driverMap);
        $this->assertStringNotContainsString('openstreetmap.org/export/embed.html', $driverMap);
        $this->assertStringNotContainsString('alpine-components/otto-', $app);
        $this->assertStringContainsString("from '../otto/mount.js'", $birdseye);
        $this->assertStringContainsString("pageType: 'scout-map'", $birdseye);
    }

    public function test_fullscreen_map_survives_a_chat_send(): void
    {
        $chat = (string) file_get_contents(resource_path('js/otto/OttoChat.vue'));
        $pane = (string) file_get_contents(resource_path('js/otto/OttoResultsPane.vue'));

        $this->assertStringContainsString('graftWidgetsFromServer', $chat);
        $this->assertStringContainsString('data-otto-results-pane', $chat);
        $this->assertStringContainsString('data-otto-results-root', $pane);
        $this->assertStringContainsString('el.replaceChildren()', $pane);
        $this->assertStringContainsString('setTimeout(() => initMap(), 50)', $pane);
        $this->assertStringContainsString('ref="mapContainer"', $pane);
        $this->assertStringContainsString('relative h-[50vh] w-full shrink-0', $pane);
        $this->assertStringNotContainsString('totalPoints', $pane);
        $this->assertStringNotContainsString("'point' : 'points'", $pane);
    }

    public function test_fullscreen_map_pins_declare_icon_size_for_infowindow_anchor(): void
    {
        $pane = (string) file_get_contents(resource_path('js/otto/OttoResultsPane.vue'));
        $popup = (string) file_get_contents(resource_path('js/otto/lib/map-popup.js'));
        $driverMap = (string) file_get_contents(resource_path('js/otto/components/widgets/DriverMapWidget.vue'));
        $format = (string) file_get_contents(resource_path('js/otto/lib/format.js'));

        $this->assertStringContainsString('size: new google.maps.Size(24, 36)', $popup);
        $this->assertStringContainsString('origin: new google.maps.Point(0, 0)', $popup);
        $this->assertStringContainsString('google.maps.OverlayView', $popup);
        $this->assertStringContainsString('fromLatLngToDivPixel', $popup);
        $this->assertStringContainsString('floatPane', $popup);
        $this->assertStringContainsString('data-otto-map-popup', $popup);
        $this->assertStringNotContainsString('google.maps.InfoWindow', $popup);
        $this->assertStringNotContainsString('anchor: marker', $popup);
        $this->assertStringNotContainsString('anchorPoint:', $popup);
        $this->assertStringContainsString('last_seen_at_utc', $format);
        $this->assertStringContainsString('pinIcon', $driverMap);
        $this->assertStringContainsString('addZoneShape', $pane);
    }

    public function test_disclaimer_blocks_send_until_the_session_check_and_stays_dismissed(): void
    {
        $js = (string) file_get_contents(resource_path('js/otto/OttoChat.vue'));
        $api = (string) file_get_contents(resource_path('js/otto/lib/api.js'));

        $this->assertStringContainsString('function disclaimerBlocksSend()', $js);
        $this->assertStringContainsString('if (disclaimerBlocksSend())', $js);
        $this->assertStringContainsString('disclaimerAccepted.value = true', $js);
        $this->assertStringContainsString('if (!disclaimerAccepted.value && typeof result?.showDisclaimer === \'boolean\')', $js);
        $this->assertStringContainsString("ottoFetch('/otto/disclaimer'", $api);
        $this->assertStringNotContainsString('conversations/${encodeURIComponent(conversationId)}/disclaimer', $api);
    }

    public function test_detail_stream_script_reads_result_id_payload(): void
    {
        $js = (string) file_get_contents(resource_path('js/otto/OttoChat.vue'));

        $this->assertStringContainsString('e?.result_id ?? e?.record_id', $js);
        $this->assertStringContainsString('e?.result_type ?? e?.detail_type', $js);
    }

    public function test_results_pane_draws_zone_polygons(): void
    {
        $js = (string) file_get_contents(resource_path('js/otto/OttoResultsPane.vue'));

        $this->assertStringContainsString('addZoneShape', $js);
        $this->assertStringContainsString('new window.google.maps.Polygon', $js);
        $this->assertStringContainsString('fillOpacity: 0.25', $js);
    }

    public function test_page_context_registers_scout_map(): void
    {
        $js = (string) file_get_contents(resource_path('js/otto/page-context.js'));

        $this->assertStringContainsString("pageType: 'scout-map'", $js);
        $this->assertStringContainsString("description: 'Org Map'", $js);
        $this->assertStringContainsString("startsWith('/scout/org-map/')", $js);
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

    private function acceptDisclaimer(): void
    {
        $this->postJson(route('otto.disclaimer'))->assertSuccessful();
    }

    private function startTurn(User $user, string $message): string
    {
        $this->actingAs($user);
        $this->acceptDisclaimer();

        $conversationId = $this->postJson(route('otto.conversations.store'), [
            'message' => $message,
        ])->assertSuccessful()->json('conversationId');

        $this->assertIsString($conversationId);

        return $conversationId;
    }

    /**
     * @return array<string, mixed>
     */
    private function poll(string $conversationId): array
    {
        return $this->postJson(route('otto.conversations.poll', ['id' => $conversationId]))
            ->assertSuccessful()
            ->json();
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
        \DateTimeInterface $at,
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

    private function forcePusherBroadcaster(): void
    {
        config()->set('broadcasting.default', 'pusher');
        config()->set('broadcasting.connections.pusher', [
            'driver' => 'pusher',
            'key' => 'test-key',
            'secret' => 'test-secret',
            'app_id' => 'test-app',
            'options' => [
                'host' => '127.0.0.1',
                'port' => 6001,
                'scheme' => 'http',
                'encrypted' => false,
                'useTLS' => false,
            ],
        ]);
        Broadcast::purge();
        require base_path('routes/channels.php');
    }
}
