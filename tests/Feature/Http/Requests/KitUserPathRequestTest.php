<?php

namespace Tests\Feature\Http\Requests;

use App\Http\Requests\KitUserPathRequest;
use Illuminate\Support\Facades\Validator;
use InsightLPR\BlackAdam\Enum\PermissionEnum;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

class KitUserPathRequestTest extends TestCase
{
    #[Group('requests')]
    public function test_user_is_authorized(): void
    {
        $this->actingAs(
            $user = $this->createUser(permissions: PermissionEnum::Analytics_Geospatial_Kit_User_Path->value)
        );

        $request = (new KitUserPathRequest)
            ->setUserResolver(fn () => $user);

        $this->assertTrue($request->authorize());
    }

    #[Group('requests')]
    public function test_user_is_not_authorized(): void
    {
        $this->actingAs(
            $user = $this->createUser(permissions: [])
        );

        $request = (new KitUserPathRequest)
            ->setUserResolver(fn () => $user);

        $this->assertFalse($request->authorize());
    }

    #[Group('requests')]
    public function test_date_toggles_must_be_valid_value(): void
    {
        $this->actingAs($user = $this->createUser(permissions: PermissionEnum::Analytics_Geospatial_Kit_User_Path->value));

        $request = (new KitUserPathRequest)
            ->setUserResolver(fn () => $user);

        $validator = Validator::make(
            [
                'kits' => ['All Kits'],
                'address' => '123 Main St',
                'date_toggles' => 'invalid_value',
            ],
            $request->rules(),
        );

        $this->assertFalse($validator->passes());
        $this->assertEquals('The selected date toggles is invalid.', $validator->errors()->first('date_toggles'));
    }

    #[Group('requests')]
    public function test_kits_must_be_array(): void
    {
        $this->actingAs($user = $this->createUser(permissions: PermissionEnum::Analytics_Geospatial_Kit_User_Path->value));

        $request = (new KitUserPathRequest)
            ->setUserResolver(fn () => $user);

        $validator = Validator::make(
            [
                'kits' => 'All Kits',
                'address' => null,
                'date_toggles' => 'yesterday',
            ],
            $request->rules(),
        );

        $this->assertFalse($validator->passes());
        $this->assertEquals('The kits field must be an array.', $validator->errors()->first('kits'));
    }

    #[Group('requests')]
    public function test_kits_must_belong_to_user_org(): void
    {
        $this->actingAs($user = $this->createUser(permissions: PermissionEnum::Analytics_Geospatial_Kit_User_Path->value));

        $request = (new KitUserPathRequest)
            ->setUserResolver(fn () => $user);

        $validator = Validator::make(
            [
                'kits' => [999],
                'address' => null,
                'date_toggles' => 'yesterday',
            ],
            $request->rules(),
        );

        $this->assertFalse($validator->passes());
        $this->assertEquals('The selected kits is invalid.', $validator->errors()->first('kits'));
    }
}
