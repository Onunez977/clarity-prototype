<?php

namespace Tests\Feature\Otto;

use App\Ai\Agents\Otto;
use App\Ai\PageContext;
use App\Ai\Surface;
use App\Ai\Tools\ApplyHitSearchFiltersTool;
use App\Ai\Tools\ApplyHotlistSearchFiltersTool;
use App\Ai\Tools\ApplyScanSearchFiltersTool;
use App\Ai\Tools\ScanSearchTool;
use App\Data\AgentInvestigation\AgentInvestigationProperties;
use App\Models\Investigation;
use App\Support\Otto\OttoAccess;
use InsightLPR\BlackAdam\Enum\PermissionEnum;
use Tests\TestCase;

class OttoPageContextTest extends TestCase
{
    private function makeInvestigation(): Investigation
    {
        $investigation = new Investigation;
        $investigation->data = new AgentInvestigationProperties;

        return $investigation;
    }

    /**
     * @return list<class-string>
     */
    private function toolClasses(Otto $otto): array
    {
        return array_map(fn ($tool) => $tool::class, iterator_to_array($otto->tools()));
    }

    public function test_scan_search_page_tool_resolves_only_on_that_page(): void
    {
        $user = $this->createUser(permissions: PermissionEnum::ViewAny_SCAN->value);
        $investigation = $this->makeInvestigation();

        $noPage = $this->toolClasses(new Otto($user, $investigation, Surface::Chat));
        $onScanPage = $this->toolClasses(new Otto(
            $user,
            $investigation,
            Surface::Chat,
            new PageContext('scan-search', 'Scans', []),
        ));
        $onOtherPage = $this->toolClasses(new Otto(
            $user,
            $investigation,
            Surface::Chat,
            new PageContext('hit-search', 'Hits', []),
        ));

        $this->assertContains(ScanSearchTool::class, $noPage);
        $this->assertContains(ScanSearchTool::class, $onScanPage);

        $this->assertNotContains(ApplyScanSearchFiltersTool::class, $noPage);
        $this->assertContains(ApplyScanSearchFiltersTool::class, $onScanPage);
        $this->assertNotContains(ApplyScanSearchFiltersTool::class, $onOtherPage);
        $this->assertNotContains(ApplyHitSearchFiltersTool::class, $onScanPage);
    }

    public function test_hit_and_hotlist_page_tools_resolve_only_on_their_pages(): void
    {
        $user = $this->createUser(permissions: [
            PermissionEnum::ViewAny_Hit->value,
            PermissionEnum::ViewAny_Hotlist->value,
        ]);
        $investigation = $this->makeInvestigation();

        $onHits = $this->toolClasses(new Otto(
            $user,
            $investigation,
            Surface::Chat,
            new PageContext('hit-search', 'Hits', []),
        ));
        $onHotlist = $this->toolClasses(new Otto(
            $user,
            $investigation,
            Surface::Chat,
            new PageContext('hotlist-search', 'Hotlist', []),
        ));

        $this->assertContains(ApplyHitSearchFiltersTool::class, $onHits);
        $this->assertNotContains(ApplyHotlistSearchFiltersTool::class, $onHits);
        $this->assertContains(ApplyHotlistSearchFiltersTool::class, $onHotlist);
        $this->assertNotContains(ApplyHitSearchFiltersTool::class, $onHotlist);
        $this->assertNotContains(ApplyScanSearchFiltersTool::class, $onHits);
    }

    public function test_scan_search_page_tool_stays_hidden_without_view_permission(): void
    {
        $user = $this->createUser();
        $investigation = $this->makeInvestigation();

        $onScanPage = $this->toolClasses(new Otto(
            $user,
            $investigation,
            Surface::Chat,
            new PageContext('scan-search', 'Scans', []),
        ));

        $this->assertNotContains(ApplyScanSearchFiltersTool::class, $onScanPage);
    }

    public function test_page_prompt_fragment_is_composed_only_with_page_context(): void
    {
        $user = $this->createUser();
        $investigation = $this->makeInvestigation();

        $noPagePrompt = (string) (new Otto($user, $investigation))->instructions();
        $withPagePrompt = (string) (new Otto(
            $user,
            $investigation,
            Surface::Chat,
            new PageContext('scan-search', 'Scans', ['plate' => 'ABC*']),
        ))->instructions();

        $this->assertStringNotContainsString('The current page', $noPagePrompt);
        $this->assertStringContainsString('The current page', $withPagePrompt);
        $this->assertStringContainsString('Scans', $withPagePrompt);
        $this->assertStringContainsString('ABC*', $withPagePrompt);
        $this->assertStringContainsString('this is NOT search results', $withPagePrompt);
    }

    public function test_page_state_time_range_is_humanized_for_the_prompt(): void
    {
        $summary = (new PageContext('scan-search', 'Scans', ['time_range' => 'last_7d']))->stateSummary();

        $this->assertStringContainsString('last 7 days', $summary);
        $this->assertStringNotContainsString('last_7d', $summary);
        $this->assertStringNotContainsString('last_7_days', $summary);
    }

    public function test_an_actionable_scans_page_prompt_tells_otto_it_can_act(): void
    {
        $user = $this->createUser(permissions: PermissionEnum::ViewAny_SCAN->value);
        $investigation = $this->makeInvestigation();

        $prompt = (string) (new Otto(
            $user,
            $investigation,
            Surface::Chat,
            new PageContext('scan-search', 'Scans', []),
        ))->instructions();

        $this->assertStringContainsString('You can act on this page directly', $prompt);
        $this->assertStringContainsString('DEFAULT to acting on the page', $prompt);
        $this->assertStringContainsString('Do NOT recite, list, or restate the individual filters', $prompt);
        $this->assertStringContainsString('recent/today request with no plate or kit', $prompt);
        $this->assertStringContainsString('do NOT ask for a plate, driver, or kit', $prompt);
        $this->assertStringNotContainsString('cannot change or control THIS page', $prompt);
        $this->assertStringNotContainsString('geo-zone', $prompt);
        $this->assertStringNotContainsString('Officer', $prompt);
    }

    public function test_hits_and_hotlist_prompts_route_record_types(): void
    {
        $user = $this->createUser(permissions: [
            PermissionEnum::ViewAny_Hit->value,
            PermissionEnum::ViewAny_Hotlist->value,
        ]);
        $investigation = $this->makeInvestigation();

        $hitPrompt = (string) (new Otto(
            $user,
            $investigation,
            Surface::Chat,
            new PageContext('hit-search', 'Hits', []),
        ))->instructions();
        $hotlistPrompt = (string) (new Otto(
            $user,
            $investigation,
            Surface::Chat,
            new PageContext('hotlist-search', 'Hotlist', []),
        ))->instructions();

        $this->assertStringContainsString('You can act on this page directly', $hitPrompt);
        $this->assertStringContainsString('HITS', $hitPrompt);
        $this->assertStringContainsString('You can act on this page directly', $hotlistPrompt);
        $this->assertStringContainsString('no date-range filter', $hotlistPrompt);
    }

    public function test_an_unactionable_page_prompt_says_otto_cannot_change_it(): void
    {
        $user = $this->createUser();
        $investigation = $this->makeInvestigation();

        $prompt = (string) (new Otto(
            $user,
            $investigation,
            Surface::Chat,
            new PageContext('dashboard', 'Dashboard', []),
        ))->instructions();

        $this->assertStringContainsString('The current page', $prompt);
        $this->assertStringContainsString('cannot change or control THIS page', $prompt);
        $this->assertStringContainsString('That does NOT mean you cannot search', $prompt);
        $this->assertStringContainsString('call the matching search tool immediately', $prompt);
        $this->assertStringContainsString('Chat search tools for scans, hits, assignments, and Scout zones ARE available', $prompt);
        $this->assertStringContainsString('Never claim you lack a scan, hit, assignment, or zone search tool', $prompt);
        $this->assertStringNotContainsString('You can act on this page directly', $prompt);
    }

    public function test_path_derived_page_types_normalize(): void
    {
        $this->assertSame('scan-search', PageContext::fromClient(['pageType' => '/app/scans'])?->pageType);
        $this->assertSame('hit-search', PageContext::fromClient(['pageType' => '/app/hits'])?->pageType);
        $this->assertSame('hotlist-search', PageContext::fromClient(['pageType' => '/app/hotlists'])?->pageType);
        $this->assertSame('hotlist-search', PageContext::fromClient(['pageType' => '/app/hotlist'])?->pageType);
        $this->assertSame('/app/dashboard', PageContext::fromClient(['pageType' => '/app/dashboard'])?->pageType);
    }

    public function test_chat_search_tools_require_the_view_permission_even_on_the_matching_page(): void
    {
        // use-otto alone no longer buys scan access. The chat search tool is
        // gated on ViewAny_SCAN exactly like the page tool beside it — Otto must
        // not be a wider door onto scans than the Filament page is.
        $ottoOnly = $this->createUser(permissions: OttoAccess::PERMISSION);

        $classes = $this->toolClasses(new Otto(
            $ottoOnly,
            $this->makeInvestigation(),
            Surface::Chat,
            new PageContext('scan-search', 'Scans', []),
        ));

        $this->assertNotContains(ScanSearchTool::class, $classes);
        $this->assertNotContains(ApplyScanSearchFiltersTool::class, $classes);

        // With the permission both are offered: page context does not withhold
        // the chat tool, which defers to the page's proposal at call time.
        $allowed = $this->createUser(permissions: [
            OttoAccess::PERMISSION,
            PermissionEnum::ViewAny_SCAN->value,
        ]);

        $classes = $this->toolClasses(new Otto(
            $allowed,
            $this->makeInvestigation(),
            Surface::Chat,
            new PageContext('scan-search', 'Scans', []),
        ));

        $this->assertContains(ScanSearchTool::class, $classes);
        $this->assertContains(ApplyScanSearchFiltersTool::class, $classes);
    }

    public function test_scan_search_tool_resolves_when_spatie_team_id_is_stale(): void
    {
        $user = $this->createUser(permissions: PermissionEnum::ViewAny_SCAN->value);
        $other = $this->createUser();

        setPermissionsTeamId($other->org_id);
        $user->load(['roles', 'permissions']);

        $classes = $this->toolClasses(new Otto($user, $this->makeInvestigation(), Surface::Chat));

        $this->assertContains(ScanSearchTool::class, $classes);
    }
}
