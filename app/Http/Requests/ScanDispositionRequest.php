<?php

namespace App\Http\Requests;

use App\Enums\DispositionEnum;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class ScanDispositionRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return auth()->check();
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array|string>
     */
    public function rules(): array
    {
        return [
            'scan_id' => [
                'required',
                'present',
                'integer',
            ],
            'reason' => [
                'nullable',
                'string',
                'in:other,not a plate,plate too blurry,wrong ocr,wrong plate state',
            ],
            'disposition' => [
                'required',
                'present',
                'string',
                'in:'.\implode(',', DispositionEnum::values()),
            ],
        ];
    }
}
