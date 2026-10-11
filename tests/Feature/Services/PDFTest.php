<?php

namespace Tests\Feature\Services;

use App\Services\PDF;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

class PDFTest extends TestCase
{
    #[Group('pdf')]
    #[Group('services')]
    public function test_can_generate_pdf_url(): void
    {
        Http::fake([
            'https://v2.convertapi.com/convert/html/to/pdf*' => Http::response([
                'Files' => [
                    [
                        'Url' => 'https://example.com/generated.pdf',
                    ],
                ],
            ]),
        ]);

        $url = PDF::stream(
            view: 'pdf.missed-opportunities',
            name: 'Test Document',
        );

        $this->assertEquals('https://example.com/generated.pdf', $url);
    }

    #[Group('pdf')]
    #[Group('services')]
    public function test_throws_error_on_failed_pdf_generation(): void
    {
        Http::fake([
            'https://v2.convertapi.com/convert/html/to/pdf*' => Http::response('Testing Error', status: 500),
        ]);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Testing Error');

        PDF::stream(
            view: 'pdf.missed-opportunities',
            name: 'Test Document',
        );
    }
}
