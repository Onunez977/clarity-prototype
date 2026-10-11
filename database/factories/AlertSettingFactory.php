<?php

namespace Database\Factories;

use App\Enums\HitTypeEnum;
use App\Models\AlertSetting;
use App\Models\Hit;
use App\Models\MissedOpportunity;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<AlertSetting>
 */
class AlertSettingFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'alertable_type' => 'user',
            'alertable_id' => User::factory(),
            'name' => fake()->words(5, true),
            'settings' => [
                'types' => [HitTypeEnum::Live->value],
                'clients' => [],
                'notifications' => [
                    'sms' => [],
                    'emails' => [],
                    'slack' => [],
                ],
            ],
        ];
    }

    public function forUser(User $user): self
    {
        return $this->state(fn () => [
            'alertable_type' => 'user',
            'alertable_id' => $user->id,
            'org_id' => $user->org_id,
        ]);
    }

    public function forTypes(Hit|MissedOpportunity|null $hit = null, ?array $types = null): self
    {
        return $this->state(function (array $attributes) use ($hit, $types) {
            $attributes['settings']['types'] = $types ?? [$hit->hit_type];

            return $attributes;
        });
    }

    public function forClients(array $clientIds = []): self
    {
        return $this->state(function (array $attributes) use ($clientIds) {
            $attributes['settings']['clients'] = $clientIds;

            return $attributes;
        });
    }

    public function smsNotification(array $phones): self
    {
        return $this->state(function (array $attributes) use ($phones) {
            $attributes['settings']['notifications']['sms'] = collect($phones)
                ->map(fn ($phone) => \trim(\preg_replace('/\D/', '', $phone)))
                ->toArray();

            return $attributes;
        });
    }

    public function emailNotification(array $email_addresses): self
    {
        return $this->state(function (array $attributes) use ($email_addresses) {
            $attributes['settings']['notifications']['emails'] = $email_addresses;

            return $attributes;
        });
    }

    public function slackNotification(array $slack_webhooks): self
    {
        return $this->state(function (array $attributes) use ($slack_webhooks) {
            $attributes['settings']['notifications']['slack'] = $slack_webhooks;

            return $attributes;
        });
    }

    public function webhookNotification(string $url, ?string $secret = null): self
    {
        return $this->state(function (array $attributes) use ($url, $secret) {
            $attributes['settings']['webhook_url'] = $url;
            $attributes['webhook_secret'] = $secret ?? Str::random(40);

            return $attributes;
        });
    }
}
