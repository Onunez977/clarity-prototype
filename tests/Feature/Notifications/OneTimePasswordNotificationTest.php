<?php

namespace Tests\Feature\Notifications;

use App\Models\User;
use App\Notifications\OneTimePasswordNotification;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

class OneTimePasswordNotificationTest extends TestCase
{
    #[Group('notifications')]
    public function test_can_route_for_user(): void
    {
        $user = User::factory()->create();

        $notification = new OneTimePasswordNotification(oneTimePassword: 123456);
        $notification->toMail($user);

        $this->assertEquals([
            'name' => null,
            'address' => $user->email,
        ], $notification->toMail($user)->to[0]);
    }
}
