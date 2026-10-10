<?php

namespace Tests\Feature\Otto;

use App\Ai\FabricationGuard;
use App\Ai\PageAction;
use App\Ai\PageActionChange;
use App\Data\AgentInvestigation\AgentInvestigationProperties;
use App\Data\AgentInvestigation\Search;
use App\Datatype\SearchType;
use App\Models\Investigation;
use Illuminate\Support\Collection;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\ToolCall as ToolCallData;
use Laravel\Ai\Responses\StreamedAgentResponse;
use Laravel\Ai\Streaming\Events\TextDelta;
use Laravel\Ai\Streaming\Events\ToolCall as ToolCallEvent;
use Tests\TestCase;

class FabricationGuardTest extends TestCase
{
    private function guard(): FabricationGuard
    {
        return new FabricationGuard;
    }

    /**
     * @param  list<Search>  $searches
     */
    private function investigation(array $searches = []): Investigation
    {
        $investigation = new Investigation;
        $investigation->data = new AgentInvestigationProperties(searches: $searches);
        $investigation->beginToolOutcomeTracking();

        return $investigation;
    }

    /**
     * @param  list<object>  $events
     */
    private function response(string $text, array $events = []): StreamedAgentResponse
    {
        $events = array_merge([new TextDelta('d1', 'm1', $text, 1)], $events);

        return new StreamedAgentResponse('inv-1', new Collection($events), new Meta);
    }

    public function test_flags_a_results_claim_with_no_tool_call(): void
    {
        $response = $this->response('Here are the 50 most recent scans from the last 30 days. Most of them came in during weekday mornings.');

        $this->assertTrue($this->guard()->looksFabricated($response, $this->investigation()));
    }

    public function test_flags_narrated_search_with_elided_result_noun(): void
    {
        $response = $this->response(
            'I searched for scans in Colorado from the last 7 days across all cameras. '
            .'Here are the 25 most recent — there may be more beyond what\'s shown. '
            .'Activity is spread across multiple cities including Denver and Aurora.'
        );

        $this->assertTrue($this->guard()->looksFabricated($response, $this->investigation()));
    }

    public function test_does_not_flag_when_a_search_was_recorded_this_run(): void
    {
        $investigation = $this->investigation();
        $investigation->data->searches[] = new Search(
            searchId: 's1',
            messageId: '',
            parameters: [],
            searchType: SearchType::Scan,
        );
        $response = $this->response(
            'Here are the 25 most recent scans from today.',
            [new ToolCallEvent('t1', new ToolCallData('t1', 'ScanSearchTool', []), 2)],
        );

        $this->assertFalse($this->guard()->looksFabricated($response, $investigation));
    }

    public function test_flags_a_claimed_filter_proposal_when_no_page_action_exists(): void
    {
        $response = $this->response(
            'I\'ve suggested that filter change below — set the plate to H0WZAWK and switch to all cameras. Review and accept to run the search.',
            [new ToolCallEvent('t1', new ToolCallData('t1', 'ScanSearchTool', []), 2)],
        );

        $this->assertTrue($this->guard()->looksFabricated($response, $this->investigation()));
    }

    public function test_flags_results_claim_when_tool_call_created_no_search(): void
    {
        $response = $this->response(
            'Here are the 25 most recent scans from today.',
            [new ToolCallEvent('t1', new ToolCallData('t1', 'ScanSearchTool', []), 2)],
        );

        $this->assertTrue($this->guard()->looksFabricated($response, $this->investigation()));
    }

    public function test_allows_a_proposal_claim_when_a_real_page_action_exists(): void
    {
        $investigation = $this->investigation();
        $investigation->recordProposedPageAction(new PageAction(
            pageType: 'scan-search',
            name: 'apply-scan-filters',
            summary: 'Apply scan filters',
            changes: [new PageActionChange('plate', 'Plate', 'Set plate to H0WZAWK', 'H0WZAWK')],
        ));
        $response = $this->response('I\'ve suggested that filter change below. Review and accept to run the search.');

        $this->assertFalse($this->guard()->looksFabricated($response, $investigation));
    }

    public function test_allows_results_claim_wording_when_a_real_page_action_exists(): void
    {
        $investigation = $this->investigation();
        $investigation->recordProposedPageAction(new PageAction(
            pageType: 'scan-search',
            name: 'apply-scan-filters',
            summary: 'Apply scan filters',
            changes: [new PageActionChange('plate', 'Plate', 'Set the plate to 33EZE*', '33EZE*')],
        ));
        $response = $this->response(
            'I\'ve looked up plates starting with 33EZE. I\'ve suggested those filter changes below — '
            .'accept them to run the search. Here are the scans that should match.'
        );

        $this->assertFalse($this->guard()->looksFabricated($response, $investigation));
    }

    public function test_allows_an_accurate_no_action_reply(): void
    {
        $response = $this->response('I couldn\'t update the filters, so no search was run.');

        $this->assertFalse($this->guard()->looksFabricated($response, $this->investigation()));
    }

    public function test_does_not_flag_a_capability_answer(): void
    {
        $response = $this->response('I can search your scans, hits, and vehicle movement patterns. What would you like to look into?');

        $this->assertFalse($this->guard()->looksFabricated($response, $this->investigation()));
    }

    public function test_still_flags_results_claim_when_only_prior_searches_exist(): void
    {
        $priorSearch = new Search(searchId: 's1', messageId: 'm-prior', parameters: [], searchType: SearchType::Scan);

        $response = $this->response(
            'I searched for scans in Colorado from the last 7 days. Here are the 25 most recent — there may be more.'
        );

        $this->assertTrue($this->guard()->looksFabricated($response, $this->investigation([$priorSearch])));
    }

    public function test_allows_non_claim_recap_when_prior_searches_exist(): void
    {
        $priorSearch = new Search(searchId: 's1', messageId: 'm-prior', parameters: [], searchType: SearchType::Scan);

        $response = $this->response(
            'Looking at the earlier Colorado results, plates from Colorado dominate the set. Want to narrow by vehicle type?'
        );

        $this->assertFalse($this->guard()->looksFabricated($response, $this->investigation([$priorSearch])));
    }
}
