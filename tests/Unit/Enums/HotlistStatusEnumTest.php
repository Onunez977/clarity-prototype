<?php

declare(strict_types=1);

namespace Tests\Unit\Enums;

use App\Enums\HotlistStatusEnum;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

class HotlistStatusEnumTest extends TestCase
{
    #[Group('enums')]
    public function test_is_terminal_for_closed_states(): void
    {
        $this->assertTrue(HotlistStatusEnum::Repossessed->isTerminal());
        $this->assertTrue(HotlistStatusEnum::ClosedPositive->isTerminal());
        $this->assertTrue(HotlistStatusEnum::Closed->isTerminal());
        $this->assertTrue(HotlistStatusEnum::Invalid->isTerminal());
    }

    #[Group('enums')]
    public function test_is_not_terminal_for_active_or_neutral_states(): void
    {
        $this->assertFalse(HotlistStatusEnum::Other->isTerminal());
        $this->assertFalse(HotlistStatusEnum::Open->isTerminal());
        $this->assertFalse(HotlistStatusEnum::Hold->isTerminal());
        $this->assertFalse(HotlistStatusEnum::NewFromClient->isTerminal());
    }
}
