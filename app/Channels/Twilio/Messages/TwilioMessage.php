<?php

namespace App\Channels\Twilio\Messages;

use App\Enums\HitTypeEnum;
use App\Filament\Resources\ScansResource;
use App\Models\Scan;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class TwilioMessage
{
    public $content;

    public $subject;

    public function content($data): static
    {
        $this->subject = $this->buildSubject($data);

        $maplink = 'https://www.google.com/maps/place/'.$data['scan']['lat'].','.$data['scan']['lng'];

        $this->content = sprintf(
            "New %s Hit from Clarity!\n\nPlate: %s %s\nHotlist: %s\nType: %s\nTime: %s\n\nClient: %s\n\nVehicle: %s\n\n%s\n\n%s",
            Str::headline(HitTypeEnum::tryFrom($data['type'])?->title() ?? $data['type']),
            $data['hotlist']['plate'],
            $data['hotlist']['state'],
            $data['hotlist']['name'],
            Str::headline($data['type']),
            $data['created_at'],
            $data['hotlist']['client_name'],
            $data['hotlist']['year'].' '.$data['hotlist']['make'].' '.$data['hotlist']['model'],
            $maplink,
            'Powered by Insight LPR + Clarity'
        );

        return $this;
    }

    public function multiScanContent(array $data, Collection $scans, string $timezone = 'UTC'): static
    {
        $this->subject = $this->buildSubject($data);

        $totalScans = $scans->count();

        $clusters = Scan::clusterByLocation($scans)
            ->values()
            ->map(fn ($group) => [
                'label' => round($group->avg('latitude'), 4).', '.round($group->avg('longitude'), 4),
                'scans' => $group,
            ]);

        $clusterCount = $clusters->count();
        $maxDisplay = 3;

        // Featured scan: prefer daytime (6am-6pm).
        $featuredScan = $scans->first(function ($scan) use ($timezone) {
            $hour = $scan->scanned_at?->setTimezone($timezone)->hour;

            return $hour !== null && $hour >= 6 && $hour < 18;
        }) ?? $scans->first();

        $clusterLines = $clusters->take($maxDisplay)->map(function ($cluster, $index) {
            $scan = $cluster['scans']->first();
            $label = $scan->address
                ?? round($cluster['scans']->avg('latitude'), 4).', '.round($cluster['scans']->avg('longitude'), 4);
            $count = $cluster['scans']->count();

            return ($index + 1).'. '.$label.' ('.$count.'x)';
        })->implode("\n");

        $summary = $totalScans.' scans across '.$clusterCount.' areas:';

        if ($clusterCount > $maxDisplay) {
            $remaining = $clusterCount - $maxDisplay;
            $clusterLines .= "\n+".$remaining.' more areas';
        }

        // Vehicle image URL for featured scan.
        $links = '';
        if (! empty($featuredScan->guid)) {
            $links .= "\n".scan_img($featuredScan->guid, 'c')."\n";
        }

        $links .= "\n".'https://www.google.com/maps/place/'.$featuredScan->latitude.','.$featuredScan->longitude;

        // "View All Scans" URL when >3 clusters.
        if ($clusterCount > $maxDisplay) {
            $plate = $data['hotlist']['plate'] ?? null;
            if ($plate) {
                $viewAllScansUrl = ScansResource::getUrl('index', parameters: [
                    'filters' => [
                        'filters' => ['ocr' => $plate],
                        'scanned_at' => ['date_toggles' => 'last_6m'],
                    ],
                ], panel: 'app');
                $links .= "\n\nView All Scans: ".$viewAllScansUrl;
            }
        }

        $this->content = sprintf(
            "New %s Hit from Clarity!\n\nPlate: %s %s\nHotlist: %s\nType: %s\nClient: %s\nVehicle: %s\n\n%s\n%s\n%s\n\n%s",
            Str::headline(HitTypeEnum::tryFrom($data['type'])?->title() ?? $data['type']),
            $data['hotlist']['plate'],
            $data['hotlist']['state'],
            $data['hotlist']['name'],
            Str::headline($data['type']),
            $data['hotlist']['client_name'],
            $data['hotlist']['year'].' '.$data['hotlist']['make'].' '.$data['hotlist']['model'],
            $summary,
            $clusterLines,
            $links,
            'Powered by Insight LPR + Clarity'
        );

        return $this;
    }

    private function buildSubject(array $data): string
    {
        return sprintf(
            'New %s Hit from Clarity: %s - %s %s',
            Str::headline(HitTypeEnum::tryFrom($data['type'])?->title() ?? $data['type']),
            $data['hotlist']['name'] ?? 'Unknown Hotlist',
            $data['hotlist']['plate'] ?? 'Unknown Plate',
            $data['hotlist']['state'] ?? 'Unknown State'
        );
    }
}
