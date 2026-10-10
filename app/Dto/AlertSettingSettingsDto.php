<?php

namespace App\Dto;

class AlertSettingSettingsDto
{
    public function __construct(
        public array $types = [],
        public array $clients = [],
        public array $notifications = [
            'sms' => [],
            'emails' => [],
            'slack' => [],
        ],
        public string $webhook_url = '',
    ) {
        //
    }

    public static function fromArray(array $data): self
    {
        $notifications = \is_array($data['notifications'] ?? null) ? $data['notifications'] : [];

        return new self(
            types: self::toList($data['types'] ?? null),
            clients: self::toList($data['clients'] ?? null, numbersAsText: false),
            notifications: [
                'sms' => self::toList($notifications['sms'] ?? null),
                'emails' => self::toList($notifications['emails'] ?? null),
                'slack' => self::toList($notifications['slack'] ?? null),
            ],
            webhook_url: \is_string($data['webhook_url'] ?? null) ? $data['webhook_url'] : '',
        );
    }

    public function toJson(): string
    {
        return collect([
            'types' => self::toList($this->types),
            'clients' => self::toList($this->clients, numbersAsText: false),
            'notifications' => [
                'sms' => self::toList($this->notifications['sms'] ?? null),
                'emails' => self::toList($this->notifications['emails'] ?? null),
                'slack' => self::toList($this->notifications['slack'] ?? null),
            ],
            'webhook_url' => $this->webhook_url,
        ])->toJson();
    }

    private static function toList(mixed $value, bool $numbersAsText = true): array
    {
        return collect(\is_array($value) ? $value : [])
            ->filter(fn ($member) => (\is_string($member) && $member !== '') || \is_int($member) || \is_float($member))
            ->map(fn ($member) => $numbersAsText ? (string) $member : $member)
            ->values()
            ->all();
    }
}
