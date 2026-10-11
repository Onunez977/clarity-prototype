<?php

declare(strict_types=1);

namespace Tests\Feature\Otto;

use App\Ai\Agents\Otto;
use App\Ai\Tools\ZoneSearchTool;
use App\Data\AgentInvestigation\AgentInvestigationProperties;
use App\Data\AgentInvestigation\Search;
use App\Data\AgentInvestigation\SearchResult;
use App\Datatype\SearchType;
use App\Models\Investigation;
use Illuminate\Support\Str;
use Tests\TestCase;

class OttoSystemPromptTest extends TestCase
{
    public function test_instructions_include_the_users_timezone_and_wall_clock_rules(): void
    {
        $user = $this->createUser([
            'name' => 'Ada Lovelace',
            'dti' => ['timeZone' => 'America/New_York'],
        ]);
        $investigation = new Investigation;
        $investigation->data = new AgentInvestigationProperties;

        $instructions = (string) (new Otto($user, $investigation))->instructions();

        $this->assertStringContainsString('Ada Lovelace', $instructions);
        $this->assertStringContainsString('Timezone: America/New_York', $instructions);
        $this->assertStringContainsString('NEVER do time-zone math', $instructions);
        $this->assertStringContainsString('NOT a time-zone conversion', $instructions);
        $this->assertStringContainsString('NEVER snap a relative window', $instructions);
        $this->assertStringContainsString('last_7_days', $instructions);
        $this->assertStringContainsString('Always use the user\'s timezone when displaying dates', $instructions);
        $this->assertStringContainsString('Never write code.', $instructions);
        $this->assertStringContainsString('refuse in plain language and stop', $instructions);
        $this->assertStringContainsString('Do not include a fenced code block or a program listing', $instructions);
        $this->assertStringContainsString('NEVER say "I found N scans"', $instructions);
        $this->assertStringContainsString('most recent', $instructions);
        $this->assertStringContainsString('do NOT need a plate, driver, or kit', $instructions);
        $this->assertStringContainsString('search immediately with just that time range', $instructions);
        $this->assertStringContainsString('a hit search is the last 12 hours', $instructions);
        $this->assertStringContainsString('do not use that 12-hour window for scans', $instructions);
        $this->assertStringContainsString('Never use it instead of ScanSearch / HitSearch / HotlistSearch / ZoneSearch', $instructions);
        $this->assertStringContainsString('Never tell the user you do not have a scan-search tool', $instructions);
        $this->assertStringNotContainsString('summary (counts', $instructions);
    }

    public function test_instructions_include_a_fabrication_correction_note_when_one_is_set(): void
    {
        $user = $this->createUser();
        $investigation = new Investigation;
        $investigation->data = new AgentInvestigationProperties;

        $instructions = (string) (new Otto($user, $investigation, correctionNote: 'SELF-CORRECTION: redo the search.'))->instructions();

        $this->assertStringContainsString('SELF-CORRECTION: redo the search.', $instructions);
    }

    public function test_instructions_do_not_include_a_search_result_total(): void
    {
        $user = $this->createUser();
        $investigation = new Investigation;
        $investigation->data = new AgentInvestigationProperties(
            searches: [
                new Search(
                    searchId: 'hitsearch01',
                    messageId: (string) Str::uuid(),
                    parameters: [],
                    searchType: SearchType::Hit,
                    title: 'Hit search',
                    createdBy: $user->id,
                ),
            ],
            searchResults: [
                new SearchResult(searchId: 'hitsearch01', resultId: 11),
                new SearchResult(searchId: 'hitsearch01', resultId: 12),
                new SearchResult(searchId: 'hitsearch01', resultId: 13),
            ],
        );

        $instructions = (string) (new Otto($user, $investigation))->instructions();

        $this->assertStringContainsString('Hit search (most recent page; not a full result count)', $instructions);
        $this->assertStringNotContainsString('3 results', $instructions);
        $this->assertStringNotContainsString('(3 results)', $instructions);
    }

    public function test_instructions_tell_the_model_to_use_the_tool_page_count_not_the_cap(): void
    {
        $user = $this->createUser();
        $investigation = new Investigation;
        $investigation->data = new AgentInvestigationProperties;

        $instructions = (string) (new Otto($user, $investigation))->instructions();

        $this->assertStringContainsString('Read the count from THIS search\'s own tool result', $instructions);
        $this->assertStringContainsString('If the tool says "the most recent 12 hits", say 12', $instructions);
        $this->assertStringContainsString('Here are the 12 most recent hits', $instructions);
        $this->assertStringContainsString('ONLY if THIS tool result itself says the page was capped', $instructions);
        $this->assertStringNotContainsString('50 most recent', $instructions);
        $this->assertStringNotContainsString('there may be more beyond what\'s shown', $instructions);
    }

    public function test_instructions_require_zone_search_and_forbid_tool_names(): void
    {
        $user = $this->createUser();
        $investigation = new Investigation;
        $investigation->data = new AgentInvestigationProperties;
        $otto = new Otto($user, $investigation);

        $instructions = (string) $otto->instructions();

        $this->assertStringContainsString('call ZoneSearch on that turn before you answer', $instructions);
        $this->assertStringContainsString('what zones do I have?', $instructions);
        $this->assertStringContainsString('Do not claim zone search is unavailable', $instructions);
        $this->assertStringContainsString('Never tell the user you do not have a zone-search tool', $instructions);
        $this->assertStringContainsString('Never say you do not have a tool', $instructions);
        $this->assertStringContainsString('zone-search tool', $instructions);
        $this->assertStringContainsString('patrol zones', $instructions);
        $this->assertStringNotContainsString('cannot yet search zones', $instructions);

        $classes = array_map(fn (object $tool): string => $tool::class, $otto->tools());
        $this->assertContains(ZoneSearchTool::class, $classes);
    }

    public function test_default_model_is_grok_4_6(): void
    {
        $user = $this->createUser();
        $investigation = new Investigation;
        $investigation->data = new AgentInvestigationProperties;

        $this->assertSame('us.xai.grok-4.6', config('otto.model'));
        $this->assertSame(
            'us.xai.grok-4.6',
            (new Otto($user, $investigation))->model()
        );
    }
}
