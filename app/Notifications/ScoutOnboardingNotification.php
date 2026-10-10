<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\URL;

class ScoutOnboardingNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public int $linkTtlDays = 3;

    public function via(mixed $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(mixed $notifiable): MailMessage
    {
        // Relative signature so validation does not depend on request scheme/host
        // (TLS-terminating proxies). Prepend APP_URL for a clickable absolute href.
        $relativeUrl = URL::temporarySignedRoute(
            'scout.set-password',
            now()->addDays($this->linkTtlDays),
            ['user' => $notifiable->getKey()],
            absolute: false,
        );

        $url = rtrim((string) config('app.url'), '/').$relativeUrl;

        return (new MailMessage)
            ->subject('Welcome to Clarity Scout')
            ->greeting('Hi '.($notifiable->name ?: $notifiable->email).',')
            ->line('You have been invited to use Clarity Scout.')
            ->line('Click the button below to set your password and finish setting up your account.')
            ->action('Set Your Password', $url)
            ->line('This link will expire in '.$this->linkTtlDays.' days.')
            ->salutation("Thanks,  \nInsight LPR Team");
    }
}
