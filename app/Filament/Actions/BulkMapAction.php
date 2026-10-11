<?php

namespace App\Filament\Actions;

use Closure;
use Filament\Actions\BulkAction;
use Illuminate\Contracts\Support\Htmlable;

class BulkMapAction extends BulkAction
{
    protected string|Htmlable|Closure|null $label = 'Map';

    protected string|Closure|Htmlable|null|false|\BackedEnum $icon = 'heroicon-m-map';

    protected string|Closure|Htmlable|null $tooltip = 'Display selected rows as pins on a map';

    protected bool|Closure $shouldDeselectRecordsAfterCompletion = false;

    public string|Closure|null $format = null;

    public ?string $relationship = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->hidden();

        $this->alpineClickHandler('$dispatch("load-records-for-map", { recordIds: entangledSelectedRecords })');
    }

    public function setRelationship(?string $relationship = null): self
    {
        $this->relationship = $relationship;

        return $this;
    }
}
