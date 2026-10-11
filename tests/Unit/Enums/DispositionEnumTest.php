<?php

declare(strict_types=1);

namespace Tests\Unit\Enums;

use App\Enums\DispositionEnum;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

class DispositionEnumTest extends TestCase
{
    #[Group('enums')]
    #[Group('disposition')]
    public function test_has_correct_cases(): void
    {
        $expected = [
            'NEW_HIT' => 'New Hit',
            'ACCESSIBLE' => 'Accessible',
            'MOVING' => 'Moving',
            'INACCESSIBLE' => 'Inaccessible',
            'INVALID_SCAN' => 'Invalid Scan',
            'VALID' => 'Valid',
            'INVALID' => 'Invalid',
            'REVIEWED' => 'Reviewed',
        ];

        foreach ($expected as $caseName => $value) {
            $this->assertTrue(
                defined(DispositionEnum::class.'::'.$caseName),
                "Case {$caseName} should exist"
            );
            $this->assertEquals($value, constant(DispositionEnum::class.'::'.$caseName)->value);
        }
    }

    #[Group('enums')]
    #[Group('disposition')]
    public function test_values_method_returns_all_values(): void
    {
        $values = DispositionEnum::values();

        $expected = [
            'New Hit', 'Accessible', 'Moving', 'Inaccessible',
            'Invalid Scan', 'Valid', 'Invalid', 'Reviewed',
        ];

        $this->assertCount(8, $values);
        foreach ($expected as $value) {
            $this->assertContains($value, $values);
        }
    }

    #[Group('enums')]
    #[Group('disposition')]
    public function test_title_returns_correct_title_for_each_case(): void
    {
        $this->assertEquals('New Hit', DispositionEnum::NEW_HIT->title());
        $this->assertEquals('Accessible', DispositionEnum::ACCESSIBLE->title());
        $this->assertEquals('Moving', DispositionEnum::MOVING->title());
        $this->assertEquals('Inaccessible', DispositionEnum::INACCESSIBLE->title());
        $this->assertEquals('Invalid Scan', DispositionEnum::INVALID_SCAN->title());
        $this->assertEquals('Valid', DispositionEnum::VALID->title());
        $this->assertEquals('Invalid', DispositionEnum::INVALID->title());
        $this->assertEquals('Reviewed', DispositionEnum::REVIEWED->title());
    }

    #[Group('enums')]
    #[Group('disposition')]
    public function test_form_options_returns_missed_opportunity_options(): void
    {
        $options = DispositionEnum::formOptions(missedOpportunity: true);

        $expectedOptions = ['Valid', 'Invalid', 'Reviewed'];

        $this->assertCount(3, $options);
        foreach ($expectedOptions as $option) {
            $this->assertArrayHasKey($option, $options);
        }

        // Should be sorted
        $keys = array_keys($options);
        $sortedKeys = $keys;
        sort($sortedKeys);
        $this->assertEquals($sortedKeys, $keys);
    }

    #[Group('enums')]
    #[Group('disposition')]
    public function test_form_options_returns_non_missed_opportunity_options(): void
    {
        $options = DispositionEnum::formOptions(missedOpportunity: false);

        $excludedOptions = ['Valid', 'Invalid', 'Reviewed'];
        $expectedOptions = ['New Hit', 'Accessible', 'Moving', 'Inaccessible', 'Invalid Scan'];

        $this->assertCount(5, $options);

        foreach ($expectedOptions as $option) {
            $this->assertArrayHasKey($option, $options);
        }

        foreach ($excludedOptions as $option) {
            $this->assertArrayNotHasKey($option, $options);
        }

        // Should be sorted
        $keys = array_keys($options);
        $sortedKeys = $keys;
        sort($sortedKeys);
        $this->assertEquals($sortedKeys, $keys);
    }

    #[Group('enums')]
    #[Group('disposition')]
    public function test_form_options_defaults_to_non_missed_opportunity(): void
    {
        $defaultOptions = DispositionEnum::formOptions();
        $explicitOptions = DispositionEnum::formOptions(missedOpportunity: false);

        $this->assertEquals($explicitOptions, $defaultOptions);
    }

    #[Group('enums')]
    #[Group('disposition')]
    public function test_form_options_includes_correct_titles(): void
    {
        $options = DispositionEnum::formOptions(missedOpportunity: true);

        $this->assertEquals('Invalid', $options['Invalid']);
        $this->assertEquals('Reviewed', $options['Reviewed']);
        $this->assertEquals('Valid', $options['Valid']);
    }

    #[Group('enums')]
    #[Group('disposition')]
    public function test_form_options_excludes_correct_enums_for_missed_opportunity(): void
    {
        $missedOpportunityOptions = DispositionEnum::formOptions(missedOpportunity: true);
        $regularOptions = DispositionEnum::formOptions(missedOpportunity: false);

        // These should only be in missed opportunity options
        $missedOpportunityOnly = ['Valid', 'Invalid', 'Reviewed'];

        foreach ($missedOpportunityOnly as $option) {
            $this->assertArrayHasKey($option, $missedOpportunityOptions);
            $this->assertArrayNotHasKey($option, $regularOptions);
        }

        // These should only be in regular options
        $regularOnly = ['New Hit', 'Accessible', 'Moving', 'Inaccessible', 'Invalid Scan'];

        foreach ($regularOnly as $option) {
            $this->assertArrayNotHasKey($option, $missedOpportunityOptions);
            $this->assertArrayHasKey($option, $regularOptions);
        }
    }

    #[Group('enums')]
    #[Group('disposition')]
    public function test_form_options_handles_edge_case(): void
    {
        // Test with all possible boolean values
        $trueOptions = DispositionEnum::formOptions(true);
        $falseOptions = DispositionEnum::formOptions(false);

        $this->assertNotEquals($trueOptions, $falseOptions);
        $this->assertTrue(count($trueOptions) + count($falseOptions) === count(DispositionEnum::values()));
    }
}
