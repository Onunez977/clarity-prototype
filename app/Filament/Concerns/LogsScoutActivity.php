<?php

declare(strict_types=1);

namespace App\Filament\Concerns;

use App\Actions\Activity\ActivityFactory;

/**
 * Shared actor/impersonator/request capture for explicit Scout audit logs.
 *
 * Mirrors the `_impersonator` / `_request` property shape that
 * {@see ActivityFactory} writes, so direct `activity()`
 * calls on Scout mutations land consistently alongside the filter-search logs.
 */
trait LogsScoutActivity
{
    /**
     * @param  array<string, mixed>  $properties
     * @return array<string, mixed>
     */
    protected static function scoutActivityProperties(array $properties = []): array
    {
        if ($impersonator = session()->get('impersonator')) {
            $properties['_impersonator'] = $impersonator;
        }

        $properties['_request'] = [
            'clientIp' => request()->getClientIp(),
            'userAgent' => request()->userAgent(),
        ];

        return $properties;
    }
}
