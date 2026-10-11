<?php

namespace App\Filament\Resources\UsersResource\Pages;

use App\Facades\Analytics;
use App\Filament\Resources\UsersResource;
use App\Support\Scout\ScoutUserProvisioner;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Contracts\Support\Htmlable;

class EditUsers extends EditRecord
{
    protected static string $resource = UsersResource::class;

    /**
     * @var array<string, mixed>
     */
    protected array $scoutProvisioningData = [];

    public function mount(int|string $record): void
    {
        parent::mount($record);

        Analytics::track('page-view', auth()->user(), ['action' => 'view'], page: 'edit-user');
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make()->label('Disable User'),
        ];
    }

    public function getTitle(): string|Htmlable
    {
        return 'Edit: '.$this->record->email;
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $this->scoutProvisioningData = ScoutUserProvisioner::extract($data);

        return $data;
    }

    protected function afterSave(): void
    {
        ScoutUserProvisioner::reconcile($this->record, $this->scoutProvisioningData);
    }
}
