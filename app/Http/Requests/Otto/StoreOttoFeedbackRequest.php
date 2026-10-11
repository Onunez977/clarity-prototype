<?php

declare(strict_types=1);

namespace App\Http\Requests\Otto;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreOttoFeedbackRequest extends FormRequest
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
            'rating' => [
                'present',
                'nullable',
                'boolean',
            ],
            'comment' => [
                'sometimes',
                'nullable',
                'string',
                'max:2000',
            ],
            'surface' => [
                'sometimes',
                'string',
                'in:chat,investigate',
            ],
        ];
    }
}
