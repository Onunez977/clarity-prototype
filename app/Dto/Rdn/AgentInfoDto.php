<?php

namespace App\Dto\Rdn;

class AgentInfoDto
{
    public function __construct(
        public int $emplid,
        public ?string $first_name,
        public ?string $last_name,
        public ?string $title,
        public ?string $address,
        public ?string $city,
        public ?string $state,
        public ?string $zip,
        public ?string $homephone,
        public ?string $cellphone,
        public ?string $email,
        public ?string $efax,
        public ?string $text_messager,
        public ?string $can_send_update_to_client,
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
                emplid: $data->emplid,
                first_name: \htmlspecialchars_decode($data->FN ?? ''),
                last_name: \htmlspecialchars_decode($data->LN ?? ''),
                title: $data->title ?? null,
                address: $data->address ?? null,
                city: $data->city ?? null,
                state: $data->state ?? null,
                zip: $data->zip ?? null,
                homephone: $data->homephone ?? null,
                cellphone: $data->cellphone ?? null,
                email: $data->email ?? null,
                efax: $data->EFax ?? null,
                text_messager: $data->text_messager ?? null,
                can_send_update_to_client: $data->can_send_update_to_client ?? null,
            );
        } elseif (\gettype($data) === 'array') {
            return new self(
                emplid: $data['emplid'],
                first_name: \htmlspecialchars_decode($data['FN'] ?? ''),
                last_name: \htmlspecialchars_decode($data['LN'] ?? ''),
                title: $data['title'] ?? null,
                address: $data['address'] ?? null,
                city: $data['city'] ?? null,
                state: $data['state'] ?? null,
                zip: $data['zip'] ?? null,
                homephone: $data['homephone'] ?? null,
                cellphone: $data['cellphone'] ?? null,
                email: $data['email'] ?? null,
                efax: $data['EFax'] ?? null,
                text_messager: $data['text_messager'] ?? null,
                can_send_update_to_client: $data['can_send_update_to_client'] ?? null,
            );
        }

        throw new \Exception('Unsupported data type');
    }
}
