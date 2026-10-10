<?php

declare(strict_types=1);

namespace Tests\Jobs;

use App\Jobs\ImportZonesFromKml;
use App\Models\Org;
use App\Models\User;
use App\Models\Zone;
use App\Notifications\ZoneImportCompleted;
use App\Services\Zones\ZoneKmlImporter;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;
use Tests\TestCase;

#[Group('clarity-scout')]
class ImportZonesFromKmlTest extends TestCase
{
    private const KML = <<<'KML'
        <?xml version="1.0" encoding="UTF-8"?>
        <kml xmlns="http://www.opengis.net/kml/2.2">
          <Document>
            <Placemark>
              <name>Warehouse District</name>
              <Polygon>
                <outerBoundaryIs>
                  <LinearRing>
                    <coordinates>-96.8,32.8,0 -96.79,32.8,0 -96.79,32.81,0 -96.8,32.81,0 -96.8,32.8,0</coordinates>
                  </LinearRing>
                </outerBoundaryIs>
              </Polygon>
            </Placemark>
            <Placemark>
              <name>Just a Pin</name>
              <Point>
                <coordinates>-96.8,32.8,0</coordinates>
              </Point>
            </Placemark>
          </Document>
        </kml>
        KML;

    public function test_it_creates_zones_notifies_the_user_and_cleans_up_the_file(): void
    {
        Notification::fake();
        Storage::fake('local');

        $org = Org::factory()->create();
        $user = User::factory()->forOrg($org)->create();

        Storage::disk('local')->put('kml-imports/test.kml', self::KML);

        (new ImportZonesFromKml('kml-imports/test.kml', 'local', $org->id, $user->id))
            ->handle(app(ZoneKmlImporter::class));

        $this->assertSame(1, Zone::query()->forOrg($org->id)->count());

        $zone = Zone::query()->forOrg($org->id)->first();
        $this->assertSame('Warehouse District', $zone->name);

        Notification::assertSentTo(
            $user,
            ZoneImportCompleted::class,
            fn (ZoneImportCompleted $notification): bool => $notification->created === 1 && $notification->skipped === 1 && ! $notification->failed,
        );

        Storage::disk('local')->assertMissing('kml-imports/test.kml');
    }

    public function test_a_missing_stored_file_fails_the_job_instead_of_a_false_success(): void
    {
        Notification::fake();
        Storage::fake('local');

        $org = Org::factory()->create();
        $user = User::factory()->forOrg($org)->create();

        $job = new ImportZonesFromKml('kml-imports/missing.kml', 'local', $org->id, $user->id);

        try {
            $job->handle(app(ZoneKmlImporter::class));
            $this->fail('Expected handle() to throw when the stored file is missing.');
        } catch (RuntimeException $exception) {
            $job->failed($exception);
        }

        $this->assertSame(0, Zone::query()->forOrg($org->id)->count());

        Notification::assertSentTo(
            $user,
            ZoneImportCompleted::class,
            fn (ZoneImportCompleted $notification): bool => $notification->failed,
        );

        Notification::assertNotSentTo(
            $user,
            ZoneImportCompleted::class,
            fn (ZoneImportCompleted $notification): bool => ! $notification->failed,
        );

        Storage::disk('local')->assertMissing('kml-imports/missing.kml');
    }

    public function test_failed_notifies_the_user_and_cleans_up_the_file(): void
    {
        Notification::fake();
        Storage::fake('local');

        $org = Org::factory()->create();
        $user = User::factory()->forOrg($org)->create();

        Storage::disk('local')->put('kml-imports/test.kml', self::KML);

        $job = new ImportZonesFromKml('kml-imports/test.kml', 'local', $org->id, $user->id);
        $job->failed(new RuntimeException('boom'));

        Notification::assertSentTo(
            $user,
            ZoneImportCompleted::class,
            fn (ZoneImportCompleted $notification): bool => $notification->failed,
        );

        Storage::disk('local')->assertMissing('kml-imports/test.kml');
    }
}
