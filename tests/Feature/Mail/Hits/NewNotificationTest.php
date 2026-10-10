<?php

namespace Tests\Feature\Mail\Hits;

use App\Enums\HitTypeEnum;
use App\Filament\Pages\AlertSettings;
use App\Mail\Hits;
use App\Models\AlertSetting;
use App\Models\Hit;
use App\Models\Scan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('alerts')]
class NewNotificationTest extends TestCase
{
    #[Group('mail')]
    public function test_can_render(): void
    {
        Mail::fake();

        $user = $this->createUser();

        $hit = Hit::factory()
            ->forOrg($user->org)
            ->create();

        $alertSetting = AlertSetting::factory()
            ->emailNotification([$user->email])
            ->create();

        Mail::queue(new Hits\NewNotification(
            hit: $hit,
            alertSettingId: $alertSetting->id,
            alert_email: $user->email,
        ));

        Mail::assertQueued(Hits\NewNotification::class, function (Hits\NewNotification $mail) use ($hit) {
            $mail->build();

            $mail
                ->assertHasSubject('New Live Hit from Clarity: '.$hit->hotlist->integration->name.' - '.$hit->hotlist->plate.' '.$hit->hotlist->us_state)
                // Heading
                ->assertSeeInText('New '.HitTypeEnum::Live->title().' Hit')
                // Overview Panel
                ->assertSeeInOrderInText(['Client', Str::limit($hit->hotlist->client->name, 30)])
                ->assertSeeInOrderInText(['Created', $hit->created_at->format(config('app.date_format'))])
                // Images
                ->assertSeeInOrderInHtml(['alt="Vehicle Image"', 'alt="Plate Image"'])
                // Table
                ->assertSeeInOrderInText(['License Plate', $hit->hotlist->plate.' '.$hit->hotlist->us_state])
                ->assertSeeInOrderInText(['VIN', $hit->hotlist->vin])
                ->assertSeeInOrderInText(['Vehicle', $hit->hotlist->vehicle])
                ->assertSeeInOrderInText(['Scanned Date', $hit->scan->scanned_at->setTimezone('UTC')->format(config('app.date_format'))])
                ->assertSeeInOrderInText(['Location', $hit->scan->address])
                ->assertSeeInHtml('https://www.google.com/maps/place/'.\implode(',', $hit->scan->coordinates), false)
                ->assertSeeInOrderInText(['Client', $hit->hotlist->client->name])
                ->assertSeeInOrderInText(['Lienholder', $hit->hotlist->lienholder->name])
                ->assertSeeInOrderInText(['Camera', $hit->scan->camera_name])
                ->assertSeeInOrderInText(['Kit User', $hit->kit->name])
                // Footer
                ->assertSeeInText('Manage Alerts')
                ->assertSeeInHtml('href="'.AlertSettings::getUrl(panel: 'app').'"', false)
                ->assertSeeInOrderInText(['Unsubscribe From This Alert', 'Unsubscribe From All Alerts']);

            return true;
        });
    }

    #[Group('mail')]
    public function test_uses_multi_scan_template_for_historical_with_multiple_scans(): void
    {
        Http::fake();

        $user = $this->createUser();

        $hit = Hit::factory()
            ->historical()
            ->forOrg($user->org)
            ->create();

        $alertSetting = AlertSetting::factory()
            ->emailNotification([$user->email])
            ->create();

        $scans = Scan::factory()
            ->forOrg($user->org)
            ->count(2)
            ->create();

        $mailable = new Hits\NewNotification(
            hit: $hit,
            alertSettingId: $alertSetting->id,
            alert_email: $user->email,
            scans: $scans->all(),
        );

        $mailable->build();

        $this->assertEquals('mail.hits.new-multi-scan-notification', $mailable->markdown);
    }

    #[Group('mail')]
    public function test_uses_single_scan_template_for_live_with_scans(): void
    {
        $user = $this->createUser();

        $hit = Hit::factory()
            ->live()
            ->forOrg($user->org)
            ->create();

        $alertSetting = AlertSetting::factory()
            ->emailNotification([$user->email])
            ->create();

        $mailable = new Hits\NewNotification(
            hit: $hit,
            alertSettingId: $alertSetting->id,
            alert_email: $user->email,
            scans: [
                ['id' => 1, 'plate' => 'ABC123'],
                ['id' => 2, 'plate' => 'DEF456'],
            ],
        );

        $mailable->build();

        $this->assertEquals('mail.hits.new-notification', $mailable->markdown);
    }
}
