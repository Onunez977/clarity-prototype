<?php

namespace Database\Factories;

use App\Models\Hotlist;
use App\Models\HotlistMeta;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<HotlistMeta>
 */
class HotlistMetaFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'hotlist_id' => Hotlist::factory(),
            'order_date' => now()->subDays(fake()->numberBetween(1, 30))->toDateString(),
        ];
    }

    public function forHotlist(Hotlist $hotlist): static
    {
        return $this->state(fn (array $attributes) => [
            'hotlist_id' => $hotlist->id,
        ]);
    }

    public function orderDate(\DateTimeInterface|string $date): static
    {
        $dateString = $date instanceof \DateTimeInterface ? $date->format('Y-m-d') : $date;

        return $this->state(fn (array $attributes) => [
            'order_date' => $dateString,
        ]);
    }

    public function repoDate(\DateTimeInterface|string $date): static
    {
        $dateString = $date instanceof \DateTimeInterface ? $date->format('Y-m-d') : $date;

        return $this->state(fn (array $attributes) => [
            'repo_date' => $dateString,
        ]);
    }

    public function repossessedAfterDays(int $daysToRepo): static
    {
        $orderDate = now()->subDays($daysToRepo + 10);
        $repoDate = $orderDate->copy()->addDays($daysToRepo);

        return $this->state(fn (array $attributes) => [
            'order_date' => $orderDate->toDateString(),
            'repo_date' => $repoDate->toDateString(),
        ]);
    }

    public function closeDate(\DateTimeInterface|string $date): static
    {
        $dateString = $date instanceof \DateTimeInterface ? $date->format('Y-m-d') : $date;

        return $this->state(fn (array $attributes) => [
            'close_date' => $dateString,
        ]);
    }
}
