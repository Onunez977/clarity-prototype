<?php

declare(strict_types=1);

namespace Tests\Feature\Otto;

use App\Ai\Agents\ConversationTitle;
use App\Events\OttoUserMessagePosted;
use App\Jobs\RunOttoChat;
use App\Models\AgentConversation;
use App\Models\User;
use App\Support\Otto\ConversationTitleGenerator;
use App\Support\Otto\OttoAccess;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use RuntimeException;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class ConversationTitleGeneratorTest extends TestCase
{
    private const LONG_PROMPT = 'Can you please show me every hotlist hit for plate ABC1234 in Dallas over the last ninety days and tell me which kits saw it?';

    protected function setUp(): void
    {
        parent::setUp();

        Permission::findOrCreate('use-otto', 'web');
    }

    public function test_placeholder_is_a_short_title_for_a_long_or_empty_prompt(): void
    {
        $generator = app(ConversationTitleGenerator::class);

        $this->assertSame('New chat', $generator->placeholder(''));
        $this->assertSame('New chat', $generator->placeholder('   '));

        $title = $generator->placeholder(self::LONG_PROMPT);

        $this->assertSame('Every hotlist hit for plate ABC1234 in Dallas', $title);
        $this->assertNotSame(self::LONG_PROMPT, $title);
        $this->assertStringNotContainsString('...', $title);
    }

    public function test_send_stores_the_short_placeholder_instead_of_the_raw_prompt(): void
    {
        Queue::fake();
        Event::fake([OttoUserMessagePosted::class]);

        $user = $this->ottoUser();
        $this->actingAs($user);

        $conversationId = $this->postJson(route('otto.conversations.store'), [
            'message' => self::LONG_PROMPT,
        ])->assertSuccessful()->json('conversationId');

        $this->assertSame(
            'Every hotlist hit for plate ABC1234 in Dallas',
            AgentConversation::query()->find($conversationId)?->title,
        );
        Queue::assertPushed(RunOttoChat::class);
    }

    public function test_history_index_shows_the_model_summary_instead_of_the_raw_prompt(): void
    {
        $user = $this->ottoUser();
        $this->actingAs($user);
        $generator = app(ConversationTitleGenerator::class);
        $conversation = $this->conversationFor($user, $generator->placeholder(self::LONG_PROMPT));

        ConversationTitle::fake(['"Dallas hotlist hits."']);

        $generator->generateFor($conversation->id, self::LONG_PROMPT);

        $this->assertSame('Dallas hotlist hits', $conversation->refresh()->title);
        $this->getJson(route('otto.conversations.index'))
            ->assertSuccessful()
            ->assertJsonPath('conversations.0.id', $conversation->id)
            ->assertJsonPath('conversations.0.title', 'Dallas hotlist hits');
        ConversationTitle::assertPrompted(Str::limit(self::LONG_PROMPT, 500));
    }

    public function test_empty_model_title_falls_back_to_a_non_empty_placeholder(): void
    {
        $generator = app(ConversationTitleGenerator::class);
        $conversation = $this->conversationFor($this->createUser(), '');

        ConversationTitle::fake(['']);

        $generator->generateFor($conversation->id, self::LONG_PROMPT);

        $this->assertSame(
            'Every hotlist hit for plate ABC1234 in Dallas',
            $conversation->refresh()->title,
        );
    }

    public function test_model_failure_still_stores_a_short_title(): void
    {
        $generator = app(ConversationTitleGenerator::class);
        $conversation = $this->conversationFor($this->createUser(), Str::limit(self::LONG_PROMPT, 60));

        ConversationTitle::fake(function (): void {
            throw new RuntimeException('title model unavailable');
        });

        $generator->generateFor($conversation->id, self::LONG_PROMPT);

        $title = $conversation->refresh()->title;
        $this->assertSame('Every hotlist hit for plate ABC1234 in Dallas', $title);
        $this->assertNotSame('', $title);
        $this->assertNotSame(Str::limit(self::LONG_PROMPT, 60), $title);
    }

    public function test_existing_summary_is_not_replaced_on_a_later_prompt(): void
    {
        $generator = app(ConversationTitleGenerator::class);
        $conversation = $this->conversationFor($this->createUser(), 'Dallas hotlist hits');

        ConversationTitle::fake(['A different title']);

        $generator->generateFor($conversation->id, 'Thanks, and also where are the drivers today?');

        $this->assertSame('Dallas hotlist hits', $conversation->refresh()->title);
        ConversationTitle::assertNeverPrompted();
    }

    public function test_disabled_generation_keeps_the_placeholder_and_does_not_call_the_model(): void
    {
        config()->set('ai.conversations.generate_title', false);

        $generator = app(ConversationTitleGenerator::class);
        $conversation = $this->conversationFor($this->createUser(), '');

        ConversationTitle::fake(['Should not be used']);

        $generator->generateFor($conversation->id, self::LONG_PROMPT);

        $this->assertSame(
            'Every hotlist hit for plate ABC1234 in Dallas',
            $conversation->refresh()->title,
        );
        ConversationTitle::assertNeverPrompted();
    }

    public function test_model_echo_of_the_raw_prompt_is_rejected(): void
    {
        $generator = app(ConversationTitleGenerator::class);
        $conversation = $this->conversationFor($this->createUser(), $generator->placeholder(self::LONG_PROMPT));

        ConversationTitle::fake([self::LONG_PROMPT]);

        $generator->generateFor($conversation->id, self::LONG_PROMPT);

        $this->assertSame(
            'Every hotlist hit for plate ABC1234 in Dallas',
            $conversation->refresh()->title,
        );
    }

    private function conversationFor(User $user, string $title): AgentConversation
    {
        return AgentConversation::query()->create([
            'id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'title' => $title,
        ]);
    }

    private function ottoUser(): User
    {
        $user = $this->createUser();
        $this->setOrgProTier($user->org);
        setPermissionsTeamId($user->org_id);
        $user->givePermissionTo(OttoAccess::PERMISSION);

        return $user->fresh();
    }
}
