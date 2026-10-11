<?php

declare(strict_types=1);

namespace App\Events;

use App\Enums\NotificationTypeEnum;
use App\Models\NotificationLog;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Support\Str;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Part\DataPart;

class NotificationLogEventHandler
{
    public function __construct() {}

    public function subscribe(Dispatcher $events): void
    {
        $events->listen(
            MessageSending::class,
            'App\Events\NotificationLogEventHandler@handleMessageSending',
        );
    }

    public function handleMessageSending(MessageSending $event): void
    {
        $message = $event->message;

        $notificationLog = NotificationLog::create([
            'type' => NotificationTypeEnum::Email,
            'from' => $this->formatAddressField($message, 'From'),
            'to' => $this->formatAddressField($message, 'To'),
            'cc' => $this->formatAddressField($message, 'Cc'),
            'bcc' => $this->formatAddressField($message, 'Bcc'),
            'subject' => $message->getSubject(),
            'body' => $message->getHtmlBody(),
            'headers' => $message->getHeaders()->toString(),
            'attachments' => $this->saveAttachments($message),
            'message_id' => (string) Str::uuid(),
            'recipient' => $this->formatAddressField($message, 'To'),
            'status' => 'sent',
        ]);

        $event->message->getHeaders()->addTextHeader('unique-id', $notificationLog->message_id);
    }

    public function formatAddressField(Email $message, string $field): ?string
    {
        $headers = $message->getHeaders();

        return $headers->get($field)?->getBodyAsString();
    }

    protected function saveAttachments(Email $message): ?string
    {
        if (empty($message->getAttachments())) {
            return null;
        }

        return collect($message->getAttachments())
            ->map(fn (DataPart $part) => $part->toString())
            ->implode("\n\n");
    }
}
