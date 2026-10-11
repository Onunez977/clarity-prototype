<?php

namespace App\Console\Commands;

use App\Enums\HitTypeEnum;
use App\Mail\Hits\NewNotification;
use App\Models\AlertSetting;
use App\Models\Hit;
use App\Models\Hotlist;
use App\Models\Integrations;
use App\Models\Kit;
use App\Models\Org;
use App\Models\Scan;
use Clickbar\Magellan\Data\Geometries\Point;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;

class SendTestHitAlert extends Command
{
    protected $signature = 'app:send-test-hit-alert
        {email : Email address to send the test alert to}
        {--hit= : ID of an existing hit to use}
        {--fake-hit : Generate a fake hit without touching the database}';

    protected $description = 'Send a test hit alert email for development/QA purposes';

    public function handle(): int
    {
        $email = $this->argument('email');

        if ($this->option('fake-hit')) {
            $hit = $this->buildFakeHit();
            $scan = $hit->scan;
        } elseif ($this->option('hit')) {
            $hit = Hit::with('scan', 'hotlist.integration', 'hotlist.client', 'kit')->find($this->option('hit'));

            if (! $hit) {
                $this->error('Hit not found.');

                return self::FAILURE;
            }

            $scan = $hit->scan;
        } else {
            $hit = Hit::with('scan', 'hotlist.integration', 'hotlist.client', 'kit')->latest('id')->first();

            if (! $hit) {
                $this->error('No hits found in the database. Use --fake-hit to generate one.');

                return self::FAILURE;
            }

            $scan = $hit->scan;
        }

        $alertSetting = AlertSetting::first();
        $alertSettingId = $alertSetting?->id ?? 0;

        $mailable = new NewNotification(
            hit: $hit,
            alertSettingId: $alertSettingId,
            alert_email: $email,
            timezone: 'America/Los_Angeles',
            scans: $scan ? [$scan] : [],
        );

        Mail::to($email)->sendNow($mailable);

        $this->info("Hit alert email sent to {$email}.");
        $this->table(
            ['Field', 'Value'],
            [
                ['Hit ID', $hit->id ?? 'fake'],
                ['Hit Type', $hit->hit_type],
                ['Plate', $hit->hotlist?->plate ?? 'N/A'],
                ['VIN', $hit->hotlist?->vin ?? 'N/A'],
                ['Vehicle', $hit->hotlist?->vehicle ?? 'N/A'],
            ]
        );

        return self::SUCCESS;
    }

    private function buildFakeHit(): Hit
    {
        $org = new Org;
        $org->id = 252820;
        $org->name = 'Location Services VRM';

        $integration = new Integrations;
        $integration->id = 1;
        $integration->name = 'Location Services VRM';

        $hotlist = new Hotlist;
        $hotlist->id = 0;
        $hotlist->plate = '9JWX718';
        $hotlist->us_state = 'CA';
        $hotlist->vin = 'JF1VBAL62P9820646';
        $hotlist->vehicle_year = '2023';
        $hotlist->vehicle_make = 'SUBARU';
        $hotlist->vehicle_model = 'WRX';
        $hotlist->status = 1;
        $hotlist->integration_id = 1;
        $hotlist->org_id = 1;
        $hotlist->client_org_id = 1;
        $hotlist->setRelation('integration', $integration);
        $hotlist->setRelation('client', $org);

        $scan = new Scan;
        $scan->id = 0;
        $scan->guid = '2473a2a0-c336-43ae-a876-2be39d2739e5';
        $scan->ocr = '9JWX718';
        $scan->us_state = 'CA';
        $scan->camera_name = 'Front Camera';
        $scan->is_visible = true;
        $scan->scanned_at = Carbon::parse('2026-03-02 19:15:00', 'UTC');
        $scan->created_at = Carbon::parse('2026-03-02 19:15:00', 'UTC');
        $scan->geo = Point::makeGeodetic(34.034933941909, -117.75363641907, 4326);

        $kit = new Kit;
        $kit->id = 0;
        $kit->name = 'Test Kit';
        $kit->org_id = 1;
        $kit->setRelation('org', $org);

        $hit = new Hit;
        $hit->id = 0;
        $hit->hit_type = HitTypeEnum::Staging_Live->value;
        $hit->hotlist_id = 0;
        $hit->scan_id = 0;
        $hit->kit_id = 0;
        $hit->created_at = Carbon::parse('2026-03-02 19:15:00', 'UTC');
        $hit->setRelation('hotlist', $hotlist);
        $hit->setRelation('scan', $scan);
        $hit->setRelation('kit', $kit);

        return $hit;
    }
}
