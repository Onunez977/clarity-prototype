<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use InsightLPR\BlackAdam\Enum\PermissionEnum;

class KitUserPathGeojsonRequest extends FormRequest
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
            'staging' => 'sometimes|boolean',
            'tiers' => 'sometimes|array',
            'tiers.*' => 'in:tier_1,tier_2_4,tier_5_9,tier_10_24,tier_25_plus',
        ];
    }
}
