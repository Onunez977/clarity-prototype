<?php

namespace Tests\Feature\Livewire;

use App\Clickhouse\Models\Hit;
use App\Livewire\TableMapComponent;
use App\Models\Scan;
use InsightLPR\BlackAdam\Enum\VehicleImageTypeEnum;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

class TableMapComponentTest extends TestCase
{
    #[Group('livewire')]
    #[Group('map')]
    public function test_can_render(): void
    {
        Livewire::test(TableMapComponent::class)
            ->assertSuccessful()
            // Initially hidden
            ->assertSee('x-show-map="0"', false)
            ->assertSet('hasData', false)
            ->set('hasData', true)
            ->assertSee('x-show-map="1"', false);
    }

    #[Group('livewire')]
    #[Group('map')]
    public function test_can_receive_livewire_events(): void
    {
        $scan = Scan::factory()->create();

        $formatedRecords = [
            [
                'location' => [
                    'lat' => $scan->latitude,
                    'lng' => $scan->longitude,
                ],
                'label' => $scan->ocr.' / '.$scan->us_state,
                'image' => $scan->vehicleImage,
                'address' => $scan->address,
                'vehicle' => $scan->vehicle,
            ],
        ];

        Livewire::test(TableMapComponent::class)
            ->assertSuccessful()
            ->assertSet('hasData', false)
            ->assertSet('oldData', [])
            ->dispatch('load-records-for-map', recordIds: [$scan->id])
            ->assertSet('hasData', true)
            ->assertSet('oldData', [$scan->id])
            ->assertSee('x-show-map="1"', false)
            ->assertDispatched('filament-maps-widget/updateMapData', data: $formatedRecords, refresh: true);
    }

    #[Group('livewire')]
    #[Group('map')]
    public function test_doesnt_dispatch_events_on_empty_records(): void
    {
        Livewire::test(TableMapComponent::class)
            ->assertSuccessful()
            ->assertSee('x-show-map="0"', false)
            ->assertSet('hasData', false)
            ->assertSet('oldData', [])
            ->dispatch('load-records-for-map', recordIds: [])
            // Nothing changed
            ->assertSet('hasData', false)
            ->assertSet('oldData', [])
            ->assertSee('x-show-map="0"', false)
            ->assertNotDispatched('filament-maps-widget/updateMapData');
    }

    #[Group('livewire')]
    #[Group('map')]
    public function test_falls_back_to_the_record_when_the_configured_relationship_does_not_exist(): void
    {
        $scan = Scan::factory()->create();

        $formatedRecords = [
            [
                'location' => [
                    'lat' => $scan->latitude,
                    'lng' => $scan->longitude,
                ],
                'label' => $scan->ocr.' / '.$scan->us_state,
                'image' => $scan->vehicleImage,
                'address' => $scan->address,
                'vehicle' => $scan->vehicle,
            ],
        ];

        // `Scan` does not define a `scans` relationship (mirrors the CH `Hit` model,
        // which also has no `scans` relationship) — the component must fall back to
        // the record itself rather than throwing RelationNotFoundException.
        Livewire::test(TableMapComponent::class, ['model' => Scan::class, 'relationship' => 'scans'])
            ->assertSuccessful()
            ->assertSet('hasData', false)
            ->dispatch('load-records-for-map', recordIds: [$scan->id])
            ->assertSet('hasData', true)
            ->assertDispatched('filament-maps-widget/updateMapData', data: $formatedRecords, refresh: true);
    }

    #[Group('map')]
    public function test_clickhouse_hit_accessors_map_raw_columns_without_a_database_connection(): void
    {
        $hit = new Hit;
        $hit->setRawAttributes([
            'scan_us_state' => 'TX',
            'scan_guid' => '08c8d475-b6f8-4f63-8acd-772ba586b472',
            'vehicle_year' => '2020',
            'vehicle_make' => 'Honda',
            'vehicle_model' => 'Civic',
            'ocr' => 'ABC123',
            'lat' => 30.267153,
            'lng' => -97.743061,
            'address' => '123 Main St',
        ]);

        $this->assertSame('TX', $hit->us_state);
        $this->assertSame('2020 Honda Civic', $hit->vehicle);
        $this->assertSame(30.267153, $hit->latitude);
        $this->assertSame(-97.743061, $hit->longitude);
        $this->assertSame('123 Main St', $hit->address);
        $this->assertIsString($hit->vehicleImage);
        $this->assertStringEndsWith('-'.VehicleImageTypeEnum::Car->value.'.jpg', $hit->vehicleImage);
    }
}
