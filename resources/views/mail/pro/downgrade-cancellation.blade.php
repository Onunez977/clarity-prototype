<x-mail::message>
# Downgrade Cancellation

**{{ $user->name }}** ({{ $user->email }}) from **{{ $user->org->name }}** has cancelled their pending downgrade from {{ \App\Enums\ProTierEnum::tryFrom($currentTier)?->label() ?? $currentTier }} to {{ \App\Enums\ProTierEnum::tryFrom($cancelledTargetTier)?->label() ?? $cancelledTargetTier }}.

<x-mail::panel>
**Current Plan:** {{ \App\Enums\ProTierEnum::tryFrom($currentTier)?->label() ?? $currentTier }}

**Cancelled Target Plan:** {{ \App\Enums\ProTierEnum::tryFrom($cancelledTargetTier)?->label() ?? $cancelledTargetTier }}

**Organization:** {{ $user->org->name }}

**User:** {{ $user->name }} ({{ $user->email }})
</x-mail::panel>

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
