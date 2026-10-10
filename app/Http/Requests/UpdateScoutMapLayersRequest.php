<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\ScoutMapLayerEnum;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use InsightLPR\BlackAdam\Enum\RoleEnum;

class UpdateScoutMapLayersRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        if ($user === null || ! $user->org_id) {
            return false;
        }

        setPermissionsTeamId($user->org_id);

        return $user->hasRole(RoleEnum::ADMIN->value);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'enabled' => ['present', 'array'],
            'enabled.*' => ['string', Rule::enum(ScoutMapLayerEnum::class)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'enabled.present' => 'Select which map layers drivers may use.',
            'enabled.array' => 'Map layers must be sent as a list of layer keys.',
            'enabled.*.enum' => 'Each layer must be a known Scout map layer.',
        ];
    }
}
