<?php

namespace App\Casts;

use App\Dto\ScanMetaDataDto;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;

class ScanMetaDataCast implements CastsAttributes
{
    /**
     * Cast the stored value to the configured DataTransferObject.
     */
    public function get($model, string $key, $value, array $attributes): ScanMetaDataDto
    {
        return ScanMetaDataDto::fromArray(\json_decode($value ?? '{}', true));
    }

    /**
     * Prepare the given value for storage.
     */
    public function set($model, string $key, $value, array $attributes)
    {
        if ($value instanceof ScanMetaDataDto) {
            return $value->toJson();
        }

        return \json_encode($value ?? [], true);
    }
}
