<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\URL;

class ScoutPasswordResetNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public int $linkTtlDays = 7;

    public function via(mixed $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(mixed $notifiable): MailMessage
    {
        // Relative signature so validation does not depend on request scheme/host
        // (TLS-terminating proxies). Prepend APP_URL for a clickable absolute href.
        $relativeUrl = URL::temporarySignedRoute(
            'scout.reset-password',
            now()->addDays($this->linkTtlDays),
            ['user' => $notifiable->getKey()],
            absolute: false,
        );

        $url = rtrim((string) config('app.url'), '/').$relativeUrl;

        return (new MailMessage)
            ->subject('Reset your Clarity Scout password')
            ->greeting('Hi '.($notifiable->name ?: $notifiable->email).',')
            ->line('We received a request to reset your Clarity Scout password.')
            ->line('Click the button below to choose a new password.')
            ->action('Reset Password', $url)
            ->line('This link will expire in '.$this->linkTtlDays.' days.')
            ->line('If you did not request a reset, you can ignore this email.')
            ->salutation("Thanks,  \nInsight LPR Team");
    }
}
