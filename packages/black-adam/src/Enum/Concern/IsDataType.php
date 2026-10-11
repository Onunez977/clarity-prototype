<?php

namespace InsightLPR\BlackAdam\Enum\Concern;

use InsightLPR\BlackAdam\Enum\MetaDataProperties\SortOrder;
use ArchTech\Enums\{InvokableCases, Names, Options, Values};
use InsightLPR\BlackAdam\Enum\MetaDataProperties\Title;
use ReflectionClass;

trait IsDataType
{
    use InvokableCases;
    use Names;
    use Values;
    use Options;

    /**
     * Form Options assume that the enum has a Title meta attribute
     */
    public static function formOptions():array {
        return collect(static::values())
            ->mapWithKeys(function ($value, $key) {
                $dt = static::tryFrom($value);
                return [$value => $dt->title()];
            })->sort(function ($a,$b){

                $ae = static::tryFromMeta(Title::make($a));
                $be = static::tryFromMeta(Title::make($b));

                if ($ae->hasMetaAttribute(SortOrder::class)){
                    $a = $ae->sortOrder()??$ae->title();
                    $b = $be->sortOrder()?? $be->title();
                }

                if ($a == $b) return 0;
                return ($a < $b) ? -1 : 1;

            })->toArray();
    }
    public static function tree():array {
        return collect(self::groupedFormOptions())
            ->map(function ($value, $key) {
                return [
                    'name'=>$key,
                    'id'=> md5($key),
                    'children' => collect($value)
                        ->map(function ($value, $key) {
                            return [
                                'id' => $key,
                                'name' => $value,
                            ];
                        })->values()
                        ->toArray(),
                ];
            })->values()
            ->toArray();
    }

    public function hasMetaAttribute(string $metaAttribute):bool {

        foreach ((new ReflectionClass(get_class($this)))->getAttributes() as $attribute) {
            if (in_array($metaAttribute, $attribute->getArguments())) {
                return true;
            }
        }
        return false;
    }

    public static function groupedFormOptions(): array
    {
        return collect(self::formOptions())
            ->groupBy(function ($value, $key) {
                return static::tryFrom($key)->group();
            }, true)->toArray();
    }

    public static function groups() {
        return collect(self::formOptions())
            ->map(function ($value, $key) {
                return static::tryFrom($key)->group();
            }, true)->unique()->values()->toArray();
    }

}
