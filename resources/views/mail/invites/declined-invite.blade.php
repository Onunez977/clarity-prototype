@component('mail::message')

# Hi {{ $invite->user->name ?? $invite->user->email }},

{{ $invite->invitable->name ?? $invite->invitable->email }} has declined your invite to "{{ $invite->type }}."

Thanks,<br>
Insight LPR Team
@endcomponent
