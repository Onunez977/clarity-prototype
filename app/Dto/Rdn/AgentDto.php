<?php

namespace App\Dto\Rdn;

class AgentDto
{
    public function __construct(
        public int $agent_id,
        public ?string $first_name,
        public ?string $last_name,
        public ?string $vendor_id,
        public ?string $vendor_name,
        public ?string $vendor_phone,
        public ?string $vendor_state,
    ) {
        //
    }

    /**
     * @throws \Exception
     */
    public static function fromObject(mixed $data): self
    {
        if (\gettype($data) === 'object') {
            return new self(
                agent_id: $data->agent_id,
                first_name: \htmlspecialchars_decode($data->first_name ?? ''),
                last_name: \htmlspecialchars_decode($data->last_name ?? ''),
                vendor_id: $data->vendor->vendor_id ?? null,
                vendor_name: $data->vendor->name ?? null,
                vendor_phone: $data->vendor->phone ?? null,
                vendor_state: $data->vendor->state ?? null,
            );
        } elseif (\gettype($data) === 'array') {
            $vendor = $data['vendor'] ?? [];
            if (is_object($vendor)) {
                $vendor = (array) $vendor;
            }

            return new self(
                agent_id: $data['agent_id'],
                first_name: \htmlspecialchars_decode($data['first_name'] ?? ''),
                last_name: \htmlspecialchars_decode($data['last_name'] ?? ''),
                vendor_id: $vendor['vendor_id'] ?? null,
                vendor_name: $vendor['name'] ?? null,
                vendor_phone: $vendor['phone'] ?? null,
                vendor_state: $vendor['state'] ?? null,
            );
        }

        throw new \Exception('Unsupported data type');
    }
}
