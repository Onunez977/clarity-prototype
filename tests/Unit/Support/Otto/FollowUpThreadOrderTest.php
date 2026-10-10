<?php

namespace Tests\Unit\Support\Otto;

use PHPUnit\Framework\TestCase;

/**
 * Live follow-up turns must land in the same order as a refresh:
 * the user message for the turn, then Otto's reply and its results table.
 */
class FollowUpThreadOrderTest extends TestCase
{
    public function test_follow_up_reply_is_placed_after_that_turns_user_message(): void
    {
        $live = [
            $this->message('user', 'hello', 'user-1'),
            $this->message('assistant', 'Hello Brad', 'asst-1', html: '<p>Hello Brad</p>'),
            $this->message('user', 'most recent scans, top 5'),
        ];
        $polled = [
            $this->message('user', 'hello', 'user-1'),
            $this->message('assistant', 'Hello Brad', 'asst-1', html: '<p>Hello Brad</p>'),
            $this->message('user', 'most recent scans, top 5', 'user-2'),
            $this->message(
                'assistant',
                'Here are the 50 most recent scans.',
                'asst-2',
                html: '<p>Here are the 50 most recent scans.</p>',
                widgets: [['type' => 'scan', 'searchId' => 'scansearch01']],
            ),
        ];

        $merged = $this->mergePolledThread($live, $polled);

        $this->assertSame(
            ['user', 'assistant', 'user', 'assistant'],
            array_column($merged, 'role'),
        );
        $this->assertSame('Hello Brad', $merged[1]['content']);
        $this->assertSame([], $merged[1]['widgets']);
        $this->assertSame('most recent scans, top 5', $merged[2]['content']);
        $this->assertSame('Here are the 50 most recent scans.', $merged[3]['content']);
        $this->assertSame('scansearch01', $merged[3]['widgets'][0]['searchId']);
        $this->assertLessThan(
            $this->indexOfContent($merged, 'Here are the 50 most recent scans.'),
            $this->indexOfContent($merged, 'most recent scans, top 5'),
        );
        $this->assertCount(1, array_filter(
            $merged,
            fn (array $message): bool => ($message['content'] ?? '') === 'most recent scans, top 5',
        ));

        $again = $this->mergePolledThread($merged, $polled);
        $this->assertSame(array_column($merged, 'role'), array_column($again, 'role'));
        $this->assertSame([], $again[1]['widgets']);
        $this->assertSame('scansearch01', $again[3]['widgets'][0]['searchId']);
        $this->assertCount(4, $again);
    }

    public function test_in_flight_follow_up_keeps_the_results_table_on_the_trailing_reply(): void
    {
        $live = [
            $this->message('user', 'hello', 'user-1'),
            $this->message('assistant', 'Hello Brad', 'asst-1', html: '<p>Hello Brad</p>'),
            $this->message('user', 'most recent scans, top 5'),
            $this->message('assistant', '', null, invocationId: 'turn-2'),
        ];
        $polled = [
            $this->message('user', 'hello', 'user-1'),
            $this->message('assistant', 'Hello Brad', 'asst-1', html: '<p>Hello Brad</p>'),
            $this->message('user', 'most recent scans, top 5', 'user-2'),
            $this->message(
                'assistant',
                'Here are the 50 most recent scans.',
                'asst-2',
                html: '<p>Here are the 50 most recent scans.</p>',
                widgets: [['type' => 'scan', 'searchId' => 'scansearch01']],
            ),
        ];

        $merged = $this->mergePolledThread($live, $polled);

        $this->assertSame(['user', 'assistant', 'user', 'assistant'], array_column($merged, 'role'));
        $this->assertSame([], $merged[1]['widgets']);
        $this->assertSame('most recent scans, top 5', $merged[2]['content']);
        $this->assertSame('asst-2', $merged[3]['id']);
        $this->assertSame('scansearch01', $merged[3]['widgets'][0]['searchId']);
        $this->assertSame('turn-2', $merged[3]['invocationId']);
    }

    public function test_first_turn_appends_the_assistant_after_the_user(): void
    {
        $live = [
            $this->message('user', 'hello'),
        ];
        $polled = [
            $this->message('user', 'hello', 'user-1'),
            $this->message(
                'assistant',
                'Hello Brad',
                'asst-1',
                html: '<p>Hello Brad</p>',
                widgets: [['type' => 'scan', 'searchId' => 'scansearch01']],
            ),
        ];

        $merged = $this->mergePolledThread($live, $polled);

        $this->assertSame(['user', 'assistant'], array_column($merged, 'role'));
        $this->assertSame('hello', $merged[0]['content']);
        $this->assertSame('scansearch01', $merged[1]['widgets'][0]['searchId']);
    }

    public function test_chat_merges_a_polled_follow_up_in_server_order(): void
    {
        $chat = (string) file_get_contents($this->projectPath('resources/js/otto/OttoChat.vue'));

        $this->assertStringContainsString('mergePolledThread(messages.value, polled)', $chat);
        $this->assertStringContainsString(
            'mergePolledThread(messages.value, normalizeServerMessages(result.messages))',
            $chat,
        );
        $this->assertStringNotContainsString('liveAssistants.length - polledAssistants.length', $chat);
        $this->assertStringNotContainsString('function graftPolledWidgets', $chat);
        $this->assertStringNotContainsString('function appendPolledAssistant', $chat);
    }

    /**
     * @param  list<array{type: string, searchId: string}>  $widgets
     * @return array<string, mixed>
     */
    private function message(
        string $role,
        string $content,
        ?string $id = null,
        string $html = '',
        array $widgets = [],
        ?string $invocationId = null,
    ): array {
        return [
            'localId' => $id ?? $role.'-'.$content,
            'id' => $id,
            'role' => $role,
            'content' => $content,
            'html' => $html,
            'widgets' => $widgets,
            'details' => [],
            'pageActions' => [],
            'invocationId' => $invocationId,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $messages
     */
    private function indexOfContent(array $messages, string $content): int
    {
        foreach ($messages as $index => $message) {
            if (($message['content'] ?? '') === $content) {
                return $index;
            }
        }

        $this->fail("Message [{$content}] was not in the merged thread.");
    }

    /**
     * @param  list<array<string, mixed>>  $live
     * @param  list<array<string, mixed>>  $polled
     * @return list<array<string, mixed>>
     */
    private function mergePolledThread(array $live, array $polled): array
    {
        $payload = json_encode(['live' => $live, 'polled' => $polled], JSON_THROW_ON_ERROR);
        $script = <<<'JS'
import { mergePolledThread } from './resources/js/otto/lib/thread-order.js';
const payload = JSON.parse(process.argv[1]);
process.stdout.write(JSON.stringify(mergePolledThread(payload.live, payload.polled)));
JS;

        $process = proc_open(
            ['node', '--input-type=module', '-e', $script, $payload],
            [
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
            $this->projectPath(),
        );

        $this->assertIsResource($process);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($process);

        $this->assertSame(0, $code, $stderr."\n".$stdout);
        $decoded = json_decode($stdout, true);
        $this->assertIsArray($decoded);

        return $decoded;
    }

    private function projectPath(string $path = ''): string
    {
        $root = dirname(__DIR__, 4);

        return $path === '' ? $root : $root.'/'.$path;
    }
}
