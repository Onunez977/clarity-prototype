<?php

namespace Tests\Feature\Otto;

use App\Ai\PageAction;
use App\Ai\PageActionChange;
use App\Ai\Tools\ApplyScanSearchFiltersTool;
use App\Data\AgentInvestigation\AgentInvestigationProperties;
use App\Events\OttoPageActionProposed;
use App\Jobs\RunOttoChat;
use App\Models\AgentConversation;
use App\Models\Investigation;
use App\Services\Otto\OttoConversation;
use App\Support\Otto\OttoAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Laravel\Ai\Tools\Request;
use Tests\TestCase;

class OttoPageActionPersistenceTest extends TestCase
{
    private function makeInvestigation(?string $conversationId = 'conv-1'): Investigation
    {
        $investigation = new Investigation;
        $investigation->data = new AgentInvestigationProperties;

        if ($conversationId !== null) {
            $investigation->setActiveConversation($conversationId);
        }

        return $investigation;
    }

    private function pageAction(): PageAction
    {
        return new PageAction(
            pageType: 'scan-search',
            name: ApplyScanSearchFiltersTool::ACTION,
            summary: 'Apply 1 change to the scans page and run the search',
            changes: [new PageActionChange('plate', 'Plate', 'Set the plate to ABC123', 'ABC123')],
        );
    }

    private function seedAssistantMessage(string $conversationId, int $userId, string $content = 'Done.'): string
    {
        $id = (string) Str::uuid7();

        DB::table('agent_conversation_messages')->insert([
            'id' => $id,
            'conversation_id' => $conversationId,
            'user_id' => $userId,
            'agent' => 'otto',
            'role' => 'assistant',
            'content' => $content,
            'attachments' => '[]',
            'tool_calls' => '[]',
            'tool_results' => '[]',
            'usage' => '{}',
            'meta' => '{}',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    public function test_investigation_records_and_pulls_proposed_page_actions(): void
    {
        $investigation = $this->makeInvestigation();
        $action = $this->pageAction();

        $investigation->recordProposedPageAction($action);

        $pulled = $investigation->pullProposedPageActions();
        $this->assertCount(1, $pulled);
        $this->assertSame($action, $pulled[0]);
        $this->assertSame([], $investigation->pullProposedPageActions());
    }

    public function test_proposing_an_action_records_it_on_the_investigation(): void
    {
        Event::fake([OttoPageActionProposed::class]);

        $user = $this->createUser();
        $investigation = $this->makeInvestigation('conv-record');
        (new ApplyScanSearchFiltersTool($user, $investigation))
            ->handle(new Request(['plate' => 'ABC123']));

        $pulled = $investigation->pullProposedPageActions();
        $this->assertCount(1, $pulled);
        $this->assertSame(ApplyScanSearchFiltersTool::ACTION, $pulled[0]->name);
    }

    public function test_completed_run_persists_proposals_onto_the_latest_assistant_message(): void
    {
        $user = $this->createUser();
        $conversationId = (string) Str::uuid();
        $messageId = $this->seedAssistantMessage($conversationId, $user->id);

        $investigation = $this->makeInvestigation($conversationId);
        $investigation->recordProposedPageAction($this->pageAction());

        $job = new RunOttoChat($user, 'prompt', $conversationId);
        (new \ReflectionMethod($job, 'persistProposedPageActions'))->invoke($job, $investigation);

        $meta = json_decode((string) DB::table('agent_conversation_messages')->where('id', $messageId)->value('meta'), true);
        $this->assertIsArray($meta['page_actions'] ?? null);
        $this->assertSame(ApplyScanSearchFiltersTool::ACTION, $meta['page_actions'][0]['name']);
        $this->assertSame([], $investigation->pullProposedPageActions());
    }

    public function test_load_conversation_restores_page_actions_and_search_instead_marker(): void
    {
        $user = $this->createUser(permissions: OttoAccess::PERMISSION);
        $this->setOrgProTier($user->org);
        $this->actingAs($user->fresh()->load('org.meta'));

        $conversationId = (string) Str::uuid();
        AgentConversation::query()->create([
            'id' => $conversationId,
            'user_id' => $user->id,
            'title' => 'test',
        ]);

        DB::table('agent_conversation_messages')->insert([
            'id' => (string) Str::uuid7(),
            'conversation_id' => $conversationId,
            'user_id' => $user->id,
            'agent' => 'otto',
            'role' => 'user',
            'content' => OttoConversation::SEARCH_INSTEAD_PROMPT_FALLBACK,
            'attachments' => '[]',
            'tool_calls' => '[]',
            'tool_results' => '[]',
            'usage' => '{}',
            'meta' => '{}',
            'created_at' => now()->subSecond(),
            'updated_at' => now()->subSecond(),
        ]);

        $assistantId = $this->seedAssistantMessage($conversationId, $user->id, 'Suggested.');
        DB::table('agent_conversation_messages')->where('id', $assistantId)->update([
            'meta' => json_encode([
                'page_actions' => [$this->pageAction()->toArray()],
            ]),
        ]);

        $messages = $this->getJson(route('otto.conversations.show', ['id' => $conversationId]))
            ->assertSuccessful()
            ->json('messages');

        $this->assertSame('event', $messages[0]['role'] ?? null);
        $this->assertSame('search_instead', $messages[0]['event'] ?? null);
        $this->assertNotEmpty($messages[1]['page_actions'] ?? []);
        $this->assertSame('apply-scan-filters', $messages[1]['page_actions'][0]['name'] ?? null);
    }
}
