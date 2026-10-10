<?php

namespace Tests\Unit\Support\Otto;

use App\Support\Otto\InChatSearchIntent;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class InChatSearchIntentTest extends TestCase
{
    #[DataProvider('matchingPrompts')]
    public function test_matches_explicit_in_chat_search_phrases(string $prompt): void
    {
        $this->assertTrue(InChatSearchIntent::matches($prompt));
    }

    #[DataProvider('nonMatchingPrompts')]
    public function test_does_not_match_ordinary_search_asks(string $prompt): void
    {
        $this->assertFalse(InChatSearchIntent::matches($prompt));
    }

    public function test_should_strip_page_context_only_on_deferring_search_pages(): void
    {
        $prompt = 'search yourself for plate ABC123';

        $this->assertTrue(InChatSearchIntent::shouldStripPageContext($prompt, [
            'pageType' => 'scan-search',
            'description' => 'Scans',
            'state' => [],
        ]));

        $this->assertTrue(InChatSearchIntent::shouldStripPageContext($prompt, [
            'pageType' => 'hit-search',
            'description' => 'Hits',
            'state' => [],
        ]));

        $this->assertTrue(InChatSearchIntent::shouldStripPageContext($prompt, [
            'pageType' => 'hotlist-search',
            'description' => 'Hotlist',
            'state' => [],
        ]));

        $this->assertTrue(InChatSearchIntent::shouldStripPageContext($prompt, [
            'pageType' => '/app/scans',
            'description' => 'Scans',
            'state' => [],
        ]));

        $this->assertFalse(InChatSearchIntent::shouldStripPageContext($prompt, [
            'pageType' => 'dashboard',
            'description' => 'Dashboard',
            'state' => [],
        ]));

        $this->assertFalse(InChatSearchIntent::shouldStripPageContext($prompt, null));
    }

    public function test_ordinary_plate_ask_does_not_strip_on_scan_search(): void
    {
        $this->assertFalse(InChatSearchIntent::shouldStripPageContext(
            'search for plate ABC123',
            ['pageType' => 'scan-search', 'description' => 'Scans', 'state' => []],
        ));
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function matchingPrompts(): iterable
    {
        yield 'search yourself' => ['Please search yourself for plate ABC123'];
        yield 'search in chat' => ['Can you search in chat for ABC123?'];
        yield 'show in chat' => ['Show in chat instead'];
        yield 'show results here' => ['Show results here for that plate'];
        yield 'show the results here' => ['Show the results here'];
        yield 'results here in the chat' => ['Put the results here in the chat'];
        yield "don't change the page" => ["Search for ABC123 but don't change the page"];
        yield 'do not change the page' => ['Do not change the page — search in chat'];
        yield 'dont change the page' => ['Dont change the page'];
        yield 'curly apostrophe' => ['Please don’t change the page'];
        yield 'run it yourself' => ['Run it yourself please'];
        yield 'run this search yourself' => ['Run this search yourself'];
        yield 'run that search yourself' => ['Run that search yourself'];
        yield 'run the search yourself' => ['Run the search yourself'];
        yield 'instead of changing the page' => ['Search instead of changing the page'];
        yield 'case insensitive' => ['SEARCH YOURSELF for LJS6798'];
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function nonMatchingPrompts(): iterable
    {
        yield 'search for plate' => ['search for plate ABC123'];
        yield 'find scans' => ['find scans for ABC123 last 7 days'];
        yield 'empty' => [''];
        yield 'look up' => ['look up ABC123 on this page'];
    }
}
