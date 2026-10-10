<?php

declare(strict_types=1);

namespace App\Mail\Hits;

use App\Enums\HitTypeEnum;
use App\Filament\Resources\ScansResource;
use App\Models\Hit;
use App\Models\Scan;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

class NewNotification extends Mailable implements ShouldQueue
{
    use Dispatchable;
    use Queueable;
    use SerializesModels;

    private const PIN_COLORS = [
        '0f29eb', // Blue (original)
        '2eab5a', // Green
        'e67e22', // Orange
        '9b59b6', // Purple
        'e74c3c', // Red
    ];

    /**
     * Create a new message instance.
     *
     * @return void
     */
    public function __construct(
        public Hit $hit,
        public int $alertSettingId,
        public string $alert_email,
        public string $timezone = 'UTC',
        public array $scans = [],
    ) {
        $this->onQueue('{high}');

        $this->hit->loadMissing('scan', 'hotlist');

        $this->subject(sprintf(
            'New %s Hit from Clarity: %s - %s %s',
            Str::headline(HitTypeEnum::tryFrom($this->hit->hit_type)?->title() ?? $this->hit->hit_type),
            $this->hit->hotlist?->integration?->name ?? 'Unknown Hotlist',
            $this->hit->hotlist?->plate ?? 'Unknown Plate',
            $this->hit->hotlist?->us_state ?? 'Unknown State'
        ));
    }

    /**
     * Build the message.
     *
     * @return $this
     */
    public function build(): self
    {
        $alertUnsubscribeUrl = URL::temporarySignedRoute(
            'email.unsubscribe.alert',
            now()->addDays(30),
            [
                'alert_setting_id' => $this->alertSettingId,
                'email' => $this->alert_email,
            ]
        );

        $allUnsubscribeUrl = URL::temporarySignedRoute(
            'email.unsubscribe.all',
            now()->addDays(30),
            [
                'email' => $this->alert_email,
            ]
        );

        $data = [
            'alert_unsubscribe_url' => $alertUnsubscribeUrl,
            'all_unsubscribe_url' => $allUnsubscribeUrl,
        ];

        $template = 'mail.hits.new-notification';
        if (\in_array($this->hit->hit_type, [HitTypeEnum::Historical->value, HitTypeEnum::Staging_Historical->value]) && \count($this->scans) > 1) {
            $template = 'mail.hits.new-multi-scan-notification';

            $scansCollection = collect($this->scans);

            $allClusters = Scan::clusterByLocation($scansCollection)
                ->values()
                ->map(fn ($group) => [
                    'label' => round($group->avg('latitude'), 4).', '.round($group->avg('longitude'), 4),
                    'scans' => $group,
                ]);

            $data['hasMoreClusters'] = $allClusters->count() > 5;
            $data['totalClusterCount'] = $allClusters->count();

            if ($data['hasMoreClusters']) {
                $plate = $this->hit->hotlist?->plate;
                $data['viewAllScansUrl'] = ScansResource::getUrl('index', parameters: [
                    'filters' => [
                        'filters' => ['ocr' => $plate],
                        'scanned_at' => ['date_toggles' => 'last_6m'],
                    ],
                ], panel: 'app');
            }

            $data['clusters'] = $allClusters->take(5);

            // First available day-time scan.
            $data['featuredScan'] = $scansCollection->first(function ($scan) {
                $hour = $scan->scanned_at?->setTimezone($this->timezone)->hour;

                return $hour !== null && $hour >= 6 && $hour < 18;
            }) ?? $scansCollection->first();

            // Build Mapbox static map URL with cluster pins.
            if ($token = config('services.mapbox.token')) {
                $pins = $data['clusters']->map(function ($cluster, $index) {
                    $lat = $cluster['scans']->avg('latitude');
                    $lng = $cluster['scans']->avg('longitude');
                    if (! $lat || ! $lng) {
                        return null;
                    }

                    return 'pin-l-'.($index + 1).'+'.self::PIN_COLORS[$index % count(self::PIN_COLORS)].'('.$lng.','.$lat.')';
                })->filter()->implode(',');

                if ($pins) {
                    $data['mapUrl'] = 'https://api.mapbox.com/styles/v1/mapbox/outdoors-v11/static/'.$pins.'/auto/600x300@2x?attribution=false&logo=false&access_token='.$token;
                }
            }

            $data['pinColors'] = self::PIN_COLORS;
        }

        $mail = $this->markdown($template)->with($data);

        if ($template === 'mail.hits.new-multi-scan-notification') {
            foreach (array_slice($this->scans, 0, 10) as $index => $scan) {
                if (! empty($scan->guid)) {
                    $response = Http::timeout(10)->get(scan_img($scan->guid, 'c'));
                    $mail->attachData($response->body(), 'vehicle-scan-'.$index.'.jpg', [
                        'mime' => 'image/jpeg',
                    ]);
                }
            }
        }

        return $mail;
    }
}
