@component('mail::message')

# New {{ \App\Enums\HitTypeEnum::tryFrom($hit->hit_type)->title() ?? $hit->hit_type }} Hit

## Alert Summary

@component('mail::panel')
<table class="hit-header" width="100%" cellpadding="0" cellspacing="0">
<tr>
    <td class="hit-header-label">Client</td>
    <td class="hit-header-label">Created</td>
</tr>
<tr>
    <td class="hit-header-value">{{ \Illuminate\Support\Str::limit(($hit->hotlist?->client?->name ?? 'N/A'), 30) }}</td>
    <td class="hit-header-value">{{ $hit->created_at->setTimezone($timezone)->format(config('app.date_format')) }}</td>
</tr>
</table>
@endcomponent

<table class="hit-details" width="100%" cellpadding="0" cellspacing="0">
<tr>
    <td class="hit-details-label">License Plate</td>
    <td class="hit-details-value">{{ $hit->hotlist?->plate }} {{ $hit->hotlist?->us_state }}</td>
</tr>
<tr>
    <td class="hit-details-label">VIN</td>
    <td class="hit-details-value">{{ $hit->hotlist?->vin }}</td>
</tr>
<tr>
    <td class="hit-details-label">Vehicle</td>
    <td class="hit-details-value">{{ $hit->hotlist?->vehicle }}</td>
</tr>
</table>

<div class="hit-images">
<img src="{{ scan_img($featuredScan->guid, 'c') }}" alt="Vehicle Image">
<img src="{{ scan_img($featuredScan->guid, 'p') }}" alt="Plate Image">

@if (! empty($mapUrl) && config('app.env') === 'production')
<img class="w-full" src="{{ $mapUrl }}" alt="Scan Locations Overview"/>
@endif
</div>

@foreach ($clusters as $cluster)
@component('mail::panel')
**{{ $cluster['label'] }}** — Area <span style="color: #{{ $pinColors[$loop->index % count($pinColors)] }}; font-weight: bold;">{{ $loop->iteration }}</span> of {{ $loop->count }}

<table class="hit-details" width="100%" cellpadding="0" cellspacing="0">
<tr>
<td class="hit-details-label" style="width: 55%; border-right: none;">Location</td>
<td class="hit-details-label" style="width: 45%; border-right: none;">Scanned At</td>
</tr>
@foreach ($cluster['scans']->take(5) as $scan)
<tr>
<td class="hit-details-value" style="padding-left: 0; padding-right: 8px;"><a href="https://www.google.com/maps/place/{{ \implode(',', $scan->coordinates ?? []) }}">{{ $scan->address ?? \implode(', ', $scan->coordinates ?? []) }}</a></td>
<td class="hit-details-value" style="white-space: nowrap;">{{ $scan->scanned_at?->setTimezone($timezone)->format(config('app.date_format')) }}</td>
</tr>
@endforeach
</table>
@if ($cluster['scans']->count() > 5)

+{{ $cluster['scans']->count() - 5 }} more scans

@endif
@endcomponent
@endforeach

@if (! empty($hasMoreClusters))
<small style="display: block; text-align: center;">+{{ $totalClusterCount - 5 }} more scan clusters.</small>
@component('mail::button', ['url' => $viewAllScansUrl])
View All Scans
@endcomponent
@endif

@component('mail::button', ['url' => \App\Filament\Pages\AlertSettings::getUrl(panel: 'app')])
Manage Alerts
@endcomponent

<small style="display: block; text-align: center;">You are receiving this email because you are subscribed these alerts.</small>

<div class="subscription-manage-links">
@component('mail::subcopy')
[Unsubscribe From This Alert]({{ $alert_unsubscribe_url }}) | [Unsubscribe From All Alerts]({{ $all_unsubscribe_url }})
@endcomponent
</div>
@endcomponent
