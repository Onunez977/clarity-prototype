<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Mail\SendOneTimePassword;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class OneTimePasswordNotification extends Notification
{
    use Queueable;

    /**
     * Create a new notification instance.
     *
     * @return void
     */
    public function __construct(
        public int $oneTimePassword
    ) {
        //
    }

    /**
     * Get the notification's delivery channels.
     */
    public function via(mixed $notifiable): array
    {
        return ['mail'];
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(mixed $notifiable): SendOneTimePassword
    {
        return (new SendOneTimePassword($notifiable, $this->oneTimePassword))
            ->to($notifiable->routeNotificationFor('mail'));
    }
}
