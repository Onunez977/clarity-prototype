<?php

declare(strict_types=1);

namespace App\Http\Requests\Otto;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class OttoZoneActionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->check();
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'zone_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'color' => ['sometimes', 'nullable', 'string', 'max:32'],
            'is_no_scan_zone' => ['sometimes', 'boolean'],
            'zip_codes' => ['sometimes', 'array'],
            'zip_codes.*' => ['string'],
            'assigned_drivers' => ['sometimes', 'array'],
            'assigned_drivers.*' => ['string'],
            'assigned_user_ids' => ['sometimes', 'array'],
            'assigned_user_ids.*' => ['integer'],
            'users' => ['sometimes', 'array'],
            'users.*' => ['integer'],
            'open_map_editor' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        return $this->validated();
    }
}
