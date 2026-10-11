<?php

declare(strict_types=1);

namespace App\Mail\MissedOpportunities;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class NewLienholderMissedOpportunity extends Mailable implements ShouldQueue
{
    use Dispatchable;
    use Queueable;
    use SerializesModels;

    /**
     * Create a new message instance.
     *
     * @return void
     */
    public function __construct(
        public array $records,
        public string $sender,
        public string $timezone = 'UTC',
    ) {

        $this->subject = 'Missed Opportunities from Clarity';

        $this->onQueue('{high}');

        $this->records = collect($records)
            ->map(function ($record) {
                $record['coordinates'] = \json_decode($record['scans_geo'] ?? '{}')?->coordinates ?? [];

                return $record;
            })
            ->toArray();
    }

    /**
     * Build the message.
     *
     * @return $this
     */
    public function build(): self
    {
        return $this->markdown('mail.missed-opportunities.new-lienholder-email', [
            'records' => $this->records,
        ]);
    }
}
