<?php

namespace Tests\Feature\Mail\MissedOpportunities;

use App\Filament\Pages\AlertSettings;
use App\Mail\MissedOpportunities;
use App\Models\AlertSetting;
use App\Models\MissedOpportunity;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

class NewNotificationTest extends TestCase
{
    #[Group('mail')]
    public function test_can_render(): void
    {
        Mail::fake();

        $user = $this->createUser();

        $missedOpportunity = MissedOpportunity::factory()
            ->forOrg($user->org)
            ->create();

        $alertSetting = AlertSetting::factory()
            ->emailNotification([$user->email])
            ->create();

        Mail::queue(new MissedOpportunities\NewNotification(
            missedOpportunity: $missedOpportunity,
            alertSettingId: $alertSetting->id,
            alert_email: $user->email,
        ));

        Mail::assertQueued(MissedOpportunities\NewNotification::class, function (MissedOpportunities\NewNotification $mail) use ($missedOpportunity) {
            $mail->build();

            $mail
                ->assertHasSubject('New Missed Opportunity from Clarity: '.$missedOpportunity->hotlist->integration->name.' - '.$missedOpportunity->hotlist->plate.' '.$missedOpportunity->hotlist->us_state)
                // Heading
                ->assertSeeInText('New Missed Opportunity')
                // Overview Panel
                ->assertSeeInOrderInText(['Client', $missedOpportunity->hotlist->client->name])
                ->assertSeeInOrderInText(['Created', $missedOpportunity->created_at->format(config('app.date_format'))])
                // Images
                ->assertSeeInOrderInHtml(['alt="Vehicle Image"', 'alt="Plate Image"'])
                // Table
                ->assertSeeInOrderInText(['License Plate', $missedOpportunity->hotlist->plate.' '.$missedOpportunity->hotlist->us_state])
                ->assertSeeInOrderInText(['VIN', $missedOpportunity->hotlist->vin])
                ->assertSeeInOrderInText(['Vehicle', $missedOpportunity->hotlist->vehicle])
                ->assertSeeInOrderInText(['Date Created', $missedOpportunity->created_at->format(config('app.date_format'))])
                ->assertSeeInOrderInText(['Location', $missedOpportunity->scan->address])
                ->assertSeeInHtml('https://www.google.com/maps/place/'.\implode(',', $missedOpportunity->scan->coordinates), false)
                ->assertSeeInOrderInText(['Client', $missedOpportunity->hotlist->client->name])
                ->assertSeeInOrderInText(['Lienholder', $missedOpportunity->hotlist->lienholder->name])
                ->assertSeeInOrderInText(['Camera', $missedOpportunity->scan->camera_name])
                ->assertSeeInOrderInText(['Kit User', $missedOpportunity->kit->name])
                // Footer
                ->assertSeeInText('Manage Alerts')
                ->assertSeeInHtml('href="'.AlertSettings::getUrl(panel: 'app').'"', false)
                ->assertSeeInOrderInText(['Unsubscribe From This Alert', 'Unsubscribe From All Alerts']);

            return true;
        });
    }
}
