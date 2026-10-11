<?php

declare(strict_types=1);

namespace Tests\Feature\Otto;

use App\Data\AgentInvestigation\AgentInvestigationProperties;
use App\Events\OttoInvestigationUpdated;
use App\Events\OttoReplyReplaced;
use App\Events\OttoWidgetCreated;
use App\Jobs\RunOttoChat;
use App\Models\AgentConversation;
use App\Models\Investigation;
use App\Models\User;
use App\Support\Otto\CodeReplyTurn;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\StreamedAgentResponse;
use Laravel\Ai\Streaming\Events\TextDelta;
use ReflectionMethod;
use Tests\TestCase;

class RunOttoChatCodeReplyTest extends TestCase
{
    private const SCRIPT = <<<'TEXT'
You can find plates that show up more than once with this script.

```python
import csv
from collections import Counter

def common_plates(path):
    counts = Counter()
    with open(path, newline="") as handle:
        for row in csv.DictReader(handle):
            counts[row["plate"]] += 1
    return [plate for plate, n in counts.items() if n > 1]
```

```bash
python common_plates.py export.csv
```
TEXT;

    protected function setUp(): void
    {
        parent::setUp();

        Event::fake([
            OttoWidgetCreated::class,
            OttoReplyReplaced::class,
            OttoInvestigationUpdated::class,
        ]);
        Queue::fake();
        config()->set('ai.conversations.generate_title', false);
    }

    public function test_a_reply_with_python_and_bash_fences_is_replaced_without_a_code_fence(): void
    {
        $user = $this->createUser();
        $conversationId = $this->makeConversation($user);
        $prompt = 'Write a Python script to find common plate reads in a CSV export.';
        $this->insertMessage($user, $conversationId, 'user', $prompt);
        $this->insertMessage($user, $conversationId, 'assistant', self::SCRIPT);

        $this->finishTurn($user, $prompt, $conversationId, $this->scriptResponse('inv-code'));

        $content = $this->assistantContent($conversationId);

        $this->assertSame(CodeReplyTurn::REFUSAL, $content);
        $this->assertStringNotContainsString('```', $content);
        $this->assertStringNotContainsString('import csv', $content);
        $this->assertStringNotContainsString('python common_plates.py', $content);
        $this->assertDoesNotMatchRegularExpression('/\b\w+Tool\b/', $content);

        Event::assertDispatched(OttoReplyReplaced::class, function (OttoReplyReplaced $event) use ($conversationId): bool {
            return $event->conversationId === $conversationId
                && $event->invocationId === 'inv-code'
                && $event->content === CodeReplyTurn::REFUSAL
                && ! str_contains($event->content, '```');
        });
    }

    public function test_a_plate_number_and_url_stay_in_the_reply(): void
    {
        $user = $this->createUser();
        $conversationId = $this->makeConversation($user);
        $kept = 'Plate ABC123 was read near https://example.com/scans/ABC123. The wildcard `ABC*` still matches it.';
        $prompt = 'Where was ABC123 seen?';
        $this->insertMessage($user, $conversationId, 'user', $prompt);
        $this->insertMessage($user, $conversationId, 'assistant', $kept);

        $this->finishTurn($user, $prompt, $conversationId, $this->textResponse('inv-plate', $kept));

        $this->assertSame($kept, $this->assistantContent($conversationId));
        Event::assertNotDispatched(OttoReplyReplaced::class);
    }

    public function test_an_unfenced_program_listing_is_replaced(): void
    {
        $user = $this->createUser();
        $conversationId = $this->makeConversation($user);
        $listing = "SELECT plate, kit FROM scans;\npython common_plates.py export.csv";
        $prompt = 'Give me the query and the command.';
        $this->insertMessage($user, $conversationId, 'user', $prompt);
        $this->insertMessage($user, $conversationId, 'assistant', $listing);

        $this->finishTurn($user, $prompt, $conversationId, $this->textResponse('inv-sql', $listing));

        $content = $this->assistantContent($conversationId);

        $this->assertSame(CodeReplyTurn::REFUSAL, $content);
        $this->assertStringNotContainsString('SELECT', $content);
        $this->assertStringNotContainsString('python common_plates.py', $content);
    }

    public function test_ordinary_sentences_are_not_treated_as_code(): void
    {
        $kept = [
            'I can select scans from yesterday where the plate matched.',
            'Select the hits from the last 7 days.',
            'Please import the CSV from the export page.',
            'python is not something I can hand you, but I can search plate ABC123.',
            'Here are the 12 most recent hits from the last 7 days.',
            'Most came in during weekday mornings. The most recent was this morning.',
        ];

        foreach ($kept as $sentence) {
            $this->assertNull(CodeReplyTurn::replacement($sentence), $sentence);
        }

        $blocked = [
            "def common_plates(path):\n    return path",
            'import csv',
            'from collections import Counter',
            "function findCommon(rows) {\n  return rows;\n}",
            'const counts = new Map();',
            "<?php\necho 'hi';",
            'SELECT * FROM scans WHERE plate = \'ABC123\'',
            'INSERT INTO hotlist (plate) VALUES (\'ABC123\')',
            "#!/bin/bash\necho hi",
            'php artisan test',
        ];

        foreach ($blocked as $listing) {
            $this->assertSame(CodeReplyTurn::REFUSAL, CodeReplyTurn::replacement($listing), $listing);
        }
    }

    private function finishTurn(User $user, string $prompt, string $conversationId, StreamedAgentResponse $response): void
    {
        $investigation = new Investigation;
        $investigation->data = new AgentInvestigationProperties;
        $investigation->beginToolOutcomeTracking();
        $investigation->setActiveConversation($conversationId);

        $job = new RunOttoChat($user, $prompt, $conversationId);
        $method = new ReflectionMethod(RunOttoChat::class, 'onStreamComplete');
        $method->invoke($job, $investigation, $response);
    }

    private function scriptResponse(string $invocationId): StreamedAgentResponse
    {
        return $this->textResponse($invocationId, self::SCRIPT);
    }

    private function textResponse(string $invocationId, string $text): StreamedAgentResponse
    {
        return new StreamedAgentResponse($invocationId, new Collection([
            new TextDelta('d1', 'm1', $text, 1),
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
}
