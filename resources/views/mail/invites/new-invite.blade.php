@component('mail::message')

# Hi {{ $greetingName }},

You have been invited by {{ $invite->user->name ?? $invite->user->email }} to "{{ $invite->type }}."

@if (\array_key_exists('code', $invite->meta))
Please use the following code to confirm the invitation:

<div style="margin-bottom: 10px" class="otp_code">{{ $invite->meta['code'] }}</div>
@endif

@component('mail::button', ['url' => $redeemUrl])
{{ $isClientScans ? 'Redeem Invite' : 'View Invite' }}
@endcomponent


Thanks,<br>
Insight LPR Team
@endcomponent
