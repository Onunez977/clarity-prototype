<?php

namespace Tests\Feature\Services;

use App\Dto\Rdn\ClientDto;
use App\Models\Org;
use App\Models\OrgMeta;
use App\Services\RDN;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\LazyCollection;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

class RDNTest extends TestCase
{
    #[Group('rdn')]
    #[Group('services')]
    public function test_can_retrieve_client_list(): void
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

        $results = RDN::make(org: $org, client: $mockedSoapClient)->getClientList();

        $this->assertInstanceOf(LazyCollection::class, $results);

        $results = $results->toArray();
        $this->assertCount(1, $results);

        $this->assertInstanceOf(ClientDto::class, $results[0]);
        $this->assertEquals(123456, $results[0]->client_id);
        $this->assertEquals('My Awesome Name', $results[0]->client_name);
        $this->assertEquals('(123) 456-7890', $results[0]->phone);
        $this->assertEquals('Local', $results[0]->type);
    }

    #[Group('rdn')]
    #[Group('services')]
    public function test_can_retrieve_paginated_client_list(): void
    {
        $mockedSoapClient = $this->partialMock(\SoapClient::class, function (MockInterface $mock) {
            $mock
                ->shouldReceive('__soapCall')
                ->twice()
                ->andReturn(
                    (object) [
                        'item' => [
                            (object) [
                                'client_id' => 123456,
                                'client_name' => 'My Awesome Name',
                                'phone' => '(123) 456-7890',
                                'type' => 'Local',
                            ],
                        ],
                    ],
                    (object) [
                        'item' => [
                            (object) [
                                'client_id' => 8675309,
                                'client_name' => 'Tommy Tutone',
                                'phone' => '867-5309',
                                'type' => 'Global',
                            ],
                        ],
                    ]
                );

            $mock
                ->shouldReceive('__getLastResponse')
                ->twice()
                ->andReturn(
                    '<?xml version="1.0" encoding="UTF-8"?><SOAP-ENV:Envelope xmlns:SOAP-ENV="http://schemas.xmlsoap.org/soap/envelope/" xmlns:ns1="'.config('services.rdn.api_url').'"><SOAP-ENV:Header><ns1:pager><page>1</page><per_page>1</per_page><next_page>2</next_page><page_items>1</page_items><total_items>2</total_items><total_pages>2</total_pages></ns1:pager></SOAP-ENV:Header></SOAP-ENV:Envelope>',
                    '<?xml version="1.0" encoding="UTF-8"?><SOAP-ENV:Envelope xmlns:SOAP-ENV="http://schemas.xmlsoap.org/soap/envelope/" xmlns:ns1="'.config('services.rdn.api_url').'"><SOAP-ENV:Header><ns1:pager><page>2</page><per_page>1</per_page><next_page>2</next_page><page_items>1</page_items><total_items>2</total_items><total_pages>2</total_pages></ns1:pager></SOAP-ENV:Header></SOAP-ENV:Envelope>'
                );
        });

        $org = Org::factory()->create([
            'rdn_api_key' => fake()->uuid(),
        ]);

        $results = RDN::make(org: $org, client: $mockedSoapClient)->getClientList();

        $this->assertInstanceOf(LazyCollection::class, $results);

        $results = $results->toArray();
        $this->assertCount(2, $results);

        $this->assertInstanceOf(ClientDto::class, $results[0]);
        $this->assertEquals(123456, $results[0]->client_id);
        $this->assertEquals('My Awesome Name', $results[0]->client_name);
        $this->assertEquals('(123) 456-7890', $results[0]->phone);
        $this->assertEquals('Local', $results[0]->type);

        $this->assertInstanceOf(ClientDto::class, $results[1]);
        $this->assertEquals(8675309, $results[1]->client_id);
        $this->assertEquals('Tommy Tutone', $results[1]->client_name);
        $this->assertEquals('867-5309', $results[1]->phone);
        $this->assertEquals('Global', $results[1]->type);
    }

    #[Group('rdn')]
    #[Group('services')]
    public function test_logs_warning_when_api_key_not_set(): void
    {
        Log::shouldReceive('warning')
            ->once()
            ->withArgs(function ($message, $context) {
                return $message === 'RDN API Key not set'
                    && isset($context['org_id'])
                    && isset($context['org_name']);
            });

        $org = Org::factory()->create([
            'rdn_api_key' => null,
        ]);

        $service = RDN::make(org: $org);

        $this->assertNull($service->client);

        $results = $service->getClientList();
        $this->assertInstanceOf(LazyCollection::class, $results);
        $this->assertEmpty($results->toArray());
    }

    #[Group('rdn')]
    #[Group('services')]
    public function test_handles_invalid_soap_method_gracefully(): void
    {
        Log::shouldReceive('error')
            ->once()
            ->withArgs(function ($message, $context) {
                return isset($context['org_id']);
            });

        $mockedSoapClient = $this->partialMock(\SoapClient::class, function (MockInterface $mock) {
            $mock
                ->shouldReceive('__soapCall')
                ->once()
                ->andThrow(new \SoapFault('Client', 'Function ("nonExistentMethod") is not a valid method for this service'));
        });

        $org = Org::factory()->create([
            'rdn_api_key' => fake()->uuid(),
        ]);

        $service = RDN::make(org: $org, client: $mockedSoapClient);

        $results = $service->toLazyCollection('nonExistentMethod');

        $this->assertInstanceOf(LazyCollection::class, $results);
        $this->assertEmpty($results->toArray());
    }

    #[Group('rdn')]
    #[Group('services')]
    public function test_the_last_call_exception_is_kept_until_the_next_call(): void
    {
        $refusal = new \SoapFault('SOAP-ENV:Server', 'This API key is not authorized to access this case');
        $calls = 0;

        $mockedSoapClient = $this->partialMock(\SoapClient::class, function (MockInterface $mock) use ($refusal, &$calls) {
            $mock
                ->shouldReceive('__soapCall')
                ->twice()
                ->andReturnUsing(function () use ($refusal, &$calls) {
                    if (++$calls === 1) {
                        throw $refusal;
                    }

                    return null;
                });
        });

        $org = Org::factory()->create([
            'rdn_api_key' => 'fake-dev2934-key-never-printed',
        ]);

        $service = RDN::make(org: $org, client: $mockedSoapClient);

        $this->assertNull($service->lastCallException());

        $service->call('getRdnCaseInfo');

        $this->assertSame($refusal, $service->lastCallException());
        $this->assertFalse($service->lastCallCompleted());

        $service->call('getRdnCaseInfo');

        $this->assertNull($service->lastCallException());
        $this->assertTrue($service->lastCallCompleted());
    }

    #[Group('rdn')]
    #[Group('services')]
    public function test_an_over_limit_call_throws_instead_of_returning_false_out_of_an_array(): void
    {
        $org = Org::factory()->create([
            'rdn_api_key' => fake()->uuid(),
        ]);

        $org->meta()->create(['properties' => ['rdn_rate_limit' => 1]]);

        $mockedSoapClient = $this->partialMock(\SoapClient::class, function (MockInterface $mock) {
            $mock->shouldReceive('__soapCall')->never();
        });

        RateLimiter::clear('rdn-api:'.$org->id);
        RateLimiter::hit('rdn-api:'.$org->id);

        // RateLimiter::attempt() answers false when the bucket is full, and call() is typed
        // : array - returning that straight out is a TypeError in whatever job or request
        // happened to make the call. An empty result would be worse still: callers cannot
        // tell it apart from "RDN has no rows".
        $this->expectException(ThrottleRequestsException::class);

        RDN::make(org: $org->fresh(), client: $mockedSoapClient)->call('getRdnCaseInfo');
    }

    #[Group('rdn')]
    #[Group('services')]
    public function test_a_throttled_pull_does_not_masquerade_as_an_empty_client_list(): void
    {
        $org = Org::factory()->create([
            'rdn_api_key' => fake()->uuid(),
        ]);

        $org->meta()->create(['properties' => ['rdn_rate_limit' => 1]]);

        $mockedSoapClient = $this->partialMock(\SoapClient::class, function (MockInterface $mock) {
            $mock->shouldReceive('__soapCall')->never();
        });

        RateLimiter::clear('rdn-api:'.$org->id);
        RateLimiter::hit('rdn-api:'.$org->id);

        // Org::getRdnClients() caches whatever this returns until end of day, and
        // toLazyCollection() would treat an empty page as the end of the book.
        $this->expectException(ThrottleRequestsException::class);

        RDN::make(org: $org->fresh(), client: $mockedSoapClient)->getClientList()->all();
    }

    #[Group('rdn')]
    #[Group('services')]
    public function test_an_ungated_call_still_charges_the_shared_service_bucket(): void
    {
        $org = Org::factory()->create([
            'rdn_api_key' => fake()->uuid(),
        ]);

        $org->meta()->create(['properties' => ['rdn_rate_limit' => 10]]);

        $mockedSoapClient = $this->partialMock(\SoapClient::class, function (MockInterface $mock) {
            $mock->shouldReceive('__soapCall')->once()->andReturn(null);
        });

        RateLimiter::clear('rdn-api:'.$org->id);

        // Org::getRdnClients() reaches RDN this way - a cached web-request read with no
        // queue middleware to admit it - and is the only remaining ungated caller.
        RDN::make(org: $org->fresh(), client: $mockedSoapClient)->call('getBranches');

        $this->assertSame(1, RateLimiter::attempts('rdn-api:'.$org->id));
    }

    #[Group('rdn')]
    #[Group('services')]
    public function test_a_queue_gated_call_leaves_the_shared_service_bucket_alone(): void
    {
        $org = Org::factory()->create([
            'rdn_api_key' => fake()->uuid(),
        ]);

        $org->meta()->create(['properties' => ['rdn_rate_limit' => 10]]);

        $mockedSoapClient = $this->partialMock(\SoapClient::class, function (MockInterface $mock) {
            $mock->shouldReceive('__soapCall')->once()->andReturn(null);
        });

        RateLimiter::clear('rdn-api:'.$org->id);

        $service = RDN::make(org: $org->fresh(), client: $mockedSoapClient)->gatedByQueue();

        $this->assertTrue($service->queueGated);

        $service->call('getRdnCaseInfo');

        // Admission was already spent by the caller's RateLimited queue middleware, which
        // releases an over-limit job. Charging the same call here as well is a second
        // uncoordinated bucket of N/min against a single N/min vendor budget.
        $this->assertSame(0, RateLimiter::attempts('rdn-api:'.$org->id));
    }

    #[Group('rdn')]
    #[Group('services')]
    public function test_a_call_that_threw_reports_that_it_never_completed(): void
    {
        Log::shouldReceive('error')->once();

        $org = Org::factory()->create(['rdn_api_key' => fake()->uuid()]);

        $mockedSoapClient = $this->partialMock(\SoapClient::class, function (MockInterface $mock) {
            $mock->shouldReceive('__soapCall')->once()->andThrow(new \SoapFault('HTTP', 'Could not connect to host'));
        });

        $service = RDN::make(org: $org, client: $mockedSoapClient);

        // call() answers the same EMPTY_RESULT it answers for a genuinely empty response,
        // and it must keep doing so - toLazyCollection() and the four RdnJob siblings all
        // read it that way. Callers that cannot treat a blip as an answer ask separately.
        $this->assertSame(['items' => [], 'hasMore' => false, 'totalPages' => 0], $service->call('getRdnCaseInfo'));
        $this->assertFalse($service->lastCallCompleted());
    }

    #[Group('rdn')]
    #[Group('services')]
    public function test_a_call_that_returned_nothing_still_completed(): void
    {
        $org = Org::factory()->create(['rdn_api_key' => fake()->uuid()]);

        $mockedSoapClient = $this->partialMock(\SoapClient::class, function (MockInterface $mock) {
            $mock->shouldReceive('__soapCall')->once()->andReturn(null);
        });

        $service = RDN::make(org: $org, client: $mockedSoapClient);

        // RDN answered and had nothing to say. Empty is the answer, not the absence of one.
        $service->call('getRdnCaseInfo');

        $this->assertTrue($service->lastCallCompleted());
    }

    #[Group('rdn')]
    #[Group('services')]
    public function test_a_call_with_no_soap_client_never_completed(): void
    {
        // No api key, so __construct never built a client and __soapCall was never
        // reached. A WSDL fetch that fails - a rotated key answering 403 - lands here too.
        $org = Org::factory()->create(['rdn_api_key' => null]);

        $service = RDN::make(org: $org);

        $service->call('getRdnCaseInfo');

        $this->assertFalse($service->lastCallCompleted());
    }

    #[Group('rdn')]
    #[Group('services')]
    public function test_a_completed_call_does_not_stay_completed_for_the_next_one(): void
    {
        Log::shouldReceive('error')->once();

        $org = Org::factory()->create(['rdn_api_key' => fake()->uuid()]);

        $mockedSoapClient = $this->partialMock(\SoapClient::class, function (MockInterface $mock) {
            $mock
                ->shouldReceive('__soapCall')
                ->twice()
                ->andReturnUsing(
                    fn () => null,
                    fn () => throw new \SoapFault('HTTP', 'Error Fetching http headers'),
                );
        });

        $service = RDN::make(org: $org, client: $mockedSoapClient);

        $service->call('getRdnCaseInfo');
        $this->assertTrue($service->lastCallCompleted());

        $service->call('getRdnCaseInfo');
        $this->assertFalse($service->lastCallCompleted());
    }

    #[Group('rdn')]
    #[Group('services')]
    public function test_an_unchanged_rate_limit_header_does_not_touch_the_org_meta_blob(): void
    {
        // detectRateLimit() runs on page 1 of every completed call, and a case-info job is
        // exactly one page-1 call - so this fires once per hotlist, ~76k times a day, and
        // RDN reports the same limit every time. Eloquent's dirty check already suppresses
        // the UPDATE statement (verified: an identical properties array issues no SQL),
        // but the save still ran; this pins that it does not run at all.
        $org = Org::factory()->create(['rdn_api_key' => fake()->uuid()]);
        $org->meta()->create(['properties' => ['rdn_rate_limit' => 250, 'enable_client_portal' => true]]);

        Event::fake();

        RDN::make(org: $org, client: $this->mockRateLimitHeader(250))->call('getRdnCaseInfo');

        Event::assertNotDispatched('eloquent.saved: '.OrgMeta::class);
    }

    #[Group('rdn')]
    #[Group('services')]
    public function test_a_changed_rate_limit_header_is_written_once_and_leaves_its_neighbours_alone(): void
    {
        $org = Org::factory()->create(['rdn_api_key' => fake()->uuid()]);
        $org->meta()->create(['properties' => ['rdn_rate_limit' => 100, 'enable_client_portal' => true]]);

        $service = RDN::make(org: $org, client: $this->mockRateLimitHeader(250));

        DB::connection('zod')->flushQueryLog();
        DB::connection('zod')->enableQueryLog();

        $service->call('getRdnCaseInfo');
        $service->call('getRdnCaseInfo');

        $this->assertSame(1, $this->orgMetaUpdateCount());

        $properties = $org->fresh('meta')->meta->properties;

        $this->assertSame(250, $properties['rdn_rate_limit']);
        $this->assertTrue($properties['enable_client_portal']);
    }

    private function mockRateLimitHeader(int $limit): \SoapClient
    {
        return $this->partialMock(\SoapClient::class, function (MockInterface $mock) use ($limit) {
            $mock->shouldReceive('__soapCall')->andReturn((object) ['item' => []]);
            $mock->shouldReceive('__getLastResponseHeaders')
                ->andReturn("HTTP/1.1 200 OK\r\nX-RateLimit-Limit: {$limit}\r\n");
            $mock->shouldReceive('__getLastResponse')->andReturn('');
        });
    }

    private function orgMetaUpdateCount(): int
    {
        $count = collect(DB::connection('zod')->getQueryLog())
            ->filter(fn (array $query): bool => str_contains($query['query'], 'update "org_meta"'))
            ->count();

        DB::connection('zod')->disableQueryLog();

        return $count;
    }
}
