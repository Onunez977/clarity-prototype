<?php
declare(strict_types=1);
namespace InsightLPR\BlackAdam\Enum;

use ArchTech\Enums\From;
use ArchTech\Enums\InvokableCases;
use ArchTech\Enums\Meta\Meta;
use ArchTech\Enums\Metadata;
use ArchTech\Enums\Names;
use ArchTech\Enums\Options;
use ArchTech\Enums\Values;
use InsightLPR\BlackAdam\Enum\MetaDataProperties\{Description};

#[Meta(Description::class)]
enum PlateSource :int
{
    use InvokableCases;
    use Names;
    use Values;
    use Options;
    use From;
    use Metadata;

    #[Description('Insight')]
    case INSIGHT = 1;
    #[Description('Experian')]
    case EXPERIAN = 2;
    #[Description('Polk')]
    case POLK = 3;
    #[Description('Insight Cache')]
    case InsightCache = 4;

    public static function filamentOptions(): array
    {
        return collect(self::cases())->mapWithKeys(fn($item)=> [$item->value => $item->description()])->toArray();
    }

}
