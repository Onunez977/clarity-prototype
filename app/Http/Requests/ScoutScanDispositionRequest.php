<?php

namespace App\Http\Requests;

use App\Enums\DispositionEnum;
use Illuminate\Contracts\Validation\ValidationRule;

class ScoutScanDispositionRequest extends ScanDispositionRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * Restricts `disposition` to the 5 driver-facing options (excludes the
     * admin/analytics-only Valid, Reviewed, and Invalid values accepted by
     * the shared parent request), so a Scout driver cannot skew their own
     * {New Hit, Valid} stats by posting an admin-only disposition.
     *
     * @return array<string, ValidationRule|array|string>
     */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'disposition' => [
                'required',
                'present',
                'string',
                'in:'.\implode(',', array_keys(DispositionEnum::formOptions(missedOpportunity: false))),
            ],
        ];
    }
}
