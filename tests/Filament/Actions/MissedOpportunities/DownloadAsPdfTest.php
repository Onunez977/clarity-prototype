<?php

namespace Tests\Filament\Actions\MissedOpportunities;

use App\Filament\Resources\MissedOpportunityResource\Actions\DownloadAsPdf;
use App\Models\MissedOpportunity;
use Filament\Resources\Pages\ListRecords;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Tests\TestCase;

class DownloadAsPdfTest extends TestCase
{
    #[Group('actions')]
    public function test_can_render(): void
    {
        $action = DownloadAsPdf::make();

        $this->assertEquals('Download as PDF', $action->getLabel());
        $this->assertEquals('download-as-pdf', $action->getName());
    }

    #[Group('actions')]
    public function test_can_download(): void
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

        $action = DownloadAsPdf::make()
            ->table(
                (new class extends ListRecords
                {
                    public function __construct()
                    {
                        // Force create dummy table.
                        $this->table = Table::make($this)
                            ->query(MissedOpportunity::query());
                    }
                })->getTable(),
            );

        $result = $this->evaluate($action->getActionFunction(), [
            'records' => [],
        ]);

        $this->assertInstanceOf(StreamedResponse::class, $result);
        $this->assertTrue($result->isSuccessful());
        $this->assertEquals('application/pdf', $result->headers->get('Content-Type'));
    }

    #[Group('actions')]
    public function test_send_error_notification_on_failure(): void
    {
        Http::fake([
            'https://v2.convertapi.com/convert/html/to/pdf*' => Http::response(status: 500),
        ]);

        $action = DownloadAsPdf::make()
            ->table(
                (new class extends ListRecords
                {
                    public function __construct()
                    {
                        // Force create dummy table.
                        $this->table = Table::make($this)
                            ->query(MissedOpportunity::query());
                    }
                })->getTable(),
            );

        $result = $this->evaluate($action->getActionFunction(), [
            'records' => [],
        ]);

        $this->assertNull($result);
    }
}
