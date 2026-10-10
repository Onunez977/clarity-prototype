<?php

namespace Tests\Feature\Jobs\Org;

use App\Jobs\Rdn\GetOrgRdnClients;
use App\Models\Org;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

class GetOrgRdnClientsTest extends TestCase
{
    #[Group('jobs')]
    public function test_it_runs_for_local_clients(): void
    {
        $mockedSoapClient = $this->partialMock(\SoapClient::class, function (MockInterface $mock) {
            $mock
                ->shouldReceive('__soapCall')
                ->once()
                ->andReturn((object) [
                    'item' => [
                        (object) [
                            'client_id' => 123456,
                            'client_name' => 'My Awesome Name',
                            'phone' => '(123) 456-7890',
                            'type' => 'Local',
                        ],
                    ],
                ]);

            $mock
                ->shouldReceive('__getLastResponse')
                ->once()
                ->andReturn(
                    '<?xml version="1.0" encoding="UTF-8"?><SOAP-ENV:Envelope xmlns:SOAP-ENV="http://schemas.xmlsoap.org/soap/envelope/" xmlns:ns1="'.config('services.rdn.api_url').'"><SOAP-ENV:Header><ns1:pager><page>1</page><per_page>1</per_page><next_page>1</next_page><page_items>1</page_items><total_items>1</total_items><total_pages>1</total_pages></ns1:pager></SOAP-ENV:Header></SOAP-ENV:Envelope>',
                );
        });

        $org = Org::factory()->create([
            'rdn_api_key' => fake()->uuid(),
        ]);

        $clientOrg = Org::factory()->create([
            'rdn_id' => 123456,
        ]);

        $this->assertNull($clientOrg->meta);

        (new GetOrgRdnClients(orgId: $org->id, org: $org, client: $mockedSoapClient))->handle();

        $clientOrg->refresh();

        $this->assertNotNull($clientOrg->meta);

        $this->assertArrayHasKey('rdn_client_type', $clientOrg->meta->properties);
        $this->assertEquals('local', $clientOrg->meta->properties['rdn_client_type']);

        $this->assertArrayHasKey('rdn_client_belongs_to', $clientOrg->meta->properties);
        $this->assertEquals($org->id, $clientOrg->meta->properties['rdn_client_belongs_to']);
    }

    #[Group('jobs')]
    public function test_it_runs_for_global_clients(): void
    {
        $mockedSoapClient = $this->partialMock(\SoapClient::class, function (MockInterface $mock) {
            $mock
                ->shouldReceive('__soapCall')
                ->once()
                ->andReturn((object) [
                    'item' => [
                        (object) [
                            'client_id' => 654321,
                            'client_name' => 'My Global Company',
                            'phone' => '867-5309',
                            'type' => 'Global',
                        ],
                    ],
                ]);

            $mock
                ->shouldReceive('__getLastResponse')
                ->once()
                ->andReturn(
                    '<?xml version="1.0" encoding="UTF-8"?><SOAP-ENV:Envelope xmlns:SOAP-ENV="http://schemas.xmlsoap.org/soap/envelope/" xmlns:ns1="'.config('services.rdn.api_url').'"><SOAP-ENV:Header><ns1:pager><page>1</page><per_page>1</per_page><next_page>1</next_page><page_items>1</page_items><total_items>1</total_items><total_pages>1</total_pages></ns1:pager></SOAP-ENV:Header></SOAP-ENV:Envelope>',
                );
        });

        $org = Org::factory()->create([
            'rdn_api_key' => fake()->uuid(),
        ]);

        $clientOrg = Org::factory()->create([
            'rdn_id' => 654321,
        ]);

        $this->assertNull($clientOrg->meta);

        (new GetOrgRdnClients(orgId: $org->id, org: $org, client: $mockedSoapClient))->handle();

        $clientOrg->refresh();

        $this->assertNotNull($clientOrg->meta);

        $this->assertArrayHasKey('rdn_client_type', $clientOrg->meta->properties);
        $this->assertEquals('global', $clientOrg->meta->properties['rdn_client_type']);

        $this->assertArrayNotHasKey('rdn_client_belongs_to', $clientOrg->meta->properties);
    }
}
