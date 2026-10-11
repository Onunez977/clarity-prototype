<?php

namespace Tests\Unit\Support\Otto;

use App\Support\Otto\PageActionCopy;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PageActionCopyTest extends TestCase
{
    #[DataProvider('timeRangePhrases')]
    public function test_time_range_phrase_is_human_and_never_a_slug(string $token, string $phrase): void
    {
        $phrase = PageActionCopy::timeRangePhrase($token);

        $this->assertNotNull($phrase);
        $summary = PageActionCopy::timeRangeSummary($phrase);

        $this->assertStringContainsString($phrase, $summary);
        $this->assertStringNotContainsString('last_7d', $summary);
        $this->assertStringNotContainsString('last_7_days', $summary);
        $this->assertStringNotContainsString('last_30d', $summary);
        $this->assertStringNotContainsString('last_30_days', $summary);
        $this->assertStringNotContainsString('last_90d', $summary);
        $this->assertStringNotContainsString('last_90_days', $summary);
        $this->assertStringNotContainsString('time_range', $summary);
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function timeRangePhrases(): array
    {
        return [
            'today' => ['today', 'today'],
            'yesterday' => ['yesterday', 'yesterday'],
            'last 7 days otto' => ['last_7_days', 'last 7 days'],
            'last 7 days filament' => ['last_7d', 'last 7 days'],
            '7d alias' => ['7d', 'last 7 days'],
            'last 30 days' => ['last_30_days', 'last 30 days'],
            'last 90 days' => ['last_90_days', 'last 90 days'],
            'last 12 hours' => ['last_12_hours', 'last 12 hours'],
        ];
    }

    public function test_last_twelve_hours_phrase_is_human(): void
    {
        $this->assertSame('the last 12 hours', PageActionCopy::timeRangePhrase('last_12_hours'));
        $this->assertSame('the last 12 hours', PageActionCopy::timeRangePhrase('12h'));
    }

    public function test_unknown_tokens_are_not_guessed(): void
    {
        $this->assertNull(PageActionCopy::timeRangePhrase('last_4_days'));
        $this->assertNull(PageActionCopy::timeRangePhrase(''));
    }
}
