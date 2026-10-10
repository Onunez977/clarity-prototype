<?php

namespace App\Filament\Resources\UsersResource\Pages;

use App\Facades\Analytics;
use App\Filament\Resources\UsersResource;
use App\Support\Scout\ScoutUserProvisioner;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Hash;

class CreateUsers extends CreateRecord
{
    protected static string $resource = UsersResource::class;

    /**
     * @var array<string, mixed>
     */
    protected array $scoutProvisioningData = [];

    public function mount(): void
    {
        parent::mount();

        Analytics::track('page-view', auth()->user(), ['action' => 'view'], page: 'create-user');
    }

    /**
     * set the organization id to the current user's organization id
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['org_id'] = auth()->user()->org_id;
        $data['password'] = Hash::make(str()->random(18));

        $this->scoutProvisioningData = ScoutUserProvisioner::extract($data);

        return $data;
    }

    protected function afterCreate(): void
    {
        ScoutUserProvisioner::reconcile($this->record, $this->scoutProvisioningData);
    }
}
