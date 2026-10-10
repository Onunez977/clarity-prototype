{{--
    Marker MessageRenderer swaps in for `otto_table:scout_driver`.

    The interactive preview is DriverMapWidget.vue. This partial must not
    mount Alpine — ottoDriverMap was removed with the Alpine chat.
--}}
<div
    data-otto-widget="driver-map"
    data-widget="{{ $type ?? 'scout_driver' }}"
    data-search-id="{{ $searchId ?? '' }}"
    data-title="{{ $title ?? '' }}"
></div>
