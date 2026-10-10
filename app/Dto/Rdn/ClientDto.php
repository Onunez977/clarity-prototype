<?php

namespace App\Dto\Rdn;

class ClientDto
{
    public function __construct(
        public int $client_id,
        public ?string $client_name,
        public ?string $phone,
        public string $type,
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
                client_id: $data->client_id,
                client_name: \htmlspecialchars_decode($data->client_name ?? 'N/A'),
                phone: $data->phone ?? null,
                type: $data->type,
            );
        } elseif (\gettype($data) === 'array') {
            return new self(
                client_id: $data['client_id'],
                client_name: \htmlspecialchars_decode($data['client_name'] ?? 'N/A'),
                phone: $data['phone'] ?? null,
                type: $data['type'],
            );
        }

        throw new \Exception('Unsupported data type');
    }
}
