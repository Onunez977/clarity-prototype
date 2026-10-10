<?php

namespace Tests\Feature\Jobs\Org;

use App\Jobs\Rdn\GetOrgBranchZips;
use App\Models\Branch;
use App\Models\Org;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

class GetOrgBranchZipsTest extends TestCase
{
    #[Group('jobs')]
    #[Group('rdn')]
    public function test_it_updates_branch_zip_codes(): void
    {
        $mockedSoapClient = $this->partialMock(\SoapClient::class, function (MockInterface $mock) {
            $mock
                ->shouldReceive('__soapCall')
                ->once()
                ->andReturn((object) [
                    'item' => [
                        '90210',
                        '90211',
                        '90212',
                    ],
                ]);

            $mock
                ->shouldReceive('__getLastResponse')
                ->once()
                ->andReturn(
                    '<?xml version="1.0" encoding="UTF-8"?><SOAP-ENV:Envelope xmlns:SOAP-ENV="http://schemas.xmlsoap.org/soap/envelope/" xmlns:ns1="'.config('services.rdn.api_url').'"><SOAP-ENV:Header><ns1:pager><page>1</page><per_page>100</per_page><next_page>1</next_page><page_items>3</page_items><total_items>3</total_items><total_pages>1</total_pages></ns1:pager></SOAP-ENV:Header></SOAP-ENV:Envelope>',
                );
        });

        $org = Org::factory()->create([
            'rdn_api_key' => fake()->uuid(),
        ]);

        $branch = Branch::create([
            'org_id' => $org->id,
            'branch_id' => 42,
            'branch_name' => 'Test Branch',
            'zip_code' => '00000',
        ]);

        (new GetOrgBranchZips(orgId: $org->id, org: $org, client: $mockedSoapClient))->handle();

        $branch->refresh();

        $this->assertEquals('90210,90211,90212', $branch->zip_code);
    }

    #[Group('jobs')]
    #[Group('rdn')]
    public function test_it_skips_branches_without_branch_id(): void
    {
        $mockedSoapClient = $this->partialMock(\SoapClient::class, function (MockInterface $mock) {
            $mock->shouldNotReceive('__soapCall');
        });

        $org = Org::factory()->create([
            'rdn_api_key' => fake()->uuid(),
        ]);

        Branch::create([
            'org_id' => $org->id,
            'branch_id' => null,
            'branch_name' => 'No Branch ID',
            'zip_code' => '00000',
        ]);

        (new GetOrgBranchZips(orgId: $org->id, org: $org, client: $mockedSoapClient))->handle();

        $this->assertEquals('00000', Branch::where('org_id', $org->id)->first()->zip_code);
    }
}
