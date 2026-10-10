<x-mail::message>
# Upgrade Request

**{{ $user->name }}** ({{ $user->email }}) from **{{ $user->org->name }}** has requested an upgrade to {{ \App\Enums\ProTierEnum::tryFrom($targetTier)?->label() ?? $targetTier }}.

<x-mail::panel>
**Plan:** {{ \App\Enums\ProTierEnum::tryFrom($targetTier)?->label() ?? $targetTier }}

**Price:** ${{ number_format($price, 2) }}/mo

**Organization:** {{ $user->org->name }}
</x-mail::panel>

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
