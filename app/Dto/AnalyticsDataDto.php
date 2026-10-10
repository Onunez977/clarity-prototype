<?php

namespace App\Dto;

class AnalyticsDataDto
{
    public function __construct(
        public ?string $action = null,
        public bool $isPro = false,
        public array $meta = [],
    ) {}

    public static function fromArray(array $data): self
    {
        $known = ['action', 'is_pro'];
        $meta = array_diff_key($data, array_flip($known));

        return new self(
            action: $data['action'] ?? null,
            isPro: $data['is_pro'] ?? false,
            meta: $meta,
        );
    }

    public function toArray(): array
    {
        return array_merge([
            'action' => $this->action,
            'is_pro' => $this->isPro,
        ], $this->meta);
    }

    public function toJson(): string
    {
        return \json_encode($this->toArray());
    }
}
