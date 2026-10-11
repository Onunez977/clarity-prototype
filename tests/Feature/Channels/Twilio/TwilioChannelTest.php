<?php

namespace Tests\Feature\Channels\Twilio;

use App\Channels\Twilio\TwilioChannel;
use App\Models\AlertSetting;
use App\Models\MissedOpportunity;
use App\Notifications\Hits\MissedOpportunityNotification;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\Group;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;
use Twilio\Rest\Api\V2010\Account\MessageInstance;
use Twilio\Rest\Api\V2010\Account\MessageList;
use Twilio\Rest\Client;

class TwilioChannelTest extends TestCase
{
    protected mixed $mockedTwilio = null;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('services.twilio.sid', fake()->uuid());
        Config::set('services.twilio.token', fake()->uuid());
        Config::set('services.twilio.from', '1-800-555-5555');

        $this->mockedTwilio = \Mockery::mock(Client::class);
        $this->mockedTwilio->messages = \Mockery::mock(MessageList::class);
    }

    #[Group('channels')]
    #[Group('twilio')]
    public function test_does_not_send_if_twilio_is_not_configured()
    {
        // Force config to be empty.
        Config::set('services.twilio.sid', null);
        Config::set('services.twilio.token', null);

        Log::shouldReceive('warning')
            ->once()
            ->with('Twilio is not configured');

        (new TwilioChannel)->send(
            (object) [],
            new MissedOpportunityNotification(
                missedOpportunity: MissedOpportunity::factory()->make(),
                alertSetting: AlertSetting::factory()->make(),
                via: [TwilioChannel::class]
            )
        );
    }

    #[Group('channels')]
    #[Group('twilio')]
    public function test_can_send_sms_message_with_alert_setting()
    {
        $this->mockedTwilio->messages
            ->shouldReceive('create')
            ->atLeast()->once()
            ->andReturn(\Mockery::mock(MessageInstance::class));

        $missedOpportunity = MissedOpportunity::factory()->create();

        $alertSetting = AlertSetting::factory()
            ->smsNotification(['15555555555'])
            ->create();

        $notification = new MissedOpportunityNotification(
            missedOpportunity: $missedOpportunity,
            alertSetting: $alertSetting,
            via: [TwilioChannel::class]
        );

        $channel = new TwilioChannel($this->mockedTwilio);
        $channel->send($alertSetting, $notification);

        $activity = Activity::query()
            ->where('description', 'Notification Sent')
            ->where('subject_type', 'missed_opportunity')
            ->where('subject_id', $missedOpportunity->id)
            ->first();

        $this->assertNotNull($activity);

        $this->assertArrayHasKey('channel', $activity->properties);
        $this->assertEquals('twilio', $activity->properties['channel']);

        $this->assertArrayHasKey('to', $activity->properties);
        $this->assertEquals('15555555555', $activity->properties['to']);
    }

    #[Group('channels')]
    #[Group('twilio')]
    public function test_can_send_sms_message_by_direct()
    {
        $this->mockedTwilio->messages
            ->shouldReceive('create')
            ->atLeast()->once()
            ->andReturn(\Mockery::mock(MessageInstance::class));

        $missedOpportunity = MissedOpportunity::factory()->create();

        $alertSetting = AlertSetting::factory()
            ->smsNotification(['15555555555'])
            ->create();

        $notification = new MissedOpportunityNotification(
            missedOpportunity: $missedOpportunity,
            alertSetting: $alertSetting,
            via: [TwilioChannel::class]
        );

        $notifiable = (object) ['routes' => ['sms' => ['15555555555']]];

        $channel = new TwilioChannel($this->mockedTwilio);
        $channel->send($notifiable, $notification);

        $activity = Activity::query()
            ->where('description', 'Notification Sent')
            ->where('subject_type', 'missed_opportunity')
            ->where('subject_id', $missedOpportunity->id)
            ->first();

        $this->assertNotNull($activity);

        $this->assertArrayHasKey('channel', $activity->properties);
        $this->assertEquals('twilio', $activity->properties['channel']);

        $this->assertArrayHasKey('to', $activity->properties);
        $this->assertEquals('15555555555', $activity->properties['to']);
    }

    #[Group('channels')]
    #[Group('twilio')]
    public function test_logs_failure_on_sms_message()
    {
        $this->mockedTwilio->messages
            ->shouldReceive('create')
            ->atLeast()->once()
            ->andThrow(new \Exception('Testing Twilio Error'));

        $missedOpportunity = MissedOpportunity::factory()->create();

        $alertSetting = AlertSetting::factory()
            ->smsNotification(['15555555555'])
            ->create();

        $notification = new MissedOpportunityNotification(
            missedOpportunity: $missedOpportunity,
            alertSetting: $alertSetting,
            via: [TwilioChannel::class]
        );

        $channel = new TwilioChannel($this->mockedTwilio);
        $channel->send($alertSetting, $notification);

        $activity = Activity::query()
            ->where('description', 'Notification Failed')
            ->where('subject_type', 'missed_opportunity')
            ->where('subject_id', $missedOpportunity->id)
            ->first();

        $this->assertNotNull($activity);

        $this->assertArrayHasKey('channel', $activity->properties);
        $this->assertEquals('twilio', $activity->properties['channel']);

        $this->assertArrayHasKey('exception', $activity->properties);
        $this->assertEquals('Testing Twilio Error', $activity->properties['exception']);

        $this->assertArrayHasKey('to', $activity->properties);
        $this->assertEquals('15555555555', $activity->properties['to']);
    }
}
