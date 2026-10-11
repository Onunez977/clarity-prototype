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
enum HitType:string
{
    use Metadata;
    use IsDataType;

    #[Title('Live')]
    case Live ="L";
    #[Title('Historical')]
    case Historical = "H";
    #[Title('Staging Live')]
    case Staging_Live = "S";
    #[Title('Staging Historical')]
    case Staging_Historical = "Z";
    #[Title('Over Watch')]
    case OverWatch = "O";

}
