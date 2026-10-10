<?php

declare(strict_types=1);

namespace Tests\Unit\Enums;

use App\Enums\InviteTypeEnum;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

class InviteTypeEnumTest extends TestCase
{
    #[Group('enums')]
    #[Group('invites')]
    public function test_has_correct_cases(): void
    {
        $expected = [
            'SHARE_SCANS' => 'Share Scans',
            'CLIENT_SCANS' => 'Client Scans',
        ];

        foreach ($expected as $caseName => $value) {
            $this->assertTrue(
                defined(InviteTypeEnum::class.'::'.$caseName),
                "Case {$caseName} should exist"
            );
            $this->assertEquals($value, constant(InviteTypeEnum::class.'::'.$caseName)->value);
        }
    }

    #[Group('enums')]
    #[Group('invites')]
    public function test_values_method_returns_all_values(): void
    {
        $values = InviteTypeEnum::values();

        $expected = ['Share Scans', 'Client Scans'];

        $this->assertCount(2, $values);
        foreach ($expected as $value) {
            $this->assertContains($value, $values);
        }
    }

    #[Group('enums')]
    #[Group('invites')]
    public function test_title_returns_correct_title_for_each_case(): void
    {
        $this->assertEquals('Share Scans', InviteTypeEnum::SHARE_SCANS->title());
        $this->assertEquals('Client Scans', InviteTypeEnum::CLIENT_SCANS->title());
    }

    #[Group('enums')]
    #[Group('invites')]
    public function test_description_returns_correct_description_for_each_case(): void
    {
        $this->assertEquals(
            'View and Share Scans from a different Company.',
            InviteTypeEnum::SHARE_SCANS->description()
        );
        $this->assertEquals(
            'Able to search through scans through the client portal.',
            InviteTypeEnum::CLIENT_SCANS->description()
        );
    }

    #[Group('enums')]
    #[Group('invites')]
    public function test_invite_types_represent_different_permission_levels(): void
    {
        $shareScans = InviteTypeEnum::SHARE_SCANS;
        $clientScans = InviteTypeEnum::CLIENT_SCANS;

        // Share Scans seems to be about cross-company sharing
        $this->assertStringContainsString('different Company', $shareScans->description());

        // Client Scans seems to be about client portal access
        $this->assertStringContainsString('client portal', $clientScans->description());
    }

    #[Group('enums')]
    #[Group('invites')]
    public function test_descriptions_provide_useful_information(): void
    {
        $shareScansDesc = InviteTypeEnum::SHARE_SCANS->description();
        $clientScansDesc = InviteTypeEnum::CLIENT_SCANS->description();

        // Descriptions should be informative
        $this->assertGreaterThan(20, strlen($shareScansDesc));
        $this->assertGreaterThan(20, strlen($clientScansDesc));

        // Descriptions should end with a period
        $this->assertStringEndsWith('.', $shareScansDesc);
        $this->assertStringEndsWith('.', $clientScansDesc);
    }
}
