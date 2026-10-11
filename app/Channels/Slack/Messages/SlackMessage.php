<?php

namespace App\Channels\Slack\Messages;

use App\Filament\Resources\ScansResource;
use App\Models\Scan;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * @codeCoverageIgnore
 */
class SlackMessage
{
    public array $content;

    public function content($data): static
    {
        $type = Str::headline($data['type']);
        $maplink = 'https://www.google.com/maps/place/'.$data['scan']['lat'].','.$data['scan']['lng'];

        $this->content = [
            [
                'type' => 'header',
                'text' => [
                    'type' => 'plain_text',
                    'text' => 'New '.$type.' Hit: "'.\trim($data['hotlist']['plate'].' '.$data['hotlist']['state']).'"',
                ],
            ],
            [
                'type' => 'section',
                // No more than 10 fields are allowed
                'fields' => [
                    [
                        'type' => 'mrkdwn',
                        'text' => '*Plate*: "'.\trim($data['hotlist']['plate'].' '.$data['hotlist']['state']).'"',
                    ],
                    [
                        'type' => 'mrkdwn',
                        'text' => '*Hotlist*: "'.$data['hotlist']['name'].'"',
                    ],
                    [
                        'type' => 'mrkdwn',
                        'text' => '*Type*: "'.$type.'"',
                    ],
                    [
                        'type' => 'mrkdwn',
                        'text' => '*Time*: "'.$data['created_at'].'"',
                    ],
                    [
                        'type' => 'mrkdwn',
                        'text' => '*Client*: "'.$data['hotlist']['client_name'].'"',
                    ],
                    [
                        'type' => 'mrkdwn',
                        'text' => '*Vehicle*: "'.\trim($data['hotlist']['year'].' '.$data['hotlist']['make'].' '.$data['hotlist']['model']).'"',
                    ],
                ],
            ],
            [
                'type' => 'section',
                'text' => [
                    'type' => 'mrkdwn',
                    'text' => '<'.$maplink.'|View Location on Google Maps>',
                ],
            ],
            [
                'type' => 'divider',
            ],
        ];

        return $this;
    }

    public function multiScanContent(array $data, Collection $scans, string $timezone = 'UTC'): static
    {
        $type = Str::headline($data['type']);
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

        if ($clusterCount > $maxDisplay) {
            $remaining = $clusterCount - $maxDisplay;
            $clusterLines .= "\n+{$remaining} more areas";
        }

        $maplink = 'https://www.google.com/maps/place/'.$featuredScan->latitude.','.$featuredScan->longitude;

        $this->content = [
            [
                'type' => 'header',
                'text' => [
                    'type' => 'plain_text',
                    'text' => 'New '.$type.' Hit: "'.\trim($data['hotlist']['plate'].' '.$data['hotlist']['state']).'"',
                ],
            ],
            [
                'type' => 'section',
                'fields' => [
                    [
                        'type' => 'mrkdwn',
                        'text' => '*Plate*: "'.\trim($data['hotlist']['plate'].' '.$data['hotlist']['state']).'"',
                    ],
                    [
                        'type' => 'mrkdwn',
                        'text' => '*Hotlist*: "'.$data['hotlist']['name'].'"',
                    ],
                    [
                        'type' => 'mrkdwn',
                        'text' => '*Type*: "'.$type.'"',
                    ],
                    [
                        'type' => 'mrkdwn',
                        'text' => '*Client*: "'.$data['hotlist']['client_name'].'"',
                    ],
                    [
                        'type' => 'mrkdwn',
                        'text' => '*Vehicle*: "'.\trim($data['hotlist']['year'].' '.$data['hotlist']['make'].' '.$data['hotlist']['model']).'"',
                    ],
                ],
            ],
            [
                'type' => 'section',
                'text' => [
                    'type' => 'mrkdwn',
                    'text' => "*{$totalScans} scans across {$clusterCount} areas:*\n{$clusterLines}",
                ],
            ],
            [
                'type' => 'section',
                'text' => [
                    'type' => 'mrkdwn',
                    'text' => '<'.$maplink.'|View Featured Scan on Google Maps>',
                ],
            ],
        ];

        if ($clusterCount > $maxDisplay) {
            $plate = $data['hotlist']['plate'] ?? null;
            if ($plate) {
                $viewAllScansUrl = ScansResource::getUrl('index', parameters: [
                    'filters' => [
                        'filters' => ['ocr' => $plate],
                        'scanned_at' => ['date_toggles' => 'last_6m'],
                    ],
                ], panel: 'app');

                $this->content[] = [
                    'type' => 'section',
                    'text' => [
                        'type' => 'mrkdwn',
                        'text' => '<'.$viewAllScansUrl.'|View All Scans>',
                    ],
                ];
            }
        }

        $this->content[] = [
            'type' => 'divider',
        ];

        return $this;
    }
}
