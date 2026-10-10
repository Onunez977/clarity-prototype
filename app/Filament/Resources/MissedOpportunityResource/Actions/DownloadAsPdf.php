<?php

namespace App\Filament\Resources\MissedOpportunityResource\Actions;

use App\Facades\Analytics;
use App\Services\PDF;
use Closure;
use Filament\Actions\BulkAction;
use Filament\Notifications;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Str;

class DownloadAsPdf extends BulkAction
{
    protected ?string $name = 'download-as-pdf';

    protected string|Closure|Htmlable|null $label = 'Download as PDF';

    protected Closure|bool $shouldDeselectRecordsAfterCompletion = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->outlined();

        $this->extraAttributes(trackAttr('missed-opportunity', 'download-pdf'));

        $this->action = function ($records) {
            Analytics::track('export', auth()->user(), ['action' => 'export'], 'missed-opportunities');

            $name = Str::of($this->getTable()?->getModelLabel() ?? 'Download')
                ->plural()
                ->headline()
                ->toString();

            try {
                $url = PDF::stream(
                    view: 'pdf.'.Str::kebab($name),
                    name: $name,
                    data: [
                        'records' => $records ?? [],
                    ],
                    timezone: auth()->user()?->timezone ?? 'UTC'
                );

                // Download the PDF file
                return response()->stream(
                    function () use ($url) {
                        echo \file_get_contents($url);
                    },
                    200,
                    [
                        'Content-Type' => 'application/pdf',
                        'Content-Disposition' => 'attachment; filename="'.$name.'.pdf"',
                    ]
                );
            } catch (\Exception $e) {
                Notifications\Notification::make()
                    ->title('Unable to generate PDF')
                    ->warning()
                    ->send();
            }

            return null;
        };
    }

    public static function getDefaultName(): ?string
    {
        return 'download-as-pdf';
    }
}
