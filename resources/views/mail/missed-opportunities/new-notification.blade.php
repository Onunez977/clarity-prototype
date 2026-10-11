@component('mail::message')

# New Missed Opportunity

## Alert Summary

@component('mail::panel')
<table class="hit-header" width="100%" cellpadding="0" cellspacing="0">
<tr>
    <td class="hit-header-label">Client</td>
    <td class="hit-header-label">Created</td>
</tr>
<tr>
    <td class="hit-header-value">{{ \Illuminate\Support\Str::limit(($missedOpportunity->hotlist?->client?->name ?? 'N/A'), 30) }}</td>
    <td class="hit-header-value">{{ $missedOpportunity->created_at->setTimezone($timezone)->format(config('app.date_format')) }}</td>
</tr>
</table>
@endcomponent

<div class="hit-images">
<img src="{{ scan_img($missedOpportunity->scan?->guid, 'c') }}" alt="Vehicle Image">
<img src="{{ scan_img($missedOpportunity->scan?->guid, 'p') }}" alt="Plate Image">
@if (config('services.mapbox.token') && $missedOpportunity->scan && config('app.env') === 'production')
<img class="w-full" src="https://api.mapbox.com/styles/v1/mapbox/outdoors-v11/static/pin-s-car+0f29eb({{ \implode(',', \array_reverse($missedOpportunity->scan?->coordinates ?? [])) }})/{{ \implode(',', \array_reverse($missedOpportunity->scan?->coordinates ?? [])) }},15/506x212?attribution=false&logo=false&access_token={{ config('services.mapbox.token') }}" alt="Map Location"/>
@endif
</div>

<table class="hit-details" width="100%" cellpadding="0" cellspacing="0">
<tr>
    <td class="hit-details-label">License Plate</td>
    <td class="hit-details-value">{{ $missedOpportunity->hotlist?->plate }} {{ $missedOpportunity->hotlist?->us_state }}</td>
</tr>
<tr>
    <td class="hit-details-label">VIN</td>
    <td class="hit-details-value">{{ $missedOpportunity->hotlist?->vin }}</td>
</tr>
<tr>
    <td class="hit-details-label">Vehicle</td>
    <td class="hit-details-value">{{ $missedOpportunity->hotlist?->vehicle }}</td>
</tr>
<tr>
    <td class="hit-details-label">Date Created</td>
    <td class="hit-details-value">{{ $missedOpportunity->created_at->setTimezone($timezone)->format(config('app.date_format')) }}</td>
</tr>
<tr>
    <td class="hit-details-label">Location</td>
    <td class="hit-details-value"><a href="https://www.google.com/maps/place/{{ \implode(',', $missedOpportunity->scan?->coordinates ?? []) }}">{{ $missedOpportunity->scan?->address ?? \implode(', ', $missedOpportunity->scan?->coordinates ?? []) }}</a></td>
</tr>
<tr>
    <td class="hit-details-label">Client</td>
    <td class="hit-details-value">{{ $missedOpportunity->hotlist?->client?->name ?? 'N/A' }}</td>
</tr>
<tr>
    <td class="hit-details-label">Lienholder</td>
    <td class="hit-details-value">{{ $missedOpportunity->hotlist?->lienholder?->name ?? 'N/A' }}</td>
</tr>
<tr>
    <td class="hit-details-label">Camera</td>
    <td class="hit-details-value">{{ $missedOpportunity->scan?->camera_name }}</td>
</tr>
<tr>
    <td class="hit-details-label">Kit User</td>
    <td class="hit-details-value">{{ $missedOpportunity->kit?->name ?? 'N/A' }}</td>
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
