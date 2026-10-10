<?php

declare(strict_types=1);

namespace App\Enums;

use ArchTech\Enums\Meta\Meta;
use ArchTech\Enums\Metadata;
use InsightLPR\BlackAdam\Enum\Concern\IsDataType;
use InsightLPR\BlackAdam\Enum\MetaDataProperties\Title;

#[Meta(Title::class)]
enum HotlistStatusEnum: int
{
    use IsDataType;
    use Metadata;

    #[Title('Other')]
    case Other = 0;

    #[Title('Open')]
    case Open = 1;

    #[Title('Hold')]
    case Hold = 2;

    #[Title('Repossessed')]
    case Repossessed = 3;

    #[Title('ClosedPositive')]
    case ClosedPositive = 4;

    #[Title('Closed')]
    case Closed = 5;

    #[Title('NewFromClient')]
    case NewFromClient = 90;

    #[Title('Invalid')]
    case Invalid = 99;

    /** Closed/terminal states — used to suppress alerts on orders that are already done. */
    public function isTerminal(): bool
    {
        return in_array($this, [
            self::Repossessed,
            self::ClosedPositive,
            self::Closed,
            self::Invalid,
        ], true);
    }
}
