<?php

namespace Tests\Filament\Tables\Filters;

use App\Enums\FeatureEnum;
use App\Filament\Tables\Filters\PlateFilter;
use App\Models\Scan;
use Laravel\Pennant\Feature;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\HasSuperUser;
use Tests\TestCase;

class PlateFilterTest extends TestCase
{
    use HasSuperUser;

    #[Group('tables')]
    #[Group('filters')]
    public function test_can_search(): void
    {
        $this->assertFalse(Feature::for($this->user)->active(FeatureEnum::FLEX_SEARCH->slug()));

        $scan = Scan::factory()
            ->forOrg($this->user->org)
            ->create([
                'ocr' => '809123',
            ]);

        $otherScan = Scan::factory()
            ->forOrg($this->user->org)
            ->create([
                'ocr' => 'BO9123',
            ]);

        $results = PlateFilter::modifyQuery(
            Scan::query(),
            'ocr',
            $scan->ocr
        )
            ->pluck('id')
            ->toArray();

        $this->assertContains($scan->id, $results);
        $this->assertNotContains($otherScan->id, $results);
    }

    #[Group('tables')]
    #[Group('filters')]
    public function test_can_fuzzy_search_with_pro(): void
    {
        $this->makeUserPro($this->user);

        $this->assertTrue(Feature::for($this->user)->active(FeatureEnum::FLEX_SEARCH->slug()));

        $scan = Scan::factory()
            ->forOrg($this->user->org)
            ->create([
                'ocr' => '809123',
            ]);

        $otherScan = Scan::factory()
            ->forOrg($this->user->org)
            ->create([
                // B = "8" and O = "0"
                'ocr' => 'BO9123',
            ]);

        $results = PlateFilter::modifyQuery(
            query: Scan::query(),
            column: 'ocr',
            plate: $scan->ocr,
            flexSearch: true
        )
            ->pluck('id')
            ->toArray();

        $this->assertContains($scan->id, $results);
        $this->assertContains($otherScan->id, $results);
    }

    #[Group('tables')]
    #[Group('filters')]
    public function test_pro_user_with_flex_search_toggle_off_does_not_expand_variants(): void
    {
        $this->makeUserPro($this->user);

        $this->assertTrue(Feature::for($this->user)->active(FeatureEnum::FLEX_SEARCH->slug()));

        $scan = Scan::factory()
            ->forOrg($this->user->org)
            ->create([
                'ocr' => '809123',
            ]);

        $otherScan = Scan::factory()
            ->forOrg($this->user->org)
            ->create([
                // B = "8" and O = "0"
                'ocr' => 'BO9123',
            ]);

        $results = PlateFilter::modifyQuery(
            query: Scan::query(),
            column: 'ocr',
            plate: $scan->ocr,
            flexSearch: false,
        )
            ->pluck('id')
            ->toArray();

        $this->assertContains($scan->id, $results);
        $this->assertNotContains($otherScan->id, $results);
    }
}
