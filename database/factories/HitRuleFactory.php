<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\HitRuleMatchModeEnum;
use App\Enums\HitTypeEnum;
use App\Models\HitRule;
use App\Models\Org;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<HitRule>
 */
class HitRuleFactory extends Factory
{
    protected $model = HitRule::class;

    public function definition(): array
    {
        return [
            'org_id' => Org::factory(),
            'hit_type' => HitTypeEnum::Live->value,
            'match_mode' => HitRuleMatchModeEnum::Exact->value,
            'state_match' => false,
        ];
    }

    public function forOrg(Org|int $org): self
    {
        return $this->state(['org_id' => $org instanceof Org ? $org->id : $org]);
    }

    public function forHitType(HitTypeEnum $hitType): self
    {
        return $this->state(['hit_type' => $hitType->value]);
    }

    public function flex(): self
    {
        return $this->state(['match_mode' => HitRuleMatchModeEnum::Flex->value]);
    }

    public function withStateMatch(): self
    {
        return $this->state(['state_match' => true]);
    }
}
