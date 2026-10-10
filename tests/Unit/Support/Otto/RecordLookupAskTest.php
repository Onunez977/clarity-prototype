<?php

declare(strict_types=1);

namespace Tests\Unit\Support\Otto;

use App\Support\Otto\RecordLookupAsk;
use PHPUnit\Framework\TestCase;

class RecordLookupAskTest extends TestCase
{
    public function test_todays_scans_is_a_record_lookup(): void
    {
        $this->assertTrue(RecordLookupAsk::matches('Show me the most recent scans from today'));
    }

    public function test_try_again_is_not_a_record_lookup(): void
    {
        $this->assertFalse(RecordLookupAsk::matches('try again'));
    }

    public function test_how_to_question_is_not_a_record_lookup(): void
    {
        $this->assertFalse(RecordLookupAsk::matches('what is a scan?'));
        $this->assertFalse(RecordLookupAsk::matches('how do I search for a plate?'));
        $this->assertFalse(RecordLookupAsk::matches('what is a zone?'));
        $this->assertFalse(RecordLookupAsk::matches('how do I create a zone?'));
    }

    public function test_zone_inventory_question_is_a_record_lookup(): void
    {
        $this->assertTrue(RecordLookupAsk::matches('what zones do I have'));
        $this->assertTrue(RecordLookupAsk::isZoneInventory('what zones do I have'));
        $this->assertTrue(RecordLookupAsk::matches('which zones are no-scan?'));
        $this->assertFalse(RecordLookupAsk::isZoneInventory('what is a zone?'));
        $this->assertFalse(RecordLookupAsk::matches('you sure about that?'));
        $this->assertFalse(RecordLookupAsk::matches('create a zone for 46204'));

        $redirect = RecordLookupAsk::knowledgeBaseRedirect('what zones do I have');
        $this->assertStringContainsString('Call ZoneSearch now', $redirect);
        $this->assertStringContainsString('zone-search tool', $redirect);
    }
}
