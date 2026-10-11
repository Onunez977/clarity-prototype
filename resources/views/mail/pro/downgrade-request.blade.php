<x-mail::message>
# Downgrade Request

**{{ $user->name }}** ({{ $user->email }}) from **{{ $user->org->name }}** has requested a downgrade from {{ \App\Enums\ProTierEnum::tryFrom($currentTier)?->label() ?? $currentTier }} to {{ \App\Enums\ProTierEnum::tryFrom($targetTier)?->label() ?? $targetTier }}.

<x-mail::panel>
**Current Plan:** {{ \App\Enums\ProTierEnum::tryFrom($currentTier)?->label() ?? $currentTier }}

**Target Plan:** {{ \App\Enums\ProTierEnum::tryFrom($targetTier)?->label() ?? $targetTier }}

**Organization:** {{ $user->org->name }}

**User:** {{ $user->name }} ({{ $user->email }})
</x-mail::panel>

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
