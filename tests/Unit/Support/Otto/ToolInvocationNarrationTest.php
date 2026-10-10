<?php

namespace Tests\Unit\Support\Otto;

use App\Support\Otto\ToolInvocationNarration;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ToolInvocationNarrationTest extends TestCase
{
    public function test_strips_bracketed_calling_stand_in(): void
    {
        $content = <<<'TXT'
Let me pull up today's scans for you right now!

[Calling ScanSearch with preset: "today"]
TXT;

        $this->assertStringNotContainsString('[Calling', ToolInvocationNarration::strip($content));
        $this->assertTrue(ToolInvocationNarration::isNarrationOnly($content));
        $this->assertSame('', ToolInvocationNarration::withoutNarration($content));
    }

    public function test_keeps_widget_markers_when_dropping_narration(): void
    {
        $content = "Let me pull up today's scans!\n\n[Calling ScanSearchTool]\n\n<!--otto_table:scan:abc123xyz-->";

        $this->assertSame(
            '<!--otto_table:scan:abc123xyz-->',
            ToolInvocationNarration::withoutNarration($content),
        );
    }

    #[DataProvider('realReplies')]
    public function test_leaves_real_replies_alone(string $content): void
    {
        $this->assertFalse(ToolInvocationNarration::isNarrationOnly($content));
        $this->assertStringContainsString('most recent', ToolInvocationNarration::withoutNarration($content));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function realReplies(): array
    {
        return [
            'summary' => ['Here are the most recent scans from today. Most came in this afternoon.'],
            'summary with leftover calling' => [
                "Here are the most recent scans from today.\n\n[Calling ScanSearch with preset: \"today\"]",
            ],
            'search as a verb in a real sentence' => [
                'Search results are the most recent page from today, not a warehouse total.',
            ],
        ];
    }
}
