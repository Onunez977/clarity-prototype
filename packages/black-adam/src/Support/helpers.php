<?php

declare(strict_types=1);

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use InsightLPR\BlackAdam\Enum\VehicleImageTypeEnum;
use InsightLPR\BlackAdam\Support\SqlFormatter;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Output\ConsoleOutput;

if (!function_exists('timeblock')) {
    function timeblock(Carbon $time): int
    {
        return (int)floor($time->timestamp / 3600);
    }
}


if (!function_exists('geoPoint2Meter')) {
    /**
     * Not really accurate but it's a good approximation.
     * @param float $value
     * @return float|int
     *
     */
    function geoPoint2Meter(float $value): float
    {
        return $value * 111000;
    }
}
if (!function_exists('geoMeter2Point')) {
    /**
     * Not really accurate but it's a good approximation.
     * @param float $value
     * @return float|int
     *
     */
    function geoMeter2Point(float $value)
    {
        return number_format($value / 110000, 6, '.', '');
    }
}

/**
 * (used for debugging)
 */
if (!function_exists('dumpsql')) {
    /**
     * Dump formatted, prettified SQL with merged bindings.
     * @codeCoverageIgnoreStart
     * @param Builder|QueryExecuted $query
     *
     * @return string
     */
    function dumpsql($query, $formatted = true, $capture = false)
    {
        if (method_exists($query, 'toSql')) {
            $vsprintf = vsprintf(str_replace('?', '"%s"', $query->toSql()), $query->getBindings());
        } elseif (property_exists($query, 'sql')) {
            $vsprintf = vsprintf(str_replace('?', '"%s"', $query->sql), $query->bindings);
        } else {
            $vsprintf = $query->toSql();
            foreach ($query->getBindings() as $binding) {
                $vsprintf = \Illuminate\Support\Str::replaceFirst(
                    '?',
                    is_numeric($binding) ? $binding : "'{$binding}'",
                    $vsprintf
                );
            }
            //$vsprintf = vsprintf($statement, $query->getBindings());
        }

        if ($formatted) {
            $output = PHP_EOL . '=============================================================================';
            $output .= PHP_EOL . $vsprintf = SqlFormatter::format($vsprintf);
            $output .= PHP_EOL . '=============================================================================' . PHP_EOL;
            $vsprintf = $output;
        }

        if ($capture) {
            return $vsprintf;
        }

        echo $vsprintf;
    }
}
if (!function_exists('ddsql')) {
    /**
     * @codeCoverageIgnoreStart
     */
    function ddsql($query)
    {
        dumpsql($query);
        exit;
    }
}
if (!function_exists('dumptotable')) {
    /**
     * @codeCoverageIgnoreStart
     */
    function dumptotable($row, $only = [])
    {
        $row = clone $row;

        $output = new ConsoleOutput();
        $t = new Table($output->section());
        $collection = $row->transform(
            function ($row) use ($only) {
                $c = collect((array)$row);
                if (count($only)) {
                    $c = $c->only($only);
                } else {
                    $c = $c->except(
                        [
                            'id',
                            'company_id',
                            'reference',
                            'created_at',
                            'updated_at',
                            'deleted_at',
                            'target_record_component_id',
                            'target_name',
                            'record_component_type',
                            'attachment_documents',
                            'attachment_images',
                            'attachment_total',
                            'distance_from_last_scan',
                            'address_score_requested_at',
                            'tag_id',
                            'taggable_id',
                            'taggable_type',
                            'taggable_slug',
                            'order_column',
                            'scans_total',
                            'sightings',
                            'street3',
                            'longitude',
                            'latitude',
                            'active_addresses',
                            'tag_name',
                            'type',
                            'slug',
                        ]
                    );
                }

                return $c->toArray();
            }
        );

        $t->setHeaders(array_keys($collection->first()));

        $t->addRows($collection->toArray());
        $t->render();
        dump($collection->count());
    }
}
if (!function_exists('jwtDecode')) {
    /**
     * @codeCoverageIgnoreStart
     */
    function jwtDecode(string $token): array
    {
        $segments = explode('.', $token);
        $header = json_decode(base64_decode($segments[0]), true);
        $payload = json_decode(base64_decode($segments[1]), true);
        $signature = $segments[2];
        return [
            'header' => $header,
            'payload' => $payload,
            'signature' => $signature,
        ];
    }
}

if (!function_exists('isJoined')) {
    function isJoined(\Illuminate\Database\Eloquent\Builder $query, $table, string $connection = 'zod'): bool
    {
        return collect($query->getQuery()->joins)
            ->pluck('table')
            ->contains(
                function ($value, $key) use ($connection, $table) {
                    if (is_a($value, \Illuminate\Database\Query\Expression::class)) {
                        $string = $value->getValue(\Illuminate\Support\Facades\DB::connection($connection)->getQueryGrammar());
                        return $string === $table; // $table is something like "table_name AS permissions_table"
                    }
                    return $value === $table;
                }
            );
    }
}

if (!function_exists('scanImageUrl')) {
    function scanImageUrl(string $guid, VehicleImageTypeEnum $type): string
    {
        return sprintf('https://s3.us-east-2.amazonaws.com/images.insightlpr.com/%s-%s.jpg', $guid, $type->value);
    }
}


