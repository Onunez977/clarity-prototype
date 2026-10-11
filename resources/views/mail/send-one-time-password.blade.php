@component('mail::message')

# Hi {{ $user->name ?? $user->email }},

Please use the following one-time password to login to your account on the InsightLPR app.

<div style="margin-bottom: 10px" class="otp_code">{{ $otp }}</div>


Thanks,<br>
Insight LPR Team
@endcomponent
