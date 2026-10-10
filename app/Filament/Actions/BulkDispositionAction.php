<?php

namespace App\Filament\Actions;

use App\Clickhouse\Models\ClickhouseModel;
use App\Enums\DispositionEnum;
use App\Models\Scan;
use Closure;
use Filament\Actions\BulkAction;
use Filament\Forms;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\HtmlString;

class BulkDispositionAction extends BulkAction
{
    protected string|Htmlable|Closure|null $label = 'Bulk Disposition';

    protected Closure|string|Htmlable|null|false|\BackedEnum $icon = 'heroicon-m-viewfinder-circle';

    protected bool|Closure $shouldDeselectRecordsAfterCompletion = true;

    public bool $missedOpportunity = false;

    public ?string $relationship = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->modalDescription(fn (Collection $records) => 'Updating '.($records?->count() ?? 0).' Scans');

        $this->form([
            Forms\Components\Select::make('disposition')
                ->label('Disposition')
                ->required()
                ->live()
                ->placeholder(fn () => $this->missedOpportunity ? 'Awaiting Validation' : 'Select a disposition')
                ->options(fn () => DispositionEnum::formOptions(missedOpportunity: $this->missedOpportunity)),

            Section::make()
                ->heading('WARNING')
                ->description(new HtmlString('Marking this as "invalid" will no longer generate historical hits.<br/><strong>What was the reason for marking this invalid?</strong><br/>Please select an option below to continue.'))
                ->visible(fn (Get $get) => $get('disposition') === 'Invalid Scan')
                ->schema([
                    Forms\Components\ToggleButtons::make('reason')
                        ->hiddenLabel()
                        ->inline()
                        ->required(fn (Get $get) => $get('disposition') === 'Invalid Scan')
                        ->options([
                            'Not a Plate' => 'Not a Plate',
                            'Plate Too Blurry' => 'Plate Too Blurry',
                            'Wrong OCR' => 'Wrong OCR',
                            'Wrong Plate State' => 'Wrong Plate State',
                            'Other' => 'Other',
                        ]),
                ]),
        ]);

        $this->action(function (Collection $records, array $data): void {
            $records->each(function ($record) use ($data) {
                if ($this->relationship) {
                    if ($record instanceof ClickhouseModel) {
                        $record = Scan::find($record->scan_id);
                    } else {
                        $record = $record->{$this->relationship};
                    }
                }

                $record?->updateDisposition(
                    disposition: $data['disposition'],
                    user: auth()->user(),
                    reason: isset($data['reason'])
                        ? \strtolower($data['reason'])
                        : null,
                );
            });
        });
    }

    public function setMissedOpportunity(bool $missedOpportunity): static
    {
        $this->missedOpportunity = $missedOpportunity;

        return $this;
    }

    public function setRelationship(?string $relationship = null): self
    {
        $this->relationship = $relationship;

        return $this;
    }
}
