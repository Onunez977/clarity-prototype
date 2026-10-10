@component('mail::message')

# Hi {{ $invite->user->name ?? $invite->user->email }},

{{ $invite->invitable->name ?? $invite->invitable->email }} has accepted your invite to "{{ $invite->type }}."

To revoke access at anytime, you can delete the invite.

@component('mail::button', ['url' => \App\Filament\Resources\InviteResource\Pages\ListInvites::getUrl().'?tableFilters[filters][direction]=sent'])
View All Invites
@endcomponent

Thanks,<br>
Insight LPR Team
@endcomponent
