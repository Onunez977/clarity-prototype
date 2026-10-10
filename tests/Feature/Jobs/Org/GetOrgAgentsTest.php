<?php

namespace Tests\Feature\Jobs\Org;

use App\Jobs\Rdn\GetOrgAgents;
use App\Models\Org;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

class GetOrgAgentsTest extends TestCase
{
    #[Group('jobs')]
    #[Group('rdn')]
    public function test_it_stores_agents_in_org_meta(): void
    {
        $mockedSoapClient = $this->partialMock(\SoapClient::class, function (MockInterface $mock) {
            $mock
                ->shouldReceive('__soapCall')
                ->twice()
                ->andReturn(
                    (object) [
                        'item' => (object) [
                            'agent_id' => 100,
                            'first_name' => 'John',
                            'last_name' => 'Doe',
                            'vendor' => (object) [
                                'vendor_id' => null,
                                'name' => null,
                                'phone' => null,
                                'state' => null,
                            ],
                        ],
                    ],
                    (object) [
                        'item' => (object) [
                            'agent_id' => 200,
                            'first_name' => 'Acme Recovery',
                            'last_name' => 'Jane Smith',
                            'vendor' => (object) [
                                'vendor_id' => 'TX1234',
                                'name' => 'Acme Recovery',
                                'phone' => '555-9999',
                                'state' => 'TX',
                            ],
                        ],
                    ],
                );

            $mock
                ->shouldReceive('__getLastResponse')
                ->twice()
                ->andReturn(
                    '<?xml version="1.0" encoding="UTF-8"?><SOAP-ENV:Envelope xmlns:SOAP-ENV="http://schemas.xmlsoap.org/soap/envelope/" xmlns:ns1="'.config('services.rdn.api_url').'"><SOAP-ENV:Header><ns1:pager><page>1</page><per_page>1</per_page><next_page>1</next_page><page_items>1</page_items><total_items>1</total_items><total_pages>1</total_pages></ns1:pager></SOAP-ENV:Header></SOAP-ENV:Envelope>',
                );
        });

        $org = Org::factory()->create([
            'rdn_api_key' => fake()->uuid(),
        ]);

        $this->assertNull($org->meta);

        (new GetOrgAgents(orgId: $org->id, org: $org, client: $mockedSoapClient))->handle();

        $org->refresh();

        $this->assertNotNull($org->meta);
        $this->assertArrayHasKey('rdn_agents', $org->meta->properties);
        $this->assertCount(2, $org->meta->properties['rdn_agents']);

        $this->assertEquals('John', $org->meta->properties['rdn_agents'][100]['first_name']);
        $this->assertEquals('Doe', $org->meta->properties['rdn_agents'][100]['last_name']);
        $this->assertEquals('Company Adjuster', $org->meta->properties['rdn_agents'][100]['type']);

        $this->assertEquals('Acme Recovery', $org->meta->properties['rdn_agents'][200]['first_name']);
        $this->assertEquals('Outside Adjuster', $org->meta->properties['rdn_agents'][200]['type']);
        $this->assertEquals('Acme Recovery', $org->meta->properties['rdn_agents'][200]['vendor_name']);
    }
}
