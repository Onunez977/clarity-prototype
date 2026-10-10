<?php

namespace App\Casts;

use App\Dto\AlertSettingSettingsDto;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;

class AlertSettingsCast implements CastsAttributes
{
    /**
     * Cast the stored value to the configured DataTransferObject.
     */
    public function get($model, string $key, $value, array $attributes): AlertSettingSettingsDto
    {
        $decoded = \json_decode($value ?? '{}', true);

        return AlertSettingSettingsDto::fromArray(\is_array($decoded) ? $decoded : []);
    }

    /**
     * Prepare the given value for storage.
     */
    public function set($model, string $key, $value, array $attributes)
    {
        if ($value instanceof AlertSettingSettingsDto) {
            return $value->toJson();
        }

        if (\is_array($value)) {
            return AlertSettingSettingsDto::fromArray($value)->toJson();
        }

        return \json_encode($value ?? [], true);
    }
}
