<?php

namespace Database\Seeders;

use App\Models\Kit;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class ClarityScoutClickhouseSeeder extends Seeder
{
    /**
     * Base coordinates: Indianapolis, IN area.
     */
    private const BASE_LAT = 39.7684;

    private const BASE_LNG = -86.1581;

    public function run(): void
    {
        if (config('database.connections.clarity_clickhouse.driver') !== 'pgsql') {
            $this->command?->warn('Skipping ClickHouse seeder: CLICKHOUSE_DRIVER is not pgsql.');

            return;
        }

        $this->ensureSchemaExists();

        $user = User::where('email', 'test@tester.com')->first();

        if (! $user || ! $user->kit_id) {
            $this->command?->warn('Skipping ClickHouse seeder: test@tester.com user or kit not found.');

            return;
        }

        $kit = Kit::find($user->kit_id);

        if (! $kit) {
            $this->command?->warn('Skipping ClickHouse seeder: Kit not found.');

            return;
        }

        dump('Seeding Clarity Scout ClickHouse data...');

        $this->seedKitTrips($kit, $user->org_id);
        $this->seedKitScanPaths($kit, $user->org_id);
        $this->seedScansHot($kit, $user->org_id);
        $this->seedScansCold($kit, $user->org_id);
        $this->seedHitsHot($kit, $user->org_id);
        $this->seedHitsCold($user->org_id);
        $this->seedHotlistAddressGeohash($user->org_id);

        dump('Clarity Scout ClickHouse data seeded.');
    }

    private function ensureSchemaExists(): void
    {
        $schemaFile = database_path('schema/local-clickhouse-schema.sql');

        if (file_exists($schemaFile)) {
            DB::connection('zod')->unprepared(file_get_contents($schemaFile));
        }

        // Set REPLICA IDENTITY FULL on clarity tables so DELETE/TRUNCATE work
        // (these tables may be part of a logical replication publication)
        $tables = ['kit_trips', 'kit_scan_paths', 'scans_hot', 'scans_cold', 'hits_hot', 'hits_cold', 'hotlist_address_geohash', 'scout_open_addresses'];

        foreach ($tables as $table) {
            try {
                DB::connection('zod')->statement("ALTER TABLE clarity.{$table} REPLICA IDENTITY FULL");
            } catch (\Throwable) {
                // Table may not exist yet or already has FULL identity
            }
        }
    }

    private function seedKitTrips(Kit $kit, int $orgId): void
    {
        $connection = DB::connection('clarity_clickhouse');
        $connection->table('clarity.kit_trips')->where('kit_id', $kit->id)->delete();

        $trips = [];
        $now = Carbon::now();

        // Today's trips: 2 completed + 1 recent
        $todayStart = $now->copy()->startOfDay()->addHours(6);

        for ($i = 0; $i < 3; $i++) {
            $tripStart = $todayStart->copy()->addMinutes($i * 120 + rand(0, 20));
            $duration = rand(25, 75);
            $tripEnd = $tripStart->copy()->addMinutes($duration);

            if ($tripEnd->isAfter($now)) {
                $tripEnd = $now->copy();
                $duration = (int) $tripStart->diffInMinutes($tripEnd);
            }

            $pointCount = $duration * rand(8, 15);
            $path = $this->generateDrivingPath(self::BASE_LAT + ($i * 0.01), self::BASE_LNG + ($i * 0.01), min($pointCount, 50));

            $trips[] = [
                'kit_id' => $kit->id,
                'org_id' => $orgId,
                'kit_mapping' => $kit->kit_mapping ?? 'LOCAL-TEST',
                'gl' => $kit->kit_mapping ?? 'LOCAL-TEST',
                'trip_date' => $tripStart->toDateString(),
                'trip_start' => $tripStart,
                'trip_end' => $tripEnd,
                'trip_start_local' => $tripStart,
                'trip_end_local' => $tripEnd,
                'duration_minutes' => $duration,
                'point_count' => $pointCount,
                'path_simplified' => json_encode($path),
                'avg_speed' => rand(15, 35) + (rand(0, 9) / 10),
                'max_speed' => rand(40, 65) + (rand(0, 9) / 10),
                '_version' => 1,
            ];
        }

        // Past week trips: 2 per day for 6 days
        for ($day = 1; $day <= 6; $day++) {
            $dayStart = $now->copy()->subDays($day)->startOfDay()->addHours(rand(6, 8));

            for ($t = 0; $t < 2; $t++) {
                $tripStart = $dayStart->copy()->addMinutes($t * 180 + rand(0, 30));
                $duration = rand(30, 90);
                $tripEnd = $tripStart->copy()->addMinutes($duration);
                $pointCount = $duration * rand(8, 15);
                $path = $this->generateDrivingPath(self::BASE_LAT + ($day * 0.005), self::BASE_LNG - ($t * 0.01), min($pointCount, 50));

                $trips[] = [
                    'kit_id' => $kit->id,
                    'org_id' => $orgId,
                    'kit_mapping' => $kit->kit_mapping ?? 'LOCAL-TEST',
                    'gl' => $kit->kit_mapping ?? 'LOCAL-TEST',
                    'trip_date' => $tripStart->toDateString(),
                    'trip_start' => $tripStart,
                    'trip_end' => $tripEnd,
                    'trip_start_local' => $tripStart,
                    'trip_end_local' => $tripEnd,
                    'duration_minutes' => $duration,
                    'point_count' => $pointCount,
                    'path_simplified' => json_encode($path),
                    'avg_speed' => rand(15, 35) + (rand(0, 9) / 10),
                    'max_speed' => rand(40, 65) + (rand(0, 9) / 10),
                    '_version' => 1,
                ];
            }
        }

        $connection->table('clarity.kit_trips')->insert($trips);
        dump('  - kit_trips: '.count($trips).' rows');
    }

    private function seedKitScanPaths(Kit $kit, int $orgId): void
    {
        $connection = DB::connection('clarity_clickhouse');
        $connection->table('clarity.kit_scan_paths')->where('kit_id', $kit->id)->delete();

        $paths = [];
        $now = Carbon::now();

        // Today: path segments every 15 minutes from 6am to now
        $segmentStart = $now->copy()->startOfDay()->addHours(6);
        $lat = self::BASE_LAT;
        $lng = self::BASE_LNG;
        $bearing = rand(0, 359);

        while ($segmentStart->isBefore($now)) {
            $points = [];
            $pointCount = rand(5, 15);

            for ($p = 0; $p < $pointCount; $p++) {
                $bearing += rand(-25, 25);
                $step = rand(1, 4) * 0.001;
                $lat += $step * cos(deg2rad($bearing));
                $lng += $step * sin(deg2rad($bearing));

                // Keep within Indianapolis area
                $lat = max(39.65, min(39.90, $lat));
                $lng = max(-86.30, min(-86.00, $lng));

                $points[] = [round($lng, 6), round($lat, 6)];
            }

            $paths[] = [
                'kit_id' => $kit->id,
                'org_id' => $orgId,
                'timestamp' => $segmentStart->toDateTimeString(),
                'scan_count' => $pointCount,
                'path_points' => json_encode($points),
                'kit_name' => $kit->name ?? 'Test Kit',
                '_version' => 1,
            ];

            $segmentStart->addMinutes(15);
        }

        if (! empty($paths)) {
            $connection->table('clarity.kit_scan_paths')->insert($paths);
        }

        dump('  - kit_scan_paths: '.count($paths).' rows');
    }

    private function seedScansHot(Kit $kit, int $orgId): void
    {
        $connection = DB::connection('clarity_clickhouse');
        $connection->table('clarity.scans_hot')->where('kit_id', $kit->id)->where('org_id', $orgId)->delete();

        $scans = [];
        $now = Carbon::now();

        // Create scans spread across today's trip windows and past week
        for ($day = 0; $day <= 6; $day++) {
            $dayStart = $now->copy()->subDays($day)->startOfDay()->addHours(6);
            $scanCount = $day === 0 ? rand(15, 30) : rand(8, 20);

            for ($s = 0; $s < $scanCount; $s++) {
                $scannedAt = $dayStart->copy()->addMinutes(rand(0, 600));
                $lat = self::BASE_LAT + (rand(-50, 50) * 0.001);
                $lng = self::BASE_LNG + (rand(-50, 50) * 0.001);

                $scans[] = [
                    'kit_id' => $kit->id,
                    'org_id' => $orgId,
                    'ocr' => strtoupper(fake()->bothify('???####')),
                    'us_state' => 'IN',
                    'camera_name' => 'CAM-'.rand(1, 4),
                    'scanned_at' => $scannedAt,
                    'created_at' => $scannedAt,
                    'lat' => $lat,
                    'lng' => $lng,
                    'kit_name' => $kit->name ?? 'Test Kit',
                    '_peerdb_is_deleted' => 0,
                    '_version' => 1,
                ];
            }
        }

        $connection->table('clarity.scans_hot')->insert($scans);
        dump('  - scans_hot: '.count($scans).' rows');
    }

    private function seedScansCold(Kit $kit, int $orgId): void
    {
        $connection = DB::connection('clarity_clickhouse');
        $connection->table('clarity.scans_cold')->where('kit_id', $kit->id)->where('org_id', $orgId)->delete();

        $scans = [];
        $now = Carbon::now();

        // Create scans spread across the last 30 days (matching browse default range)
        for ($day = 0; $day <= 30; $day++) {
            $dayStart = $now->copy()->subDays($day)->startOfDay()->addHours(6);
            $scanCount = $day === 0 ? rand(15, 30) : rand(8, 20);

            for ($s = 0; $s < $scanCount; $s++) {
                $scannedAt = $dayStart->copy()->addMinutes(rand(0, 600));
                $lat = self::BASE_LAT + (rand(-50, 50) * 0.001);
                $lng = self::BASE_LNG + (rand(-50, 50) * 0.001);

                $scans[] = [
                    'kit_id' => $kit->id,
                    'org_id' => $orgId,
                    'ocr' => strtoupper(fake()->bothify('???####')),
                    'us_state' => 'IN',
                    'camera_name' => 'CAM-'.rand(1, 4),
                    'scanned_at' => $scannedAt,
                    'created_at' => $scannedAt,
                    'lat' => $lat,
                    'lng' => $lng,
                    'kit_name' => $kit->name ?? 'Test Kit',
                    '_peerdb_is_deleted' => 0,
                    '_version' => 1,
                ];
            }
        }

        $connection->table('clarity.scans_cold')->insert($scans);
        dump('  - scans_cold: '.count($scans).' rows');
    }

    private function seedHitsHot(Kit $kit, int $orgId): void
    {
        $connection = DB::connection('clarity_clickhouse');
        $connection->table('clarity.hits_hot')->where('kit_id', $kit->id)->where('kit_org_id', $orgId)->delete();

        $hits = [];
        $hitTypes = ['L', 'H', 'S', 'Z'];
        $now = Carbon::now();

        // Create hits spread across trip windows
        for ($day = 0; $day <= 6; $day++) {
            $dayStart = $now->copy()->subDays($day)->startOfDay()->addHours(6);
            $hitCount = rand(2, 6);

            for ($h = 0; $h < $hitCount; $h++) {
                $hitTime = $dayStart->copy()->addMinutes(rand(0, 600));
                $lat = self::BASE_LAT + (rand(-50, 50) * 0.001);
                $lng = self::BASE_LNG + (rand(-50, 50) * 0.001);

                $hits[] = [
                    'hotlist_id' => rand(1, 100),
                    'scan_id' => rand(1, 1000),
                    'hit_type' => $hitTypes[array_rand($hitTypes)],
                    'hit_created_at' => $hitTime,
                    'ocr' => strtoupper(fake()->bothify('???####')),
                    'scanned_at' => $hitTime,
                    'camera_name' => 'CAM-'.rand(1, 4),
                    'scan_us_state' => 'IN',
                    'kit_id' => $kit->id,
                    'kit_name' => $kit->name ?? 'Test Kit',
                    'kit_org_id' => $orgId,
                    'hotlist_org_id' => $orgId,
                    'hotlist_us_state' => 'IN',
                    'hotlist_plate' => strtoupper(fake()->bothify('???####')),
                    'hotlist_status' => rand(1, 3),
                    'lat' => $lat,
                    'lng' => $lng,
                    '_peerdb_is_deleted' => 0,
                    '_version' => 1,
                ];
            }
        }

        $connection->table('clarity.hits_hot')->insert($hits);
        dump('  - hits_hot: '.count($hits).' rows');
    }

    private function seedHitsCold(int $orgId): void
    {
        $connection = DB::connection('clarity_clickhouse');
        $connection->table('clarity.hits_cold')->where('hotlist_org_id', $orgId)->delete();

        $hits = [];
        $hitTypes = ['L', 'H', 'S', 'Z'];

        // 6 clusters of hits around Indianapolis
        $clusters = [
            [39.7684, -86.1581],  // Downtown
            [39.7910, -86.1480],  // North side
            [39.7450, -86.1750],  // South side
            [39.7700, -86.2000],  // West side
            [39.7800, -86.1200],  // East side
            [39.8100, -86.1400],  // Broad Ripple
        ];

        foreach ($clusters as $index => $center) {
            $clusterSize = rand(3, 12);

            for ($h = 0; $h < $clusterSize; $h++) {
                $lat = $center[0] + (rand(-50, 50) * 0.0001);
                $lng = $center[1] + (rand(-50, 50) * 0.0001);

                $hits[] = [
                    'hotlist_id' => rand(1, 100),
                    'scan_id' => rand(1, 1000),
                    'hit_type' => $hitTypes[array_rand($hitTypes)],
                    'hit_created_at' => now()->subDays(rand(1, 270))->toDateTimeString(),
                    'ocr' => strtoupper(fake()->bothify('???####')),
                    'scanned_at' => now()->subDays(rand(1, 270))->toDateTimeString(),
                    'camera_name' => 'CAM-'.rand(1, 4),
                    'scan_us_state' => 'IN',
                    'kit_id' => rand(1, 15),
                    'kit_name' => 'Test Kit',
                    'kit_org_id' => $orgId,
                    'hotlist_org_id' => $orgId,
                    'hotlist_us_state' => 'IN',
                    'hotlist_plate' => strtoupper(fake()->bothify('???####')),
                    'hotlist_status' => rand(1, 3),
                    'lat' => $lat,
                    'lng' => $lng,
                    '_peerdb_is_deleted' => 0,
                    '_version' => 1,
                ];
            }
        }

        $connection->table('clarity.hits_cold')->insert($hits);
        dump('  - hits_cold: '.count($hits).' rows');
    }

    private function seedHotlistAddressGeohash(int $orgId): void
    {
        $connection = DB::connection('clarity_clickhouse');
        $connection->table('clarity.hotlist_address_geohash')->where('org_id', $orgId)->delete();

        $rows = [];

        // Generate geohash cells with varying address counts across tiers
        $locations = [
            [39.7684, -86.1581, rand(1, 1)],      // tier_1
            [39.7750, -86.1500, rand(2, 4)],       // tier_2_4
            [39.7810, -86.1480, rand(2, 4)],       // tier_2_4
            [39.7450, -86.1750, rand(5, 9)],       // tier_5_9
            [39.7500, -86.1650, rand(5, 9)],       // tier_5_9
            [39.7600, -86.1400, rand(5, 9)],       // tier_5_9
            [39.7900, -86.1300, rand(10, 24)],     // tier_10_24
            [39.7700, -86.2000, rand(10, 24)],     // tier_10_24
            [39.8000, -86.1200, rand(10, 24)],     // tier_10_24
            [39.7350, -86.1900, rand(10, 24)],     // tier_10_24
            [39.8100, -86.1400, rand(25, 40)],     // tier_25_plus
            [39.7550, -86.1100, rand(25, 35)],     // tier_25_plus
            [39.7950, -86.1600, rand(1, 1)],       // tier_1
            [39.7400, -86.1300, rand(2, 4)],       // tier_2_4
            [39.7650, -86.1850, rand(5, 9)],       // tier_5_9
            [39.7850, -86.1150, rand(10, 20)],     // tier_10_24
            [39.7300, -86.1550, rand(1, 1)],       // tier_1
            [39.8050, -86.1700, rand(3, 4)],       // tier_2_4
            [39.7500, -86.2100, rand(7, 9)],       // tier_5_9
            [39.7750, -86.0950, rand(12, 20)],     // tier_10_24
        ];

        foreach ($locations as [$lat, $lng, $count]) {
            $geohash = $this->encodeGeohash($lng, $lat);

            $rows[] = [
                'org_id' => $orgId,
                'source' => 'own',
                'geohash' => $geohash,
                'address_count' => $count,
                'center_lng' => $lng,
                'center_lat' => $lat,
                '_version' => 1,
            ];
        }

        $connection->table('clarity.hotlist_address_geohash')->insert($rows);
        dump('  - hotlist_address_geohash: '.count($rows).' rows');
    }

    private function generateDrivingPath(float $startLat, float $startLng, int $points): array
    {
        $path = [];
        $lat = $startLat;
        $lng = $startLng;
        $bearing = rand(0, 359);

        for ($i = 0; $i < $points; $i++) {
            $bearing += rand(-25, 25);
            $step = rand(1, 4) * 0.001;
            $lat += $step * cos(deg2rad($bearing));
            $lng += $step * sin(deg2rad($bearing));
            $lat = max(39.65, min(39.90, $lat));
            $lng = max(-86.30, min(-86.00, $lng));
            $path[] = [round($lng, 6), round($lat, 6)];
        }

        return $path;
    }

    private function encodeGeohash(float $lng, float $lat, int $precision = 6): string
    {
        $base32 = '0123456789bcdefghjkmnpqrstuvwxyz';
        $minLat = -90.0;
        $maxLat = 90.0;
        $minLng = -180.0;
        $maxLng = 180.0;
        $isLng = true;
        $result = '';
        $chIdx = 0;
        $bit = 4;

        while (strlen($result) < $precision) {
            if ($isLng) {
                $mid = ($minLng + $maxLng) / 2;
                if ($lng >= $mid) {
                    $chIdx |= (1 << $bit);
                    $minLng = $mid;
                } else {
                    $maxLng = $mid;
                }
            } else {
                $mid = ($minLat + $maxLat) / 2;
                if ($lat >= $mid) {
                    $chIdx |= (1 << $bit);
                    $minLat = $mid;
                } else {
                    $maxLat = $mid;
                }
            }

            $isLng = ! $isLng;
            $bit--;

            if ($bit < 0) {
                $result .= $base32[$chIdx];
                $bit = 4;
                $chIdx = 0;
            }
        }

        return $result;
    }
}
