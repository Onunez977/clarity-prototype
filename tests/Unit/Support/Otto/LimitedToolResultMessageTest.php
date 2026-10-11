<?php

declare(strict_types=1);

namespace Tests\Unit\Support\Otto;

use App\Support\Otto\LimitedToolResultMessage;
use PHPUnit\Framework\TestCase;

class LimitedToolResultMessageTest extends TestCase
{
    public function test_ordered_search_under_limit_uses_most_recent_wording_without_found(): void
    {
        $message = LimitedToolResultMessage::forOrderedSearch(3, 50, 'scan', ' for Priya Patel');

        $this->assertStringStartsWith('Here are the most recent 3 scans for Priya Patel.', $message);
        $this->assertStringContainsString('Repeat the 3 most recent scans', $message);
        $this->assertDoesNotMatchRegularExpression('/\b(?:I )?found \d/i', $message);
        $this->assertUnderCapMessageOmitsCapLanguage($message);
    }

    public function test_ordered_search_of_thirteen_does_not_mention_the_cap(): void
    {
        $message = LimitedToolResultMessage::forOrderedSearch(13, LimitedToolResultMessage::SEARCH_LIMIT, 'hit');

        $this->assertStringStartsWith('Here are the most recent 13 hits.', $message);
        $this->assertStringContainsString('Repeat the 13 most recent hits', $message);
        $this->assertDoesNotMatchRegularExpression('/\b(?:I )?found \d/i', $message);
        $this->assertUnderCapMessageOmitsCapLanguage($message);
    }

    public function test_ordered_search_of_seventeen_does_not_mention_the_cap(): void
    {
        $message = LimitedToolResultMessage::forOrderedSearch(17, LimitedToolResultMessage::SEARCH_LIMIT, 'hit');

        $this->assertStringStartsWith('Here are the most recent 17 hits.', $message);
        $this->assertStringContainsString('Repeat the 17 most recent hits', $message);
        $this->assertDoesNotMatchRegularExpression('/\b(?:I )?found \d/i', $message);
        $this->assertUnderCapMessageOmitsCapLanguage($message);
    }

    public function test_ordered_search_single_result_has_no_corpus_total(): void
    {
        $message = LimitedToolResultMessage::forOrderedSearch(1, 50, 'hit', ' for 12ABC*');

        $this->assertStringStartsWith('Here is the most recent hit for 12ABC*.', $message);
        $this->assertDoesNotMatchRegularExpression('/\b(?:I )?found \d/i', $message);
        $this->assertUnderCapMessageOmitsCapLanguage($message);
    }

    public function test_ordered_search_empty_has_no_found_count(): void
    {
        $message = LimitedToolResultMessage::forOrderedSearch(0, 50, 'hit');

        $this->assertStringStartsWith('No matching hits.', $message);
        $this->assertStringNotContainsString('Found 0', $message);
        $this->assertUnderCapMessageOmitsCapLanguage($message);
    }

    public function test_ordered_search_at_limit_uses_most_recent_wording_and_cap_disclosure(): void
    {
        $message = LimitedToolResultMessage::forOrderedSearch(50, 50, 'scan');

        $this->assertStringStartsWith('Here are the most recent 50 scans.', $message);
        $this->assertStringContainsString('Each search returns up to 50 of the most recent matches', $message);
        $this->assertStringContainsString('there may be more in Clarity', $message);
        $this->assertStringContainsString('explain this per-search cap', $message);
        $this->assertDoesNotMatchRegularExpression('/\b(?:I )?found \d/i', $message);
    }

    public function test_cap_result_ids_truncates_to_search_limit(): void
    {
        $ids = range(1, LimitedToolResultMessage::SEARCH_LIMIT + 10);

        $capped = LimitedToolResultMessage::capResultIds($ids);

        $this->assertCount(LimitedToolResultMessage::SEARCH_LIMIT, $capped);
        $this->assertSame(range(1, LimitedToolResultMessage::SEARCH_LIMIT), $capped);
    }

    public function test_fleet_driver_summary_overflow_mentions_cap(): void
    {
        $message = LimitedToolResultMessage::fleetDriverSummaryOverflow(4, 8);

        $this->assertStringContainsString('…and 4 more', $message);
        $this->assertStringContainsString('up to 8 drivers', $message);
    }

    public function test_knowledge_articles_at_limit_mentions_cap(): void
    {
        $message = LimitedToolResultMessage::forKnowledgeArticles(3, 3, 'hotlist', '## Body');

        $this->assertStringStartsWith('Here are the top 3 knowledge-base articles', $message);
        $this->assertStringContainsString('up to 3 per lookup', $message);
        $this->assertStringContainsString('explain the per-lookup cap', $message);
    }

    private function assertUnderCapMessageOmitsCapLanguage(string $message): void
    {
        $this->assertStringNotContainsString((string) LimitedToolResultMessage::SEARCH_LIMIT, $message);
        $this->assertStringNotContainsString('up to', $message);
        $this->assertDoesNotMatchRegularExpression('/\bcap\b/i', $message);
        $this->assertDoesNotMatchRegularExpression('/\blimit\b/i', $message);
        $this->assertStringNotContainsString('there may be more', $message);
    }
}
