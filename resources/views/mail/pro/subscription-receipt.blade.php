<x-mail::message>
@if ($changeType === 'upgrade')
# Your Plan Has Been Upgraded

Hi {{ $user->name }},

Your subscription has been upgraded. Here are the details of your plan change:

<x-mail::panel>
**Previous Plan:** {{ $previousTierLabel }}

**New Plan:** {{ $newTierLabel }}

**Price:** ${{ number_format($newPrice, 2) }}/mo

**Effective:** Immediately
</x-mail::panel>

Your new features are available now.
@elseif ($changeType === 'cancellation')
# Your Downgrade Has Been Cancelled

Hi {{ $user->name }},

Your pending downgrade has been cancelled. Your current plan will remain active.

<x-mail::panel>
**Current Plan:** {{ $previousTierLabel }}

**Price:** ${{ number_format($previousPrice, 2) }}/mo

**Cancelled Target Plan:** {{ $newTierLabel }}
</x-mail::panel>

Your {{ $previousTierLabel }} features will continue without interruption.
@else
# Your Plan Change Has Been Confirmed

Hi {{ $user->name }},

Your plan change has been confirmed. Here are the details:

<x-mail::panel>
**Current Plan:** {{ $previousTierLabel }}

**New Plan:** {{ $newTierLabel }}

**New Price:** ${{ number_format($newPrice, 2) }}/mo

**Effective Date:** {{ $effectiveDate?->format('F j, Y') ?? 'End of billing cycle' }}
</x-mail::panel>

Your current {{ $previousTierLabel }} features will remain active until the effective date above.
@endif

If you have any questions, please contact our sales team at {{ config('app.pro.sales_email') }}.

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
