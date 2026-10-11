<?php

namespace Tests\Filament\Concerns;

use App\Filament\Concerns\HasRetryQuery;
use App\Models\User;
use Filament\Resources\Pages\ListRecords;
use Filament\Tables\Table;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

class HasRetryQueryTest extends TestCase
{
    protected function tearDown(): void
    {
        TestingSometimesFailingClass::$shouldFail = true;

        parent::tearDown();
    }

    #[Group('query')]
    #[Group('concerns')]
    public function test_renders_empty_state_and_polls_for_one_retry(): void
    {
        Config::set('app.debug', false);

        $this->actingAs(
            $this->createUser()
        );

        $class = new class extends TestingBaseClass
        {
            use HasRetryQuery;
        };

        $this->assertNull($class->getTable()->getPollingInterval());
        $this->assertNotEquals('heroicon-o-information-circle', $class->getTable()->getEmptyStateIcon());
        $this->assertNotEquals('Search Criteria is too broad', $class->getTable()->getEmptyStateHeading());
        $this->assertNotEquals('Add additional search criteria to narrow down the search results', $class->getTable()->getEmptyStateDescription());

        // Will throw QueryException.
        $class->getTableRecords();

        $this->assertEquals('10s', $class->getTable()->getPollingInterval());
        $this->assertEquals('heroicon-o-information-circle', $class->getTable()->getEmptyStateIcon());
        $this->assertEquals('Search Criteria is too broad', $class->getTable()->getEmptyStateHeading());
        $this->assertEquals('Add additional search criteria to narrow down the search results', $class->getTable()->getEmptyStateDescription());
    }

    #[Group('query')]
    #[Group('concerns')]
    public function test_retries_once_then_stops(): void
    {
        Config::set('app.debug', false);

        $this->actingAs(
            $this->createUser()
        );

        $class = new class extends TestingBaseClass
        {
            use HasRetryQuery;
        };

        $this->assertEquals(0, $class->retryCount);
        $this->assertEquals(2, $class->retryUntil);

        // DEV-2591 kept retryUntil at 2: the trait is shared by four list pages, and the
        // amplification fix comes from the report guard, not from removing the retry.
        $class->getTableRecords();
        $this->assertEquals(1, $class->retryCount);
        $this->assertEquals('10s', $class->getTable()->getPollingInterval());

        $class->getTableRecords();
        $this->assertEquals(2, $class->retryCount);
        $this->assertNull($class->getTable()->getPollingInterval());
    }

    #[Group('query')]
    #[Group('concerns')]
    public function test_reports_once_per_search_not_once_per_retry(): void
    {
        Config::set('app.debug', false);

        $this->actingAs(
            $this->createUser()
        );

        $class = new class extends TestingBaseClass
        {
            use HasRetryQuery;
        };

        Log::shouldReceive('channel')->with('db-error')->once()->andReturnSelf();
        Log::shouldReceive('error')->once();

        // Same filter state re-entering the catch block must report exactly once.
        $class->getTableRecords();
        $class->getTableRecords();
        $class->getTableRecords();

        $this->assertNotSame('', $class->lastFailureKey);
    }

    #[Group('query')]
    #[Group('concerns')]
    public function test_a_new_search_reports_again(): void
    {
        Config::set('app.debug', false);

        $this->actingAs(
            $this->createUser()
        );

        $class = new class extends TestingBaseClass
        {
            use HasRetryQuery;
        };

        Log::shouldReceive('channel')->with('db-error')->twice()->andReturnSelf();
        Log::shouldReceive('error')->twice();

        $class->tableFilters = ['filters' => ['ocr' => 'NA']];
        $class->getTableRecords();
        $firstKey = $class->lastFailureKey;

        // Changing the filter set is a genuinely new search: it re-arms the guard
        // and resets the retry budget.
        $class->tableFilters = ['filters' => ['ocr' => 'N/A']];
        $class->getTableRecords();

        $this->assertNotEquals($firstKey, $class->lastFailureKey);
        $this->assertEquals(1, $class->retryCount);
    }

    #[Group('query')]
    #[Group('concerns')]
    public function test_alternating_error_codes_still_exhaust_the_retry_budget(): void
    {
        Config::set('app.debug', false);

        $this->actingAs(
            $this->createUser()
        );

        $class = new class extends AlternatingCodeClass
        {
            use HasRetryQuery;
        };

        // The dedup key gates the retry budget as well as the report. Folding the
        // SQLSTATE into it meant a search failing with alternating codes - pg 57014 and
        // ClickHouse 241 both fire on this cluster - reset the budget every attempt and
        // polled forever (review round 3, H-1).
        $class->getTableRecords();
        $class->getTableRecords();

        $this->assertEquals(2, $class->retryCount);
        $this->assertNull(
            $class->getTable()->getPollingInterval(),
            'polling must terminate even when the error code changes between attempts'
        );
    }

    #[Group('query')]
    #[Group('concerns')]
    public function test_a_successful_search_re_arms_the_report_guard(): void
    {
        Config::set('app.debug', false);

        $this->actingAs(
            $this->createUser()
        );

        $class = new class extends TestingSometimesFailingClass
        {
            use HasRetryQuery;
        };

        // The same search failing, succeeding, then failing again is TWO real failures.
        // Without a reset on success the second one matched the stale key and vanished
        // from both the log and the metric (DEV-2591 review round 1).
        Log::shouldReceive('channel')->with('db-error')->twice()->andReturnSelf();
        Log::shouldReceive('error')->twice();

        TestingSometimesFailingClass::$shouldFail = true;
        $class->getTableRecords();
        $this->assertNotSame('', $class->lastFailureKey);

        TestingSometimesFailingClass::$shouldFail = false;
        $class->getTableRecords();
        $this->assertSame('', $class->lastFailureKey, 'a success must clear the guard');
        $this->assertEquals(0, $class->retryCount);

        TestingSometimesFailingClass::$shouldFail = true;
        $class->getTableRecords();

        TestingSometimesFailingClass::$shouldFail = true;
    }

    #[Group('query')]
    #[Group('concerns')]
    public function test_throws_real_exception_on_failure(): void
    {
        Config::set('app.debug', false);

        $this->actingAs(
            $this->createUser()
        );

        $class = new class extends TestingOtherClass
        {
            use HasRetryQuery;
        };

        $this->assertNull($class->getTable()->getPollingInterval());
        $this->assertNotEquals('heroicon-o-information-circle', $class->getTable()->getEmptyStateIcon());
        $this->assertNotEquals('Search Criteria is too broad', $class->getTable()->getEmptyStateHeading());
        $this->assertNotEquals('Add additional search criteria to narrow down the search results', $class->getTable()->getEmptyStateDescription());

        $this->expectException(\Exception::class);

        // Will throw Exception.
        $class->getTableRecords();

        $this->assertNull($class->getTable()->getPollingInterval());
        $this->assertCount(0, $class->cachedTableRecords);
    }
}

class TestingBaseClass extends ListRecords
{
    public function __construct()
    {
        // Force create dummy table.
        $this->table = Table::make($this)
            ->query(User::query());
    }

    public function getTableRecords(): Collection|Paginator|CursorPaginator
    {
        throw new QueryException('test', 'sql', [], new \Exception('test'));
    }
}

class AlternatingCodeClass extends ListRecords
{
    public int $n = 0;

    public function __construct()
    {
        $this->table = Table::make($this)
            ->query(User::query());
    }

    public function getTableRecords(): Collection|Paginator|CursorPaginator
    {
        $this->n++;

        throw new QueryException('test', 'sql', [], new \Exception('boom', $this->n % 2 === 0 ? 57014 : 241));
    }
}

class TestingSometimesFailingClass extends ListRecords
{
    public static bool $shouldFail = true;

    public function __construct()
    {
        $this->table = Table::make($this)
            ->query(User::query());
    }

    public function getTableRecords(): Collection|Paginator|CursorPaginator
    {
        if (static::$shouldFail) {
            throw new QueryException('test', 'sql', [], new \Exception('test'));
        }

        return User::query()->get();
    }
}

class TestingOtherClass extends ListRecords
{
    public function __construct()
    {
        // Force create dummy table.
        $this->table = Table::make($this)
            ->query(User::query());
    }

    public function getTableRecords(): Collection|Paginator|CursorPaginator
    {
        throw new \Exception('test exception');
    }
}
