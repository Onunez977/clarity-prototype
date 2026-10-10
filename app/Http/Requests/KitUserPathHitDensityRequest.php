<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use InsightLPR\BlackAdam\Enum\PermissionEnum;

class KitUserPathHitDensityRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return auth()->user()?->can(PermissionEnum::Analytics_Geospatial_Kit_User_Path->value) ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array|string>
     */
    public function rules(): array
    {
        return [
            'north' => 'required|numeric',
            'south' => 'required|numeric',
            'east' => 'required|numeric',
            'west' => 'required|numeric',
            'tiers' => 'sometimes|array',
            'tiers.*' => 'in:hit_tier_1,hit_tier_2_5,hit_tier_6_15,hit_tier_16_50,hit_tier_51_plus',
        ];
    }
}
