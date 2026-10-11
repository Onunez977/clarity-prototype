<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\HitTypeEnum;
use App\Models\Hotlist;
use App\Models\Kit;
use App\Models\MissedOpportunity;
use App\Models\Org;
use App\Models\Scan;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * @extends Factory<MissedOpportunity>
 */
class MissedOpportunityFactory extends Factory
{
    protected ?Org $org = null;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'kit_id' => Kit::factory(),
            'hotlist_id' => Hotlist::factory(),
            'scan_id' => Scan::factory(),
            'hit_type' => HitTypeEnum::Missed_Opportunity->value,
        ];
    }

    public function forOrg(Org $org): self
    {
        $this->org = $org;

        return $this;
    }

    public function create($attributes = [], ?Model $parent = null)
    {
        if ($this->org) {
            $kit = Kit::factory()
                ->forOrg($this->org)
                ->create();

            // LIVE HITS
            $hotlist = Hotlist::factory()->create([
                'org_id' => $this->org->id,
            ]);

            $scan = Scan::factory()
                ->for($kit)
                ->create([
                    'ocr' => $hotlist->plate,
                ]);

            $attributes = \array_merge($this->definition(), $attributes);
            $attributes['kit_id'] = $kit->id;
            $attributes['hotlist_id'] = $hotlist->id;
            $attributes['scan_id'] = $scan->id;

            $results = $this->make($attributes);

            if ($results instanceof Model) {
                $this->store(new Collection([$results]));

                $this->callAfterCreating(new Collection([$results]), $parent);
            } else {
                $this->store($results);

                $this->callAfterCreating($results, $parent);
            }

            return $results;
        }

        return parent::create($attributes, $parent);
    }
}
