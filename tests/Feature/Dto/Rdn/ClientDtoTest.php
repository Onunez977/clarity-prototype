<?php

namespace Tests\Feature\Dto\Rdn;

use App\Dto\Rdn\ClientDto;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

class ClientDtoTest extends TestCase
{
    #[Group('dto')]
    public function test_creates_from_object(): void
    {
        $client_id = 123456;
        $client_name = fake()->company();
        $phone = fake()->phoneNumber();
        $type = fake()->randomElement(['Local', 'Global']);

        $dto = ClientDto::fromObject((object) [
            'client_id' => $client_id,
            'client_name' => $client_name ?? null,
            'phone' => $phone,
            'type' => $type,
        ]);

        $this->assertObjectHasProperty('client_id', $dto);
        $this->assertEquals($client_id, $dto->client_id);

        $this->assertObjectHasProperty('client_name', $dto);
        $this->assertEquals($client_name, $dto->client_name);

        $this->assertObjectHasProperty('phone', $dto);
        $this->assertEquals($phone, $dto->phone);

        $this->assertObjectHasProperty('type', $dto);
        $this->assertEquals($type, $dto->type);
    }
}
