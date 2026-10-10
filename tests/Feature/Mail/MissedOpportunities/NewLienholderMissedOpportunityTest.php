<?php

namespace Tests\Feature\Mail\MissedOpportunities;

use App\Mail\MissedOpportunities\NewLienholderMissedOpportunity;
use Illuminate\Support\Facades\Mail;
use InsightLPR\BlackAdam\Enum\RoleEnum;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

class NewLienholderMissedOpportunityTest extends TestCase
{
    #[Group('mail')]
    public function test_can_create_mailable(): void
    {
        $mail = new NewLienholderMissedOpportunity(
            records: [],
            sender: fake()->safeEmail()
        );

        $this->assertEquals('Missed Opportunities from Clarity', $mail->subject);
        $this->assertInstanceOf(NewLienholderMissedOpportunity::class, $mail);
    }

    #[Group('mail')]
    public function test_can_render(): void
    {
        Mail::fake();

        $admin = $this->createUser(role: RoleEnum::ADMIN->value);

        $records = [
            [
                'id' => 1,
                'hotlist_id' => 1,
                'scan_id' => 1,
                'hit_type' => 'M',
                'created_at' => '2025-09-04T15:34:48.153614Z',
                'kit_id' => 1,
                'scans_ocr' => 'KME238',
                'scans_scanned_at' => '2025-09-04 20:34:48',
                'scans_camera_name' => 'incidunt',
                'scans_us_state' => 'IN',
                'scans_guid' => '0092db5d-8930-4b08-b7b4-82d2d11b04f6',
                'scan_metadata' => null,
                'kit_name' => 'huel.kareem',
                'kit_org_id' => 2632,
                'hotlist_us_state' => 'IN',
                'hotlist_plate' => 'KME238',
                'hotlist_status' => 1,
                'hotlist_vin' => '1HGCM82633A84552',
                'hotlist_org_id' => 2632,
                'hotlist_remote_order_id' => '3359950',
                'hotlist_integration_id' => 568,
                'hotlist_vehicle_year' => '1986',
                'hotlist_vehicle_make' => 'GMC',
                'hotlist_vehicle_model' => 'Accord',
                'client_org_id' => 8487,
                'client_org_name' => 'Lind-Keebler',
                'lienholder_org_id' => 8488,
                'lienholder_org_name' => 'Powlowski, Hagenes and Wunsch',
                'scans_geo' => '{"type":"Point","coordinates":[-113.815524,39.378315]}',
                'is_commercial' => false,
            ],
        ];

        Mail::to([$admin->email])->queue(new NewLienholderMissedOpportunity(
            records: $records,
            sender: fake()->safeEmail()
        ));

        Mail::assertQueued(NewLienholderMissedOpportunity::class, function (NewLienholderMissedOpportunity $mail) {
            $mail->build();

            $mail
                ->assertHasSubject('Missed Opportunities from Clarity')
                ->assertSeeInText('License Plate')
                ->assertSeeInText('KME238');

            return true;
        });
    }
}
