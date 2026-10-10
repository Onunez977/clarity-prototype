<?php

declare(strict_types=1);

namespace App\Services\Zones;

use App\Enums\ScoutAnalyticsEvent;
use App\Enums\ScoutAnalyticsPage;
use App\Facades\Analytics;
use App\Jobs\ImportZonesFromKml;
use App\Models\User;
use App\Models\Zone;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * Shared parse + create + tally executor for KML bulk zone import. Written
 * once and invoked by both the synchronous Filament action and the queued
 * {@see ImportZonesFromKml} job so the loop never gets duplicated.
 *
 * Each valid polygon is created in its OWN platform-connection transaction —
 * never one transaction wrapping the whole loop — so a single bad shape
 * can't fail the rest of the batch.
 */
class ZoneKmlImporter
{
    public function __construct(private readonly KmlZoneParser $parser) {}

    /**
     * @return array{created: int, skipped: int, skip_reasons: list<string>}
     */
    public function run(string $kml, int $orgId, int $userId): array
    {
        $placemarks = $this->parser->parse($kml);

        $created = 0;
        $skipped = 0;
        $skipReasons = [];

        foreach ($placemarks as $placemark) {
            if ($placemark['polygon'] === null) {
                $skipped++;
                $skipReasons[] = $placemark['skip_reason'] ?? 'Placemark skipped.';

                continue;
            }

            try {
                DB::connection('platform')->transaction(function () use ($placemark, $orgId, $userId): void {
                    $zone = Zone::create([
                        'org_id' => $orgId,
                        'created_by_user_id' => $userId,
                        'name' => $placemark['name'],
                        'color' => Zone::DEFAULT_COLOR,
                        'is_no_scan_zone' => false,
                        'definition' => [
                            'polygons' => [$placemark['polygon']],
                            'zip_codes' => [],
                        ],
                    ]);

                    $zone->rebuildGeometryFromDefinition();

                    $isEmpty = DB::connection('platform')
                        ->table('zones')
                        ->where('id', $zone->id)
                        ->rawValue('(geom IS NULL OR public.ST_IsEmpty(geom))::int AS is_empty');

                    if ((int) $isEmpty === 1) {
                        throw new RuntimeException('Placemark produced a degenerate (zero-area) geometry.');
                    }
                });

                $created++;
            } catch (Throwable $exception) {
                $skipped++;
                $skipReasons[] = $exception->getMessage();
            }
        }

        Analytics::track(
            ScoutAnalyticsEvent::ZoneImport->value,
            User::find($userId),
            ['created' => $created, 'skipped' => $skipped],
            ScoutAnalyticsPage::Zones->value,
        );

        return [
            'created' => $created,
            'skipped' => $skipped,
            'skip_reasons' => $skipReasons,
        ];
    }
}
