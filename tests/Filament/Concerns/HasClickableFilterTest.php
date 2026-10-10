<?php

namespace Tests\Filament\Concerns;

use App\Filament\Concerns\HasClickableFilter;
use App\Models\Hit;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

class HasClickableFilterTest extends TestCase
{
    #[Group('concerns')]
    #[Group('filters')]
    public function test_can_add_to_table_query_filters(): void
    {
        $class = new class
        {
            use HasClickableFilter;

            public ?array $tableFilters = null;

            public function dispatch($event): void
            {
                //
            }
        };

        $this->assertTrue(\property_exists($class, 'tableFilters'));
        $this->assertFalse(\property_exists($class, 'tableDeferredFilters'));

        $type = fake()->words(3, true);
        $value = fake()->words(3, true);

        $class->addQueryFilter(type: $type, value: $value);

        $this->assertArrayHasKey('queryBuilder', $class->tableFilters);
        $this->assertArrayHasKey('rules', $class->tableFilters['queryBuilder']);

        // Random generated key.
        $key = \array_keys($class->tableFilters['queryBuilder']['rules'])[0];

        $this->assertEquals([
            'type' => $type,
            'data' => [
                'operator' => 'equals',
                'settings' => [
                    'text' => $value,
                ],
            ],
        ], $class->tableFilters['queryBuilder']['rules'][$key]);
    }

    #[Group('concerns')]
    #[Group('filters')]
    public function test_can_add_to_table_filters(): void
    {
        $class = new class
        {
            use HasClickableFilter;

            public ?array $tableFilters = null;

            public function dispatch($event): void
            {
                //
            }
        };

        $this->assertTrue(\property_exists($class, 'tableFilters'));
        $this->assertFalse(\property_exists($class, 'tableDeferredFilters'));

        $type = fake()->words(3, true);
        $value = fake()->words(3, true);

        $class->addFilter(type: $type, value: $value);

        $this->assertArrayHasKey('filters', $class->tableFilters);
        $this->assertArrayHasKey($type, $class->tableFilters['filters']);

        $this->assertEquals([
            $type => $value,
        ], $class->tableFilters['filters']);
    }

    #[Group('concerns')]
    #[Group('filters')]
    public function test_can_add_to_table_deferred_query_filters(): void
    {
        $class = new class
        {
            use HasClickableFilter;

            public ?array $tableDeferredFilters = null;

            public function dispatch($event): void
            {
                //
            }
        };

        $this->assertTrue(\property_exists($class, 'tableDeferredFilters'));
        $this->assertFalse(\property_exists($class, 'tableFilters'));

        $type = fake()->words(3, true);
        $value = fake()->words(3, true);

        $class->addQueryFilter(type: $type, value: $value);

        $this->assertArrayHasKey('queryBuilder', $class->tableDeferredFilters);
        $this->assertArrayHasKey('rules', $class->tableDeferredFilters['queryBuilder']);

        // Random generated key.
        $key = \array_keys($class->tableDeferredFilters['queryBuilder']['rules'])[0];

        $this->assertEquals([
            'type' => $type,
            'data' => [
                'operator' => 'equals',
                'settings' => [
                    'text' => $value,
                ],
            ],
        ], $class->tableDeferredFilters['queryBuilder']['rules'][$key]);
    }

    #[Group('concerns')]
    #[Group('filters')]
    public function test_can_add_to_table_deferred_filters(): void
    {
        $class = new class
        {
            use HasClickableFilter;

            public ?array $tableDeferredFilters = null;

            public function dispatch($event): void
            {
                //
            }
        };

        $this->assertTrue(\property_exists($class, 'tableDeferredFilters'));
        $this->assertFalse(\property_exists($class, 'tableFilters'));

        $type = fake()->words(3, true);
        $value = fake()->words(3, true);

        $class->addFilter(type: $type, value: $value);

        $this->assertArrayHasKey('filters', $class->tableDeferredFilters);
        $this->assertArrayHasKey($type, $class->tableDeferredFilters['filters']);

        $this->assertEquals([
            $type => $value,
        ], $class->tableDeferredFilters['filters']);
    }

    #[Group('concerns')]
    #[Group('filters')]
    public function test_can_add_geo_equals_to_table_deferred_filters(): void
    {
        $class = new class
        {
            use HasClickableFilter;

            public ?array $tableFilters = null;

            public function dispatch($event): void
            {
                //
            }
        };

        $type = fake()->words(3, true);

        $class->addQueryFilter(type: $type, value: 'lat,lon', operator: 'equalsGeo');

        $this->assertArrayHasKey('queryBuilder', $class->tableFilters);
        $this->assertArrayHasKey('rules', $class->tableFilters['queryBuilder']);

        // Random generated key.
        $key = \array_keys($class->tableFilters['queryBuilder']['rules'])[0];

        $this->assertEquals([
            'type' => $type,
            'data' => [
                'operator' => 'equalsGeo',
                'settings' => [
                    'lat' => 'lat',
                    'lon' => 'lon',
                ],
            ],
        ], $class->tableFilters['queryBuilder']['rules'][$key]);
    }

    #[Group('concerns')]
    #[Group('filters')]
    public function test_can_add_array_geo_type_filter(): void
    {
        $class = new class
        {
            use HasClickableFilter;

            public ?array $tableFilters = null;

            public ?array $tableDeferredFilters = null;

            public function getModel(): string
            {
                return fake()->words(3, true);
            }

            public function dispatch($event): void
            {
                //
            }
        };

        $this->assertTrue(\property_exists($class, 'tableFilters'));
        $this->assertTrue(\property_exists($class, 'tableDeferredFilters'));

        $lat = fake()->latitude();
        $lon = fake()->longitude();

        $class->addFilter(['lat', 'lon'], [$lat, $lon]);

        foreach (['tableFilters', 'tableDeferredFilters'] as $property) {
            $filters = $class->$property;

            $this->assertEquals([
                'geo' => [
                    'lat' => $lat,
                    'lon' => $lon,
                ],
            ], $filters);
        }
    }

    #[Group('concerns')]
    #[Group('filters')]
    public function test_can_add_array_geo_type_filter_for_hits(): void
    {
        $class = new class
        {
            use HasClickableFilter;

            public ?array $tableFilters = null;

            public ?array $tableDeferredFilters = null;

            public function getModel(): string
            {
                return Hit::class;
            }

            public function dispatch($event): void
            {
                //
            }
        };

        $this->assertTrue(\property_exists($class, 'tableFilters'));
        $this->assertTrue(\property_exists($class, 'tableDeferredFilters'));

        $lat = fake()->latitude();
        $lon = fake()->longitude();

        $class->addFilter(['lat', 'lon'], [$lat, $lon]);

        foreach (['tableFilters', 'tableDeferredFilters'] as $property) {
            $filters = $class->$property;

            $this->assertEquals([
                // One nested for Scans
                'scan' => [
                    'geo' => [
                        'lat' => $lat,
                        'lon' => $lon,
                    ],
                ],
            ], $filters);
        }
    }

    #[Group('concerns')]
    #[Group('filters')]
    public function test_can_nested_filters(): void
    {
        $class = new class
        {
            use HasClickableFilter;

            public ?array $tableFilters = null;

            public function dispatch($event): void
            {
                //
            }
        };

        $type = 'scan.ocr';
        $value = fake()->words(3, true);

        $class->addFilter(type: $type, value: $value);

        $this->assertArrayHasKey('filters', $class->tableFilters);
        $this->assertArrayHasKey('scan', $class->tableFilters['filters']);
        $this->assertArrayHasKey('ocr', $class->tableFilters['filters']['scan']);

        $this->assertEquals([
            'scan' => [
                'ocr' => $value,
            ],
        ], $class->tableFilters['filters']);
    }
}
