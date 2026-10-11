<x-filament-panels::page
    x-init="$store.sidebar.close()"
    x-data="{
        targetTier: null,
        targetTierLabel: '',
        targetTierPrice: 0,
    }"
>
    <div class="max-w-6xl mx-auto">
        <div class="text-center mb-8">
            <h1 class="text-3xl font-bold text-gray-900 dark:text-white">
                Manage your Clarity Subscription
            </h1>
            <p class="mt-2 text-gray-500 dark:text-gray-400">
                You're currently on the <strong>{{ $currentTier->label() }}</strong> plan
            </p>
        </div>

        @if ($sourceFeatureTitle)
            <div class="rounded-xl border p-4 mb-6" style="border-color: #FF6666; background-color: rgba(255, 102, 102, 0.06);">
                <div class="flex items-start gap-3">
                    <div>
                        <p class="font-semibold text-gray-900 dark:text-white">
                            {{ $sourceFeatureTitle }}
                        </p>
                        <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">
                            {{ $sourceFeatureDescription }}
                        </p>
                        @if ($sourceFeatureTier)
                            <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">
                                Available on the <strong class="text-gray-900 dark:text-white">{{ $sourceFeatureTier }}</strong> plan.
                            </p>
                        @endif
                    </div>
                </div>
            </div>
        @endif

        @if ($pendingDowngradeTier)
            @php
                $pendingTierLabel = \App\Enums\ProTierEnum::tryFrom($pendingDowngradeTier)?->label() ?? $pendingDowngradeTier;
            @endphp
            <div class="rounded-xl border border-amber-300 dark:border-amber-600 bg-amber-50 dark:bg-amber-900/20 p-4 mb-6">
                <div class="flex items-center justify-between">
                    <div class="flex items-center gap-3">
                        <x-heroicon-o-clock class="h-5 w-5 text-amber-500 flex-shrink-0" />
                        <p class="text-sm text-amber-800 dark:text-amber-200">
                            Your plan will change to <strong>{{ $pendingTierLabel }}</strong> on <strong>{{ $pendingDowngradeDate }}</strong>.
                            You'll keep your current features until then.
                        </p>
                    </div>
                    @if (auth()->user()->hasRole(\InsightLPR\BlackAdam\Enum\RoleEnum::ADMIN->value))
                        <button
                            type="button"
                            wire:click="cancelDowngrade"
                            wire:loading.attr="disabled"
                            class="ml-4 flex-shrink-0 rounded-lg border px-3 py-1.5 text-xs font-semibold transition-colors duration-150 border-amber-400 dark:border-amber-600 bg-white dark:bg-amber-900/40 hover:bg-amber-100 dark:hover:bg-amber-900/60 text-amber-700 dark:text-amber-300"
                        >
                            <span wire:loading.remove wire:target="cancelDowngrade">Cancel Downgrade</span>
                            <span wire:loading wire:target="cancelDowngrade">Cancelling...</span>
                        </button>
                    @endif
                </div>
            </div>
        @endif

        @if ($currentTier === \App\Enums\ProTierEnum::CLARITY_PRO_PLUS)
            <div class="rounded-xl border p-4 mb-6" style="border-color: #FF6666; background-color: rgba(255, 102, 102, 0.06);">
                <div class="flex items-center gap-3">
                    <x-heroicon-o-information-circle class="h-5 w-5 flex-shrink-0" style="color: #FF6666;" />
                    <p class="text-sm text-gray-700 dark:text-gray-300">
                        You're on <strong class="text-gray-900 dark:text-white">Clarity Pro Plus</strong>. To make changes to your plan, contact your InsightLPR account manager.
                    </p>
                </div>
            </div>
        @endif

        <div class="grid grid-cols-1 {{ $currentTier === \App\Enums\ProTierEnum::CLARITY_PRO_PLUS ? 'md:grid-cols-2 xl:grid-cols-4' : 'md:grid-cols-3' }} gap-6">
            {{-- Basic --}}
            <div
                class="relative rounded-2xl shadow-sm bg-white dark:bg-gray-900 p-8"
                style="border: 2px solid {{ $currentTier === \App\Enums\ProTierEnum::BASIC ? '#FF6666' : 'rgb(229, 231, 235)' }};"
            >
                @if ($currentTier === \App\Enums\ProTierEnum::BASIC)
                    <div class="absolute -top-3 left-1/2 -translate-x-1/2">
                        <span class="inline-flex items-center rounded-full px-3 py-1 text-xs font-bold text-white" style="background-color: #FF6666;">
                            CURRENT PLAN
                        </span>
                    </div>
                @endif

                <div class="mb-6">
                    <h3 class="text-lg font-semibold text-gray-900 dark:text-white">Basic</h3>
                    <div class="mt-4 flex items-baseline gap-1">
                        <span class="text-4xl font-bold text-gray-900 dark:text-white">$0</span>
                        <span class="text-gray-500 dark:text-gray-400">/mo</span>
                    </div>
                </div>

                @if ($currentTier === \App\Enums\ProTierEnum::BASIC)
                    <button
                        type="button"
                        disabled
                        class="w-full rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 px-4 py-2.5 text-sm font-semibold text-gray-400 dark:text-gray-500 cursor-not-allowed"
                    >
                        Current Plan
                    </button>
                @elseif ($currentTier === \App\Enums\ProTierEnum::CLARITY_PRO_PLUS)
                    <button
                        type="button"
                        disabled
                        class="w-full rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 px-4 py-2.5 text-sm font-semibold text-gray-400 dark:text-gray-500 cursor-not-allowed"
                    >
                        Contact your account manager
                    </button>
                @elseif (auth()->user()->hasRole(\InsightLPR\BlackAdam\Enum\RoleEnum::ADMIN->value) && $pendingDowngradeTier === 'basic')
                    <button
                        type="button"
                        disabled
                        class="w-full rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 px-4 py-2.5 text-sm font-semibold text-gray-400 dark:text-gray-500 cursor-not-allowed"
                    >
                        Downgrade Scheduled
                    </button>
                @elseif (auth()->user()->hasRole(\InsightLPR\BlackAdam\Enum\RoleEnum::ADMIN->value))
                    <button
                        type="button"
                        @click="
                            targetTier = 'basic';
                            targetTierLabel = 'Basic';
                            targetTierPrice = 0;
                            $dispatch('open-modal', { id: 'downgrade-modal' });
                        "
                        class="w-full rounded-lg border border-gray-300 dark:border-gray-600 px-4 py-2.5 text-sm font-semibold transition-colors duration-150 bg-transparent hover:bg-gray-100 dark:hover:bg-gray-800 text-gray-600 dark:text-gray-400 focus:outline-none focus:ring-2 focus:ring-gray-500 focus:ring-offset-2"
                    >
                        Downgrade to Basic
                    </button>
                @else
                    <button
                        type="button"
                        disabled
                        class="w-full rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 px-4 py-2.5 text-sm font-semibold text-gray-400 dark:text-gray-500 cursor-not-allowed"
                    >
                        Contact your admin to manage plan
                    </button>
                @endif

                <hr class="my-6 border-gray-200 dark:border-gray-700" />

                <p class="text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide mb-3">
                    Included Features:
                </p>

                <ul class="space-y-3">
                    @foreach ([
                        'License Plate Recognition',
                        'In Car Alerting',
                        'Direct Hotlist Management',
                        'National Staging Hotlist ~400k open orders',
                        'RDN/ClearPlan integration',
                        'Unrestricted Investigative Search Tools',
                        'Performance Reporting',
                        'Kit User Path',
                        'Custom Roles',
                        'Audit log',
                    ] as $feature)
                        <li class="flex items-start gap-3">
                            <x-heroicon-s-check class="h-5 w-5 text-gray-400 dark:text-gray-500 flex-shrink-0 mt-0.5" />
                            <span class="text-sm text-gray-600 dark:text-gray-400">{{ $feature }}</span>
                        </li>
                    @endforeach
                </ul>
            </div>

            {{-- Pro Lite --}}
            <div
                class="relative rounded-2xl shadow-sm bg-white dark:bg-gray-900 p-8"
                style="border: 2px solid {{ $currentTier === \App\Enums\ProTierEnum::PRO_LITE ? '#FF6666' : 'rgb(229, 231, 235)' }};"
            >
                @if ($currentTier === \App\Enums\ProTierEnum::PRO_LITE)
                    <div class="absolute -top-3 left-1/2 -translate-x-1/2">
                        <span class="inline-flex items-center rounded-full px-3 py-1 text-xs font-bold text-white" style="background-color: #FF6666;">
                            CURRENT PLAN
                        </span>
                    </div>
                @endif

                <div class="mb-6">
                    <h3 class="text-lg font-semibold text-gray-900 dark:text-white">Pro Lite</h3>
                    <div class="mt-4 flex items-baseline gap-1">
                        <span class="text-4xl font-bold text-gray-900 dark:text-white">$249</span>
                        <span class="text-gray-500 dark:text-gray-400">/mo</span>
                    </div>
                </div>

                @if ($currentTier === \App\Enums\ProTierEnum::PRO_LITE)
                    <button
                        type="button"
                        disabled
                        class="w-full rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 px-4 py-2.5 text-sm font-semibold text-gray-400 dark:text-gray-500 cursor-not-allowed"
                    >
                        Current Plan
                    </button>
                @elseif ($currentTier === \App\Enums\ProTierEnum::CLARITY_PRO_PLUS)
                    <button
                        type="button"
                        disabled
                        class="w-full rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 px-4 py-2.5 text-sm font-semibold text-gray-400 dark:text-gray-500 cursor-not-allowed"
                    >
                        Contact your account manager
                    </button>
                @elseif (! auth()->user()->hasRole(\InsightLPR\BlackAdam\Enum\RoleEnum::ADMIN->value))
                    <button
                        type="button"
                        disabled
                        class="w-full rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 px-4 py-2.5 text-sm font-semibold text-gray-400 dark:text-gray-500 cursor-not-allowed"
                    >
                        Contact your admin to manage plan
                    </button>
                @elseif ($currentTier->price() < \App\Enums\ProTierEnum::PRO_LITE->price())
                    <button
                        type="button"
                        @click="
                            targetTier = 'pro_lite';
                            targetTierLabel = 'Pro Lite';
                            targetTierPrice = 249;
                            $dispatch('open-modal', { id: 'upgrade-modal' });
                        "
                        class="w-full rounded-lg px-4 py-2.5 text-sm font-semibold text-white transition-colors duration-150 focus:outline-none focus:ring-2 focus:ring-offset-2"
                        style="background-color: #FF6666;"
                        onmouseover="this.style.opacity='0.9'"
                        onmouseout="this.style.opacity='1'"
                    >
                        Upgrade to Pro Lite
                    </button>
                @elseif ($pendingDowngradeTier === 'pro_lite')
                    <button
                        type="button"
                        disabled
                        class="w-full rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 px-4 py-2.5 text-sm font-semibold text-gray-400 dark:text-gray-500 cursor-not-allowed"
                    >
                        Downgrade Scheduled
                    </button>
                @else
                    <button
                        type="button"
                        @click="
                            targetTier = 'pro_lite';
                            targetTierLabel = 'Pro Lite';
                            targetTierPrice = 249;
                            $dispatch('open-modal', { id: 'downgrade-modal' });
                        "
                        class="w-full rounded-lg border border-gray-300 dark:border-gray-600 px-4 py-2.5 text-sm font-semibold transition-colors duration-150 bg-transparent hover:bg-gray-100 dark:hover:bg-gray-800 text-gray-600 dark:text-gray-400 focus:outline-none focus:ring-2 focus:ring-gray-500 focus:ring-offset-2"
                    >
                        Downgrade to Pro Lite
                    </button>
                @endif

                <hr class="my-6 border-gray-200 dark:border-gray-700" />

                <p class="text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide mb-3">
                    Everything in Basic plan, plus:
                </p>

                <ul class="space-y-3">
                    @foreach ([
                        'Flex Search',
                        'Location clusters',
                        'Heatmap',
                        'LPR Visits',
                        'Advanced Plate State recognition',
                        'Advanced Kit User Path',
                    ] as $feature)
                        <li class="flex items-start gap-3">
                            <x-heroicon-s-check class="h-5 w-5 flex-shrink-0 mt-0.5" style="color: #FF6666;" />
                            <span class="text-sm text-gray-600 dark:text-gray-300">{{ $feature }}</span>
                        </li>
                    @endforeach
                </ul>
            </div>

            {{-- Clarity Pro --}}
            <div
                class="relative rounded-2xl shadow-lg bg-white dark:bg-gray-900 p-8"
                style="border: 2px solid {{ $currentTier === \App\Enums\ProTierEnum::CLARITY_PRO ? '#FF6666' : 'rgb(229, 231, 235)' }};"
            >
                @if ($currentTier === \App\Enums\ProTierEnum::CLARITY_PRO)
                    <div class="absolute -top-3 left-1/2 -translate-x-1/2">
                        <span class="inline-flex items-center rounded-full px-3 py-1 text-xs font-bold text-white" style="background-color: #FF6666;">
                            CURRENT {{ now()->isBefore(\Illuminate\Support\Carbon::parse('2026-04-01')) ? 'TRIAL ' : '' }}PLAN
                        </span>
                    </div>
                @endif

                <div class="mb-6">
                    <h3 class="text-lg font-semibold text-gray-900 dark:text-white">Clarity Pro</h3>
                    <div class="mt-4 flex items-baseline gap-1">
                        <span class="text-4xl font-bold text-gray-900 dark:text-white">$599</span>
                        <span class="text-gray-500 dark:text-gray-400">/mo</span>
                    </div>
                </div>

                @if ($currentTier === \App\Enums\ProTierEnum::CLARITY_PRO && now()->isAfter(\Illuminate\Support\Carbon::parse('2026-04-01')))
                    <button
                        type="button"
                        disabled
                        class="w-full rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 px-4 py-2.5 text-sm font-semibold text-gray-400 dark:text-gray-500 cursor-not-allowed"
                    >
                        Current Plan
                    </button>
                @elseif ($currentTier === \App\Enums\ProTierEnum::CLARITY_PRO_PLUS)
                    <button
                        type="button"
                        disabled
                        class="w-full rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 px-4 py-2.5 text-sm font-semibold text-gray-400 dark:text-gray-500 cursor-not-allowed"
                    >
                        Contact your account manager
                    </button>
                @elseif (! auth()->user()->hasRole(\InsightLPR\BlackAdam\Enum\RoleEnum::ADMIN->value))
                    <button
                        type="button"
                        disabled
                        class="w-full rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 px-4 py-2.5 text-sm font-semibold text-gray-400 dark:text-gray-500 cursor-not-allowed"
                    >
                        Contact your admin to manage plan
                    </button>
                @else
                    <button
                        type="button"
                        @click="
                            targetTier = 'clarity_pro';
                            targetTierLabel = 'Clarity Pro';
                            targetTierPrice = 599;
                            $dispatch('open-modal', { id: 'upgrade-modal' });
                        "
                        class="w-full rounded-lg px-4 py-2.5 text-sm font-semibold text-white transition-colors duration-150 focus:outline-none focus:ring-2 focus:ring-offset-2"
                        style="background-color: #FF6666;"
                        onmouseover="this.style.opacity='0.9'"
                        onmouseout="this.style.opacity='1'"
                    >
                        Upgrade to Clarity Pro
                    </button>
                @endif

                <hr class="my-6 border-gray-200 dark:border-gray-700" />

                <p class="text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide mb-3">
                    Everything in Basic plan, Pro Lite, plus:
                </p>

                <ul class="space-y-3">
                    @foreach ([
                        'Client Analytics',
                        'Missed Opportunities',
                        'Custom Alert settings',
                        'Resend Hits to Clearplan',
                        'RDN Reporting Integration',
                        'Optimized Gridding (Scout)',
                    ] as $feature)
                        <li class="flex items-start gap-3">
                            <x-heroicon-s-check class="h-5 w-5 flex-shrink-0 mt-0.5" style="color: #FF6666;" />
                            <span class="text-sm text-gray-600 dark:text-gray-300">{{ $feature }}</span>
                        </li>
                    @endforeach
                </ul>

                <hr class="my-6 border-gray-200 dark:border-gray-700" />

                <p class="text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide mb-3">
                    Coming Soon
                </p>

                <ul class="space-y-3">
                    @foreach ([
                        'Customized Hit Generation rules',
                        'Automated updates to RDN orders',
                        'Lender Notifications',
                        'Advanced LPR Analytics',
                    ] as $feature)
                        <li class="flex items-start gap-3">
                            <x-heroicon-s-check class="h-5 w-5 text-gray-300 dark:text-gray-600 flex-shrink-0 mt-0.5" />
                            <span class="text-sm text-gray-400 dark:text-gray-500 italic">{{ $feature }}</span>
                        </li>
                    @endforeach
                </ul>
            </div>

            {{-- Clarity Pro Plus (visible only to orgs already on this tier) --}}
            @if ($currentTier === \App\Enums\ProTierEnum::CLARITY_PRO_PLUS)
            <div
                class="relative rounded-2xl shadow-lg bg-white dark:bg-gray-900 p-8"
                style="border: 2px solid #FF6666;"
            >
                <div class="absolute -top-3 left-1/2 -translate-x-1/2">
                    <span class="inline-flex items-center rounded-full px-3 py-1 text-xs font-bold text-white" style="background-color: #FF6666;">
                        CURRENT PLAN
                    </span>
                </div>

                <div class="mb-6">
                    <h3 class="text-lg font-semibold text-gray-900 dark:text-white">Clarity Pro Plus</h3>
                    <div class="mt-4 flex items-baseline gap-1">
                        <span class="text-4xl font-bold text-gray-900 dark:text-white">$1,199</span>
                        <span class="text-gray-500 dark:text-gray-400">/mo</span>
                    </div>
                </div>

                <button
                    type="button"
                    disabled
                    class="w-full rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 px-4 py-2.5 text-sm font-semibold text-gray-400 dark:text-gray-500 cursor-not-allowed"
                >
                    Contact your account manager
                </button>

                <hr class="my-6 border-gray-200 dark:border-gray-700" />

                <p class="text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide mb-3">
                    Everything in Clarity Pro, plus:
                </p>

                <ul class="space-y-3">
                    <li class="flex items-start gap-3">
                        <x-heroicon-s-check class="h-5 w-5 text-gray-300 dark:text-gray-600 flex-shrink-0 mt-0.5" />
                        <span class="text-sm text-gray-400 dark:text-gray-500 italic">Additional features coming soon</span>
                    </li>
                </ul>
            </div>
            @endif
        </div>

        @if ($currentTier !== \App\Enums\ProTierEnum::BASIC)
            <div class="mt-8 rounded-2xl shadow-sm bg-white dark:bg-gray-900 p-6 border border-gray-200 dark:border-gray-700">
                <div class="flex items-start justify-between gap-4">
                    <div class="flex-1">
                        <h3 id="rdn-metrics-push-label" class="text-base font-semibold text-gray-900 dark:text-white">
                            RDN Statistics Push
                        </h3>
                        <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                            Push hourly scan and hit counts to RDN for your dashboards. Disabled by default.
                        </p>
                        <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">
                            Moved to
                            <a href="{{ \App\Filament\Pages\Configuration::getUrl() }}" class="font-medium underline" style="color: #FF6666;">Account → Configuration</a>.
                        </p>
                        @if (blank($orgRdnId))
                            <p class="mt-2 text-xs text-amber-600 dark:text-amber-400">
                                Your org doesn't have an RDN ID configured — toggling this on will have no effect until one is set.
                            </p>
                        @endif
                    </div>

                    @if (auth()->user()->hasRole(\InsightLPR\BlackAdam\Enum\RoleEnum::ADMIN->value))
                        <button
                            type="button"
                            role="switch"
                            aria-checked="{{ $rdnMetricsPushEnabled ? 'true' : 'false' }}"
                            aria-labelledby="rdn-metrics-push-label"
                            wire:click="toggleRdnMetricsPush({{ $rdnMetricsPushEnabled ? 'false' : 'true' }})"
                            wire:loading.attr="disabled"
                            wire:target="toggleRdnMetricsPush"
                            class="relative inline-flex h-6 w-11 flex-shrink-0 cursor-pointer rounded-full border-2 border-transparent transition-colors duration-200 ease-in-out focus:outline-none focus:ring-2 focus:ring-offset-2"
                            style="background-color: {{ $rdnMetricsPushEnabled ? '#FF6666' : 'rgb(209, 213, 219)' }};"
                        >
                            <span
                                aria-hidden="true"
                                class="pointer-events-none inline-block h-5 w-5 transform rounded-full bg-white shadow ring-0 transition duration-200 ease-in-out"
                                style="transform: translateX({{ $rdnMetricsPushEnabled ? '1.25rem' : '0' }});"
                            ></span>
                        </button>
                    @else
                        <span class="text-xs text-gray-400 dark:text-gray-500">
                            Admin-only
                        </span>
                    @endif
                </div>
            </div>
        @endif

        <div class="mt-8 text-center">
            <p class="text-sm text-gray-500 dark:text-gray-400">
                Have questions?
                <a href="mailto:{{ config('app.pro.sales_email') }}" class="font-medium" style="color: #FF6666;">
                    Contact our sales team
                </a>
            </p>
            <p class="mt-2 text-xs text-gray-400 dark:text-gray-500">
                All plans include 24/7 support and regular feature updates.
            </p>
        </div>
    </div>

    {{-- Upgrade Modal --}}
    <x-filament::modal
        id="upgrade-modal"
        alignment="center"
        width="md"
    >
        <div
            x-data="{ step: 1 }"
            @pro-upgrade-success.window="step = 2"
        >
            <div x-show="step === 1">
                <div class="flex flex-col items-center text-center">
                    <div class="mx-auto flex h-12 w-12 items-center justify-center rounded-full bg-gray-100 dark:bg-gray-800 mb-4">
                        <x-heroicon-o-lock-closed class="h-6 w-6 text-gray-600 dark:text-gray-400" />
                    </div>

                    <h2 class="text-lg font-semibold text-gray-900 dark:text-white">
                        Authorize Payment
                    </h2>

                    <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">
                        To complete your upgrade to <span x-text="targetTierLabel" class="font-semibold"></span>, please authorize InsightLPR to charge your organization's payment method on file.
                    </p>
                </div>

                <div class="mt-6 rounded-lg border border-gray-200 dark:border-gray-700 overflow-hidden">
                    <table class="w-full text-sm">
                        <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                            <tr>
                                <td class="px-4 py-3 font-medium text-gray-500 dark:text-gray-400 bg-gray-50 dark:bg-gray-800/50">Plan</td>
                                <td class="px-4 py-3 text-gray-900 dark:text-white" x-text="targetTierLabel"></td>
                            </tr>
                            <tr>
                                <td class="px-4 py-3 font-medium text-gray-500 dark:text-gray-400 bg-gray-50 dark:bg-gray-800/50">Billing</td>
                                <td class="px-4 py-3 text-gray-900 dark:text-white">Monthly</td>
                            </tr>
                            <tr>
                                <td class="px-4 py-3 font-medium text-gray-500 dark:text-gray-400 bg-gray-50 dark:bg-gray-800/50">Total</td>
                                <td class="px-4 py-3 font-semibold" style="color: #FF6666;" x-text="'$' + targetTierPrice.toLocaleString('en-US', {minimumFractionDigits: 2}) + '/mo'"></td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div class="mt-6">
                    <button
                        type="button"
                        class="w-full rounded-lg px-4 py-2.5 text-sm font-semibold text-white transition-colors duration-150 focus:outline-none focus:ring-2 focus:ring-offset-2"
                        style="background-color: #FF6666;"
                        onmouseover="this.style.opacity='0.9'"
                        onmouseout="this.style.opacity='1'"
                        x-on:click="$wire.requestUpgrade(targetTier)"
                        wire:loading.attr="disabled"
                    >
                        <span wire:loading.remove wire:target="requestUpgrade">Authorize Payment</span>
                        <span wire:loading wire:target="requestUpgrade">Processing...</span>
                    </button>
                </div>
            </div>

            <div x-show="step === 2" x-cloak>
                <div class="flex flex-col items-center text-center">
                    <div class="mx-auto flex h-12 w-12 items-center justify-center rounded-full mb-4" style="background-color: rgba(255, 102, 102, 0.15);">
                        <x-heroicon-o-check-circle class="h-6 w-6" style="color: #FF6666;" />
                    </div>

                    <h2 class="text-lg font-semibold text-gray-900 dark:text-white">
                        Authorization Successful
                    </h2>

                    <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">
                        Thank you for your authorization. InsightLPR will charge your payment method on file.
                    </p>
                </div>

                <div class="mt-6">
                    <button
                        type="button"
                        @click="step = 1; $dispatch('close-modal', { id: 'upgrade-modal' })"
                        class="w-full rounded-lg px-4 py-2.5 text-sm font-semibold text-white transition-colors duration-150 focus:outline-none focus:ring-2 focus:ring-offset-2"
                        style="background-color: #FF6666;"
                        onmouseover="this.style.opacity='0.9'"
                        onmouseout="this.style.opacity='1'"
                    >
                        Done
                    </button>
                </div>
            </div>
        </div>
    </x-filament::modal>

    {{-- Downgrade Modal --}}
    <x-filament::modal
        id="downgrade-modal"
        alignment="center"
        width="md"
    >
        <div
            x-data="{ step: 1 }"
            @pro-downgrade-success.window="step = 2"
        >
            <div x-show="step === 1">
                <div class="flex flex-col items-center text-center">
                    <div class="mx-auto flex h-12 w-12 items-center justify-center rounded-full mb-4" style="background-color: rgba(255, 102, 102, 0.15);">
                        <x-heroicon-o-exclamation-triangle class="h-6 w-6" style="color: #FF6666;" />
                    </div>

                    <h2 class="text-lg font-semibold text-gray-900 dark:text-white">
                        Are you sure that you want to downgrade?
                    </h2>

                    <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">
                        You'll continue to enjoy {{ $currentTier->label() }} features through the end of your billing cycle.
                    </p>
                </div>

                <div class="mt-6 flex gap-3">
                    <button
                        type="button"
                        @click="$dispatch('close-modal', { id: 'downgrade-modal' })"
                        class="flex-1 rounded-lg border border-gray-300 dark:border-gray-600 bg-transparent hover:bg-gray-100 dark:hover:bg-gray-800 px-4 py-2.5 text-sm font-semibold text-gray-600 dark:text-gray-400 transition-colors duration-150 focus:outline-none focus:ring-2 focus:ring-gray-500 focus:ring-offset-2"
                    >
                        Cancel
                    </button>
                    <button
                        type="button"
                        class="flex-1 rounded-lg px-4 py-2.5 text-sm font-semibold text-white transition-colors duration-150 focus:outline-none focus:ring-2 focus:ring-offset-2"
                        style="background-color: #FF6666;"
                        onmouseover="this.style.opacity='0.9'"
                        onmouseout="this.style.opacity='1'"
                        x-on:click="$wire.requestDowngrade(targetTier)"
                        wire:loading.attr="disabled"
                    >
                        <span wire:loading.remove wire:target="requestDowngrade">Change Plan</span>
                        <span wire:loading wire:target="requestDowngrade">Processing...</span>
                    </button>
                </div>
            </div>

            <div x-show="step === 2" x-cloak>
                <div class="flex flex-col items-center text-center">
                    <div class="mx-auto flex h-12 w-12 items-center justify-center rounded-full mb-4" style="background-color: rgba(255, 102, 102, 0.15);">
                        <x-heroicon-o-check-circle class="h-6 w-6" style="color: #FF6666;" />
                    </div>

                    <h2 class="text-lg font-semibold text-gray-900 dark:text-white">
                        Plan Changed Successfully
                    </h2>

                    <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">
                        Your subscription has been changed to <span x-text="targetTierLabel" class="font-semibold"></span>. You'll continue to have access to {{ $currentTier->label() }} features until the end of your current billing cycle.
                    </p>
                </div>

                <div class="mt-6">
                    <button
                        type="button"
                        @click="step = 1; $dispatch('close-modal', { id: 'downgrade-modal' })"
                        class="w-full rounded-lg px-4 py-2.5 text-sm font-semibold text-white transition-colors duration-150 focus:outline-none focus:ring-2 focus:ring-offset-2"
                        style="background-color: #FF6666;"
                        onmouseover="this.style.opacity='0.9'"
                        onmouseout="this.style.opacity='1'"
                    >
                        Done
                    </button>
                </div>
            </div>
        </div>
    </x-filament::modal>
</x-filament-panels::page>
