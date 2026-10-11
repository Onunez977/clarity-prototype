<?php

declare(strict_types=1);

namespace App\Http\Requests\Otto;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class SendOttoMessageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->check();
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('message'))) {
            $this->merge(['message' => trim((string) $this->input('message'))]);
        }
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'message' => [
                'required',
                'string',
                'max:20000',
            ],
            'page_context' => [
                'sometimes',
                'nullable',
                'array',
            ],
            'pageContext' => [
                'sometimes',
                'nullable',
                'array',
            ],
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function pageContext(): ?array
    {
        $context = $this->input('page_context') ?? $this->input('pageContext');

        return is_array($context) ? $context : null;
    }
}
