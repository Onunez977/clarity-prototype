<?php

declare(strict_types=1);

namespace App\Enums;

use ArchTech\Enums\Meta\Meta;
use ArchTech\Enums\Metadata;
use InsightLPR\BlackAdam\Enum\Concern\EnumHasValues;
use InsightLPR\BlackAdam\Enum\Concern\IsDataType;
use InsightLPR\BlackAdam\Enum\MetaDataProperties\Title;

#[Meta(Title::class)]
enum ScoutMapLayerEnum: string
{
    use EnumHasValues;
    use IsDataType;
    use Metadata;

    #[Title('Live Hit Density')]
    case Hits = 'hits';

    #[Title('Direct Orders')]
    case Hotlist = 'hotlist';

    #[Title('Staging Orders')]
    case Staging = 'staging';

    #[Title('Teammate Activity')]
    case Teammates = 'teammates';

    /**
     * @return array<string, string>
     */
    public static function formOptions(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $case) => [$case->value => $case->title()])
            ->toArray();
    }
}
