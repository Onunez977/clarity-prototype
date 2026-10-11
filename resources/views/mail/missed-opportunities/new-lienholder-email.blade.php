@component('mail::message')

# Missed Opportunities from Clarity

@foreach($records as $record)
@component('mail::panel')
<div class="hit-images">
    <img src="{{ scan_img($record['scans_guid'], 'c') }}" alt="Vehicle Image">
    <img src="{{ scan_img($record['scans_guid'], 'p') }}" alt="Plate Image">
    @if (config('services.mapbox.token') && config('app.env') === 'production' && $record['coordinates'])
        <img class="w-full" src="https://api.mapbox.com/styles/v1/mapbox/outdoors-v11/static/pin-s-car+0f29eb({{ \implode(',', \array_reverse($record['coordinates'])) }})/{{ \implode(',', \array_reverse($record['coordinates'])) }},15/506x212?attribution=false&logo=false&access_token={{ config('services.mapbox.token') }}" alt="Map Location"/>
    @endif
</div>

@component('mail::table')
| Detail             | Value         |
| :----------------- |:------------- |
| License Plate      | {{ $record['scans_ocr'] }} {{ $record['scans_us_state'] }} |
| VIN                | {{ $record['hotlist_vin'] }} |
| Year, Make, Model  | {{ $record['hotlist_vehicle_year'].' '.$record['hotlist_vehicle_make'].' '.$record['hotlist_vehicle_model'] }} |
| Date Scanned       | {{ \Carbon\Carbon::parse($record['scans_scanned_at'])->setTimezone('UTC')->format(config('app.date_format')) }} |
| Local Date Scanned | {{ \Carbon\Carbon::parse($record['scans_scanned_at'])->setTimezone($timezone)->format(config('app.date_format')) }} |
| Date Created       | {{ \Carbon\Carbon::parse($record['created_at'])->setTimezone('UTC')->format(config('app.date_format')) }} |
| Local Date Created | {{ \Carbon\Carbon::parse($record['created_at'])->setTimezone($timezone)->format(config('app.date_format')) }} |
@endcomponent
@endcomponent
@endforeach

You have been sent this email by {{ $sender }} with missed opportunity details for the vehicles above.

Thanks,<br>
Insight LPR Team
@endcomponent
