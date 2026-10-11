<?php

namespace Tests\Filament\Pages;

use App\Filament\Pages\Dashboard;
use App\Filament\Widgets\Dashboard\CompanyMetricsOverview;
use App\Filament\Widgets\Dashboard\Concerns\ExplainsCardQuery;
use Illuminate\Support\Carbon;
use InsightLPR\BlackAdam\Enum\RoleEnum;
use PHPUnit\Framework\Attributes\Group;
use ReflectionMethod;
use Tests\TestCase;

class DashboardCardQuerySectionTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function cardQueryComponents(Dashboard $page): array
    {
        $method = new ReflectionMethod($page, 'cardQueryComponents');
        $method->setAccessible(true);

        return $method->invoke($page);
    }

    private function expandedPage(string $card = 'unique-scan-counts', string $date = 'today'): Dashboard
    {
        $page = new Dashboard;
        $page->expandedCard = $card;
        $page->filters = ['date_toggles' => $date];

        return $page;
    }

    #[Group('pages')]
    #[Group('dashboard')]
    public function test_a_non_impersonator_never_has_the_sql_built_at_all(): void
    {
        Carbon::setTestNow('2026-08-12 12:00:00');
        $this->actingAs($this->createUser(role: RoleEnum::ADMIN->value));

        session()->forget('admin_impersonator');

        // Not "hidden at render time" -- the gate returns BEFORE the card is constructed,
        // so no statement object and no SQL string is ever created for this request.
        $this->assertSame([], $this->cardQueryComponents($this->expandedPage()));
    }

    #[Group('pages')]
    #[Group('dashboard')]
    public function test_an_impersonator_gets_the_statement_for_the_expanded_card(): void
    {
        Carbon::setTestNow('2026-08-12 12:00:00');
        $this->actingAs($this->createUser(role: RoleEnum::ADMIN->value));

        session()->put('admin_impersonator', ['id' => 1, 'name' => 'QA']);

        $components = $this->cardQueryComponents($this->expandedPage());

        $this->assertCount(1, $components);

        $data = $components[0]->getViewData();

        $this->assertTrue($data['supported']);
        $this->assertNull($data['error']);
        $this->assertNotEmpty($data['statements']);
        $this->assertNotSame('', trim($data['statements'][0]->sql));
    }

    #[Group('pages')]
    #[Group('dashboard')]
    public function test_nothing_is_built_for_an_unknown_or_unexpandable_card_key(): void
    {
        Carbon::setTestNow('2026-08-12 12:00:00');
        $this->actingAs($this->createUser(role: RoleEnum::ADMIN->value));

        session()->put('admin_impersonator', ['id' => 1, 'name' => 'QA']);

        $this->assertSame([], $this->cardQueryComponents($this->expandedPage('does-not-exist')));

        $page = $this->expandedPage();
        $page->expandedCard = null;
        $this->assertSame([], $this->cardQueryComponents($page));
    }

    #[Group('pages')]
    #[Group('dashboard')]
    public function test_a_throwing_statement_builder_degrades_instead_of_failing_the_page(): void
    {
        Carbon::setTestNow('2026-08-12 12:00:00');
        $this->actingAs($this->createUser(role: RoleEnum::ADMIN->value));

        session()->put('admin_impersonator', ['id' => 1, 'name' => 'QA']);

        $page = new class extends Dashboard
        {
            protected function cardWidgets(): array
            {
                return ['throwing-card' => ThrowingQueryCard::class];
            }
        };
        $page->expandedCard = 'throwing-card';
        $page->filters = ['date_toggles' => 'today'];

        $components = $this->cardQueryComponents($page);

        $this->assertCount(1, $components);

        $data = $components[0]->getViewData();

        $this->assertSame('deliberate', $data['error']);
        $this->assertSame([], $data['statements']);
    }

    #[Group('pages')]
    #[Group('dashboard')]
    public function test_card_query_uses_a_nested_page_date_toggle(): void
    {
        Carbon::setTestNow('2026-08-12 12:00:00');
        $user = $this->createUser(role: RoleEnum::ADMIN->value);
        $this->actingAs($user);

        session()->put('admin_impersonator', ['id' => 1, 'name' => 'QA']);

        $timezone = $user->timezone ?? 'UTC';
        $lastMonth = now(tz: $timezone)->startOfMonth()->subMonth();
        $thisMonth = now(tz: $timezone)->startOfMonth();

        $page = new Dashboard;
        $page->expandedCard = 'recovery-leader';
        $page->filters = ['date' => ['date_toggles' => 'last_month']];

        $components = $this->cardQueryComponents($page);
        $sql = $components[0]->getViewData()['statements'][0]->sql;

        $this->assertStringContainsString($lastMonth->toDateString(), $sql);
        $this->assertStringNotContainsString($thisMonth->toDateString(), $sql);
    }

    #[Group('pages')]
    #[Group('dashboard')]
    public function test_every_registered_card_can_explain_its_headline_query(): void
    {
        foreach (CompanyMetricsOverview::cards() as $key => $class) {
            $this->assertContains(
                ExplainsCardQuery::class,
                class_implements($class),
                "{$key} does not implement ExplainsCardQuery",
            );
        }
    }
}
