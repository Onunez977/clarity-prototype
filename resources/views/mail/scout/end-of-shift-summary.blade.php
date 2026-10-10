@component('mail::message')

# Shift Summary — {{ $summary->endType->title() }}

@php
    $bannerColor = $summary->endType === \App\Enums\ShiftEndTypeEnum::Confirmed ? '#16a34a' : '#d97706';
    $bannerLabel = $summary->endType === \App\Enums\ShiftEndTypeEnum::Confirmed
        ? 'Driver tapped End Shift'
        : 'Automatically closed after 3 hours of inactivity';
@endphp

<div style="background:{{ $bannerColor }};color:#ffffff;padding:10px 16px;border-radius:6px;font-weight:600;margin:0 0 16px;">
    {{ $summary->endType->title() }} Shift End — {{ $bannerLabel }}
</div>

**Kit:** {{ $summary->kitName }}<br>
@if ($summary->driverName)
**Driver:** {{ $summary->driverName }}<br>
@endif
**Started:** {{ $summary->startedAt->format(config('app.date_format')) }}<br>
**Ended:** {{ $summary->endedAt->format(config('app.date_format')) }}<br>
**Duration:** {{ number_format($summary->durationMinutes()) }} min
@if ($summary->isTruncated())
<br>**Activity shown:** last {{ $summary->activityWindowHours() }} hours ({{ $summary->windowStartedAt->format(config('app.date_format')) }} – {{ $summary->windowEndedAt->format(config('app.date_format')) }})
@endif

@if (! $summary->hasActivity())

@component('mail::panel')
@if ($summary->isTruncated())
**No scans or GPS activity were recorded in the last {{ $summary->activityWindowHours() }} hours of this shift.**
@else
**No scans or GPS activity were recorded during this shift.**
@endif
@endcomponent

@else

@component('mail::panel')
@if ($summary->isTruncated())
<strong>Last {{ $summary->activityWindowHours() }} hours</strong>
@endif
<table width="100%" cellpadding="0" cellspacing="0">
<tr>
    <td><strong>{{ $summary->isTruncated() ? 'Miles' : 'Total Miles' }}</strong></td>
    <td><strong>Plates Scanned</strong></td>
    <td><strong>Hits</strong></td>
    <td><strong>Zones Covered</strong></td>
</tr>
<tr>
    <td>{{ number_format($summary->totalMiles, 1) }}</td>
    <td>{{ number_format($summary->totalScans) }}</td>
    <td>{{ number_format($summary->totalHits) }}</td>
    <td>{{ count($summary->zones) }}</td>
</tr>
</table>
@endcomponent

@if ($summary->mapUrl)
<img src="{{ $summary->mapUrl }}" alt="Shift map" style="width:100%;max-width:600px;border-radius:6px;display:block;margin:8px 0;"/>
@endif

@if (! empty($summary->zones))

## Zones Covered{{ $summary->isTruncated() ? ' (last '.$summary->activityWindowHours().' hours)' : '' }}

@component('mail::table')
| Zone | Plates | Hits | Time in Zone |
| :--- | :----: | :--: | :----------- |
@foreach ($summary->zones as $zone)
| {{ $zone->name }} | {{ number_format($zone->scans) }} | {{ number_format($zone->hits) }} | {{ $zone->timeInZoneMinutes }} min |
@endforeach
@endcomponent

@endif

@endif

@if ($driverEmailMissing)

---

_No driver email on file — this summary was sent to admins only._

@endif

Thanks,<br>
Clarity Scout

@endcomponent
