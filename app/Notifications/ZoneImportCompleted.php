<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Emailed to the acting user when a queued KML zone import ({@see
 * \App\Jobs\ImportZonesFromKml}) finishes or fails — the async-feedback
 * channel for uploads too large to import synchronously.
 */
class ZoneImportCompleted extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public ?int $created = null,
        public ?int $skipped = null,
        public bool $failed = false,
    ) {}

    public static function failed(): self
    {
        return new self(failed: true);
    }

    public function via(mixed $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(mixed $notifiable): MailMessage
    {
        $greeting = 'Hi '.($notifiable->name ?: $notifiable->email).',';

        if ($this->failed) {
            return (new MailMessage)
                ->subject('Your KML zone import failed')
                ->greeting($greeting)
                ->line('Your KML zone import could not be completed. Please try again or contact support if the issue persists.');
        }

        return (new MailMessage)
            ->subject('Your KML zone import finished')
            ->greeting($greeting)
            ->line("Your KML zone import finished — {$this->created} created, {$this->skipped} skipped.");
    }
}
