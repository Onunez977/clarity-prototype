<?php

namespace Tests\Feature\Otto;

use App\Ai\Knowledge\KnowledgeBase;
use App\Ai\Surface;
use App\Ai\Tools\KnowledgeBaseSearchTool;
use App\Data\AgentInvestigation\AgentInvestigationProperties;
use App\Models\Investigation;
use App\Models\User;
use Illuminate\Support\Str;
use Laravel\Ai\Tools\Request;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class KnowledgeBaseSearchToolTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(
            KnowledgeBase::class,
            new KnowledgeBase(base_path('tests/Fixtures/otto-knowledge')),
        );

        Permission::findOrCreate('view-otto-kb-fixture', 'web');
    }

    private function tool(User $user): KnowledgeBaseSearchTool
    {
        $investigation = new Investigation;
        $investigation->data = new AgentInvestigationProperties;
        $investigation->setActiveConversation((string) Str::uuid());

        return new KnowledgeBaseSearchTool($user, $investigation);
    }

    public function test_it_returns_matching_article_content(): void
    {
        $user = $this->createUser();

        $result = (string) $this->tool($user)->handle(new Request(['query' => 'how do I search scans']));

        $this->assertStringContainsString('Searching scans', $result);
        $this->assertStringContainsString('plate', $result);
        $this->assertStringContainsString('Answer using only this material', $result);
    }

    public function test_it_does_not_leak_permission_gated_content(): void
    {
        $user = $this->createUser();

        $result = (string) $this->tool($user)->handle(new Request(['query' => 'companion vehicles gated']));

        $this->assertStringNotContainsString('GATED_FIXTURE_BODY', $result);
        $this->assertStringNotContainsString('repeatedly seen alongside', $result);
    }

    public function test_it_returns_gated_content_to_authorized_users(): void
    {
        $user = $this->createUser();
        setPermissionsTeamId($user->org_id);
        $user->givePermissionTo('view-otto-kb-fixture');

        $result = (string) $this->tool($user)->handle(new Request(['query' => 'companion vehicles gated']));

        $this->assertStringContainsString('Gated fixture feature', $result);
        $this->assertStringContainsString('GATED_FIXTURE_BODY', $result);
    }

    public function test_it_does_not_leak_feature_gated_content(): void
    {
        $user = $this->createUser();

        $result = (string) $this->tool($user)->handle(new Request(['query' => 'pro-only-widget pennant']));

        $this->assertStringNotContainsString('GATED_FEATURE_BODY', $result);
        $this->assertStringContainsString("couldn't find", strtolower($result));
    }

    public function test_it_refuses_when_the_user_asked_for_todays_scans(): void
    {
        $user = $this->createUser();
        $investigation = new Investigation;
        $investigation->data = new AgentInvestigationProperties;
        $investigation->setActivePrompt('Show me the most recent scans from today');

        $result = (string) (new KnowledgeBaseSearchTool($user, $investigation))
            ->handle(new Request(['query' => 'searching scans with Otto']));

        $this->assertStringContainsString('ScanSearch', $result);
        $this->assertStringContainsString('live records', $result);
        $this->assertStringNotContainsString('Searching scans with Otto', $result);
    }

    public function test_it_refuses_when_the_user_asked_which_zones_they_have(): void
    {
        $user = $this->createUser();
        $investigation = new Investigation;
        $investigation->data = new AgentInvestigationProperties;
        $investigation->setActivePrompt('what zones do I have');

        $result = (string) (new KnowledgeBaseSearchTool($user, $investigation))
            ->handle(new Request(['query' => 'asking otto for help zones']));

        $this->assertStringContainsString('Call ZoneSearch now', $result);
        $this->assertStringContainsString('zone-search tool', $result);
        $this->assertStringNotContainsString('cannot yet search zones', $result);
    }

    public function test_it_asks_for_a_topic_when_none_is_given(): void
    {
        $result = (string) $this->tool($this->createUser())->handle(new Request([]));

        $this->assertStringContainsString('topic', strtolower($result));
    }

    public function test_it_reports_when_nothing_matches(): void
    {
        $result = (string) $this->tool($this->createUser())->handle(new Request([
            'query' => 'payroll spreadsheet onboarding',
        ]));

        $this->assertStringContainsString("couldn't find", strtolower($result));
        $this->assertStringContainsString('do not invent', strtolower($result));
    }

    public function test_it_is_available_on_chat_and_unrestricted(): void
    {
        $user = $this->createUser();
        $tool = $this->tool($user);

        $this->assertTrue($tool->availableOn(Surface::Chat));
        $this->assertTrue($tool->authorizedFor($user));
    }
}
