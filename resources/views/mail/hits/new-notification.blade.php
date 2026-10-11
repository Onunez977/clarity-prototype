@component('mail::message')

# New {{ \App\Enums\HitTypeEnum::tryFrom($hit->hit_type)?->title() ?? $hit->hit_type }} Hit

@if (! empty($vehicle_analysis))
@component('mail::panel')
<strong>AI Vehicle Analysis</strong>
<table class="hit-details" width="100%" cellpadding="0" cellspacing="0">
<tr>
    <td class="hit-details-label">Vehicle Match</td>
    <td class="hit-details-value">{{ ucfirst($vehicle_analysis['vehicle_match']) }}</td>
</tr>
<tr>
    <td class="hit-details-label">Observed Vehicle</td>
    <td class="hit-details-value">{{ $vehicle_analysis['observed_vehicle'] }}</td>
</tr>
<tr>
    <td class="hit-details-label">Observed Color</td>
    <td class="hit-details-value">{{ ucfirst($vehicle_analysis['observed_color']) }}</td>
</tr>
<tr>
    <td class="hit-details-label">Vehicle Status</td>
    <td class="hit-details-value">{{ ucfirst($vehicle_analysis['vehicle_status']) }}</td>
</tr>
</table>
@endcomponent
@endif

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

<table class="hit-images" width="100%" cellpadding="0" cellspacing="0">
<tr>
    <td colspan="2"><img src="{{ scan_img($hit->scan?->guid, 'c') }}" alt="Vehicle Image" width="100%" style="display: block;"></td>
</tr>
<tr>
@if (config('services.mapbox.token') && $hit->scan && config('app.env') === 'production')
    <td width="60%" style="padding-top: 8px; padding-right: 4px; vertical-align: top;"><img src="https://api.mapbox.com/styles/v1/mapbox/outdoors-v11/static/pin-s-car+0f29eb({{ \implode(',', \array_reverse($hit->scan?->coordinates ?? [])) }})/{{ \implode(',', \array_reverse($hit->scan?->coordinates ?? [])) }},15/506x212?attribution=false&logo=false&access_token={{ config('services.mapbox.token') }}" alt="Map Location" width="100%" style="display: block;"></td>
    <td width="40%" style="padding-top: 8px; padding-left: 4px; vertical-align: top;"><img src="{{ scan_img($hit->scan?->guid, 'p') }}" alt="Plate Image" width="100%" style="display: block;"></td>
@else
    <td colspan="2" style="padding-top: 8px;"><img src="{{ scan_img($hit->scan?->guid, 'p') }}" alt="Plate Image" width="250" style="display: block;"></td>
@endif
</tr>
</table>

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
<tr>
    <td class="hit-details-label">Scanned Date</td>
    <td class="hit-details-value">{{ $hit->scan?->scanned_at?->setTimezone($timezone)?->format(config('app.date_format')) ?? 'N/A' }}</td>
</tr>
<tr>
    <td class="hit-details-label">Location</td>
    <td class="hit-details-value"><a href="https://www.google.com/maps/place/{{ \implode(',', $hit->scan?->coordinates ?? []) }}">{{ $hit->scan?->address ?? \implode(', ', $hit->scan?->coordinates ?? []) }}</a></td>
</tr>
<tr>
    <td class="hit-details-label">Client</td>
    <td class="hit-details-value">{{ $hit->hotlist?->client?->name ?? 'N/A' }}</td>
</tr>
<tr>
    <td class="hit-details-label">Lienholder</td>
    <td class="hit-details-value">{{ $hit->hotlist?->lienholder?->name ?? 'N/A' }}</td>
</tr>
<tr>
    <td class="hit-details-label">Camera</td>
    <td class="hit-details-value">{{ $hit->scan?->camera_name }}</td>
</tr>
<tr>
    <td class="hit-details-label">Kit User</td>
    <td class="hit-details-value">{{ $hit->kit?->name ?? 'N/A' }}</td>
</tr>
</table>

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
