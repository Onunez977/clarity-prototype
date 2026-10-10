<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\ScoutAnalyticsEvent;
use App\Enums\ScoutAnalyticsPage;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ScoutTrackRequest extends FormRequest
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
            'feature' => [
                'required',
                'string',
                Rule::in(ScoutAnalyticsEvent::values()),
            ],
            'page' => [
                'nullable',
                'string',
                Rule::in(ScoutAnalyticsPage::values()),
            ],
            'params' => [
                'sometimes',
                'array',
                'max:20',
            ],
            'params.*' => [
                'nullable',
                'max:500',
            ],
        ];
    }
}
