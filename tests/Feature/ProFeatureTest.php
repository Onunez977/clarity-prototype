<?php

namespace Tests\Feature;

use App\Models\Org;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

class ProFeatureTest extends TestCase
{
    #[Group('pro')]
    public function test_pro_is_enabled(): void
    {
        $org = Org::factory()->create();
        $org->meta()->create([
            'properties' => [
                'platform_repo_pro' => true,
            ],
        ]);
        $org->refresh();

        $this->assertTrue($org->isProEnabled());
    }

    #[Group('pro')]
    public function test_pro_isnt_enabled(): void
    {
        $org = Org::factory()->create();

        $this->assertFalse($org->isProEnabled());

        $org->meta()->create([
            'properties' => [
                'platform_repo_pro' => false,
            ],
        ]);
        $org->refresh();

        $this->assertFalse($org->isProEnabled());
    }

    #[Group('pro')]
    public function test_pro_end_date_is_future(): void
    {
        $org = Org::factory()->create();

        $this->assertFalse($org->isProEnabled());

        $org->meta()->create([
            'properties' => [
                'platform_repo_pro' => true,
                'platform_repo_pro_end' => now()->addDay(),
            ],
        ]);
        $org->refresh();

        cache()->clear();

        $this->assertTrue($org->isProEnabled());
    }

    #[Group('pro')]
    public function test_pro_end_date_is_past(): void
    {
        $org = Org::factory()->create();

        $org->meta()->create([
            'properties' => [
                'platform_repo_pro' => true,
                'platform_repo_pro_end' => now()->subDay(),
            ],
        ]);
        $org->refresh();

        $this->assertFalse($org->isProEnabled());
    }
}
