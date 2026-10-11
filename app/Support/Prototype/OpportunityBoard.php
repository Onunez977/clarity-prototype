<?php

declare(strict_types=1);

namespace App\Support\Prototype;

use App\Models\OpportunityCase;
use App\Models\OpportunityDriver;
use App\Models\OrgCoverageZone;
use App\Models\OrgLocation;
use App\Support\Scout\Haversine;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Loads seeded opportunity rows, scores them, and shapes the queue and morning plan.
 */
final class OpportunityBoard
{
    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public static function present(int $orgId, array $filters, string $sort, string $dir, ?int $drawerId = null): array
    {
        $now = CarbonImmutable::now();
        $scorer = new RecoveryLikelihood;
        $coverage = self::coverage($orgId);
        $drivers = OpportunityDriver::query()->where('org_id', $orgId)->orderBy('name')->get();
        $driverPayload = $drivers->map(fn (OpportunityDriver $driver): array => [
            'id' => $driver->id,
            'name' => $driver->name,
            'kit' => $driver->kit_name,
            'lat' => (float) $driver->latitude,
            'lng' => (float) $driver->longitude,
            'fix_minutes_ago' => (int) $driver->fix_minutes_ago,
        ])->all();

        $cases = OpportunityCase::query()
            ->where('org_id', $orgId)
            ->with(['addresses', 'hits', 'checkIns', 'dispatch.driver'])
            ->get();

        $rows = [];
        foreach ($cases as $case) {
            $rows[] = self::shape($case, $scorer, $coverage, $driverPayload, $now);
        }

        $counts = [
            'total' => count($rows),
            'hot' => count(array_filter($rows, fn (array $row): bool => $row['band'] === 'hot')),
            'warm' => count(array_filter($rows, fn (array $row): bool => $row['band'] === 'warm')),
            'cool' => count(array_filter($rows, fn (array $row): bool => $row['band'] === 'cool')),
            'unassigned' => count(array_filter($rows, fn (array $row): bool => $row['status'] === 'new')),
        ];

        $filtered = array_values(array_filter($rows, fn (array $row): bool => self::matches($row, $filters)));
        usort($filtered, fn (array $a, array $b): int => self::compare($a, $b, $sort, $dir));

        $drawer = null;
        if ($drawerId) {
            foreach ($rows as $row) {
                if ($row['id'] === $drawerId) {
                    $drawer = $row;
                    break;
                }
            }
        }

        return [
            'rows' => $filtered,
            'counts' => $counts,
            'clients' => self::options($rows, 'client'),
            'branches' => self::options($rows, 'branch'),
            'drivers' => $drivers->map(fn (OpportunityDriver $driver): array => [
                'id' => $driver->id,
                'name' => $driver->name,
                'kit' => $driver->kit_name,
                'fix_label' => self::fixLabel((int) $driver->fix_minutes_ago),
                'fresh' => $driver->freshGps(),
                'lat' => (float) $driver->latitude,
                'lng' => (float) $driver->longitude,
            ])->all(),
            'coverage_label' => $coverage['label'],
            'drawer' => $drawer,
            'plan' => self::plan($rows, $drivers, $coverage),
            'map' => self::overviewMap($rows, $drivers, $coverage),
        ];
    }

    /**
     * @param  array<string, mixed>  $coverage
     * @param  list<array<string, mixed>>  $drivers
     * @return array<string, mixed>
     */
    private static function shape(
        OpportunityCase $case,
        RecoveryLikelihood $scorer,
        array $coverage,
        array $drivers,
        CarbonImmutable $now,
    ): array {
        $addressRows = $case->addresses->map(fn ($address): array => [
            'id' => $address->id,
            'position' => $address->position,
            'line' => $address->line,
            'city' => $address->city,
            'lat' => $address->latitude,
            'lng' => $address->longitude,
        ])->all();

        $hitRows = $case->hits->map(fn ($hit): array => [
            'id' => $hit->id,
            'address_id' => $hit->address_id,
            'scanned_at' => $hit->scanned_at,
            'hit_type' => $hit->hit_type,
            'distance_miles' => $hit->distance_miles,
            'local_hour' => $hit->local_hour,
            'disposition' => $hit->disposition,
            'state_mismatch' => $hit->state_mismatch,
            'kit_name' => $hit->kit_name,
        ])->all();

        $checkRows = $case->checkIns->map(fn ($checkIn): array => [
            'address_id' => $checkIn->address_id,
            'matched_at' => $checkIn->matched_at,
            'kit_name' => $checkIn->kit_name,
        ])->all();

        $scored = $scorer->score([
            'order_date' => $case->order_date,
            'addresses' => $addressRows,
            'hits' => $hitRows,
            'check_ins' => $checkRows,
        ], $coverage, $drivers, $now);

        $addresses = $scored['addresses'];
        usort($addresses, function (array $a, array $b): int {
            $score = ($b['score'] ?? 0) <=> ($a['score'] ?? 0);
            if ($score !== 0) {
                return $score;
            }

            return ($a['position'] ?? 0) <=> ($b['position'] ?? 0);
        });

        $best = null;
        foreach ($addresses as $index => $address) {
            $addresses[$index]['best'] = $address['id'] === $scored['best_address_id'];
            if ($addresses[$index]['best']) {
                $best = $addresses[$index];
            }
        }

        $dispatch = $case->dispatch;
        $status = $dispatch?->status ?? 'new';
        $orderDays = $case->order_date
            ? (int) floor(max(0, (now()->startOfDay()->getTimestamp() - $case->order_date->copy()->startOfDay()->getTimestamp()) / 86400))
            : 0;

        $hits = [];
        foreach ($case->hits as $index => $hit) {
            $address = $case->addresses->firstWhere('id', $hit->address_id);
            $historical = strtoupper((string) $hit->hit_type) === 'H'
                && $hit->scanned_at
                && $case->order_date
                && $hit->scanned_at->lessThan($case->order_date->copy()->endOfDay());
            $point = self::hitPoint($address?->latitude, $address?->longitude, $hit->distance_miles, $index);
            $hits[] = [
                'id' => $hit->id,
                'address_id' => $hit->address_id,
                'position' => $address?->position,
                'line' => $address?->line,
                'type' => strtoupper((string) $hit->hit_type),
                'type_label' => self::hitTypeLabel((string) $hit->hit_type),
                'when' => $historical
                    ? 'Historical, scanned '.$hit->scanned_at->format('M j, Y')
                    : $scorer->ago($hit->scanned_at, $now),
                'scanned_at' => $hit->scanned_at?->toIso8601String(),
                'distance' => $hit->distance_miles === null ? null : RecoveryLikelihood::formatMiles((float) $hit->distance_miles),
                'local_hour' => $hit->local_hour,
                'local_label' => self::hourLabel((int) $hit->local_hour),
                'disposition' => $hit->disposition,
                'disposition_label' => self::dispositionLabel($hit->disposition),
                'state_mismatch' => (bool) $hit->state_mismatch,
                'kit' => $hit->kit_name,
                'historical' => $historical,
                'lat' => $point['lat'],
                'lng' => $point['lng'],
            ];
        }

        $checkIns = [];
        foreach ($case->checkIns as $checkIn) {
            $address = $case->addresses->firstWhere('id', $checkIn->address_id);
            $checkIns[] = [
                'position' => $address?->position,
                'line' => $address?->line,
                'when' => $scorer->ago($checkIn->matched_at, $now),
                'kit' => $checkIn->kit_name,
            ];
        }

        $distance = $best['nearest_miles'] ?? null;

        return [
            'id' => $case->id,
            'plate' => $case->plate,
            'state' => $case->us_state,
            'vin' => $case->vin,
            'ymm' => trim($case->vehicle_year.' '.$case->vehicle_make.' '.$case->vehicle_model),
            'client' => $case->client_name,
            'branch' => $case->branch_name,
            'order_id' => $case->remote_order_id,
            'order_age_days' => $orderDays,
            'order_age_label' => $orderDays.'d',
            'score' => $scored['score'],
            'band' => $scored['band'],
            'band_label' => $scored['band_label'],
            'reason' => $scored['reason'],
            'breakdown' => $scored['breakdown'],
            'warnings' => $scored['warnings'],
            'newest_hit_at' => $scored['newest_hit_at'] ?? '',
            'newest_hit_label' => $scored['newest_hit_label'],
            'best_address' => $best ? 'Address '.$best['position'].' · '.$best['line'] : 'No address',
            'best_position' => $best['position'] ?? null,
            'best_distance' => $distance ?? 9999,
            'best_distance_label' => $distance === null ? '—' : RecoveryLikelihood::formatMiles((float) $distance),
            'best_lat' => $best['lat'] ?? null,
            'best_lng' => $best['lng'] ?? null,
            'in_coverage' => $scored['in_coverage'],
            'coverage_label' => $coverage['label'],
            'driver_id' => $dispatch?->driver_id,
            'driver_name' => $dispatch?->driver?->name,
            'kit_name' => $dispatch?->driver?->kit_name,
            'nearby_driver' => $scored['driver_name'],
            'status' => $status,
            'status_label' => self::statusLabel($status),
            'status_rank' => self::statusRank($status),
            'priority' => $dispatch?->priority,
            'priority_label' => self::priorityLabel($dispatch?->priority),
            'due' => $dispatch?->due_window,
            'due_label' => self::dueLabel($dispatch?->due_window),
            'note' => $dispatch?->note,
            'notification' => $dispatch?->notification_log,
            'stop_order' => $dispatch?->stop_order ?? 0,
            'addresses' => array_map(function (array $address) use ($scored): array {
                $nearest = $address['nearest_miles'] ?? null;

                return [
                    'id' => $address['id'],
                    'position' => $address['position'],
                    'line' => $address['line'],
                    'city' => $address['city'],
                    'lat' => $address['lat'],
                    'lng' => $address['lng'],
                    'score' => $address['score'],
                    'reason' => $address['reason'],
                    'hit_count' => $address['hit_count'],
                    'close_hit_count' => $address['close_hit_count'],
                    'nearest' => $nearest === null ? '—' : RecoveryLikelihood::formatMiles((float) $nearest),
                    'check_in_count' => $address['check_in_count'],
                    'check_in_label' => $address['check_in_count'] === 0
                        ? 'No check-in recorded'
                        : $address['check_in_count'].' check-in'.($address['check_in_count'] === 1 ? '' : 's').', latest '.$address['last_check_in_label'],
                    'in_coverage' => $address['in_coverage'],
                    'geocoded' => $address['geocoded'],
                    'best' => $address['id'] === $scored['best_address_id'],
                ];
            }, $addresses),
            'hits' => $hits,
            'check_ins' => $checkIns,
            'detail_map' => self::detailMap($addresses, $hits),
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, mixed>  $filters
     */
    private static function matches(array $row, array $filters): bool
    {
        $q = strtolower(trim((string) ($filters['q'] ?? '')));
        if ($q !== '') {
            $haystack = strtolower($row['plate'].' '.$row['vin'].' '.$row['order_id']);
            if (! str_contains($haystack, $q)) {
                return false;
            }
        }
        if (($filters['client'] ?? '') !== '' && $row['client'] !== $filters['client']) {
            return false;
        }
        if (($filters['branch'] ?? '') !== '' && $row['branch'] !== $filters['branch']) {
            return false;
        }
        if (($filters['band'] ?? '') !== '' && $row['band'] !== $filters['band']) {
            return false;
        }
        $coverage = (string) ($filters['coverage'] ?? '');
        if ($coverage === 'inside' && $row['in_coverage'] !== true) {
            return false;
        }
        if ($coverage === 'outside' && $row['in_coverage'] !== false) {
            return false;
        }
        $recency = (string) ($filters['recency'] ?? '');
        if ($recency !== '' && ! self::recencyMatches($row['newest_hit_at'], $recency)) {
            return false;
        }
        if (! empty($filters['unassigned']) && $row['status'] !== 'new') {
            return false;
        }

        return true;
    }

    private static function recencyMatches(string $newest, string $recency): bool
    {
        if ($newest === '') {
            return $recency === 'older';
        }
        $hours = max(0, (CarbonImmutable::now()->getTimestamp() - CarbonImmutable::parse($newest)->getTimestamp()) / 3600);

        return match ($recency) {
            '48h' => $hours < 48,
            '10d' => $hours >= 48 && $hours <= 240,
            'older' => $hours > 240,
            default => true,
        };
    }

    /**
     * @param  array<string, mixed>  $a
     * @param  array<string, mixed>  $b
     */
    private static function compare(array $a, array $b, string $sort, string $dir): int
    {
        $key = match ($sort) {
            'plate' => 'plate',
            'client' => 'client',
            'order_age' => 'order_age_days',
            'newest_hit' => 'newest_hit_at',
            'address' => 'best_address',
            'distance' => 'best_distance',
            'driver' => 'driver_name',
            'status' => 'status_rank',
            default => 'score',
        };
        $left = $a[$key] ?? '';
        $right = $b[$key] ?? '';
        $left = $left === null ? '' : $left;
        $right = $right === null ? '' : $right;
        $cmp = $left <=> $right;
        if ($cmp === 0) {
            return strcmp($a['plate'], $b['plate']);
        }

        return $dir === 'asc' ? $cmp : -$cmp;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<string>
     */
    private static function options(array $rows, string $key): array
    {
        $values = array_values(array_unique(array_map(fn (array $row): string => (string) $row[$key], $rows)));
        sort($values);

        return $values;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  Collection<int, OpportunityDriver>  $drivers
     * @param  array<string, mixed>  $coverage
     * @return array<string, mixed>
     */
    private static function plan(array $rows, Collection $drivers, array $coverage): array
    {
        $columns = [];
        foreach ($drivers as $driver) {
            $stops = array_values(array_filter(
                $rows,
                fn (array $row): bool => $row['driver_id'] === $driver->id && in_array($row['status'], ['dispatched', 'checked_in'], true),
            ));
            usort($stops, fn (array $a, array $b): int => ($a['stop_order'] <=> $b['stop_order']) ?: strcmp($a['plate'], $b['plate']));
            $legs = [];
            $previousLat = (float) $driver->latitude;
            $previousLng = (float) $driver->longitude;
            $fromDriver = $driver->freshGps();
            foreach ($stops as $index => $stop) {
                $miles = null;
                if ($stop['best_lat'] !== null && $stop['best_lng'] !== null && ($fromDriver || $index > 0)) {
                    $miles = Haversine::feet([$previousLng, $previousLat], [(float) $stop['best_lng'], (float) $stop['best_lat']]) / 5280;
                }
                $legs[] = [
                    'case_id' => $stop['id'],
                    'plate' => $stop['plate'],
                    'score' => $stop['score'],
                    'band' => $stop['band'],
                    'address' => $stop['best_address'],
                    'status_label' => $stop['status_label'],
                    'due_label' => $stop['due_label'],
                    'miles' => $miles,
                    'miles_label' => $miles === null
                        ? 'GPS older than 24 hours'
                        : RecoveryLikelihood::formatMiles($miles).' straight-line '.($index === 0 ? 'from '.$driver->name : 'from the previous stop'),
                    'lat' => $stop['best_lat'],
                    'lng' => $stop['best_lng'],
                ];
                if ($stop['best_lat'] !== null && $stop['best_lng'] !== null) {
                    $previousLat = (float) $stop['best_lat'];
                    $previousLng = (float) $stop['best_lng'];
                    $fromDriver = true;
                }
            }
            $columns[] = [
                'id' => $driver->id,
                'name' => $driver->name,
                'kit' => $driver->kit_name,
                'fix_label' => self::fixLabel((int) $driver->fix_minutes_ago),
                'fresh' => $driver->freshGps(),
                'stops' => array_slice($legs, 0, 8),
                'extra' => max(0, count($legs) - 8),
            ];
        }

        $unassigned = array_values(array_filter(
            $rows,
            fn (array $row): bool => $row['status'] === 'new' && $row['band'] === 'hot',
        ));
        usort($unassigned, fn (array $a, array $b): int => ($b['score'] <=> $a['score']) ?: strcmp($a['plate'], $b['plate']));

        return [
            'coverage_label' => $coverage['label'],
            'columns' => $columns,
            'unassigned' => array_map(fn (array $row): array => [
                'id' => $row['id'],
                'plate' => $row['plate'],
                'score' => $row['score'],
                'address' => $row['best_address'],
                'reason' => $row['reason'],
            ], array_slice($unassigned, 0, 8)),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  Collection<int, OpportunityDriver>  $drivers
     * @param  array<string, mixed>  $coverage
     * @return array<string, mixed>
     */
    private static function overviewMap(array $rows, Collection $drivers, array $coverage): array
    {
        $circles = [];
        $polygons = [];
        if (($coverage['kind'] ?? '') === 'radius') {
            $circles[] = [
                'lat' => $coverage['lat'],
                'lng' => $coverage['lng'],
                'radiusMiles' => $coverage['radius_miles'],
                'color' => '#FF6666',
                'label' => $coverage['label'],
                'dashed' => true,
            ];
        } elseif (($coverage['kind'] ?? '') === 'polygon' && ($coverage['ring'] ?? []) !== []) {
            $polygons[] = ['path' => $coverage['ring'], 'color' => '#FF6666', 'label' => $coverage['label']];
        } elseif (($coverage['kind'] ?? '') === 'locations') {
            foreach ($coverage['shapes'] as $shape) {
                if ($shape['kind'] === 'radius') {
                    $circles[] = [
                        'lat' => $shape['lat'],
                        'lng' => $shape['lng'],
                        'radiusMiles' => $shape['radius_miles'],
                        'color' => '#FF6666',
                        'label' => 'Location zone',
                        'dashed' => true,
                    ];
                } elseif (($shape['ring'] ?? []) !== []) {
                    $polygons[] = ['path' => $shape['ring'], 'color' => '#FF6666', 'label' => 'Location zone'];
                }
            }
        }

        $pins = [];
        foreach ($coverage['locations'] ?? [] as $location) {
            if ($location->latitude === null) {
                continue;
            }
            $type = $location->typeEnum();
            $pins[] = [
                'lat' => (float) $location->latitude,
                'lng' => (float) $location->longitude,
                'color' => $type->color(),
                'label' => $location->name,
                'shape' => 'yard',
            ];
        }
        foreach ($drivers as $driver) {
            $pins[] = [
                'lat' => (float) $driver->latitude,
                'lng' => (float) $driver->longitude,
                'color' => $driver->freshGps() ? '#111827' : '#9ca3af',
                'label' => $driver->name.' · '.$driver->kit_name,
                'shape' => 'driver',
            ];
        }
        foreach ($rows as $row) {
            if (! in_array($row['band'], ['hot', 'warm'], true) || $row['best_lat'] === null) {
                continue;
            }
            $pins[] = [
                'lat' => $row['best_lat'],
                'lng' => $row['best_lng'],
                'color' => $row['band'] === 'hot' ? '#FF6666' : '#d97706',
                'label' => $row['plate'].' · '.$row['score'].' · '.$row['best_address'],
                'shape' => 'hit',
            ];
        }

        return OpportunityMap::project($circles, $polygons, $pins);
    }

    /**
     * @param  list<array<string, mixed>>  $addresses
     * @param  list<array<string, mixed>>  $hits
     * @return array<string, mixed>
     */
    private static function detailMap(array $addresses, array $hits): array
    {
        $circles = [];
        $pins = [];
        foreach ($addresses as $address) {
            if (! ($address['geocoded'] ?? false) || $address['lat'] === null) {
                continue;
            }
            $circles[] = [
                'lat' => $address['lat'],
                'lng' => $address['lng'],
                'radiusMiles' => RecoveryLikelihood::CLOSE_MILES,
                'color' => '#2563eb',
                'label' => 'Address '.$address['position'].' · 0.1 mi',
                'dashed' => true,
                'opacity' => 0.18,
            ];
            $pins[] = [
                'lat' => $address['lat'],
                'lng' => $address['lng'],
                'color' => $address['best'] ?? false ? '#FF6666' : '#2563eb',
                'label' => 'Address '.$address['position'].' · '.$address['line'],
                'shape' => 'address',
            ];
        }
        foreach ($hits as $hit) {
            if (($hit['lat'] ?? null) === null || ($hit['type'] ?? '') === 'P') {
                continue;
            }
            $pins[] = [
                'lat' => $hit['lat'],
                'lng' => $hit['lng'],
                'color' => ($hit['historical'] ?? false) ? '#9ca3af' : '#FF6666',
                'label' => ($hit['type_label'] ?? 'Hit').' · '.($hit['when'] ?? ''),
                'shape' => 'hit',
            ];
        }

        return OpportunityMap::project($circles, [], $pins, 640, 280);
    }

    /**
     * @return array{lat: ?float, lng: ?float}
     */
    private static function hitPoint(mixed $lat, mixed $lng, mixed $miles, int $index): array
    {
        if ($lat === null || $lng === null || $miles === null) {
            return ['lat' => $lat === null ? null : (float) $lat, 'lng' => $lng === null ? null : (float) $lng];
        }
        $miles = (float) $miles;
        $lat = (float) $lat + ($miles / 69);
        $lng = (float) $lng + ($index * 0.00035);

        return ['lat' => $lat, 'lng' => $lng];
    }

    /**
     * @return array<string, mixed>
     */
    private static function coverage(int $orgId): array
    {
        $zone = OrgCoverageZone::query()->where('org_id', $orgId)->first();
        $locations = OrgLocation::query()->where('org_id', $orgId)->get();
        $base = [
            'kind' => 'none',
            'label' => 'No coverage zone yet',
            'locations' => $locations,
            'shapes' => [],
        ];
        if (! $zone) {
            return $base;
        }

        if ($zone->scope === 'location') {
            $shapes = [];
            foreach ($locations as $location) {
                if ($location->coverage_mode === 'radius' && $location->coverage_radius_miles && $location->latitude !== null) {
                    $shapes[] = [
                        'kind' => 'radius',
                        'lat' => (float) $location->latitude,
                        'lng' => (float) $location->longitude,
                        'radius_miles' => (float) $location->coverage_radius_miles,
                    ];
                }
                $ring = LocationCoverageMap::ring($location->coverage_polygon);
                if ($location->coverage_mode === 'polygon' && $ring !== []) {
                    $shapes[] = ['kind' => 'polygon', 'ring' => $ring];
                }
            }

            return [
                'kind' => $shapes === [] ? 'none' : 'locations',
                'label' => 'Per location',
                'shapes' => $shapes,
                'locations' => $locations,
            ];
        }

        if ($zone->mode === 'polygon') {
            $ring = LocationCoverageMap::ring($zone->polygon);

            return $ring === [] ? $base : [
                'kind' => 'polygon',
                'label' => 'Company-wide drawn area',
                'ring' => $ring,
                'locations' => $locations,
                'shapes' => [],
            ];
        }

        $center = $locations->firstWhere('id', $zone->center_location_id);
        if ($center?->latitude === null || ! $zone->radius_miles) {
            return $base;
        }

        return [
            'kind' => 'radius',
            'label' => LocationCoverageMap::miles((float) $zone->radius_miles).' around '.$center->name,
            'lat' => (float) $center->latitude,
            'lng' => (float) $center->longitude,
            'radius_miles' => (float) $zone->radius_miles,
            'locations' => $locations,
            'shapes' => [],
        ];
    }

    public static function fixLabel(int $minutes): string
    {
        if ($minutes >= RecoveryLikelihood::DRIVER_FRESH_MINUTES) {
            return 'Last fix '.(int) round($minutes / 60).' hours ago';
        }
        if ($minutes >= 60) {
            return 'Last fix '.(int) round($minutes / 60).' hours ago';
        }

        return 'Last fix '.$minutes.' min ago';
    }

    public static function statusLabel(string $status): string
    {
        return match ($status) {
            'dispatched' => 'Dispatched',
            'checked_in' => 'Checked in',
            'recovered' => 'Recovered',
            'not_found' => 'Not found',
            default => 'New',
        };
    }

    public static function statusRank(string $status): int
    {
        return match ($status) {
            'new' => 0,
            'dispatched' => 1,
            'checked_in' => 2,
            'recovered' => 3,
            'not_found' => 4,
            default => 9,
        };
    }

    public static function priorityLabel(?string $priority): ?string
    {
        return match ($priority) {
            'high' => 'High',
            'normal' => 'Normal',
            default => null,
        };
    }

    public static function dueLabel(?string $due): ?string
    {
        return match ($due) {
            'tonight' => 'Tonight',
            'overnight' => 'Overnight',
            'tomorrow' => 'Tomorrow',
            default => null,
        };
    }

    private static function hitTypeLabel(string $type): string
    {
        return match (strtoupper($type)) {
            'L' => 'Exact live',
            'H' => 'Exact historical',
            'P' => 'Probable',
            default => strtoupper($type),
        };
    }

    private static function dispositionLabel(?string $disposition): ?string
    {
        return match ($disposition) {
            'invalid_scan' => 'Invalid Scan',
            'moving' => 'Moving',
            default => null,
        };
    }

    private static function hourLabel(int $hour): string
    {
        $hour = $hour % 24;
        $suffix = $hour < 12 ? 'am' : 'pm';
        $display = $hour % 12;
        if ($display === 0) {
            $display = 12;
        }

        return $display.$suffix.' local';
    }
}
