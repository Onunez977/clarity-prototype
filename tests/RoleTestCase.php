<?php

namespace Tests;

use App\Models\User;

class RoleTestCase extends TestCase
{
    public ?User $user = null;

    protected function setUp(): void
    {
        parent::setUp();

        $role = null;
        if (\property_exists($this, 'role')) {
            $role = $this->role;
        }

        $pro = null;
        if (\property_exists($this, 'pro')) {
            $pro = $this->pro;
        }

        $this->actingAs(
            $this->user = $this->createUser(role: $role, permissions: [], pro: $pro)
        );
    }
}
