<?php

namespace App\Http\Responses\Auth;

use App\Filament\Client\Resources\ClientSearchScansResource;
use App\Filament\Pages\Dashboard;
use Filament\Facades\Filament;
use Illuminate\Http\RedirectResponse;
use Livewire\Features\SupportRedirects\Redirector;

class LoginResponse extends \Filament\Auth\Http\Responses\LoginResponse
{
    public function toResponse($request): RedirectResponse|Redirector
    {
        if (Filament::auth()->user()->canAccessPanel(Filament::getPanel('client'))) {
            return redirect()->intended(ClientSearchScansResource::getUrl(panel: 'client'));
        }

        return redirect()->to(Dashboard::getUrl(panel: 'app'));
    }
}
