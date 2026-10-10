<?php

namespace Tests\Feature\Otto;

use App\Ai\Tools\ApplyHitSearchFiltersTool;
use App\Ai\Tools\ApplyHotlistSearchFiltersTool;
use App\Ai\Tools\ApplyScanSearchFiltersTool;
use App\Ai\Tools\HitSearchTool;
use App\Ai\Tools\HotlistSearchTool;
use App\Ai\Tools\ProposeCreateZoneTool;
use App\Ai\Tools\ProposeUpdateZoneTool;
use App\Ai\Tools\ScanSearchTool;
use App\Ai\Tools\ScoutDriverPositionTool;
use App\Ai\Tools\ShowResultDetailsTool;
use App\Ai\Tools\ToolRegistry;
use App\Ai\Tools\ZoneSearchTool;
use App\Clickhouse\Models\Hit as ClickhouseHit;
use App\Clickhouse\Models\Hotlist as ClickhouseHotlist;
use App\Clickhouse\Models\Scan;
use App\Data\AgentInvestigation\AgentInvestigationProperties;
use App\Data\AgentInvestigation\Search;
use App\Data\AgentInvestigation\SearchResult;
use App\Datatype\SearchType;
use App\Events\OttoResultDetailShown;
use App\Events\OttoWidgetCreated;
use App\Models\AgentInvestigation;
use App\Models\Hit;
use App\Models\Hotlist;
use App\Models\Investigation;
use App\Models\Kit;
use App\Models\Scan as PostgresScan;
use App\Models\User;
use App\Support\Hits\HitsListQuery;
use App\Support\Otto\LimitedToolResultMessage;
use App\Support\Otto\PlatePattern;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use InsightLPR\BlackAdam\Enum\PermissionEnum;
use Laravel\Ai\Tools\Request;
use ReflectionMethod;
use Tests\TestCase;

class OttoSearchToolsTest extends TestCase
{
    public function test_scan_search_defaults_to_last_30_days_and_optional_plate(): void
    {
        Event::fake([OttoWidgetCreated::class]);

        $user = $this->createUser();
        $this->actingAs($user);

        $investigation = $this->makeInvestigation();
        $tool = new ScanSearchTool($user, $investigation);

        $result = (string) $tool->handle(new Request([]));

        $this->assertStringContainsString('No matching scans.', $result);
        $this->assertStringNotContainsString('Found 0', $result);
        $this->assertStringNotContainsString('plate or a driver/kit filter', strtolower($result));
        $this->assertNull($investigation->data->searches[0]->parameters['plate']);
        $this->assertSame('Scan search', $investigation->data->searches[0]->title);

        $tz = $user->timezone ?? 'UTC';
        $this->assertSame(
            now($tz)->copy()->subDays(30)->startOfDay()->toIso8601String(),
            $investigation->data->searches[0]->parameters['start_date'],
        );
        $this->assertSame(
            now($tz)->copy()->endOfDay()->toIso8601String(),
            $investigation->data->searches[0]->parameters['end_date'],
        );
    }

    public function test_scan_search_returns_recent_scans_without_plate_or_kit(): void
    {
        Event::fake([OttoWidgetCreated::class]);

        $user = $this->createUser();
        $this->actingAs($user);

        $today = Scan::factory()->forOrg($user->org)->create([
            'ocr' => 'TODAY01',
            'scanned_at' => now()->subMinutes(5),
        ]);
        $recent = Scan::factory()->forOrg($user->org)->create([
            'ocr' => 'YDAY99',
            'scanned_at' => now()->subDays(2),
        ]);

        // ScanSearchTool reads clarity.scans_hot for any window starting inside
        // 7 days, and this search is `today`. The ClickHouse Scan factory only
        // writes scans_cold, so without mirroring, a "today" search finds
        // nothing while the 30-day tests (which read scans_cold) pass. In
        // production CDC writes every scan to both tables.
        $this->mirrorScanToHotTable($today);
        $this->mirrorScanToHotTable($recent);

        $investigation = $this->makeInvestigation();
        $result = (string) (new ScanSearchTool($user, $investigation))->handle(new Request([
            'time_range' => 'today',
        ]));

        $this->assertStringContainsString('Here is the most recent scan', $result);
        $this->assertStringNotContainsString('Found 1 scan', $result);
        $this->assertStringNotContainsString('plate or a driver/kit filter', strtolower($result));
        $this->assertSame([$today->id], collect($investigation->data->searchResults)->pluck('resultId')->all());
        $this->assertSame('Scan search', $investigation->data->searches[0]->title);
        $this->assertNull($investigation->data->searches[0]->parameters['plate']);

        Event::assertDispatched(OttoWidgetCreated::class, function (OttoWidgetCreated $event): bool {
            return $event->widgetType === 'scan';
        });
    }

    public function test_scan_search_tool_filters_by_driver_name(): void
    {
        Event::fake([OttoWidgetCreated::class]);

        $user = $this->createUser();
        $this->actingAs($user);

        $priyaKit = Kit::factory()->forOrg($user->org)->create(['name' => 'priya.patel']);
        User::factory()->forOrg($user->org)->forKit($priyaKit)->create(['name' => 'Priya Patel']);

        $mariaKit = Kit::factory()->forOrg($user->org)->create(['name' => 'maria.garcia']);
        User::factory()->forOrg($user->org)->forKit($mariaKit)->create(['name' => 'Maria Garcia']);

        cache()->forget('scanner:birds-eye-org-kits:'.$user->org_id);
        cache()->forget('org-kits:'.$user->org_id.'-all');

        $priyaScan = Scan::factory()->forOrg($user->org)->forKit($priyaKit->id)->create([
            'ocr' => 'NSH2211',
            'scanned_at' => now()->subMinutes(2),
        ]);
        Scan::factory()->forOrg($user->org)->forKit($mariaKit->id)->create([
            'ocr' => 'DAL4455',
            'scanned_at' => now()->subMinutes(1),
        ]);

        $investigation = $this->makeInvestigation();
        $tool = new ScanSearchTool($user, $investigation);

        $result = (string) $tool->handle(new Request(['driver_name' => 'Priya']));

        $this->assertMatchesRegularExpression('/Here (is|are) the most recent/i', $result);
        $this->assertDoesNotMatchRegularExpression('/\b(?:I )?found \d/i', $result);
        $this->assertStringContainsString('Priya Patel', $result);
        $this->assertSame('Scan search: Priya Patel (priya.patel)', $investigation->data->searches[0]->title);
        $this->assertSame('Priya', $investigation->data->searches[0]->parameters['driver_name']);
        $this->assertContains($priyaScan->id, collect($investigation->data->searchResults)->pluck('resultId')->all());
    }

    public function test_hit_search_tool_filters_by_driver_name(): void
    {
        Event::fake([OttoWidgetCreated::class]);

        $user = $this->createUser();
        $this->actingAs($user);

        $priyaKit = Kit::factory()->forOrg($user->org)->create(['name' => 'priya.patel']);
        User::factory()->forOrg($user->org)->forKit($priyaKit)->create(['name' => 'Priya Patel']);

        $mariaKit = Kit::factory()->forOrg($user->org)->create(['name' => 'maria.garcia']);
        User::factory()->forOrg($user->org)->forKit($mariaKit)->create(['name' => 'Maria Garcia']);

        cache()->forget('scanner:birds-eye-org-kits:'.$user->org_id);
        cache()->forget('org-kits:'.$user->org_id.'-all');

        $priyaScan = PostgresScan::factory()->create([
            'kit_id' => $priyaKit->id,
            'ocr' => 'NSH2211',
            'scanned_at' => now()->subMinutes(2),
            'is_visible' => true,
        ]);

        $mariaScan = PostgresScan::factory()->create([
            'kit_id' => $mariaKit->id,
            'ocr' => 'DAL4455',
            'scanned_at' => now()->subMinutes(1),
            'is_visible' => true,
        ]);

        $priyaHit = Hit::factory()->live()->create([
            'hotlist_id' => Hotlist::factory()->forOrg($user->org),
            'scan_id' => $priyaScan->id,
            'kit_id' => $priyaKit->id,
            'created_at' => now()->subMinutes(2),
        ]);

        $mariaHit = Hit::factory()->live()->create([
            'hotlist_id' => Hotlist::factory()->forOrg($user->org),
            'scan_id' => $mariaScan->id,
            'kit_id' => $mariaKit->id,
            'created_at' => now()->subMinutes(1),
        ]);

        $investigation = $this->makeInvestigation();
        $tool = new HitSearchTool($user, $investigation);

        $result = (string) $tool->handle(new Request(['driver_name' => 'Priya']));

        $this->assertMatchesRegularExpression('/Here (is|are) the most recent/i', $result);
        $this->assertDoesNotMatchRegularExpression('/\b(?:I )?found \d/i', $result);
        $this->assertStringContainsString('Priya Patel', $result);
        $this->assertSame('Hit search: Priya Patel (priya.patel)', $investigation->data->searches[0]->title);
        $ids = collect($investigation->data->searchResults)->pluck('resultId')->all();
        $this->assertContains($priyaHit->id, $ids);
        $this->assertNotContains($mariaHit->id, $ids);
    }

    public function test_hit_search_tool_is_org_scoped_in_description_and_schema(): void
    {
        $user = $this->createUser();
        $investigation = new Investigation;
        $investigation->data = new AgentInvestigationProperties;

        $tool = new HitSearchTool($user, $investigation);

        $description = strtolower((string) $tool->description());
        $this->assertStringContainsString('organization', $description);
        $this->assertStringContainsString('no plate or kit', $description);
        $this->assertArrayHasKey('plate', $tool->schema(new JsonSchemaTypeFactory));
        $this->assertSearchToolDescriptionOmitsCap((string) $tool->description());
        $this->assertStringContainsString('last 12 hours', $description);
    }

    public function test_latest_hit_search_keeps_hits_from_the_last_twelve_hours(): void
    {
        Event::fake([OttoWidgetCreated::class]);
        Carbon::setTestNow(Carbon::parse('2026-07-16 15:00:00', 'UTC'));

        try {
            $user = $this->createUser([
                'dti' => ['timeZone' => 'UTC'],
            ]);
            $this->actingAs($user);

            $recent = Hit::factory()->forOrg($user->org, [
                'ocr' => 'LATE12H',
                'scanned_at' => now()->subHour(),
            ])->create([
                'created_at' => now()->subHour(),
            ]);
            $older = Hit::factory()->forOrg($user->org, [
                'ocr' => 'OLD13HR',
                'scanned_at' => now()->subHours(13),
            ])->create([
                'created_at' => now()->subHours(13),
            ]);

            cache()->forget('org-kits:'.$user->org_id.'-all');

            $investigation = $this->makeInvestigation();
            $investigation->setActivePrompt('show me the latest hits');

            (new HitSearchTool($user, $investigation))->handle(new Request([
                'time_range' => 'last_30_days',
            ]));

            $ids = collect($investigation->data->searchResults)->pluck('resultId')->all();
            $this->assertContains($recent->id, $ids);
            $this->assertNotContains($older->id, $ids);

            $start = Carbon::parse($investigation->data->searches[0]->parameters['start_date'])->utc();
            $this->assertSame('2026-07-16 03:00:00', $start->format('Y-m-d H:i:s'));
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_latest_scan_search_keeps_the_thirty_day_default(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-16 15:00:00', 'UTC'));

        try {
            $user = $this->createUser([
                'dti' => ['timeZone' => 'UTC'],
            ]);
            $investigation = $this->makeInvestigation();
            $investigation->setActivePrompt('show me the latest scans');

            $tool = new class($user, $investigation) extends ScanSearchTool
            {
                /**
                 * @return array{0: ?string, 1: ?string}
                 */
                public function resolvedWindow(Request $request): array
                {
                    return $this->resolveOttoRange($request, 'UTC');
                }
            };

            [$start, $end] = $tool->resolvedWindow(new Request([]));

            $this->assertSame('2026-06-16 00:00:00', Carbon::parse($start)->utc()->format('Y-m-d H:i:s'));
            $this->assertSame('2026-07-16 23:59:59', Carbon::parse($end)->utc()->format('Y-m-d H:i:s'));
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_scan_and_hotlist_search_descriptions_omit_the_search_cap(): void
    {
        $user = $this->createUser();
        $investigation = new Investigation;
        $investigation->data = new AgentInvestigationProperties;

        $scanDescription = (string) (new ScanSearchTool($user, $investigation))->description();
        $this->assertSearchToolDescriptionOmitsCap($scanDescription);
        $this->assertStringContainsString('no plate or kit', strtolower($scanDescription));
        $this->assertStringContainsString('Available on every page', $scanDescription);
        $this->assertSearchToolDescriptionOmitsCap((string) (new HotlistSearchTool($user, $investigation))->description());
    }

    public function test_plate_pattern_keeps_wildcard_asterisks(): void
    {
        $this->assertSame('12ABC*', PlatePattern::normalize('12abc*'));
        $this->assertSame('ABC', PlatePattern::normalize('abc'));
        $this->assertSame('12ABC*', PlatePattern::normalize('12-ABC*'));
        $this->assertSame('ABC12', PlatePattern::normalize('ABC-12'));
    }

    public function test_show_result_details_requires_matching_search_type(): void
    {
        Event::fake([OttoResultDetailShown::class]);

        $user = $this->createUser(permissions: PermissionEnum::ViewAny_Hit->value);
        $investigation = new Investigation;
        $investigation->setActiveConversation((string) Str::uuid());
        $investigation->data = new AgentInvestigationProperties(
            searches: [
                new Search(
                    searchId: 'scansearch01',
                    messageId: (string) Str::uuid(),
                    parameters: [],
                    searchType: SearchType::Scan,
                    title: 'Scan search',
                    createdBy: $user->id,
                ),
            ],
            searchResults: [
                new SearchResult(searchId: 'scansearch01', resultId: 100),
            ],
        );

        $tool = new ShowResultDetailsTool($user, $investigation);
        $result = (string) $tool->handle(new Request([
            'type' => 'hit',
            'result_ids' => [100],
        ]));

        $this->assertStringContainsString('None of those result IDs', $result);
        Event::assertNotDispatched(OttoResultDetailShown::class);
    }

    public function test_show_result_details_broadcasts_result_id_payload(): void
    {
        Event::fake([OttoResultDetailShown::class]);

        $user = $this->createUser(permissions: PermissionEnum::ViewAny_Hit->value);
        $investigation = new Investigation;
        $investigation->setActiveConversation((string) Str::uuid());
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
                new SearchResult(searchId: 'hitsearch01', resultId: 42),
            ],
        );

        $tool = new ShowResultDetailsTool($user, $investigation);
        $tool->handle(new Request([
            'type' => 'hit',
            'result_ids' => [42],
        ]));

        Event::assertDispatched(OttoResultDetailShown::class, function (OttoResultDetailShown $event): bool {
            $payload = $event->broadcastWith();

            return $payload['result_id'] === 42
                && $payload['result_type'] === 'hit';
        });
    }

    public function test_hits_list_query_is_kit_scoped(): void
    {
        $user = $this->createUser();

        $this->assertStringContainsString('0 = 1', HitsListQuery::baseQuery($user)->toSql());

        Kit::factory()->forOrg($user->org)->create();
        Kit::forgetCachedKits($user->org_id);

        $sql = HitsListQuery::baseQuery($user)->toSql();

        $this->assertStringContainsString('"hits"."kit_id"', $sql);
        $this->assertStringContainsString('"hotlist"."integration_id"', $sql);
        $this->assertDoesNotMatchRegularExpression('/\bwhere\b.*\bhotlist_org_id\b/i', $sql);
    }

    public function test_hits_list_query_is_kit_scoped_on_clickhouse(): void
    {
        Config::set('app.experimental.clickhouse.hits', true);
        $user = $this->createUser();

        $empty = str_replace(['`', '"'], '', HitsListQuery::baseQuery($user)->toSql());
        $this->assertStringContainsString('0 = 1', $empty);
        $this->assertStringNotContainsString('in (0)', $empty);

        Kit::factory()->forOrg($user->org)->create();
        Kit::forgetCachedKits($user->org_id);

        $sql = str_replace(['`', '"'], '', HitsListQuery::baseQuery($user)->toSql());
        $where = substr($sql, (int) stripos($sql, ' where '));

        $this->assertStringContainsString('kit_id in', $where);
        $this->assertStringNotContainsString('hotlist_org_id', $where);
        $this->assertStringNotContainsString('0 = 1', $where);
    }

    public function test_hit_search_actionable_filter_keeps_literal_new_hit_on_clickhouse(): void
    {
        $tool = new HitSearchTool($this->createUser(), $this->makeInvestigation());
        $query = (new ClickhouseHit)->newQuery();

        $constrain = new ReflectionMethod($tool, 'constrainActionable');
        $constrain->setAccessible(true);
        $constrain->invoke($tool, $query);

        $sql = $query->toSql();

        $this->assertStringContainsString('disposition IS NULL', $sql);
        $this->assertStringContainsString("'New Hit'", $sql);
    }

    public function test_hit_search_defaults_to_last_30_days_and_optional_plate(): void
    {
        Event::fake([OttoWidgetCreated::class]);

        $user = $this->createUser();
        $this->actingAs($user);

        $investigation = $this->makeInvestigation();
        $tool = new HitSearchTool($user, $investigation);

        $result = (string) $tool->handle(new Request([]));

        $this->assertStringContainsString('No matching hits.', $result);
        $this->assertStringNotContainsString('Found 0', $result);
        $this->assertNull($investigation->data->searches[0]->parameters['plate']);

        $tz = $user->timezone ?? 'UTC';
        $this->assertSame(
            now($tz)->copy()->subDays(30)->startOfDay()->toIso8601String(),
            $investigation->data->searches[0]->parameters['start_date'],
        );
        $this->assertSame(
            now($tz)->copy()->endOfDay()->toIso8601String(),
            $investigation->data->searches[0]->parameters['end_date'],
        );
    }

    public function test_hit_search_expands_date_only_end_date_to_include_same_day_hits(): void
    {
        Event::fake([OttoWidgetCreated::class]);

        $user = $this->createUser();
        $this->actingAs($user);

        $hit = Hit::factory()->forOrg($user->org, [
            'ocr' => 'TODAY01',
            'scanned_at' => now()->setTime(16, 20, 0),
        ])->create(['created_at' => now()->setTime(16, 20, 0)]);

        cache()->forget('org-kits:'.$user->org_id.'-all');

        $investigation = $this->makeInvestigation();
        $tool = new HitSearchTool($user, $investigation);

        $result = (string) $tool->handle(new Request([
            'start_date' => now()->subDays(90)->toDateString(),
            'end_date' => now()->toDateString(),
        ]));

        $this->assertStringContainsString('Here is the most recent hit', $result);
        $this->assertStringNotContainsString('Found 1 hit', $result);
        $this->assertSame([$hit->id], collect($investigation->data->searchResults)->pluck('resultId')->all());
        $this->assertSame(
            now($user->timezone ?? 'UTC')->endOfDay()->toIso8601String(),
            $investigation->data->searches[0]->parameters['end_date'],
        );
    }

    public function test_hit_search_normalizes_wildcard_plates(): void
    {
        Event::fake([OttoWidgetCreated::class]);

        $user = $this->createUser();
        $this->actingAs($user);

        $investigation = $this->makeInvestigation();
        $tool = new HitSearchTool($user, $investigation);

        $tool->handle(new Request(['plate' => '12ABC*']));

        $this->assertSame('12ABC*', $investigation->data->searches[0]->parameters['plate']);
        $this->assertSame('Hit search: 12ABC*', $investigation->data->searches[0]->title);
        $this->assertSame('', $investigation->data->searches[0]->messageId);
    }

    public function test_hit_search_returns_only_the_users_kit_hits(): void
    {
        Event::fake([OttoWidgetCreated::class]);

        $user = $this->createUser();
        $this->actingAs($user);

        $own = Hit::factory()->forOrg($user->org, [
            'ocr' => 'ABC1234',
            'scanned_at' => now(),
        ])->create(['created_at' => now()]);

        $otherUser = $this->createUser();
        $other = Hit::factory()->forOrg($otherUser->org, [
            'ocr' => 'XYZ9999',
            'scanned_at' => now(),
        ])->create(['created_at' => now()]);

        cache()->forget('org-kits:'.$user->org_id.'-all');

        $investigation = $this->makeInvestigation();
        $tool = new HitSearchTool($user, $investigation);

        $result = (string) $tool->handle(new Request([]));

        $this->assertStringContainsString('Here is the most recent hit.', $result);
        $this->assertStringNotContainsString('Found 1 hit', $result);

        $ids = collect($investigation->data->searchResults)->pluck('resultId');
        $this->assertTrue($ids->contains($own->id));
        $this->assertFalse($ids->contains($other->id));

        Event::assertDispatched(OttoWidgetCreated::class, function (OttoWidgetCreated $event): bool {
            return $event->widgetType === 'hit';
        });
    }

    public function test_hit_search_matches_prefix_wildcard_against_kit_hits(): void
    {
        Event::fake([OttoWidgetCreated::class]);

        $user = $this->createUser();
        $this->actingAs($user);

        $match = Hit::factory()->forOrg($user->org, [
            'ocr' => '12ABC99',
            'scanned_at' => now(),
        ])->create(['created_at' => now()]);

        Hit::factory()->forOrg($user->org, [
            'ocr' => 'ZZZ0000',
            'scanned_at' => now(),
        ])->create(['created_at' => now()]);

        Hit::factory()->forOrg($user->org, [
            'ocr' => 'X12ABC',
            'scanned_at' => now(),
        ])->create(['created_at' => now()]);

        cache()->forget('org-kits:'.$user->org_id.'-all');

        $investigation = $this->makeInvestigation();
        $tool = new HitSearchTool($user, $investigation);

        $result = (string) $tool->handle(new Request(['plate' => '12ABC*']));

        $this->assertStringContainsString('Here is the most recent hit for 12ABC*', $result);
        $this->assertStringNotContainsString('Found 1 hit', $result);
        $this->assertSame([$match->id], collect($investigation->data->searchResults)->pluck('resultId')->all());
    }

    public function test_search_widget_filters_rows_by_search_ids(): void
    {
        $html = view('otto.search-widget', [
            'type' => 'hit',
            'searchId' => 'abc123xyz',
        ])->render();

        $this->assertStringContainsString('searchIds', $html);
        $this->assertStringContainsString('abc123xyz', $html);
        $this->assertStringContainsString('pageLabel', $html);
        $this->assertStringContainsString('most recent', $html);
        $this->assertStringNotContainsString("'record' : 'records'", $html);
    }

    public function test_search_widget_uses_typed_hotlist_pool(): void
    {
        $html = view('otto.search-widget', [
            'type' => 'hotlist',
            'searchId' => 'hlsearch01',
        ])->render();

        $this->assertStringContainsString('data.hotlists', $html);
        $this->assertStringContainsString('isHotlist', $html);
        $this->assertStringContainsString('Assignment Results', $html);
    }

    public function test_investigation_data_hydrates_kit_scoped_hits_with_search_ids(): void
    {
        $user = $this->createUser(permissions: PermissionEnum::ViewAny_Hit->value);
        $this->setOrgProTier($user->org);
        setPermissionsTeamId($user->org_id);
        $user->givePermissionTo('use-otto');
        $this->actingAs($user);

        $own = Hit::factory()->forOrg($user->org, [
            'ocr' => 'ABC1234',
            'scanned_at' => now(),
            'camera_name' => 'Unit 7',
        ])->create(['created_at' => now()]);

        $otherUser = $this->createUser();
        $other = Hit::factory()->forOrg($otherUser->org, [
            'ocr' => 'LEAK999',
            'scanned_at' => now(),
        ])->create(['created_at' => now()]);

        cache()->forget('org-kits:'.$user->org_id.'-all');

        $conversationId = (string) Str::uuid();
        DB::table('agent_conversations')->insert([
            'id' => $conversationId,
            'user_id' => $user->id,
            'title' => 'Hit search',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        AgentInvestigation::query()->create([
            'user_id' => $user->id,
            'conversation_id' => $conversationId,
            'properties' => new AgentInvestigationProperties(
                searches: [
                    new Search(
                        searchId: 'hitsearch01',
                        messageId: (string) Str::uuid(),
                        parameters: ['plate' => null],
                        searchType: SearchType::Hit,
                        title: 'Hit search',
                        createdBy: $user->id,
                        conversationId: $conversationId,
                    ),
                ],
                searchResults: [
                    new SearchResult(searchId: 'hitsearch01', resultId: $own->id),
                    new SearchResult(searchId: 'hitsearch01', resultId: $other->id),
                ],
            ),
        ]);

        $response = $this->getJson(route('otto.investigation', $conversationId));

        $response->assertOk();
        $response->assertJsonPath('hits.0.id', $own->id);
        $response->assertJsonPath('hits.0.searchIds.0', 'hitsearch01');
        $response->assertJsonPath('hits.0.plate', 'ABC1234');
        $this->assertNotEmpty($response->json('hits.0.camera'));
        $this->assertCount(1, $response->json('hits'));
        $this->assertArrayNotHasKey('total', $response->json());
        $this->assertArrayNotHasKey('recordCount', $response->json());
        $this->assertArrayNotHasKey('count', $response->json());
        $this->assertNotEmpty($response->json('hits.0.car_image'));
        $this->assertNotEmpty($response->json('hits.0.guid'));
    }

    public function test_scout_driver_position_tool_is_registered(): void
    {
        $registry = app(ToolRegistry::class);

        $this->assertContains(
            ScoutDriverPositionTool::class,
            $registry->registered()
        );
    }

    public function test_hotlist_search_tool_describes_assignments_not_hit_events(): void
    {
        $user = $this->createUser();
        $investigation = new Investigation;
        $investigation->data = new AgentInvestigationProperties;

        $tool = new HotlistSearchTool($user, $investigation);
        $description = strtolower((string) $tool->description());
        $schema = $tool->schema(new JsonSchemaTypeFactory);

        $this->assertStringContainsString('assignment', $description);
        $this->assertStringContainsString('organization', $description);
        $this->assertStringContainsString('wanted', $description);
        $this->assertStringNotContainsString('hotlist match', $description);
        $this->assertStringNotContainsString('last 30 days when dates are omitted', $description);
        $this->assertArrayHasKey('plate', $schema);
        $this->assertArrayHasKey('vin', $schema);
        $this->assertArrayHasKey('status', $schema);
        $this->assertArrayHasKey('order_id', $schema);
        $this->assertArrayHasKey('client', $schema);
        $this->assertArrayHasKey('source', $schema);
    }

    public function test_hotlist_search_tool_is_registered(): void
    {
        $registry = app(ToolRegistry::class);

        $this->assertContains(HotlistSearchTool::class, $registry->registered());
        $this->assertContains(ApplyScanSearchFiltersTool::class, $registry->registered());
        $this->assertContains(ApplyHitSearchFiltersTool::class, $registry->registered());
        $this->assertContains(ApplyHotlistSearchFiltersTool::class, $registry->registered());
        $this->assertContains(ZoneSearchTool::class, $registry->registered());
        $this->assertContains(ProposeCreateZoneTool::class, $registry->registered());
        $this->assertContains(ProposeUpdateZoneTool::class, $registry->registered());
    }

    public function test_hotlist_search_rejects_unknown_status(): void
    {
        Event::fake([OttoWidgetCreated::class]);

        $user = $this->createUser();
        $investigation = $this->makeInvestigation();
        $tool = new HotlistSearchTool($user, $investigation);

        $result = strtolower((string) $tool->handle(new Request(['status' => 'not-a-real-status'])));

        $this->assertStringContainsString('recognize', $result);
        $this->assertStringContainsString('open', $result);
        $this->assertSame([], $investigation->data->searches);
        Event::assertNotDispatched(OttoWidgetCreated::class);
    }

    public function test_show_result_details_does_not_treat_hotlist_ids_as_hits(): void
    {
        Event::fake([OttoResultDetailShown::class]);

        $user = $this->createUser(permissions: PermissionEnum::ViewAny_Hit->value);
        $investigation = new Investigation;
        $investigation->setActiveConversation((string) Str::uuid());
        $investigation->data = new AgentInvestigationProperties(
            searches: [
                new Search(
                    searchId: 'hotlistsearch01',
                    messageId: (string) Str::uuid(),
                    parameters: [],
                    searchType: SearchType::Hotlist,
                    title: 'Assignment search',
                    createdBy: $user->id,
                ),
            ],
            searchResults: [
                new SearchResult(searchId: 'hotlistsearch01', resultId: 77),
            ],
        );

        $tool = new ShowResultDetailsTool($user, $investigation);
        $result = (string) $tool->handle(new Request([
            'type' => 'hit',
            'result_ids' => [77],
        ]));

        $this->assertStringContainsString('None of those result IDs', $result);
        Event::assertNotDispatched(OttoResultDetailShown::class);
    }

    public function test_chat_search_tools_require_the_matching_view_permission(): void
    {
        $investigation = $this->makeInvestigation();

        foreach ([
            [ScanSearchTool::class, PermissionEnum::ViewAny_SCAN],
            [HitSearchTool::class, PermissionEnum::ViewAny_Hit],
            [HotlistSearchTool::class, PermissionEnum::ViewAny_Hotlist],
        ] as [$toolClass, $permission]) {
            $allowed = $this->createUser(permissions: $permission->value);
            $this->assertTrue(
                (new $toolClass($allowed, $investigation))->authorizedFor($allowed),
                $toolClass.' should be offered to a user holding '.$permission->value,
            );

            $denied = $this->createUser();
            $this->assertFalse(
                (new $toolClass($denied, $investigation))->authorizedFor($denied),
                $toolClass.' must not be offered without '.$permission->value,
            );
        }
    }

    public function test_show_result_details_refuses_a_type_the_user_may_not_view(): void
    {
        Event::fake([OttoResultDetailShown::class]);

        $user = $this->createUser(permissions: PermissionEnum::ViewAny_Hotlist->value);
        $investigation = new Investigation;
        $investigation->setActiveConversation((string) Str::uuid());
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
                new SearchResult(searchId: 'hitsearch01', resultId: 42),
            ],
        );

        $tool = new ShowResultDetailsTool($user, $investigation);

        // Registered (they hold one of the three), but the requested type is not
        // theirs — the per-type check in handle() is what stops it.
        $this->assertTrue($tool->authorizedFor($user));

        $result = (string) $tool->handle(new Request([
            'type' => 'hit',
            'result_ids' => [42],
        ]));

        $this->assertStringContainsString('not permitted', $result);
        Event::assertNotDispatched(OttoResultDetailShown::class);
    }

    public function test_investigation_data_omits_hits_the_user_may_not_view(): void
    {
        $user = $this->createUser();
        $this->setOrgProTier($user->org);
        setPermissionsTeamId($user->org_id);
        $user->givePermissionTo('use-otto');
        $this->actingAs($user);

        $own = Hit::factory()->forOrg($user->org, [
            'ocr' => 'ABC1234',
            'scanned_at' => now(),
            'camera_name' => 'Unit 7',
        ])->create(['created_at' => now()]);

        cache()->forget('org-kits:'.$user->org_id.'-all');

        $conversationId = (string) Str::uuid();
        DB::table('agent_conversations')->insert([
            'id' => $conversationId,
            'user_id' => $user->id,
            'title' => 'Hit search',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        AgentInvestigation::query()->create([
            'user_id' => $user->id,
            'conversation_id' => $conversationId,
            'properties' => new AgentInvestigationProperties(
                searches: [
                    new Search(
                        searchId: 'hitsearch01',
                        messageId: (string) Str::uuid(),
                        parameters: ['plate' => null],
                        searchType: SearchType::Hit,
                        title: 'Hit search',
                        createdBy: $user->id,
                        conversationId: $conversationId,
                    ),
                ],
                searchResults: [
                    new SearchResult(searchId: 'hitsearch01', resultId: $own->id),
                ],
            ),
        ]);

        // use-otto alone must not hydrate hit records: the ids survive on the
        // investigation, so this is the layer that holds after a role loses
        // ViewAny_Hit.
        $response = $this->getJson(route('otto.investigation', $conversationId));

        $response->assertOk();
        $response->assertJsonPath('hits', []);
    }

    public function test_visible_to_org_includes_user_org_and_excludes_foreign_org_without_staging(): void
    {
        $user = $this->createUser();
        $orgId = (int) $user->org_id;
        $foreignOrgId = $orgId + 999;

        $withoutStaging = ClickhouseHotlist::query()->visibleToOrg($orgId, []);

        $this->assertContains($orgId, $withoutStaging->getBindings());
        $this->assertNotContains($foreignOrgId, $withoutStaging->getBindings());
        $this->assertStringContainsString('org_id', $withoutStaging->toSql());
        $this->assertStringNotContainsString((string) $foreignOrgId, $withoutStaging->toSql());

        $withStaging = ClickhouseHotlist::query()->visibleToOrg($orgId, [$foreignOrgId]);

        $this->assertContains($orgId, $withStaging->getBindings());
        $this->assertStringContainsString((string) $foreignOrgId, $withStaging->toSql());
        $this->assertStringContainsString('is_staged', $withStaging->toSql());
    }

    public function test_investigation_data_includes_empty_hotlists_without_querying_clickhouse(): void
    {
        $user = $this->createUser();
        $this->setOrgProTier($user->org);
        setPermissionsTeamId($user->org_id);
        $user->givePermissionTo('use-otto');
        $this->actingAs($user);

        $conversationId = (string) Str::uuid();
        DB::table('agent_conversations')->insert([
            'id' => $conversationId,
            'user_id' => $user->id,
            'title' => 'Assignment search',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        AgentInvestigation::query()->create([
            'user_id' => $user->id,
            'conversation_id' => $conversationId,
            'properties' => new AgentInvestigationProperties(
                searches: [
                    new Search(
                        searchId: 'hotlistsearch01',
                        messageId: (string) Str::uuid(),
                        parameters: ['plate' => null],
                        searchType: SearchType::Hotlist,
                        title: 'Assignment search',
                        createdBy: $user->id,
                        conversationId: $conversationId,
                    ),
                ],
            ),
        ]);

        $response = $this->getJson(route('otto.investigation', $conversationId));

        $response->assertOk();
        $response->assertJsonPath('hotlists', []);
    }

    public function test_investigation_data_hydrates_scout_driver_positions_from_search_parameters(): void
    {
        $user = $this->createUser();
        $this->setOrgProTier($user->org);
        setPermissionsTeamId($user->org_id);
        $user->givePermissionTo('use-otto');
        $this->actingAs($user);

        $conversationId = (string) Str::uuid();
        DB::table('agent_conversations')->insert([
            'id' => $conversationId,
            'user_id' => $user->id,
            'title' => 'Scout driver location',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        AgentInvestigation::query()->create([
            'user_id' => $user->id,
            'conversation_id' => $conversationId,
            'properties' => new AgentInvestigationProperties(
                searches: [
                    new Search(
                        searchId: 'scoutsearch01',
                        messageId: (string) Str::uuid(),
                        parameters: [
                            'positions' => [[
                                'kit_id' => 8,
                                'name' => 'mertz.kimberly',
                                'driver_name' => 'Admin',
                                'lat' => 39.7684,
                                'lng' => -86.1581,
                                'last_seen_at_utc' => '2026-08-19T14:00:00+00:00',
                                'place' => '123 N Meridian St, Indianapolis, IN',
                            ]],
                        ],
                        searchType: SearchType::ScoutDriver,
                        title: 'Scout driver location',
                        createdBy: $user->id,
                        conversationId: $conversationId,
                    ),
                ],
            ),
        ]);

        $response = $this->getJson(route('otto.investigation', $conversationId));

        $response->assertOk();
        $response->assertJsonPath('scans', []);
        $response->assertJsonPath('hits', []);
        $response->assertJsonPath('drivers.0.id', 8);
        $response->assertJsonPath('drivers.0.type', 'scout_driver');
        $response->assertJsonPath('drivers.0.searchIds.0', 'scoutsearch01');
        $response->assertJsonPath('drivers.0.plate', 'Admin');
        $response->assertJsonPath('drivers.0.place', '123 N Meridian St, Indianapolis, IN');
        $response->assertJsonPath('drivers.0.kit_name', 'mertz.kimberly');
        $response->assertJsonPath('drivers.0.last_seen_at_utc', '2026-08-19T14:00:00+00:00');
        $this->assertArrayNotHasKey('scanned_at', $response->json('drivers.0'));
        $this->assertEqualsWithDelta(39.7684, $response->json('drivers.0.lat'), 0.0001);
        $this->assertEqualsWithDelta(-86.1581, $response->json('drivers.0.lng'), 0.0001);
        $this->assertCount(1, $response->json('drivers'));
    }

    public function test_investigation_data_recomputes_driver_minutes_ago_from_last_seen_at_utc(): void
    {
        $this->travelTo(Carbon::parse('2026-08-19T16:28:33+00:00'));

        $user = $this->createUser();
        $this->setOrgProTier($user->org);
        setPermissionsTeamId($user->org_id);
        $user->givePermissionTo('use-otto');
        $this->actingAs($user);

        $conversationId = (string) Str::uuid();
        DB::table('agent_conversations')->insert([
            'id' => $conversationId,
            'user_id' => $user->id,
            'title' => 'Scout driver location',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        AgentInvestigation::query()->create([
            'user_id' => $user->id,
            'conversation_id' => $conversationId,
            'properties' => new AgentInvestigationProperties(
                searches: [
                    new Search(
                        searchId: 'scoutsearch01',
                        messageId: (string) Str::uuid(),
                        parameters: [
                            'positions' => [[
                                'kit_id' => 19,
                                'name' => 'maria.garcia',
                                'driver_name' => 'Maria Garcia',
                                'lat' => 32.7767,
                                'lng' => -96.797,
                                'last_seen_at_utc' => '2026-08-19T16:20:33+00:00',
                                'place' => 'Dallas, TX',
                            ]],
                        ],
                        searchType: SearchType::ScoutDriver,
                        title: 'Scout driver location',
                        createdBy: $user->id,
                        conversationId: $conversationId,
                    ),
                ],
            ),
        ]);

        $fresh = $this->getJson(route('otto.investigation', $conversationId));
        $fresh->assertOk();
        $fresh->assertJsonPath('drivers.0.last_seen_at_utc', '2026-08-19T16:20:33+00:00');
        $fresh->assertJsonPath('drivers.0.minutes_ago', 8);

        $this->travel(30)->minutes();

        $later = $this->getJson(route('otto.investigation', $conversationId));
        $later->assertOk();
        $later->assertJsonPath('drivers.0.last_seen_at_utc', '2026-08-19T16:20:33+00:00');
        $later->assertJsonPath('drivers.0.minutes_ago', 38);
        $this->assertNotSame(8, $later->json('drivers.0.minutes_ago'));
    }

    public function test_investigation_data_omits_minutes_ago_when_last_seen_at_utc_is_missing(): void
    {
        $user = $this->createUser();
        $this->setOrgProTier($user->org);
        setPermissionsTeamId($user->org_id);
        $user->givePermissionTo('use-otto');
        $this->actingAs($user);

        $conversationId = (string) Str::uuid();
        DB::table('agent_conversations')->insert([
            'id' => $conversationId,
            'user_id' => $user->id,
            'title' => 'Scout driver location',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        AgentInvestigation::query()->create([
            'user_id' => $user->id,
            'conversation_id' => $conversationId,
            'properties' => new AgentInvestigationProperties(
                searches: [
                    new Search(
                        searchId: 'scoutsearch01',
                        messageId: (string) Str::uuid(),
                        parameters: [
                            'positions' => [[
                                'kit_id' => 19,
                                'name' => 'maria.garcia',
                                'driver_name' => 'Maria Garcia',
                                'lat' => 32.7767,
                                'lng' => -96.797,
                                'minutes_ago' => 8,
                                'place' => 'Dallas, TX',
                            ]],
                        ],
                        searchType: SearchType::ScoutDriver,
                        title: 'Scout driver location',
                        createdBy: $user->id,
                        conversationId: $conversationId,
                    ),
                ],
            ),
        ]);

        $response = $this->getJson(route('otto.investigation', $conversationId));
        $response->assertOk();
        $response->assertJsonPath('drivers.0.last_seen_at_utc', null);
        $response->assertJsonPath('drivers.0.minutes_ago', null);
    }

    public function test_investigation_data_falls_back_to_legacy_driver_scanned_at(): void
    {
        $this->travelTo(Carbon::parse('2026-08-19T16:28:33+00:00'));

        $user = $this->createUser();
        $this->setOrgProTier($user->org);
        setPermissionsTeamId($user->org_id);
        $user->givePermissionTo('use-otto');
        $this->actingAs($user);

        $conversationId = (string) Str::uuid();
        DB::table('agent_conversations')->insert([
            'id' => $conversationId,
            'user_id' => $user->id,
            'title' => 'Scout driver location',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        AgentInvestigation::query()->create([
            'user_id' => $user->id,
            'conversation_id' => $conversationId,
            'properties' => new AgentInvestigationProperties(
                searches: [
                    new Search(
                        searchId: 'scoutsearch01',
                        messageId: (string) Str::uuid(),
                        parameters: [
                            'positions' => [[
                                'kit_id' => 19,
                                'name' => 'maria.garcia',
                                'driver_name' => 'Maria Garcia',
                                'lat' => 32.7767,
                                'lng' => -96.797,
                                'minutes_ago' => 8,
                                'scanned_at' => '2026-08-19T16:20:33+00:00',
                                'place' => 'Dallas, TX',
                            ]],
                        ],
                        searchType: SearchType::ScoutDriver,
                        title: 'Scout driver location',
                        createdBy: $user->id,
                        conversationId: $conversationId,
                    ),
                ],
            ),
        ]);

        $response = $this->getJson(route('otto.investigation', $conversationId));
        $response->assertOk();
        $response->assertJsonPath('drivers.0.last_seen_at_utc', '2026-08-19T16:20:33+00:00');
        $response->assertJsonPath('drivers.0.minutes_ago', 8);
        $this->assertArrayNotHasKey('scanned_at', $response->json('drivers.0'));
    }

    public function test_investigation_data_keeps_driver_when_last_seen_at_utc_is_unparseable(): void
    {
        $user = $this->createUser();
        $this->setOrgProTier($user->org);
        setPermissionsTeamId($user->org_id);
        $user->givePermissionTo('use-otto');
        $this->actingAs($user);

        $conversationId = (string) Str::uuid();
        DB::table('agent_conversations')->insert([
            'id' => $conversationId,
            'user_id' => $user->id,
            'title' => 'Scout driver location',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        AgentInvestigation::query()->create([
            'user_id' => $user->id,
            'conversation_id' => $conversationId,
            'properties' => new AgentInvestigationProperties(
                searches: [
                    new Search(
                        searchId: 'scoutsearch01',
                        messageId: (string) Str::uuid(),
                        parameters: [
                            'positions' => [[
                                'kit_id' => 19,
                                'name' => 'maria.garcia',
                                'driver_name' => 'Maria Garcia',
                                'lat' => 32.7767,
                                'lng' => -96.797,
                                'last_seen_at_utc' => 'not-a-date',
                                'place' => 'Dallas, TX',
                            ]],
                        ],
                        searchType: SearchType::ScoutDriver,
                        title: 'Scout driver location',
                        createdBy: $user->id,
                        conversationId: $conversationId,
                    ),
                ],
            ),
        ]);

        $response = $this->getJson(route('otto.investigation', $conversationId));
        $response->assertOk();
        $response->assertJsonPath('drivers.0.id', 19);
        $response->assertJsonPath('drivers.0.plate', 'Maria Garcia');
        $response->assertJsonPath('drivers.0.last_seen_at_utc', null);
        $response->assertJsonPath('drivers.0.minutes_ago', null);
        $this->assertEqualsWithDelta(32.7767, $response->json('drivers.0.lat'), 0.0001);
    }

    public function test_hit_search_interprets_naive_custom_dates_in_the_users_timezone_without_auth(): void
    {
        Event::fake([OttoWidgetCreated::class]);

        $user = $this->createUser(['dti' => ['timeZone' => 'America/Chicago']]);
        $investigation = $this->makeInvestigation();
        $tool = new HitSearchTool($user, $investigation);

        $tool->handle(new Request([
            'start_date' => '2026-05-26T18:43:00',
            'end_date' => '2026-06-12T15:00:00',
        ]));

        $start = $investigation->data->searches[0]->parameters['start_date'];
        $end = $investigation->data->searches[0]->parameters['end_date'];

        $this->assertSame('2026-05-26 23:43:00', Carbon::parse($start)->utc()->format('Y-m-d H:i:s'));
        $this->assertSame('2026-06-12 20:00:00', Carbon::parse($end)->utc()->format('Y-m-d H:i:s'));
        $this->assertMatchesRegularExpression('/[+-]\d{2}:\d{2}$/', $start);
        $this->assertMatchesRegularExpression('/[+-]\d{2}:\d{2}$/', $end);
    }

    public function test_hit_search_honors_an_explicit_timezone_offset_over_profile_timezone(): void
    {
        Event::fake([OttoWidgetCreated::class]);

        $user = $this->createUser(['dti' => ['timeZone' => 'America/New_York']]);
        $investigation = $this->makeInvestigation();
        $tool = new HitSearchTool($user, $investigation);

        $tool->handle(new Request([
            'start_date' => '2026-05-26T18:43:00-05:00',
            'end_date' => '2026-06-12T15:00:00-05:00',
        ]));

        $start = $investigation->data->searches[0]->parameters['start_date'];

        $this->assertSame('2026-05-26 23:43:00', Carbon::parse($start)->utc()->format('Y-m-d H:i:s'));
    }

    public function test_hit_search_today_uses_user_timezone_on_the_queue_without_auth(): void
    {
        Event::fake([OttoWidgetCreated::class]);

        $this->travelTo(Carbon::parse('2026-07-16 03:00:00', 'UTC'));

        $user = $this->createUser(['dti' => ['timeZone' => 'America/Chicago']]);

        $inToday = Hit::factory()->forOrg($user->org, [
            'ocr' => 'TODAY01',
            'scanned_at' => Carbon::parse('2026-07-16 02:00:00', 'UTC'),
        ])->create(['created_at' => Carbon::parse('2026-07-16 02:00:00', 'UTC')]);

        Hit::factory()->forOrg($user->org, [
            'ocr' => 'YDAY99',
            'scanned_at' => Carbon::parse('2026-07-15 03:00:00', 'UTC'),
        ])->create(['created_at' => Carbon::parse('2026-07-15 03:00:00', 'UTC')]);

        cache()->forget('org-kits:'.$user->org_id.'-all');

        $investigation = $this->makeInvestigation();
        $tool = new HitSearchTool($user, $investigation);

        $result = (string) $tool->handle(new Request(['time_range' => 'today']));

        $this->assertStringContainsString('Here is the most recent hit', $result);
        $this->assertStringNotContainsString('Found 1 hit', $result);
        $this->assertSame([$inToday->id], collect($investigation->data->searchResults)->pluck('resultId')->all());
    }

    public function test_investigation_data_formats_hit_scanned_at_in_the_users_timezone(): void
    {
        $this->travelTo(Carbon::parse('2026-07-16 03:00:00', 'UTC'));

        $user = $this->createUser(['dti' => ['timeZone' => 'America/Chicago']], permissions: PermissionEnum::ViewAny_Hit->value);
        $this->setOrgProTier($user->org);
        setPermissionsTeamId($user->org_id);
        $user->givePermissionTo('use-otto');
        $this->actingAs($user);

        $own = Hit::factory()->forOrg($user->org, [
            'ocr' => 'ABC1234',
            'scanned_at' => Carbon::parse('2026-07-16 02:00:00', 'UTC'),
            'camera_name' => 'Unit 7',
        ])->create(['created_at' => Carbon::parse('2026-07-16 02:00:00', 'UTC')]);

        cache()->forget('org-kits:'.$user->org_id.'-all');

        $conversationId = (string) Str::uuid();
        DB::table('agent_conversations')->insert([
            'id' => $conversationId,
            'user_id' => $user->id,
            'title' => 'Hit search',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        AgentInvestigation::query()->create([
            'user_id' => $user->id,
            'conversation_id' => $conversationId,
            'properties' => new AgentInvestigationProperties(
                searches: [
                    new Search(
                        searchId: 'hitsearch01',
                        messageId: (string) Str::uuid(),
                        parameters: ['plate' => null],
                        searchType: SearchType::Hit,
                        title: 'Hit search',
                        createdBy: $user->id,
                        conversationId: $conversationId,
                    ),
                ],
                searchResults: [
                    new SearchResult(searchId: 'hitsearch01', resultId: $own->id),
                ],
            ),
        ]);

        $response = $this->getJson(route('otto.investigation', $conversationId));

        $response->assertOk();
        $response->assertJsonPath('hits.0.id', $own->id);
        $response->assertJsonPath('hits.0.scanned_at', 'Jul 15, 2026 09:00 PM CDT');
        $this->assertStringNotContainsString('T02:00:00', (string) $response->json('hits.0.scanned_at'));
    }

    public function test_hit_search_with_thirteen_rows_names_thirteen_not_the_cap(): void
    {
        Event::fake([OttoWidgetCreated::class]);

        $user = $this->createUser();
        $this->actingAs($user);

        for ($i = 0; $i < 13; $i++) {
            Hit::factory()->forOrg($user->org, [
                'ocr' => 'PG13'.str_pad((string) $i, 3, '0', STR_PAD_LEFT),
                'scanned_at' => now()->subSeconds($i),
            ])->create([
                'created_at' => now()->subSeconds($i),
            ]);
        }

        cache()->forget('org-kits:'.$user->org_id.'-all');

        $investigation = $this->makeInvestigation();
        $result = (string) (new HitSearchTool($user, $investigation))->handle(new Request([]));

        $pageCount = collect($investigation->data->searchResults)->pluck('resultId')->unique()->count();

        $this->assertGreaterThan(1, $pageCount);
        $this->assertLessThan(LimitedToolResultMessage::SEARCH_LIMIT, $pageCount);
        $this->assertReturnedPageCountWithoutCap($result, $pageCount);
    }

    public function test_scan_search_with_thirteen_rows_names_thirteen_not_the_cap(): void
    {
        Event::fake([OttoWidgetCreated::class]);

        $user = $this->createUser();
        $this->actingAs($user);

        for ($i = 0; $i < 13; $i++) {
            Scan::factory()->forOrg($user->org)->create([
                'ocr' => 'PG13SCAN',
                'scanned_at' => now()->subMinutes($i),
            ]);
        }

        $investigation = $this->makeInvestigation();
        $result = (string) (new ScanSearchTool($user, $investigation))->handle(new Request([
            'plate' => 'PG13SCAN',
        ]));

        $this->assertReturnedPageCountWithoutCap($result, 13);
        $this->assertCount(13, collect($investigation->data->searchResults)->pluck('resultId')->unique());
    }

    public function test_hotlist_search_with_thirteen_rows_names_thirteen_not_the_cap(): void
    {
        Event::fake([OttoWidgetCreated::class]);

        $user = $this->createUser();
        $this->actingAs($user);

        ClickhouseHotlist::factory()->count(13)->forOrg($user->org)->create();

        $investigation = $this->makeInvestigation();
        $result = (string) (new HotlistSearchTool($user, $investigation))->handle(new Request([]));

        $this->assertReturnedPageCountWithoutCap($result, 13);
        $this->assertCount(13, collect($investigation->data->searchResults)->pluck('resultId')->unique());
    }

    public function test_hit_search_stores_at_most_search_limit_ids_without_a_warehouse_count(): void
    {
        Event::fake([OttoWidgetCreated::class]);

        $user = $this->createUser();
        $this->actingAs($user);

        $wanted = LimitedToolResultMessage::SEARCH_LIMIT + 2;

        for ($i = 0; $i < $wanted; $i++) {
            Hit::factory()->forOrg($user->org, [
                'ocr' => 'CAP'.str_pad((string) $i, 4, '0', STR_PAD_LEFT),
                'scanned_at' => now()->subSeconds($i),
            ])->create([
                'created_at' => now()->subSeconds($i),
            ]);
        }

        cache()->forget('org-kits:'.$user->org_id.'-all');

        $investigation = $this->makeInvestigation();
        $sql = $this->captureSearchSql(function () use ($user, $investigation): void {
            $result = (string) (new HitSearchTool($user, $investigation))->handle(new Request([]));

            $this->assertStringContainsString('most recent '.LimitedToolResultMessage::SEARCH_LIMIT, $result);
            $this->assertDoesNotMatchRegularExpression('/\b(?:I )?found \d/i', $result);
        });

        $this->assertNoWarehouseCountOnTables($sql, ['hits', 'hits_hot', 'hits_cold']);
        $this->assertSearchToolDoesNotRunQueryCount(HitSearchTool::class);
        $this->assertCount(
            LimitedToolResultMessage::SEARCH_LIMIT,
            collect($investigation->data->searchResults)->pluck('resultId')->unique(),
        );
    }

    public function test_scan_search_does_not_run_a_warehouse_count(): void
    {
        Event::fake([OttoWidgetCreated::class]);

        $user = $this->createUser();
        $this->actingAs($user);

        $sql = $this->captureSearchSql(function () use ($user): void {
            (new ScanSearchTool($user, $this->makeInvestigation()))
                ->handle(new Request(['plate' => 'ABC1234']));
        });

        $this->assertNoWarehouseCountOnTables($sql, ['scans', 'scans_hot', 'scans_cold'], requireCapturedSql: false);
        $this->assertSearchToolDoesNotRunQueryCount(ScanSearchTool::class);
    }

    public function test_hotlist_search_does_not_run_a_warehouse_count(): void
    {
        Event::fake([OttoWidgetCreated::class]);

        $user = $this->createUser();
        $this->actingAs($user);

        $sql = $this->captureSearchSql(function () use ($user): void {
            (new HotlistSearchTool($user, $this->makeInvestigation()))
                ->handle(new Request([]));
        });

        $this->assertNoWarehouseCountOnTables($sql, ['hotlist'], requireCapturedSql: false);
        $this->assertSearchToolDoesNotRunQueryCount(HotlistSearchTool::class);
    }

    public function test_investigation_data_hydrates_at_most_search_limit_hits_per_search(): void
    {
        $user = $this->createUser(permissions: PermissionEnum::ViewAny_Hit->value);
        $this->setOrgProTier($user->org);
        setPermissionsTeamId($user->org_id);
        $user->givePermissionTo('use-otto');
        $this->actingAs($user);

        $hits = [];
        $wanted = LimitedToolResultMessage::SEARCH_LIMIT + 5;

        for ($i = 0; $i < $wanted; $i++) {
            $hits[] = Hit::factory()->forOrg($user->org, [
                'ocr' => 'HYD'.str_pad((string) $i, 4, '0', STR_PAD_LEFT),
                'scanned_at' => now()->subSeconds($i),
            ])->create([
                'created_at' => now()->subSeconds($i),
            ]);
        }

        cache()->forget('org-kits:'.$user->org_id.'-all');

        $conversationId = (string) Str::uuid();
        DB::table('agent_conversations')->insert([
            'id' => $conversationId,
            'user_id' => $user->id,
            'title' => 'Hit search',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        AgentInvestigation::query()->create([
            'user_id' => $user->id,
            'conversation_id' => $conversationId,
            'properties' => new AgentInvestigationProperties(
                searches: [
                    new Search(
                        searchId: 'hitsearch200',
                        messageId: (string) Str::uuid(),
                        parameters: ['plate' => null],
                        searchType: SearchType::Hit,
                        title: 'Hit search',
                        createdBy: $user->id,
                        conversationId: $conversationId,
                    ),
                ],
                searchResults: collect($hits)
                    ->map(fn (Hit $hit): SearchResult => new SearchResult(
                        searchId: 'hitsearch200',
                        resultId: $hit->id,
                    ))
                    ->all(),
            ),
        ]);

        $response = $this->getJson(route('otto.investigation', $conversationId));

        $response->assertOk();
        $this->assertCount(LimitedToolResultMessage::SEARCH_LIMIT, $response->json('hits'));
        $this->assertArrayNotHasKey('total', $response->json());
        $this->assertArrayNotHasKey('recordCount', $response->json());
        $this->assertArrayNotHasKey('count', $response->json());
    }

    /**
     * Copy a scan the factory wrote to clarity.scans_cold into clarity.scans_hot,
     * the way production CDC writes both. Reads the persisted row back so values
     * are already in ClickHouse's own representation, then keeps only the columns
     * scans_hot actually has: the two tables are NOT identical (scans_cold
     * carries `_peerdb_metadata_synced_at` and `_peerdb_kits_synced_at`, which
     * scans_hot does not), and database/schema/clickhouse-schema.sql is stale on
     * exactly this point, so the column list is read from the server. `geo` is
     * dropped so the Point default applies rather than round-tripping it.
     */
    private function mirrorScanToHotTable(Scan $scan): void
    {
        $row = DB::connection('clarity_clickhouse')
            ->table('clarity.scans_cold')
            ->where('id', $scan->id)
            ->first();

        if ($row === null) {
            return;
        }

        $row = array_intersect_key((array) $row, array_flip($this->clickhouseColumns('scans_hot')));
        unset($row['geo']);

        DB::connection('clarity_clickhouse')->table('clarity.scans_hot')->insert($row);
    }

    /**
     * @return list<string>
     */
    private function clickhouseColumns(string $table): array
    {
        $rows = DB::connection('clarity_clickhouse')->select(
            "SELECT name FROM system.columns WHERE database = 'clarity' AND table = '{$table}'"
        );

        return collect($rows)
            ->map(fn ($row): string => (string) (is_array($row) ? $row['name'] : $row->name))
            ->all();
    }

    private function makeInvestigation(): Investigation
    {
        $investigation = new Investigation;
        $investigation->data = new AgentInvestigationProperties;
        $investigation->setActiveConversation((string) Str::uuid());

        return $investigation;
    }

    /**
     * @return list<string>
     */
    private function captureSearchSql(callable $callback): array
    {
        $connections = ['zod', 'platform', 'clarity_clickhouse', 'clarity_clickhouse_write', 'public_clickhouse'];

        foreach ($connections as $name) {
            try {
                DB::connection($name)->flushQueryLog();
                DB::connection($name)->enableQueryLog();
            } catch (\Throwable) {
            }
        }

        $callback();

        $queries = [];

        foreach ($connections as $name) {
            try {
                foreach (DB::connection($name)->getQueryLog() as $entry) {
                    $queries[] = $entry['query'];
                }
            } catch (\Throwable) {
            }
        }

        return $queries;
    }

    /**
     * @param  list<string>  $sql
     * @param  list<string>  $tables
     */
    private function assertNoWarehouseCountOnTables(array $sql, array $tables, bool $requireCapturedSql = true): void
    {
        $tablePattern = implode('|', array_map(fn (string $table): string => preg_quote($table, '/'), $tables));
        $relevant = array_values(array_filter(
            $sql,
            fn (string $query): bool => (bool) preg_match('/\b(?:'.$tablePattern.')\b/i', $query),
        ));

        if ($requireCapturedSql) {
            $this->assertNotEmpty($relevant, 'Expected at least one query against '.implode(', ', $tables));
        }

        foreach ($relevant as $query) {
            $this->assertDoesNotMatchRegularExpression(
                '/\bcount\s*\(/i',
                $query,
                'Otto search must not run COUNT() on '.implode(', ', $tables).': '.$query,
            );
        }
    }

    /**
     * @param  class-string  $tool
     */
    private function assertSearchToolDoesNotRunQueryCount(string $tool): void
    {
        $src = file_get_contents((new \ReflectionClass($tool))->getFileName());

        $this->assertIsString($src);
        $this->assertStringContainsString('->limit(LimitedToolResultMessage::SEARCH_LIMIT)', $src);
        $this->assertDoesNotMatchRegularExpression('/\$query->count\s*\(/', $src);
        $this->assertStringNotContainsString('->paginate(', $src);
    }

    private function assertReturnedPageCountWithoutCap(string $result, int $count): void
    {
        $this->assertStringContainsString('most recent '.$count, $result);
        $this->assertStringNotContainsString((string) LimitedToolResultMessage::SEARCH_LIMIT, $result);
        $this->assertDoesNotMatchRegularExpression('/\b(?:I )?found \d/i', $result);
        $this->assertStringNotContainsString('there may be more', $result);
        $this->assertDoesNotMatchRegularExpression('/\bcap\b/i', $result);
    }

    private function assertSearchToolDescriptionOmitsCap(string $description): void
    {
        $this->assertStringContainsString('a page of the most recent matches', $description);
        $this->assertStringNotContainsString((string) LimitedToolResultMessage::SEARCH_LIMIT, $description);
        $this->assertStringNotContainsString('SEARCH_LIMIT', $description);
        $this->assertStringNotContainsString('up to', $description);
        $this->assertStringNotContainsString('there may be more', $description);
        $this->assertStringNotContainsString('more may exist', $description);
    }
}
