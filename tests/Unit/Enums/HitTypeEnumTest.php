<?php

declare(strict_types=1);

namespace Tests\Unit\Enums;

use App\Enums\HitTypeEnum;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

class HitTypeEnumTest extends TestCase
{
    #[Group('enums')]
    #[Group('hits')]
    public function test_has_correct_cases(): void
    {
        $expected = [
            'Live' => 'L',
            'Historical' => 'H',
            'Staging_Live' => 'S',
            'Staging_Historical' => 'Z',
            'Missed_Opportunity' => 'M',
            'Missed_Opportunity_Historical' => 'F',
            'Probable_Match' => 'P',
        ];

        foreach ($expected as $caseName => $value) {
            $this->assertTrue(
                defined(HitTypeEnum::class.'::'.$caseName),
                "Case {$caseName} should exist"
            );
            $this->assertEquals($value, constant(HitTypeEnum::class.'::'.$caseName)->value);
        }
    }

    #[Group('enums')]
    #[Group('hits')]
    public function test_values_method_returns_all_values(): void
    {
        $values = HitTypeEnum::values();

        $expected = ['L', 'H', 'S', 'Z', 'M', 'F', 'P'];

        $this->assertCount(7, $values);
        foreach ($expected as $value) {
            $this->assertContains($value, $values);
        }
    }

    #[Group('enums')]
    #[Group('hits')]
    public function test_title_returns_correct_title_for_each_case(): void
    {
        $this->assertEquals('Live', HitTypeEnum::Live->title());
        $this->assertEquals('Historical', HitTypeEnum::Historical->title());
        $this->assertEquals('Staging Live', HitTypeEnum::Staging_Live->title());
        $this->assertEquals('Staging Historical', HitTypeEnum::Staging_Historical->title());
        $this->assertEquals('Missed Opportunity', HitTypeEnum::Missed_Opportunity->title());
        $this->assertEquals('Missed Opportunity Spotted & Closed', HitTypeEnum::Missed_Opportunity_Historical->title());
        $this->assertEquals('Probable Match', HitTypeEnum::Probable_Match->title());
    }

    #[Group('enums')]
    #[Group('hits')]
    public function test_cases_represent_different_hit_types(): void
    {
        // Verify we have both live and historical variants
        $this->assertTrue(in_array('L', HitTypeEnum::values()), 'Should have live hits');
        $this->assertTrue(in_array('H', HitTypeEnum::values()), 'Should have historical hits');

        // Verify we have staging variants
        $this->assertTrue(in_array('S', HitTypeEnum::values()), 'Should have staging live hits');
        $this->assertTrue(in_array('Z', HitTypeEnum::values()), 'Should have staging historical hits');

        // Verify we have missed opportunity
        $this->assertTrue(in_array('M', HitTypeEnum::values()), 'Should have missed opportunity hits');

        // Verify we have historical missed opportunity
        $this->assertTrue(in_array('F', HitTypeEnum::values()), 'Should have historical missed opportunity hits');

        // Verify we have probable match
        $this->assertTrue(in_array('P', HitTypeEnum::values()), 'Should have probable match hits');
    }

    #[Group('enums')]
    #[Group('hits')]
    public function test_staging_types_have_correct_naming(): void
    {
        $stagingLive = HitTypeEnum::Staging_Live;
        $stagingHistorical = HitTypeEnum::Staging_Historical;

        $this->assertStringContainsString('Staging', $stagingLive->title());
        $this->assertStringContainsString('Staging', $stagingHistorical->title());
        $this->assertStringContainsString('Live', $stagingLive->title());
        $this->assertStringContainsString('Historical', $stagingHistorical->title());
    }
}
