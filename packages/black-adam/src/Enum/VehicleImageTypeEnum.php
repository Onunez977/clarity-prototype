<?php

namespace InsightLPR\BlackAdam\Enum;

enum VehicleImageTypeEnum :string
{
    case Plate = 'p';
    case Car = 'c';

    public static function getValues(): array
    {
        return collect(self::cases())->mapWithKeys(fn($case) => [$case->name => $case->value])->toArray();
    }
}
