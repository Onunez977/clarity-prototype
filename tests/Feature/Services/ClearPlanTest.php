<?php

namespace Tests\Feature\Services;

use App\Models\Hit;
use App\Models\Org;
use App\Services\Aws\Sqs;
use App\Services\ClearPlan;
use Aws\Result;
use Illuminate\Support\Facades\Log;
use Mockery;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('clearplan')]
#[Group('services')]
class ClearPlanTest extends TestCase
{
    protected function createClearPlanWithMockedSqs(Sqs $mockSqs): ClearPlan
    {
        $clearPlan = new ClearPlan;

        $ref = new \ReflectionClass($clearPlan);
        $prop = $ref->getProperty('sqs');
        $prop->setAccessible(true);
        $prop->setValue($clearPlan, $mockSqs);

        return $clearPlan;
    }

    public function test_send_hit_returns_true_on_success(): void
    {
        $org = Org::factory()->create();

        $hit = Hit::factory()->forOrg($org)->create();

        $mockSqs = Mockery::mock(Sqs::class);
        $mockSqs->shouldReceive('sendClearplanMessage')
            ->once()
            ->andReturn(new Result([]));

        $clearPlan = $this->createClearPlanWithMockedSqs($mockSqs);

        $result = $clearPlan->sendHit($hit);

        $this->assertTrue($result);
    }

    public function test_send_hit_returns_false_on_exception(): void
    {
        $org = Org::factory()->create();

        $hit = Hit::factory()->forOrg($org)->create();

        $mockSqs = Mockery::mock(Sqs::class);
        $mockSqs->shouldReceive('sendClearplanMessage')
            ->once()
            ->andThrow(new \Exception('SQS connection failed'));

        Log::shouldReceive('error')
            ->once()
            ->withArgs(function ($message, $context) use ($hit) {
                return $message === 'ClearPlan SQS error'
                    && $context['hit_id'] === $hit->id
                    && $context['message'] === 'SQS connection failed';
            });

        $clearPlan = $this->createClearPlanWithMockedSqs($mockSqs);

        $result = $clearPlan->sendHit($hit);

        $this->assertFalse($result);
    }

    public function test_payload_structure_matches_expected_format(): void
    {
        $org = Org::factory()->create();

        $hit = Hit::factory()->forOrg($org)->create();
        $hit->loadMissing(['hotlist.org', 'scan.scanMetaData', 'kit.org']);

        $capturedBody = null;

        $mockSqs = Mockery::mock(Sqs::class);
        $mockSqs->shouldReceive('sendClearplanMessage')
            ->once()
            ->withArgs(function ($body, $messageAttributes, $messageGroupId, $messageDeduplicationId) use (&$capturedBody) {
                $capturedBody = json_decode($body, true);

                return true;
            })
            ->andReturn(new Result([]));

        $clearPlan = $this->createClearPlanWithMockedSqs($mockSqs);
        $clearPlan->sendHit($hit);

        $this->assertNotNull($capturedBody);

        // Verify all expected keys exist
        $expectedKeys = [
            'now', 'vin', 'plate', 'state', 'us_state', 'make', 'model', 'year',
            'udbsaid', 'scanning_org_id', 'hotlist_org_id', 'color',
            'ml_is_not_plate', 'ml_plate_state', 'insight_hit_id',
            'insight_hit_scan_id', 'insight_kit_id', 'insight_hotlist_id',
            'insight_org_type', 'insight_scanning_org_type',
            'insight_hotlist_org_type', 'insight_integration_id',
            'remote_order_id', 'scans', 'case_claim_url',
        ];

        foreach ($expectedKeys as $key) {
            $this->assertArrayHasKey($key, $capturedBody, "Missing key: {$key}");
        }

        // Verify payload values map correctly
        $this->assertEquals($hit->hotlist->vin, $capturedBody['vin']);
        $this->assertEquals($hit->hotlist->plate, $capturedBody['plate']);
        $this->assertEquals($hit->hotlist->us_state, $capturedBody['state']);
        $this->assertEquals($hit->hotlist->us_state, $capturedBody['us_state']);
        $this->assertEquals($hit->hotlist->vehicle_make, $capturedBody['make']);
        $this->assertEquals($hit->hotlist->vehicle_model, $capturedBody['model']);
        $this->assertEquals($hit->hotlist->vehicle_year, $capturedBody['year']);
        $this->assertEquals($hit->kit->org_id, $capturedBody['scanning_org_id']);
        $this->assertEquals($hit->hotlist->org_id, $capturedBody['hotlist_org_id']);
        $this->assertEquals($hit->id, $capturedBody['insight_hit_id']);
        $this->assertEquals($hit->scan_id, $capturedBody['insight_hit_scan_id']);
        $this->assertEquals($hit->kit_id, $capturedBody['insight_kit_id']);
        $this->assertEquals($hit->hotlist_id, $capturedBody['insight_hotlist_id']);
        $this->assertEquals($hit->hotlist->remote_order_id, $capturedBody['remote_order_id']);
        $this->assertEquals($hit->kit->org->rdn_id, $capturedBody['udbsaid']);
        $this->assertEquals('', $capturedBody['case_claim_url']);

        // Verify scans array structure
        $this->assertCount(1, $capturedBody['scans']);
        $this->assertArrayHasKey('location', $capturedBody['scans'][0]);
        $this->assertArrayHasKey('lat', $capturedBody['scans'][0]['location']);
        $this->assertArrayHasKey('lng', $capturedBody['scans'][0]['location']);
        $this->assertArrayHasKey('plate_image_url', $capturedBody['scans'][0]);
        $this->assertArrayHasKey('car_image_url', $capturedBody['scans'][0]);
        $this->assertArrayHasKey('scanDate', $capturedBody['scans'][0]);
        $this->assertArrayHasKey('scanDateUTC', $capturedBody['scans'][0]);
        $this->assertArrayHasKey('guid', $capturedBody['scans'][0]);
        $this->assertTrue($capturedBody['scans'][0]['validated']);
        $this->assertEquals($hit->scan->id, $capturedBody['scans'][0]['id']);
    }

    public function test_message_attributes_include_hit_type(): void
    {
        $org = Org::factory()->create();

        $hit = Hit::factory()->forOrg($org)->create();

        $mockSqs = Mockery::mock(Sqs::class);
        $mockSqs->shouldReceive('sendClearplanMessage')
            ->once()
            ->withArgs(function ($body, $messageAttributes) use ($hit) {
                return isset($messageAttributes['HitType'])
                    && $messageAttributes['HitType']['Type'] === 'String'
                    && $messageAttributes['HitType']['Value'] === $hit->hit_type;
            })
            ->andReturn(new Result([]));

        $clearPlan = $this->createClearPlanWithMockedSqs($mockSqs);
        $clearPlan->sendHit($hit);
    }
}
