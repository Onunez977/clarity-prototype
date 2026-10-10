<?php

use Carbon\Carbon;
use DataDog\DogStatsd;

function scan_img(?string $guid, string $type = 'c'): string
{
    // Prototype: never fetch plate/vehicle images from production S3.
    if (config('app.env') === 'local') {
        return '/prototype/sample-image.svg';
    }

    if (! $guid) {
        return '/img/no-image.svg';
    }

    return sprintf('https://images.insightlpr.io/%s-%s.jpg', $guid, $type);
}

function prettyDatetime($datetime, $timezone = null): string
{
    return convertToTimezone($datetime, $timezone ?? auth()->user()?->timezone ?? 'UTC')->format(config('app.date_format'));
}

function convertToTimezone($datetime, $timezone = null): Carbon
{
    if ($datetime instanceof Carbon) {
        return $datetime->setTimezone($timezone ?? auth()->user()?->timezone ?? 'UTC');
    }

    return Carbon::parse($datetime)->setTimezone($timezone ?? auth()->user()?->timezone ?? 'UTC');
}

function convertToUtc($datetime, $timezone = null): Carbon
{
    if ($datetime instanceof Carbon) {
        return $datetime->setTimezone('UTC');
    }

    return Carbon::parse($datetime, $timezone ?? auth()->user()?->timezone ?? 'UTC')->setTimezone('UTC');
}

function logDatadogCount(string $metric, float $amount = 1, array $tags = []): void
{
    if (! config('services.datadog_forwarding')) {
        return;
    }

    if ($amount <= 0) {
        return;
    }

    if (empty($tags)) {
        $tags = [
            'category' => $metric,
            'item' => $metric,
        ];
    }

    /**
     * @var $statsd DogStatsd
     */
    $statsd = app(DogStatsd::class);

    $statsd->increment($metric, tags: $tags, value: $amount);
}

function trackAttr(string $page, string $element): array
{
    return [
        'data-track' => $element,
        'data-track-page' => $page,
    ];
}
