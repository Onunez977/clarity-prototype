<?php

namespace Tests;

use App\Models\User;

class ResourceTestCase extends TestCase
{
    public ?User $user = null;

    protected function setUp(): void
    {
        parent::setUp();

        $roles = null;
        if (\property_exists($this, 'userRoles')) {
            $roles = $this->userRoles;
        } elseif (\method_exists($this, 'userRoles')) {
            $roles = $this->userRoles();
        }

        $permissions = null;
        if (\property_exists($this, 'userPermissions')) {
            $permissions = $this->userPermissions;
        } elseif (\method_exists($this, 'userPermissions')) {
            $roles = $this->userPermissions();
        }

        $pro = null;
        if (\property_exists($this, 'pro')) {
            $pro = $this->pro;
        }

        $this->actingAs(
            $this->user = $this->createUser(role: $roles, permissions: $permissions, pro: $pro)
        );
    }
}
