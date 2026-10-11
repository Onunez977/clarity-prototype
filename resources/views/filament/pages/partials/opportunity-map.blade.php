@php
    $height = $height ?? 'h-[340px]';
@endphp

<div class="overflow-hidden rounded-xl border border-gray-200 bg-gray-50 dark:border-white/10 dark:bg-gray-950">
    @if (($map['pins'] ?? []) === [] && ($map['circles'] ?? []) === [])
        <div class="flex h-48 items-center justify-center px-6 text-center text-sm text-gray-500 dark:text-gray-400">
            No coordinates to plot.
        </div>
    @else
        <svg viewBox="0 0 {{ $map['width'] }} {{ $map['height'] }}" class="{{ $height }} w-full" role="img" aria-label="{{ $label ?? 'Opportunity map' }}">
            <rect width="100%" height="100%" fill="#f3f4f6"></rect>
            @for ($g = 0; $g < 8; $g++)
                <line x1="{{ $g * 100 }}" y1="0" x2="{{ $g * 100 }}" y2="{{ $map['height'] }}" stroke="#e5e7eb" stroke-width="1"></line>
            @endfor
            @foreach ($map['circles'] as $circle)
                <ellipse
                    cx="{{ $circle['cx'] }}"
                    cy="{{ $circle['cy'] }}"
                    rx="{{ $circle['rx'] }}"
                    ry="{{ $circle['ry'] }}"
                    fill="{{ $circle['color'] }}"
                    fill-opacity="{{ $circle['opacity'] }}"
                    stroke="{{ $circle['color'] }}"
                    stroke-width="2"
                    @if ($circle['dashed']) stroke-dasharray="6 4" @endif
                >
                    <title>{{ $circle['label'] }}</title>
                </ellipse>
            @endforeach
            @foreach ($map['polygons'] as $polygon)
                <polygon points="{{ $polygon['points'] }}" fill="{{ $polygon['color'] }}" fill-opacity="0.16" stroke="{{ $polygon['color'] }}" stroke-width="2">
                    <title>{{ $polygon['label'] }}</title>
                </polygon>
            @endforeach
            @foreach ($map['pins'] as $pin)
                <g transform="translate({{ $pin['x'] }}, {{ $pin['y'] }})">
                    <title>{{ $pin['label'] }}</title>
                    @if ($pin['shape'] === 'driver')
                        <circle cx="0" cy="0" r="7" fill="{{ $pin['color'] }}" stroke="#fff" stroke-width="2"></circle>
                    @elseif ($pin['shape'] === 'yard')
                        <rect x="-5" y="-5" width="10" height="10" rx="1" fill="{{ $pin['color'] }}" stroke="#fff" stroke-width="1.5"></rect>
                    @elseif ($pin['shape'] === 'address')
                        <circle cx="0" cy="0" r="5" fill="{{ $pin['color'] }}" stroke="#fff" stroke-width="1.5"></circle>
                    @else
                        <circle cx="0" cy="0" r="5" fill="{{ $pin['color'] }}" stroke="#fff" stroke-width="1.5"></circle>
                    @endif
                </g>
            @endforeach
        </svg>
    @endif
</div>
