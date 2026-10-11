<?php

namespace Tests\Feature\Otto;

use App\Ai\FabricationGuard;
use App\Data\AgentInvestigation\AgentInvestigationProperties;
use App\Events\OttoReplyDiscarded;
use App\Events\OttoReplyReplaced;
use App\Jobs\RunOttoChat;
use App\Jobs\RunsOttoStream;
use App\Models\AgentConversation;
use App\Models\Investigation;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\StreamedAgentResponse;
use Laravel\Ai\Streaming\Events\TextDelta;
use ReflectionMethod;
use ReflectionProperty;
use Tests\TestCase;

class RunOttoChatFabricationTest extends TestCase
{
    public function test_a_fabricated_reply_is_discarded_and_otto_re_runs_to_correct_itself(): void
    {
        Event::fake([OttoReplyDiscarded::class]);
        Queue::fake();

        $user = $this->createUser();
        $conversationId = $this->makeConversation($user);
        $this->insertMessage($user, $conversationId, 'user', 'earlier question');
        $this->insertMessage($user, $conversationId, 'assistant', 'Earlier answer.');
        $this->insertMessage($user, $conversationId, 'user', 'recent scans');
        $this->insertMessage($user, $conversationId, 'assistant', 'Here are the 50 most recent scans from the last 30 days.');

        $investigation = new Investigation;
        $investigation->data = new AgentInvestigationProperties;
        $investigation->beginToolOutcomeTracking();

        $response = new StreamedAgentResponse('inv-1', new Collection([
            new TextDelta('d1', 'm1', 'Here are the 50 most recent scans from the last 30 days.', 1),
        ]), new Meta);

        $job = $this->invokeCorrectFabrication($user, $conversationId, 'recent scans', $investigation, $response);

        $this->assertDatabaseMissing('agent_conversation_messages', [
            'conversation_id' => $conversationId,
            'content' => 'Here are the 50 most recent scans from the last 30 days.',
        ]);
        $this->assertDatabaseHas('agent_conversation_messages', [
            'conversation_id' => $conversationId,
            'content' => 'Earlier answer.',
        ]);
        $this->assertSame('recent scans', RunsOttoStream::turnCache()->get(RunOttoChat::pendingPromptKey($conversationId)));
        $deferred = new ReflectionProperty(RunsOttoStream::class, 'deferPendingPromptRelease');
        $this->assertTrue($deferred->getValue($job));

        Event::assertDispatched(
            OttoReplyDiscarded::class,
            fn (OttoReplyDiscarded $event): bool => $event->conversationId === $conversationId
                && $event->invocationId === 'inv-1'
                && $event->userId === $user->id,
        );

        Queue::assertPushed(
            RunOttoChat::class,
            fn (RunOttoChat $queued): bool => $queued->conversationId === $conversationId
                && $queued->prompt === 'recent scans'
                && $queued->corrections === 1
                && $queued->correction !== null
                && str_contains((string) $queued->correction, 'SELF-CORRECTION'),
        );
    }

    public function test_fabrication_correction_does_not_loop_past_the_cap(): void
    {
        Event::fake([OttoReplyReplaced::class, OttoReplyDiscarded::class]);
        Queue::fake();

        $user = $this->createUser();
        $conversationId = $this->makeConversation($user);
        $this->insertMessage($user, $conversationId, 'user', 'recent scans');
        $this->insertMessage($user, $conversationId, 'assistant', 'Here are the 50 most recent scans from the last 30 days.');

        $investigation = new Investigation;
        $investigation->data = new AgentInvestigationProperties;
        $investigation->beginToolOutcomeTracking();

        $response = new StreamedAgentResponse('inv-2', new Collection([
            new TextDelta('d1', 'm1', 'Here are the 50 most recent scans from the last 30 days.', 1),
        ]), new Meta);

        $job = new RunOttoChat($user, 'recent scans', $conversationId, corrections: 1);
        $method = new ReflectionMethod(RunOttoChat::class, 'correctFabricationIfDetected');
        $retried = $method->invoke($job, $investigation, $response);

        $this->assertFalse($retried);
        $this->assertDatabaseHas('agent_conversation_messages', [
            'conversation_id' => $conversationId,
            'role' => 'assistant',
            'content' => FabricationGuard::UNFULFILLED_ACTION_RESPONSE,
        ]);
        Event::assertDispatched(OttoReplyReplaced::class);
        Event::assertNotDispatched(OttoReplyDiscarded::class);
        Queue::assertNotPushed(RunOttoChat::class);
    }

    private function makeConversation(User $user): string
    {
        $conversationId = (string) Str::uuid7();

        AgentConversation::query()->create([
            'id' => $conversationId,
            'user_id' => $user->id,
            'title' => 'Popout chat',
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

    private function invokeCorrectFabrication(
        User $user,
        string $conversationId,
        string $prompt,
        Investigation $investigation,
        StreamedAgentResponse $response,
    ): RunOttoChat {
        $job = new RunOttoChat($user, $prompt, $conversationId);
        $method = new ReflectionMethod(RunOttoChat::class, 'correctFabricationIfDetected');
        $method->invoke($job, $investigation, $response);

        return $job;
    }
}
