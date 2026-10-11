<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\HitTypeEnum;
use App\Models\HitRuleChange;
use App\Models\Org;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<HitRuleChange>
 */
class HitRuleChangeFactory extends Factory
{
    protected $model = HitRuleChange::class;

    public function definition(): array
    {
        return [
            'org_id' => Org::factory(),
            'hit_type' => HitTypeEnum::Live->value,
            'field' => 'match_mode',
            'old_value' => 'exact',
            'new_value' => 'flex',
            'changed_by' => User::factory(),
            'created_at' => now(),
        ];
    }

    public function forOrg(Org|int $org): self
    {
        return $this->state(['org_id' => $org instanceof Org ? $org->id : $org]);
    }
}
