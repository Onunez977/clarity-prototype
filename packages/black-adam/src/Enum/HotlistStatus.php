<?php

declare(strict_types=1);

namespace InsightLPR\BlackAdam\Enum;

use ArchTech\Enums\Meta\Meta;
use ArchTech\Enums\Metadata;
use InsightLPR\BlackAdam\Enum\MetaDataProperties\Title;
use InsightLPR\BlackAdam\Enum\Concern\IsDataType;

/**
 * @codeCoverageIgnore
 */
#[Meta(Title::class)]
enum HotlistStatus : int
{
    use Metadata;
    use IsDataType;
    #[Title('Other')]
    case Other = 0;
    #[Title('Open')]
    case Open = 1;
    #[Title('Repossessed')]
    case Repossessed = 3;
}
