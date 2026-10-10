<?php

namespace Tests\Feature\Otto;

use App\Models\AgentConversation;
use App\Models\User;
use DataDog\DogStatsd;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mockery;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class OttoMetricsInstrumentationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Permission::findOrCreate('use-otto', 'web');
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function ottoUser(): User
    {
        $user = $this->createUser();
        $this->setOrgProTier($user->org);
        setPermissionsTeamId($user->org_id);
        $user->givePermissionTo('use-otto');

        return $user->fresh();
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function seedAssistantMessage(User $user): array
    {
        $conversationId = (string) Str::uuid7();
        $messageId = (string) Str::uuid7();

        AgentConversation::query()->create([
            'id' => $conversationId,
            'user_id' => $user->id,
            'title' => 'Metrics chat',
        ]);

        DB::table('agent_conversation_messages')->insert([
            'id' => $messageId,
            'conversation_id' => $conversationId,
            'user_id' => $user->id,
            'agent' => 'otto',
            'role' => 'assistant',
            'content' => 'Here are the results.',
            'attachments' => '[]',
            'tool_calls' => '[]',
            'tool_results' => '[]',
            'usage' => '{}',
            'meta' => '{}',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [$conversationId, $messageId];
    }

    /**
     * actingAs must run while env is still "testing". detectEnvironment('production')
     * makes runningUnitTests() false, which turns CSRF enforcement back on.
     */
    private function enableOttoMetricsForRequest(): void
    {
        $this->app->detectEnvironment(fn () => 'production');
        config(['services.datadog_forwarding' => true]);
        $this->withoutMiddleware(ValidateCsrfToken::class);
    }

    public function test_feedback_emits_metric_when_enabled(): void
    {
        $user = $this->ottoUser();
        [$conversationId, $messageId] = $this->seedAssistantMessage($user);
        $this->actingAs($user);

        $this->enableOttoMetricsForRequest();

        $emitted = [];
        $statsd = Mockery::mock(DogStatsd::class);
        $statsd->shouldReceive('increment')
            ->andReturnUsing(function (string $metric, $sampleRate = 1.0, $tags = null) use (&$emitted) {
                $emitted[] = ['metric' => $metric, 'tags' => $tags];
            });
        $this->app->instance(DogStatsd::class, $statsd);

        $this->postJson(route('otto.conversations.messages.feedback', [
            'id' => $conversationId,
            'messageId' => $messageId,
        ]), [
            'rating' => true,
            'surface' => 'chat',
        ])->assertOk();

        $this->assertTrue(
            collect($emitted)->contains(
                fn (array $row) => $row['metric'] === 'clarity.otto_feedback'
                    && ($row['tags']['rating'] ?? null) === 'positive',
            ),
        );
    }

    public function test_clearing_feedback_does_not_emit_metric(): void
    {
        $user = $this->ottoUser();
        [$conversationId, $messageId] = $this->seedAssistantMessage($user);

        DB::table('agent_conversation_messages')->where('id', $messageId)->update([
            'meta' => json_encode([
                'feedback' => [
                    'rating' => true,
                    'comment' => null,
                    'surface' => 'chat',
                ],
            ]),
        ]);

        $this->actingAs($user);
        $this->enableOttoMetricsForRequest();

        $statsd = Mockery::mock(DogStatsd::class);
        $statsd->shouldNotReceive('increment');
        $this->app->instance(DogStatsd::class, $statsd);

        $this->postJson(route('otto.conversations.messages.feedback', [
            'id' => $conversationId,
            'messageId' => $messageId,
        ]), [
            'rating' => null,
            'surface' => 'chat',
        ])->assertOk();

        $this->addToAssertionCount(1);
    }
}
