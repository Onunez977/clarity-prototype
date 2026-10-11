<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use InsightLPR\BlackAdam\Enum\PermissionEnum;

class KitUserPathRequest extends FormRequest
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
            'address' => [
                'present',
                'nullable',
                'string',
            ],
            'date_toggles' => [
                'required',
                'present',
                'string',
                'in:yesterday,7_days,15_days,30_days',
            ],
            'kits' => [
                'required',
                'present',
                'array',
                // Kit must belong to User's Org.
                'in:'.$this->user()->getUserKits(withDeleted: false)->pluck('id')->push('All Kits')->implode(','),
            ],
        ];
    }
}
