<x-filament-panels::page>
    <div class="max-w-4xl mx-auto space-y-8">
        {{-- Section 1: Introduction --}}
        <x-filament::section>
            <x-slot name="heading">
                <div class="flex items-center">
                    <span class="methodology-section-number">1</span>
                    <span>Introduction</span>
                </div>
            </x-slot>

            <h3 class="text-lg font-semibold text-gray-800 dark:text-gray-200 mb-4">What is the Client Dashboard?</h3>
            <p class="text-gray-600 dark:text-gray-400 mb-6">
                The Client Dashboard provides comprehensive analytics about your vehicle recovery operations. It shows how many assignments you've received, how many were successfully recovered, and key performance metrics to help you understand and improve your business performance.
            </p>

            <h4 class="text-base font-semibold text-gray-700 dark:text-gray-300 mt-6 mb-3">Quick Navigation</h4>
            <div class="grid grid-cols-2 md:grid-cols-4 gap-2 mb-6">
                <a href="#dashboard-overview" class="px-3 py-2 text-sm text-center flex items-center justify-center rounded-lg bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-300 hover:bg-gray-200 dark:hover:bg-gray-600 hover:text-gray-900 dark:hover:text-white transition-colors">Dashboard Overview</a>
                <a href="#time-period-filters" class="px-3 py-2 text-sm text-center flex items-center justify-center rounded-lg bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-300 hover:bg-gray-200 dark:hover:bg-gray-600 hover:text-gray-900 dark:hover:text-white transition-colors">Time Period Filters</a>
                <a href="#client-table-features" class="px-3 py-2 text-sm text-center flex items-center justify-center rounded-lg bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-300 hover:bg-gray-200 dark:hover:bg-gray-600 hover:text-gray-900 dark:hover:text-white transition-colors">Client Table Features</a>
                <a href="#metric-definitions" class="px-3 py-2 text-sm text-center flex items-center justify-center rounded-lg bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-300 hover:bg-gray-200 dark:hover:bg-gray-600 hover:text-gray-900 dark:hover:text-white transition-colors">Metric Definitions</a>
                <a href="#understanding-comparisons" class="px-3 py-2 text-sm text-center flex items-center justify-center rounded-lg bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-300 hover:bg-gray-200 dark:hover:bg-gray-600 hover:text-gray-900 dark:hover:text-white transition-colors">Understanding Comparisons</a>
                <a href="#charts-guide" class="px-3 py-2 text-sm text-center flex items-center justify-center rounded-lg bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-300 hover:bg-gray-200 dark:hover:bg-gray-600 hover:text-gray-900 dark:hover:text-white transition-colors">Charts Guide</a>
                <a href="#glossary" class="px-3 py-2 text-sm text-center flex items-center justify-center rounded-lg bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-300 hover:bg-gray-200 dark:hover:bg-gray-600 hover:text-gray-900 dark:hover:text-white transition-colors">Glossary</a>
            </div>

            <div class="methodology-info-box">
                <div class="font-semibold text-primary-600 dark:text-primary-400 mb-2">Data Freshness</div>
                <p class="text-gray-600 dark:text-gray-400 mb-0">
                    Metrics are updated every 10 minutes from our analytics system. The data you see reflects activity up to the last refresh cycle.
                </p>
            </div>

            <div class="methodology-info-box">
                <div class="font-semibold text-primary-600 dark:text-primary-400 mb-2">Time Zones</div>
                <p class="text-gray-600 dark:text-gray-400 mb-0">
                    All times and dates are displayed in your configured timezone, ensuring consistency with your local operations.
                </p>
            </div>
        </x-filament::section>

        {{-- Section 2: Dashboard Overview --}}
        <x-filament::section id="dashboard-overview">
            <x-slot name="heading">
                <div class="flex items-center">
                    <span class="methodology-section-number">2</span>
                    <span>Dashboard Overview</span>
                </div>
            </x-slot>

            <p class="text-gray-600 dark:text-gray-400 mb-6">
                The dashboard is organized into distinct areas to help you quickly find the information you need.
            </p>

            {{-- Dashboard Layout Diagram --}}
            <div class="my-6 text-center">
                <svg viewBox="0 0 700 440" xmlns="http://www.w3.org/2000/svg" class="max-w-full h-auto">
                    {{-- Background --}}
                    <rect x="10" y="10" width="680" height="420" rx="8" fill="#f9fafb" stroke="#e5e7eb" stroke-width="2" class="dark:fill-gray-800 dark:stroke-gray-700"/>

                    {{-- Header Bar --}}
                    <rect x="20" y="20" width="660" height="50" rx="6" fill="#1e40af"/>
                    <text x="40" y="52" font-family="sans-serif" font-size="16" font-weight="600" fill="white">Client Analytics</text>

                    {{-- Filters Section --}}
                    <rect x="20" y="80" width="660" height="50" rx="6" fill="white" stroke="#e5e7eb" stroke-width="1" class="dark:fill-gray-700 dark:stroke-gray-600"/>
                    <text x="40" y="112" font-family="sans-serif" font-size="13" fill="#6b7280">Branch Filter</text>
                    <rect x="140" y="95" width="150" height="24" rx="4" fill="#f3f4f6" stroke="#d1d5db" class="dark:fill-gray-600 dark:stroke-gray-500"/>
                    <text x="155" y="112" font-family="sans-serif" font-size="12" fill="#6b7280">All Branches</text>

                    {{-- Date Toggles (MTD, YTD, Advanced) --}}
                    <rect x="450" y="95" width="50" height="24" rx="4" fill="#1e40af"/>
                    <text x="463" y="112" font-family="sans-serif" font-size="12" font-weight="600" fill="white">MTD</text>
                    <rect x="505" y="95" width="50" height="24" rx="4" fill="#f3f4f6" stroke="#d1d5db" class="dark:fill-gray-600 dark:stroke-gray-500"/>
                    <text x="520" y="112" font-family="sans-serif" font-size="12" fill="#6b7280">YTD</text>
                    <rect x="560" y="95" width="70" height="24" rx="4" fill="#f3f4f6" stroke="#d1d5db" class="dark:fill-gray-600 dark:stroke-gray-500"/>
                    <text x="569" y="112" font-family="sans-serif" font-size="12" fill="#6b7280">Advanced</text>

                    {{-- Stat Cards Row --}}
                    <g>
                        {{-- Card 1 --}}
                        <rect x="20" y="145" width="125" height="80" rx="6" fill="white" stroke="#e5e7eb" stroke-width="1" class="dark:fill-gray-700 dark:stroke-gray-600"/>
                        <text x="35" y="170" font-family="sans-serif" font-size="11" fill="#6b7280">Total Assignments</text>
                        <text x="35" y="200" font-family="sans-serif" font-size="24" font-weight="700" fill="#1f2937" class="dark:fill-white">1,247</text>
                        <text x="100" y="200" font-family="sans-serif" font-size="11" fill="#22c55e">+12%</text>

                        {{-- Card 2 --}}
                        <rect x="155" y="145" width="125" height="80" rx="6" fill="white" stroke="#e5e7eb" stroke-width="1" class="dark:fill-gray-700 dark:stroke-gray-600"/>
                        <text x="170" y="170" font-family="sans-serif" font-size="11" fill="#6b7280">Repossessions</text>
                        <text x="170" y="200" font-family="sans-serif" font-size="24" font-weight="700" fill="#1f2937" class="dark:fill-white">436</text>
                        <text x="225" y="200" font-family="sans-serif" font-size="11" fill="#22c55e">+8%</text>

                        {{-- Card 3 --}}
                        <rect x="290" y="145" width="125" height="80" rx="6" fill="white" stroke="#e5e7eb" stroke-width="1" class="dark:fill-gray-700 dark:stroke-gray-600"/>
                        <text x="305" y="170" font-family="sans-serif" font-size="11" fill="#6b7280">LPR Assists</text>
                        <text x="305" y="200" font-family="sans-serif" font-size="24" font-weight="700" fill="#1f2937" class="dark:fill-white">89</text>
                        <text x="345" y="200" font-family="sans-serif" font-size="11" fill="#22c55e">+15%</text>

                        {{-- Card 4 --}}
                        <rect x="425" y="145" width="125" height="80" rx="6" fill="white" stroke="#e5e7eb" stroke-width="1" class="dark:fill-gray-700 dark:stroke-gray-600"/>
                        <text x="440" y="170" font-family="sans-serif" font-size="11" fill="#6b7280">Missed Opps</text>
                        <text x="440" y="200" font-family="sans-serif" font-size="24" font-weight="700" fill="#1f2937" class="dark:fill-white">23</text>
                        <text x="480" y="200" font-family="sans-serif" font-size="11" fill="#22c55e">-5%</text>

                        {{-- Card 5 --}}
                        <rect x="560" y="145" width="120" height="80" rx="6" fill="white" stroke="#e5e7eb" stroke-width="1" class="dark:fill-gray-700 dark:stroke-gray-600"/>
                        <text x="575" y="170" font-family="sans-serif" font-size="11" fill="#6b7280">Recovery Rate</text>
                        <text x="575" y="200" font-family="sans-serif" font-size="24" font-weight="700" fill="#1f2937" class="dark:fill-white">35%</text>
                        <text x="625" y="200" font-family="sans-serif" font-size="11" fill="#22c55e">+3%</text>
                    </g>

                    {{-- Table Controls Bar --}}
                    <rect x="20" y="240" width="660" height="36" rx="6" fill="white" stroke="#e5e7eb" stroke-width="1" class="dark:fill-gray-700 dark:stroke-gray-600"/>
                    {{-- Client Filter --}}
                    <text x="40" y="263" font-family="sans-serif" font-size="11" fill="#6b7280">Client Filter</text>
                    <rect x="115" y="248" width="120" height="22" rx="4" fill="#f3f4f6" stroke="#d1d5db" class="dark:fill-gray-600 dark:stroke-gray-500"/>
                    <text x="127" y="263" font-family="sans-serif" font-size="11" fill="#6b7280">All Clients</text>
                    {{-- Comparison Toggle --}}
                    <rect x="480" y="250" width="36" height="18" rx="9" fill="#1e40af"/>
                    <circle cx="502" cy="259" r="6" fill="white"/>
                    <text x="525" y="263" font-family="sans-serif" font-size="11" fill="#6b7280">Compare to Previous</text>

                    {{-- Client Table --}}
                    <rect x="20" y="280" width="660" height="140" rx="6" fill="white" stroke="#e5e7eb" stroke-width="1" class="dark:fill-gray-700 dark:stroke-gray-600"/>
                    <rect x="20" y="280" width="660" height="35" rx="6" fill="#f9fafb" class="dark:fill-gray-800"/>
                    <text x="40" y="303" font-family="sans-serif" font-size="12" font-weight="600" fill="#374151" class="dark:fill-gray-300">Client</text>
                    <text x="200" y="303" font-family="sans-serif" font-size="12" font-weight="600" fill="#374151" class="dark:fill-gray-300">Assignments</text>
                    <text x="320" y="303" font-family="sans-serif" font-size="12" font-weight="600" fill="#374151" class="dark:fill-gray-300">Repos</text>
                    <text x="420" y="303" font-family="sans-serif" font-size="12" font-weight="600" fill="#374151" class="dark:fill-gray-300">Recovery %</text>
                    <text x="540" y="303" font-family="sans-serif" font-size="12" font-weight="600" fill="#374151" class="dark:fill-gray-300">LPR Assists</text>

                    {{-- Table Rows --}}
                    <line x1="20" y1="315" x2="680" y2="315" stroke="#e5e7eb" class="dark:stroke-gray-600"/>
                    <text x="40" y="340" font-family="sans-serif" font-size="12" fill="#4b5563" class="dark:fill-gray-400">ABC Lender</text>
                    <text x="200" y="340" font-family="sans-serif" font-size="12" fill="#4b5563" class="dark:fill-gray-400">523</text>
                    <text x="320" y="340" font-family="sans-serif" font-size="12" fill="#4b5563" class="dark:fill-gray-400">198</text>
                    <text x="420" y="340" font-family="sans-serif" font-size="12" fill="#4b5563" class="dark:fill-gray-400">37.9%</text>
                    <text x="540" y="340" font-family="sans-serif" font-size="12" fill="#4b5563" class="dark:fill-gray-400">42</text>

                    <line x1="20" y1="355" x2="680" y2="355" stroke="#e5e7eb" class="dark:stroke-gray-600"/>
                    <text x="40" y="380" font-family="sans-serif" font-size="12" fill="#4b5563" class="dark:fill-gray-400">XYZ Credit Union</text>
                    <text x="200" y="380" font-family="sans-serif" font-size="12" fill="#4b5563" class="dark:fill-gray-400">412</text>
                    <text x="320" y="380" font-family="sans-serif" font-size="12" fill="#4b5563" class="dark:fill-gray-400">156</text>
                    <text x="420" y="380" font-family="sans-serif" font-size="12" fill="#4b5563" class="dark:fill-gray-400">37.9%</text>
                    <text x="540" y="380" font-family="sans-serif" font-size="12" fill="#4b5563" class="dark:fill-gray-400">31</text>

                    {{-- Annotations --}}
                    {{-- A: Branch Filter --}}
                    <circle cx="320" cy="95" r="12" fill="none" stroke="#f59e0b" stroke-width="2"/>
                    <text x="340" y="99" font-family="sans-serif" font-size="10" fill="#f59e0b" font-weight="600">A</text>

                    {{-- B: Date Toggles (MTD, YTD, Advanced) --}}
                    <circle cx="430" cy="107" r="12" fill="none" stroke="#f59e0b" stroke-width="2"/>
                    <text x="405" y="111" font-family="sans-serif" font-size="10" fill="#f59e0b" font-weight="600">B</text>

                    {{-- C: Client Filter --}}
                    <circle cx="260" cy="259" r="12" fill="none" stroke="#f59e0b" stroke-width="2"/>
                    <text x="280" y="263" font-family="sans-serif" font-size="10" fill="#f59e0b" font-weight="600">C</text>

                    {{-- D: Comparison Toggle --}}
                    <circle cx="458" cy="259" r="12" fill="none" stroke="#f59e0b" stroke-width="2"/>
                    <text x="438" y="263" font-family="sans-serif" font-size="10" fill="#f59e0b" font-weight="600">D</text>
                </svg>
            </div>

            <h4 class="text-base font-semibold text-gray-700 dark:text-gray-300 mt-6 mb-4">Key Areas</h4>
            <div class="overflow-x-auto">
                <table class="w-full border-collapse">
                    <thead>
                        <tr class="bg-gray-50 dark:bg-gray-800">
                            <th class="px-4 py-3 text-left text-sm font-semibold text-gray-700 dark:text-gray-300 border-b border-gray-200 dark:border-gray-700" style="width: 80px;">Label</th>
                            <th class="px-4 py-3 text-left text-sm font-semibold text-gray-700 dark:text-gray-300 border-b border-gray-200 dark:border-gray-700">Area</th>
                            <th class="px-4 py-3 text-left text-sm font-semibold text-gray-700 dark:text-gray-300 border-b border-gray-200 dark:border-gray-700">Description</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td class="px-4 py-3 text-gray-600 dark:text-gray-400 border-b border-gray-200 dark:border-gray-700"><strong>A</strong></td>
                            <td class="px-4 py-3 text-gray-600 dark:text-gray-400 border-b border-gray-200 dark:border-gray-700">Branch Filter</td>
                            <td class="px-4 py-3 text-gray-600 dark:text-gray-400 border-b border-gray-200 dark:border-gray-700">Filter data by specific branch location</td>
                        </tr>
                        <tr>
                            <td class="px-4 py-3 text-gray-600 dark:text-gray-400 border-b border-gray-200 dark:border-gray-700"><strong>B</strong></td>
                            <td class="px-4 py-3 text-gray-600 dark:text-gray-400 border-b border-gray-200 dark:border-gray-700">Date Toggles</td>
                            <td class="px-4 py-3 text-gray-600 dark:text-gray-400 border-b border-gray-200 dark:border-gray-700">MTD, YTD, or Advanced (custom date range)</td>
                        </tr>
                        <tr>
                            <td class="px-4 py-3 text-gray-600 dark:text-gray-400 border-b border-gray-200 dark:border-gray-700"><strong>C</strong></td>
                            <td class="px-4 py-3 text-gray-600 dark:text-gray-400 border-b border-gray-200 dark:border-gray-700">Client Filter</td>
                            <td class="px-4 py-3 text-gray-600 dark:text-gray-400 border-b border-gray-200 dark:border-gray-700">Filter table by specific clients (multi-select)</td>
                        </tr>
                        <tr>
                            <td class="px-4 py-3 text-gray-600 dark:text-gray-400 border-b border-gray-200 dark:border-gray-700"><strong>D</strong></td>
                            <td class="px-4 py-3 text-gray-600 dark:text-gray-400 border-b border-gray-200 dark:border-gray-700">Comparison Toggle</td>
                            <td class="px-4 py-3 text-gray-600 dark:text-gray-400 border-b border-gray-200 dark:border-gray-700">Enable/disable previous period comparisons</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </x-filament::section>

        {{-- Section 3: Time Period Filters --}}
        <x-filament::section id="time-period-filters">
            <x-slot name="heading">
                <div class="flex items-center">
                    <span class="methodology-section-number">3</span>
                    <span>Time Period Filters</span>
                </div>
            </x-slot>

            <p class="text-gray-600 dark:text-gray-400 mb-6">
                Use the date toggle buttons to switch between different time views. Each view also includes a comparison to help you understand performance trends.
            </p>

            <div class="grid grid-cols-1 md:grid-cols-3 gap-5">
                <div class="bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-xl p-5">
                    <div class="text-lg font-semibold text-primary-600 dark:text-primary-400 mb-2">MTD</div>
                    <div class="text-sm text-gray-500 dark:text-gray-400 mb-3">Month to Date</div>
                    <div class="text-gray-700 dark:text-gray-300 mb-2">Shows data from the 1st of the current month until today.</div>
                    <div class="text-sm text-gray-500 dark:text-gray-400 italic">Compares against: Same period last month</div>
                </div>
                <div class="bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-xl p-5">
                    <div class="text-lg font-semibold text-primary-600 dark:text-primary-400 mb-2">YTD</div>
                    <div class="text-sm text-gray-500 dark:text-gray-400 mb-3">Year to Date</div>
                    <div class="text-gray-700 dark:text-gray-300 mb-2">Shows data from January 1st of the current year until today.</div>
                    <div class="text-sm text-gray-500 dark:text-gray-400 italic">Compares against: Same period last year</div>
                </div>
                <div class="bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-xl p-5">
                    <div class="text-lg font-semibold text-primary-600 dark:text-primary-400 mb-2">Advanced</div>
                    <div class="text-sm text-gray-500 dark:text-gray-400 mb-3">Custom Date Range</div>
                    <div class="text-gray-700 dark:text-gray-300 mb-2">Select custom Start and End dates for any date range.</div>
                    <div class="text-sm text-amber-600 dark:text-amber-400 italic font-medium">No comparison data available for custom ranges</div>
                </div>
            </div>
        </x-filament::section>

        {{-- Section 4: Client Table Features --}}
        <x-filament::section id="client-table-features">
            <x-slot name="heading">
                <div class="flex items-center">
                    <span class="methodology-section-number">4</span>
                    <span>Client Table Features</span>
                </div>
            </x-slot>

            <p class="text-gray-600 dark:text-gray-400 mb-6">
                The client table provides several features to help you filter, compare, and analyze data for specific clients.
            </p>

            {{-- Table Columns Overview --}}
            <h4 class="text-base font-semibold text-gray-700 dark:text-gray-300 mb-4">Table Columns Overview</h4>
            <div class="bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-xl p-5 mb-6">
                <p class="text-gray-600 dark:text-gray-400 mb-4">
                    The client table displays the following data columns for each client:
                </p>
                <div class="overflow-x-auto">
                    <table class="w-full border-collapse">
                        <thead>
                            <tr class="bg-gray-50 dark:bg-gray-700">
                                <th class="px-4 py-3 text-left text-sm font-semibold text-gray-700 dark:text-gray-300 border-b border-gray-200 dark:border-gray-600">Column</th>
                                <th class="px-4 py-3 text-left text-sm font-semibold text-gray-700 dark:text-gray-300 border-b border-gray-200 dark:border-gray-600">Description</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td class="px-4 py-3 text-gray-600 dark:text-gray-400 border-b border-gray-200 dark:border-gray-700"><strong>Client</strong></td>
                                <td class="px-4 py-3 text-gray-600 dark:text-gray-400 border-b border-gray-200 dark:border-gray-700">Client name with percentage of total assignments shown in parentheses. Clickable to view individual client dashboard.</td>
                            </tr>
                            <tr>
                                <td class="px-4 py-3 text-gray-600 dark:text-gray-400 border-b border-gray-200 dark:border-gray-700"><strong>Accepted</strong></td>
                                <td class="px-4 py-3 text-gray-600 dark:text-gray-400 border-b border-gray-200 dark:border-gray-700">Assignment acceptance count with comparison indicator when enabled.</td>
                            </tr>
                            <tr>
                                <td class="px-4 py-3 text-gray-600 dark:text-gray-400 border-b border-gray-200 dark:border-gray-700"><strong>Closed</strong></td>
                                <td class="px-4 py-3 text-gray-600 dark:text-gray-400 border-b border-gray-200 dark:border-gray-700">Assignment closure/decline count with comparison indicator when enabled.</td>
                            </tr>
                            <tr>
                                <td class="px-4 py-3 text-gray-600 dark:text-gray-400 border-b border-gray-200 dark:border-gray-700"><strong>Repossessions</strong></td>
                                <td class="px-4 py-3 text-gray-600 dark:text-gray-400 border-b border-gray-200 dark:border-gray-700">Total successful vehicle repossessions with comparison indicator.</td>
                            </tr>
                            <tr>
                                <td class="px-4 py-3 text-gray-600 dark:text-gray-400 border-b border-gray-200 dark:border-gray-700"><strong>LPR Assists</strong></td>
                                <td class="px-4 py-3 text-gray-600 dark:text-gray-400 border-b border-gray-200 dark:border-gray-700">Recoveries assisted by LPR scanning technology with comparison indicator.</td>
                            </tr>
                            <tr>
                                <td class="px-4 py-3 text-gray-600 dark:text-gray-400 border-b border-gray-200 dark:border-gray-700"><strong>Missed Opportunities</strong></td>
                                <td class="px-4 py-3 text-gray-600 dark:text-gray-400 border-b border-gray-200 dark:border-gray-700">Vehicles scanned but not recovered before assignment ended.</td>
                            </tr>
                            <tr>
                                <td class="px-4 py-3 text-gray-600 dark:text-gray-400 border-b border-gray-200 dark:border-gray-700"><strong>Recovery Rate</strong></td>
                                <td class="px-4 py-3 text-gray-600 dark:text-gray-400 border-b border-gray-200 dark:border-gray-700">Success percentage (Repossessions &divide; Accepted Assignments).</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <div class="mt-4 p-3 bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-700 rounded-lg">
                    <p class="text-sm text-blue-700 dark:text-blue-400">
                        <strong>Comparison Indicators:</strong> When comparison is enabled, changes are color-coded&mdash;<span class="text-green-600 dark:text-green-400 font-medium">green</span> indicates improvement, <span class="text-red-600 dark:text-red-400 font-medium">red</span> indicates areas needing attention. Previous period data is shown in smaller text below the current value. Hover over column headers for additional tooltip information.
                    </p>
                </div>
            </div>

            {{-- Client Filter --}}
            <h4 class="text-base font-semibold text-gray-700 dark:text-gray-300 mb-4">Client Filter</h4>
            <div class="bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-xl p-5 mb-6">
                <div class="flex items-start gap-4">
                    <div class="methodology-metric-icon blue flex-shrink-0">
                        <x-heroicon-o-funnel class="w-6 h-6" />
                    </div>
                    <div>
                        <div class="text-gray-700 dark:text-gray-300 mb-2">
                            Multi-select dropdown to filter the table by specific clients.
                        </div>
                        <ul class="list-disc list-inside text-gray-600 dark:text-gray-400 space-y-1 text-sm">
                            <li>Located in the table's control bar above the column headers</li>
                            <li>Shows all clients from your organization's hotlist</li>
                            <li>Select one or more clients to filter the table</li>
                            <li>Clear the filter to show all clients again</li>
                        </ul>
                    </div>
                </div>
            </div>

            {{-- Comparison Toggle --}}
            <h4 class="text-base font-semibold text-gray-700 dark:text-gray-300 mb-4">Comparison Toggle</h4>
            <div class="bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-xl p-5 mb-6">
                <div class="flex items-start gap-4">
                    <div class="methodology-metric-icon green flex-shrink-0">
                        <x-heroicon-o-arrow-path class="w-6 h-6" />
                    </div>
                    <div>
                        <div class="text-gray-700 dark:text-gray-300 mb-2">
                            "Compare to Previous Timeframe" toggle controls whether comparison data is displayed.
                        </div>
                        <ul class="list-disc list-inside text-gray-600 dark:text-gray-400 space-y-1 text-sm">
                            <li><strong>When enabled:</strong> Shows previous period metrics and % change columns</li>
                            <li><strong>When disabled:</strong> Only shows current period data (cleaner view)</li>
                            <li>Toggle is enabled by default</li>
                        </ul>
                        <div class="mt-3 p-3 bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-700 rounded-lg">
                            <p class="text-sm text-amber-700 dark:text-amber-400">
                                <strong>Note:</strong> Comparison data is only available for MTD and YTD views. When using Advanced (custom date range), comparison columns are automatically hidden.
                            </p>
                        </div>
                    </div>
                </div>
            </div>

            {{-- Hidden Clients --}}
            <h4 class="text-base font-semibold text-gray-700 dark:text-gray-300 mb-4">Hidden Clients</h4>
            <div class="bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-xl p-5 mb-6">
                <div class="flex items-start gap-4">
                    <div class="methodology-metric-icon purple flex-shrink-0">
                        <x-heroicon-o-eye-slash class="w-6 h-6" />
                    </div>
                    <div>
                        <div class="text-gray-700 dark:text-gray-300 mb-2">
                            Clients with zero assignments in the selected time period are hidden by default to keep the table focused on active clients.
                        </div>
                        <ul class="list-disc list-inside text-gray-600 dark:text-gray-400 space-y-1 text-sm">
                            <li>A message below the table shows: "Hidden X client(s) with 0 assignments"</li>
                            <li>Click "Show All" to display all clients including those with no activity</li>
                            <li>Click "Hide Empty" to return to the filtered view</li>
                        </ul>
                    </div>
                </div>
            </div>

            {{-- Sorting --}}
            <h4 class="text-base font-semibold text-gray-700 dark:text-gray-300 mb-4">Sorting</h4>
            <div class="bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-xl p-5 mb-6">
                <div class="flex items-start gap-4">
                    <div class="methodology-metric-icon cyan flex-shrink-0">
                        <x-heroicon-o-bars-arrow-down class="w-6 h-6" />
                    </div>
                    <div>
                        <div class="text-gray-700 dark:text-gray-300 mb-2">
                            All columns in the table are sortable to help you quickly find the information you need.
                        </div>
                        <ul class="list-disc list-inside text-gray-600 dark:text-gray-400 space-y-1 text-sm">
                            <li>Click any column header to sort by that column</li>
                            <li><strong>Default sort:</strong> Accepted (highest first)</li>
                            <li>Click the same column header again to reverse the sort direction</li>
                            <li>A sort indicator arrow shows the current sort column and direction</li>
                        </ul>
                    </div>
                </div>
            </div>

            {{-- Pagination --}}
            <h4 class="text-base font-semibold text-gray-700 dark:text-gray-300 mb-4">Pagination</h4>
            <div class="bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-xl p-5 mb-6">
                <div class="flex items-start gap-4">
                    <div class="methodology-metric-icon amber flex-shrink-0">
                        <x-heroicon-o-document-duplicate class="w-6 h-6" />
                    </div>
                    <div>
                        <div class="text-gray-700 dark:text-gray-300 mb-2">
                            For organizations with many clients, the table is paginated to improve performance and readability.
                        </div>
                        <ul class="list-disc list-inside text-gray-600 dark:text-gray-400 space-y-1 text-sm">
                            <li><strong>Default:</strong> 10 rows per page</li>
                            <li><strong>Options:</strong> 10, 25, 50, or 100 rows per page</li>
                            <li>Use the dropdown at the bottom of the table to change the page size</li>
                            <li>Navigate between pages using the pagination controls</li>
                        </ul>
                    </div>
                </div>
            </div>

            {{-- Row Navigation --}}
            <h4 class="text-base font-semibold text-gray-700 dark:text-gray-300 mb-4">Row Navigation</h4>
            <div class="bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-xl p-5">
                <div class="flex items-start gap-4">
                    <div class="methodology-metric-icon green flex-shrink-0">
                        <x-heroicon-o-arrow-right-circle class="w-6 h-6" />
                    </div>
                    <div>
                        <div class="text-gray-700 dark:text-gray-300 mb-2">
                            Each row in the table is interactive, allowing you to drill down into individual client data.
                        </div>
                        <ul class="list-disc list-inside text-gray-600 dark:text-gray-400 space-y-1 text-sm">
                            <li>Click anywhere on a row to view that client's individual dashboard</li>
                            <li>Alternatively, click the arrow button on the right side of each row</li>
                            <li>The individual client dashboard shows detailed metrics and charts for just that client</li>
                        </ul>
                    </div>
                </div>
            </div>
        </x-filament::section>

        {{-- Section 5: Metric Definitions --}}
        <x-filament::section id="metric-definitions">
            <x-slot name="heading">
                <div class="flex items-center">
                    <span class="methodology-section-number">5</span>
                    <span>Metric Definitions</span>
                </div>
            </x-slot>

            <p class="text-gray-600 dark:text-gray-400 mb-6">
                Below you'll find detailed explanations of each metric displayed on your dashboard, including how it's calculated and what it means for your operations.
            </p>

            {{-- Metric 1: Total Assignments --}}
            <div class="methodology-metric-card">
                <div class="flex items-center gap-4 mb-4">
                    <div class="methodology-metric-icon blue">
                        <x-heroicon-o-clipboard-document-list class="w-6 h-6" />
                    </div>
                    <div class="text-xl font-semibold text-gray-900 dark:text-white">Total Assignments</div>
                </div>
                <div class="mb-4">
                    <div class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1">What it means</div>
                    <div class="text-gray-700 dark:text-gray-300">The number of vehicle recovery assignments received during the selected time period.</div>
                </div>
                <div>
                    <div class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1">How it's calculated</div>
                    <div class="text-gray-700 dark:text-gray-300">We count each unique assignment that was marked as "Open" (accepted for work) during the time range you've selected. Each vehicle is only counted once, even if its status changed multiple times.</div>
                </div>
            </div>

            {{-- Metric 2: Accepted --}}
            <div class="methodology-metric-card">
                <div class="flex items-center gap-4 mb-4">
                    <div class="methodology-metric-icon green">
                        <x-heroicon-o-check-circle class="w-6 h-6" />
                    </div>
                    <div class="text-xl font-semibold text-gray-900 dark:text-white">Accepted Assignments</div>
                </div>
                <div class="mb-4">
                    <div class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1">What it means</div>
                    <div class="text-gray-700 dark:text-gray-300">Assignments that were formally accepted and moved into active status.</div>
                </div>
                <div>
                    <div class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1">How it's calculated</div>
                    <div class="text-gray-700 dark:text-gray-300">We count assignments that transitioned from their initial "New from Client" status to "Open" status, indicating they were accepted for work.</div>
                </div>
            </div>

            {{-- Metric 3: Declined/Closed --}}
            <div class="methodology-metric-card">
                <div class="flex items-center gap-4 mb-4">
                    <div class="methodology-metric-icon purple">
                        <x-heroicon-o-x-mark class="w-6 h-6" />
                    </div>
                    <div class="text-xl font-semibold text-gray-900 dark:text-white">Declined / Closed</div>
                </div>
                <div class="mb-4">
                    <div class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1">What it means</div>
                    <div class="text-gray-700 dark:text-gray-300">Assignments that were closed without a successful recovery.</div>
                </div>
                <div>
                    <div class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1">How it's calculated</div>
                    <div class="text-gray-700 dark:text-gray-300">We count assignments that moved from "Open" or "Hold" status to "Closed" status. This includes declined work and assignments that expired.</div>
                </div>
            </div>

            {{-- Metric 4: Repossessions --}}
            <div class="methodology-metric-card">
                <div class="flex items-center gap-4 mb-4">
                    <div class="methodology-metric-icon green">
                        <x-heroicon-o-truck class="w-6 h-6" />
                    </div>
                    <div class="text-xl font-semibold text-gray-900 dark:text-white">Repossessions</div>
                </div>
                <div class="mb-4">
                    <div class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1">What it means</div>
                    <div class="text-gray-700 dark:text-gray-300">Successful vehicle recoveries completed during the period.</div>
                </div>
                <div>
                    <div class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1">How it's calculated</div>
                    <div class="text-gray-700 dark:text-gray-300">We count unique assignments that were marked as "Repossessed" during the selected time range.</div>
                </div>
            </div>

            {{-- Metric 5: Recovery Rate --}}
            <div class="methodology-metric-card">
                <div class="flex items-center gap-4 mb-4">
                    <div class="methodology-metric-icon cyan">
                        <x-heroicon-o-chart-pie class="w-6 h-6" />
                    </div>
                    <div class="text-xl font-semibold text-gray-900 dark:text-white">Recovery Rate</div>
                </div>
                <div class="mb-4">
                    <div class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1">What it means</div>
                    <div class="text-gray-700 dark:text-gray-300">The percentage of assignments that resulted in a successful vehicle recovery.</div>
                </div>
                <div class="mb-4">
                    <div class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1">How it's calculated</div>
                    <div class="methodology-formula-box">Recovery Rate = (Repossessions &divide; Total Assignments) &times; 100</div>
                </div>
                <div class="methodology-example-box">
                    <div class="text-xs font-semibold uppercase text-green-600 dark:text-green-400 mb-1">Example</div>
                    <div class="text-gray-700 dark:text-gray-300">If you had 100 assignments and 35 repossessions, your recovery rate would be 35%.</div>
                </div>
                <div class="mt-4">
                    <div class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1">What good looks like</div>
                    <div class="text-gray-700 dark:text-gray-300">A higher recovery rate indicates more efficient operations.</div>
                </div>
            </div>

            {{-- Metric 6: LPR Assists --}}
            <div class="methodology-metric-card">
                <div class="flex items-center gap-4 mb-4">
                    <div class="methodology-metric-icon blue">
                        <x-heroicon-o-camera class="w-6 h-6" />
                    </div>
                    <div class="text-xl font-semibold text-gray-900 dark:text-white">LPR Assists</div>
                </div>
                <div class="mb-4">
                    <div class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1">What it means</div>
                    <div class="text-gray-700 dark:text-gray-300">Recoveries where license plate recognition (LPR) scanning technology helped locate the vehicle.</div>
                </div>
                <div class="mb-4">
                    <div class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1">How it's calculated</div>
                    <div class="text-gray-700 dark:text-gray-300">We count recoveries where our scanning equipment detected the target vehicle within 0.1 miles (approximately 500 feet) of where the recovery ultimately took place.</div>
                </div>
                <div>
                    <div class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1">Why it matters</div>
                    <div class="text-gray-700 dark:text-gray-300">This metric shows how effectively scanning technology is contributing to your recovery success.</div>
                </div>
            </div>

            {{-- Metric 7: Missed Opportunities --}}
            <div class="methodology-metric-card">
                <div class="flex items-center gap-4 mb-4">
                    <div class="methodology-metric-icon amber">
                        <x-heroicon-o-exclamation-triangle class="w-6 h-6" />
                    </div>
                    <div class="text-xl font-semibold text-gray-900 dark:text-white">Missed Opportunities</div>
                </div>
                <div class="mb-4">
                    <div class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1">What it means</div>
                    <div class="text-gray-700 dark:text-gray-300">Vehicles that were scanned by our LPR equipment but were not recovered before the assignment ended.</div>
                </div>
                <div class="mb-4">
                    <div class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1">How it's calculated</div>
                    <div class="text-gray-700 dark:text-gray-300">We count distinct vehicles that were scanned during an active assignment but the assignment was closed before a recovery was made.</div>
                </div>
                <div class="mb-4">
                    <div class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1">Why it matters</div>
                    <div class="text-gray-700 dark:text-gray-300">These represent potential recoveries that weren't completed&mdash;vehicles that were spotted but not recovered in time.</div>
                </div>
                <div class="methodology-info-box">
                    <div class="font-semibold text-primary-600 dark:text-primary-400 mb-2">Note on Comparisons</div>
                    <p class="text-gray-600 dark:text-gray-400 mb-0">
                        For this metric, <strong>lower is better</strong>. A decrease is shown in green because fewer missed opportunities is an improvement.
                    </p>
                </div>
            </div>

            {{-- Metric 8: Days to Repo --}}
            <div class="methodology-metric-card">
                <div class="flex items-center gap-4 mb-4">
                    <div class="methodology-metric-icon purple">
                        <x-heroicon-o-clock class="w-6 h-6" />
                    </div>
                    <div class="text-xl font-semibold text-gray-900 dark:text-white">Days to Repo</div>
                </div>
                <div class="mb-4">
                    <div class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1">What it means</div>
                    <div class="text-gray-700 dark:text-gray-300">The average number of days between when an assignment was received and when the vehicle was recovered.</div>
                </div>
                <div class="mb-4">
                    <div class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1">How it's calculated</div>
                    <div class="methodology-formula-box">Days to Repo = Total Days Across All Repos &divide; Number of Repossessions</div>
                </div>
                <div class="mb-4">
                    <div class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1">What good looks like</div>
                    <div class="text-gray-700 dark:text-gray-300">Lower numbers are better&mdash;they indicate faster recoveries.</div>
                </div>
                <div class="methodology-info-box">
                    <div class="font-semibold text-primary-600 dark:text-primary-400 mb-2">Note on Comparisons</div>
                    <p class="text-gray-600 dark:text-gray-400 mb-0">
                        For this metric, <strong>lower is better</strong>. A decrease is shown in green because fewer days means faster recovery times.
                    </p>
                </div>
            </div>

            {{-- Metric 9: Current Open --}}
            <div class="methodology-metric-card">
                <div class="flex items-center gap-4 mb-4">
                    <div class="methodology-metric-icon green">
                        <x-heroicon-o-queue-list class="w-6 h-6" />
                    </div>
                    <div class="text-xl font-semibold text-gray-900 dark:text-white">Current Open Assignments</div>
                </div>
                <div class="mb-4">
                    <div class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1">What it means</div>
                    <div class="text-gray-700 dark:text-gray-300">The number of assignments that are currently active and open for work right now.</div>
                </div>
                <div>
                    <div class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1">How it's calculated</div>
                    <div class="text-gray-700 dark:text-gray-300">This is a real-time count of all assignments currently in "Open" status (excluding those on hold).</div>
                </div>
            </div>

            {{-- Metric 10: 30-Day Recovery Rate --}}
            <div class="methodology-metric-card">
                <div class="flex items-center gap-4 mb-4">
                    <div class="methodology-metric-icon cyan">
                        <x-heroicon-o-calendar class="w-6 h-6" />
                    </div>
                    <div class="text-xl font-semibold text-gray-900 dark:text-white">30-Day Recovery Rate</div>
                </div>
                <div class="mb-4">
                    <div class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1">What it means</div>
                    <div class="text-gray-700 dark:text-gray-300">Your recovery rate performance over the most recent 30-day period.</div>
                </div>
                <div>
                    <div class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1">How it's calculated</div>
                    <div class="text-gray-700 dark:text-gray-300">Same formula as Recovery Rate, but fixed to the last 30 days regardless of the dashboard's selected date range. This provides a consistent recent-performance snapshot.</div>
                </div>
            </div>
        </x-filament::section>

        {{-- Section 6: Understanding Comparisons --}}
        <x-filament::section id="understanding-comparisons">
            <x-slot name="heading">
                <div class="flex items-center">
                    <span class="methodology-section-number">6</span>
                    <span>Understanding Comparisons</span>
                </div>
            </x-slot>

            <p class="text-gray-600 dark:text-gray-400 mb-6">
                Each metric displays a comparison indicator showing how performance has changed compared to the previous period. Here's how to read them:
            </p>

            <div class="flex flex-wrap gap-6 my-6">
                <div class="methodology-comparison-card improvement">
                    <div class="text-4xl mb-2 text-green-600">&uarr;</div>
                    <div class="font-semibold text-green-800 dark:text-green-400 mb-1">Improvement</div>
                    <div class="text-sm text-gray-600 dark:text-gray-400">Performance is better than the previous period</div>
                </div>
                <div class="methodology-comparison-card decline">
                    <div class="text-4xl mb-2 text-red-600">&darr;</div>
                    <div class="font-semibold text-red-800 dark:text-red-400 mb-1">Needs Attention</div>
                    <div class="text-sm text-gray-600 dark:text-gray-400">Performance has declined from the previous period</div>
                </div>
            </div>

            <h4 class="text-base font-semibold text-gray-700 dark:text-gray-300 mt-6 mb-4">Important Exception: "Lower is Better" Metrics</h4>
            <p class="text-gray-600 dark:text-gray-400 mb-4">
                For <strong>Days to Repo</strong> and <strong>Missed Opportunities</strong>, lower numbers indicate better performance. For these metrics, the colors are reversed:
            </p>

            <div class="overflow-x-auto mb-6">
                <table class="w-full border-collapse">
                    <thead>
                        <tr class="bg-gray-50 dark:bg-gray-800">
                            <th class="px-4 py-3 text-left text-sm font-semibold text-gray-700 dark:text-gray-300 border-b border-gray-200 dark:border-gray-700">Metric</th>
                            <th class="px-4 py-3 text-left text-sm font-semibold text-green-600 dark:text-green-400 border-b border-gray-200 dark:border-gray-700">&darr; Green (Good)</th>
                            <th class="px-4 py-3 text-left text-sm font-semibold text-red-600 dark:text-red-400 border-b border-gray-200 dark:border-gray-700">&uarr; Red (Attention)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td class="px-4 py-3 text-gray-600 dark:text-gray-400 border-b border-gray-200 dark:border-gray-700">Days to Repo</td>
                            <td class="px-4 py-3 text-gray-600 dark:text-gray-400 border-b border-gray-200 dark:border-gray-700">Faster recoveries</td>
                            <td class="px-4 py-3 text-gray-600 dark:text-gray-400 border-b border-gray-200 dark:border-gray-700">Slower recoveries</td>
                        </tr>
                        <tr>
                            <td class="px-4 py-3 text-gray-600 dark:text-gray-400 border-b border-gray-200 dark:border-gray-700">Missed Opportunities</td>
                            <td class="px-4 py-3 text-gray-600 dark:text-gray-400 border-b border-gray-200 dark:border-gray-700">Fewer missed vehicles</td>
                            <td class="px-4 py-3 text-gray-600 dark:text-gray-400 border-b border-gray-200 dark:border-gray-700">More missed vehicles</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <h4 class="text-base font-semibold text-gray-700 dark:text-gray-300 mt-6 mb-4">Comparison Periods</h4>
            <div class="overflow-x-auto">
                <table class="w-full border-collapse">
                    <thead>
                        <tr class="bg-gray-50 dark:bg-gray-800">
                            <th class="px-4 py-3 text-left text-sm font-semibold text-gray-700 dark:text-gray-300 border-b border-gray-200 dark:border-gray-700">View</th>
                            <th class="px-4 py-3 text-left text-sm font-semibold text-gray-700 dark:text-gray-300 border-b border-gray-200 dark:border-gray-700">Compares Against</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td class="px-4 py-3 text-gray-600 dark:text-gray-400 border-b border-gray-200 dark:border-gray-700"><strong>MTD</strong> (Month to Date)</td>
                            <td class="px-4 py-3 text-gray-600 dark:text-gray-400 border-b border-gray-200 dark:border-gray-700">Same period last month</td>
                        </tr>
                        <tr>
                            <td class="px-4 py-3 text-gray-600 dark:text-gray-400 border-b border-gray-200 dark:border-gray-700"><strong>YTD</strong> (Year to Date)</td>
                            <td class="px-4 py-3 text-gray-600 dark:text-gray-400 border-b border-gray-200 dark:border-gray-700">Same period last year</td>
                        </tr>
                        <tr>
                            <td class="px-4 py-3 text-gray-600 dark:text-gray-400 border-b border-gray-200 dark:border-gray-700"><strong>Advanced</strong> (Custom Range)</td>
                            <td class="px-4 py-3 text-amber-600 dark:text-amber-400 border-b border-gray-200 dark:border-gray-700 font-medium">No comparison available</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div class="methodology-info-box mt-6">
                <div class="font-semibold text-primary-600 dark:text-primary-400 mb-2">Note on Comparison Toggle</div>
                <ul class="text-gray-600 dark:text-gray-400 list-disc list-inside space-y-1 mb-0">
                    <li>The "Compare to Previous Timeframe" toggle must be enabled to see percentage changes</li>
                    <li>The toggle is enabled by default for MTD and YTD views</li>
                    <li>Custom date ranges (Advanced) do not support comparisons&mdash;comparison columns are automatically hidden</li>
                </ul>
            </div>
        </x-filament::section>

        {{-- Section 7: Charts Guide --}}
        <x-filament::section id="charts-guide">
            <x-slot name="heading">
                <div class="flex items-center">
                    <span class="methodology-section-number">7</span>
                    <span>Charts Guide</span>
                </div>
            </x-slot>

            <h3 class="text-lg font-semibold text-gray-800 dark:text-gray-200 mb-4">Assignment Breakdown Chart</h3>
            <p class="text-gray-600 dark:text-gray-400 mb-6">
                This stacked bar chart shows how assignment statuses changed each month, giving you a visual overview of your operational flow.
            </p>

            {{-- Assignment Breakdown Chart Diagram --}}
            <div class="my-6 text-center">
                <svg viewBox="0 0 600 280" xmlns="http://www.w3.org/2000/svg" class="max-w-full h-auto">
                    {{-- Background --}}
                    <rect x="0" y="0" width="600" height="280" fill="white" class="dark:fill-gray-800"/>

                    {{-- Y-axis --}}
                    <line x1="60" y1="30" x2="60" y2="220" stroke="#e5e7eb" stroke-width="1" class="dark:stroke-gray-600"/>
                    <text x="50" y="35" font-family="sans-serif" font-size="10" fill="#6b7280" text-anchor="end">500</text>
                    <text x="50" y="82" font-family="sans-serif" font-size="10" fill="#6b7280" text-anchor="end">375</text>
                    <text x="50" y="129" font-family="sans-serif" font-size="10" fill="#6b7280" text-anchor="end">250</text>
                    <text x="50" y="176" font-family="sans-serif" font-size="10" fill="#6b7280" text-anchor="end">125</text>
                    <text x="50" y="223" font-family="sans-serif" font-size="10" fill="#6b7280" text-anchor="end">0</text>

                    {{-- Grid lines --}}
                    <line x1="60" y1="30" x2="560" y2="30" stroke="#f3f4f6" stroke-width="1" class="dark:stroke-gray-700"/>
                    <line x1="60" y1="77" x2="560" y2="77" stroke="#f3f4f6" stroke-width="1" class="dark:stroke-gray-700"/>
                    <line x1="60" y1="124" x2="560" y2="124" stroke="#f3f4f6" stroke-width="1" class="dark:stroke-gray-700"/>
                    <line x1="60" y1="171" x2="560" y2="171" stroke="#f3f4f6" stroke-width="1" class="dark:stroke-gray-700"/>
                    <line x1="60" y1="218" x2="560" y2="218" stroke="#e5e7eb" stroke-width="1" class="dark:stroke-gray-600"/>

                    {{-- Bars for each month --}}
                    {{-- Jan --}}
                    <rect x="80" y="168" width="40" height="50" fill="#22c55e" rx="2"/>
                    <rect x="80" y="128" width="40" height="40" fill="#3b82f6" rx="2"/>
                    <rect x="80" y="98" width="40" height="30" fill="#8b5cf6" rx="2"/>
                    <rect x="80" y="88" width="40" height="10" fill="#f59e0b" rx="2"/>
                    <text x="100" y="235" font-family="sans-serif" font-size="10" fill="#6b7280" text-anchor="middle">Jan</text>

                    {{-- Feb --}}
                    <rect x="140" y="158" width="40" height="60" fill="#22c55e" rx="2"/>
                    <rect x="140" y="113" width="40" height="45" fill="#3b82f6" rx="2"/>
                    <rect x="140" y="78" width="40" height="35" fill="#8b5cf6" rx="2"/>
                    <rect x="140" y="68" width="40" height="10" fill="#f59e0b" rx="2"/>
                    <text x="160" y="235" font-family="sans-serif" font-size="10" fill="#6b7280" text-anchor="middle">Feb</text>

                    {{-- Mar --}}
                    <rect x="200" y="148" width="40" height="70" fill="#22c55e" rx="2"/>
                    <rect x="200" y="98" width="40" height="50" fill="#3b82f6" rx="2"/>
                    <rect x="200" y="58" width="40" height="40" fill="#8b5cf6" rx="2"/>
                    <rect x="200" y="48" width="40" height="10" fill="#f59e0b" rx="2"/>
                    <text x="220" y="235" font-family="sans-serif" font-size="10" fill="#6b7280" text-anchor="middle">Mar</text>

                    {{-- Apr --}}
                    <rect x="260" y="138" width="40" height="80" fill="#22c55e" rx="2"/>
                    <rect x="260" y="88" width="40" height="50" fill="#3b82f6" rx="2"/>
                    <rect x="260" y="53" width="40" height="35" fill="#8b5cf6" rx="2"/>
                    <rect x="260" y="43" width="40" height="10" fill="#f59e0b" rx="2"/>
                    <text x="280" y="235" font-family="sans-serif" font-size="10" fill="#6b7280" text-anchor="middle">Apr</text>

                    {{-- May --}}
                    <rect x="320" y="143" width="40" height="75" fill="#22c55e" rx="2"/>
                    <rect x="320" y="93" width="40" height="50" fill="#3b82f6" rx="2"/>
                    <rect x="320" y="58" width="40" height="35" fill="#8b5cf6" rx="2"/>
                    <rect x="320" y="48" width="40" height="10" fill="#f59e0b" rx="2"/>
                    <text x="340" y="235" font-family="sans-serif" font-size="10" fill="#6b7280" text-anchor="middle">May</text>

                    {{-- Jun --}}
                    <rect x="380" y="153" width="40" height="65" fill="#22c55e" rx="2"/>
                    <rect x="380" y="103" width="40" height="50" fill="#3b82f6" rx="2"/>
                    <rect x="380" y="63" width="40" height="40" fill="#8b5cf6" rx="2"/>
                    <rect x="380" y="53" width="40" height="10" fill="#f59e0b" rx="2"/>
                    <text x="400" y="235" font-family="sans-serif" font-size="10" fill="#6b7280" text-anchor="middle">Jun</text>

                    {{-- Jul --}}
                    <rect x="440" y="148" width="40" height="70" fill="#22c55e" rx="2"/>
                    <rect x="440" y="98" width="40" height="50" fill="#3b82f6" rx="2"/>
                    <rect x="440" y="58" width="40" height="40" fill="#8b5cf6" rx="2"/>
                    <rect x="440" y="48" width="40" height="10" fill="#f59e0b" rx="2"/>
                    <text x="460" y="235" font-family="sans-serif" font-size="10" fill="#6b7280" text-anchor="middle">Jul</text>

                    {{-- Aug --}}
                    <rect x="500" y="138" width="40" height="80" fill="#22c55e" rx="2"/>
                    <rect x="500" y="83" width="40" height="55" fill="#3b82f6" rx="2"/>
                    <rect x="500" y="43" width="40" height="40" fill="#8b5cf6" rx="2"/>
                    <rect x="500" y="33" width="40" height="10" fill="#f59e0b" rx="2"/>
                    <text x="520" y="235" font-family="sans-serif" font-size="10" fill="#6b7280" text-anchor="middle">Aug</text>

                    {{-- Legend --}}
                    <rect x="80" y="255" width="12" height="12" fill="#22c55e" rx="2"/>
                    <text x="97" y="265" font-family="sans-serif" font-size="11" fill="#374151" class="dark:fill-gray-300">Open</text>

                    <rect x="160" y="255" width="12" height="12" fill="#3b82f6" rx="2"/>
                    <text x="177" y="265" font-family="sans-serif" font-size="11" fill="#374151" class="dark:fill-gray-300">Repossessed</text>

                    <rect x="280" y="255" width="12" height="12" fill="#8b5cf6" rx="2"/>
                    <text x="297" y="265" font-family="sans-serif" font-size="11" fill="#374151" class="dark:fill-gray-300">Closed</text>

                    <rect x="380" y="255" width="12" height="12" fill="#f59e0b" rx="2"/>
                    <text x="397" y="265" font-family="sans-serif" font-size="11" fill="#374151" class="dark:fill-gray-300">Hold</text>
                </svg>
            </div>

            <h4 class="text-base font-semibold text-gray-700 dark:text-gray-300 mt-6 mb-4">How to Read It</h4>
            <p class="text-gray-600 dark:text-gray-400 mb-6">
                Each bar represents one month. The height of each colored section shows the count for that status. This helps you visualize the flow of assignments through your system over time.
            </p>

            <h3 class="text-lg font-semibold text-gray-800 dark:text-gray-200 mt-8 mb-4">Days to Repo Trend Chart</h3>
            <p class="text-gray-600 dark:text-gray-400 mb-6">
                This line chart shows average days-to-repo over time, broken down by recovery type.
            </p>

            {{-- Days to Repo Trend Diagram --}}
            <div class="my-6 text-center">
                <svg viewBox="0 0 600 280" xmlns="http://www.w3.org/2000/svg" class="max-w-full h-auto">
                    {{-- Background --}}
                    <rect x="0" y="0" width="600" height="280" fill="white" class="dark:fill-gray-800"/>

                    {{-- Y-axis --}}
                    <line x1="60" y1="30" x2="60" y2="220" stroke="#e5e7eb" stroke-width="1" class="dark:stroke-gray-600"/>
                    <text x="50" y="35" font-family="sans-serif" font-size="10" fill="#6b7280" text-anchor="end">60d</text>
                    <text x="50" y="82" font-family="sans-serif" font-size="10" fill="#6b7280" text-anchor="end">45d</text>
                    <text x="50" y="129" font-family="sans-serif" font-size="10" fill="#6b7280" text-anchor="end">30d</text>
                    <text x="50" y="176" font-family="sans-serif" font-size="10" fill="#6b7280" text-anchor="end">15d</text>
                    <text x="50" y="223" font-family="sans-serif" font-size="10" fill="#6b7280" text-anchor="end">0d</text>

                    {{-- Grid lines --}}
                    <line x1="60" y1="30" x2="560" y2="30" stroke="#f3f4f6" stroke-width="1" class="dark:stroke-gray-700"/>
                    <line x1="60" y1="77" x2="560" y2="77" stroke="#f3f4f6" stroke-width="1" class="dark:stroke-gray-700"/>
                    <line x1="60" y1="124" x2="560" y2="124" stroke="#f3f4f6" stroke-width="1" class="dark:stroke-gray-700"/>
                    <line x1="60" y1="171" x2="560" y2="171" stroke="#f3f4f6" stroke-width="1" class="dark:stroke-gray-700"/>
                    <line x1="60" y1="218" x2="560" y2="218" stroke="#e5e7eb" stroke-width="1" class="dark:stroke-gray-600"/>

                    {{-- X-axis labels --}}
                    <text x="100" y="235" font-family="sans-serif" font-size="10" fill="#6b7280" text-anchor="middle">Jan</text>
                    <text x="160" y="235" font-family="sans-serif" font-size="10" fill="#6b7280" text-anchor="middle">Feb</text>
                    <text x="220" y="235" font-family="sans-serif" font-size="10" fill="#6b7280" text-anchor="middle">Mar</text>
                    <text x="280" y="235" font-family="sans-serif" font-size="10" fill="#6b7280" text-anchor="middle">Apr</text>
                    <text x="340" y="235" font-family="sans-serif" font-size="10" fill="#6b7280" text-anchor="middle">May</text>
                    <text x="400" y="235" font-family="sans-serif" font-size="10" fill="#6b7280" text-anchor="middle">Jun</text>
                    <text x="460" y="235" font-family="sans-serif" font-size="10" fill="#6b7280" text-anchor="middle">Jul</text>
                    <text x="520" y="235" font-family="sans-serif" font-size="10" fill="#6b7280" text-anchor="middle">Aug</text>

                    {{-- Line: Involuntary (blue) --}}
                    <polyline points="100,90 160,85 220,95 280,88 340,92 400,85 460,80 520,75" fill="none" stroke="#3b82f6" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"/>
                    <circle cx="100" cy="90" r="4" fill="#3b82f6"/>
                    <circle cx="160" cy="85" r="4" fill="#3b82f6"/>
                    <circle cx="220" cy="95" r="4" fill="#3b82f6"/>
                    <circle cx="280" cy="88" r="4" fill="#3b82f6"/>
                    <circle cx="340" cy="92" r="4" fill="#3b82f6"/>
                    <circle cx="400" cy="85" r="4" fill="#3b82f6"/>
                    <circle cx="460" cy="80" r="4" fill="#3b82f6"/>
                    <circle cx="520" cy="75" r="4" fill="#3b82f6"/>

                    {{-- Line: Voluntary (green) --}}
                    <polyline points="100,165 160,160 220,155 280,150 340,155 400,148 460,145 520,140" fill="none" stroke="#22c55e" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"/>
                    <circle cx="100" cy="165" r="4" fill="#22c55e"/>
                    <circle cx="160" cy="160" r="4" fill="#22c55e"/>
                    <circle cx="220" cy="155" r="4" fill="#22c55e"/>
                    <circle cx="280" cy="150" r="4" fill="#22c55e"/>
                    <circle cx="340" cy="155" r="4" fill="#22c55e"/>
                    <circle cx="400" cy="148" r="4" fill="#22c55e"/>
                    <circle cx="460" cy="145" r="4" fill="#22c55e"/>
                    <circle cx="520" cy="140" r="4" fill="#22c55e"/>

                    {{-- Line: LPR Staging (purple) --}}
                    <polyline points="100,140 160,135 220,130 280,125 340,130 400,120 460,115 520,110" fill="none" stroke="#8b5cf6" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"/>
                    <circle cx="100" cy="140" r="4" fill="#8b5cf6"/>
                    <circle cx="160" cy="135" r="4" fill="#8b5cf6"/>
                    <circle cx="220" cy="130" r="4" fill="#8b5cf6"/>
                    <circle cx="280" cy="125" r="4" fill="#8b5cf6"/>
                    <circle cx="340" cy="130" r="4" fill="#8b5cf6"/>
                    <circle cx="400" cy="120" r="4" fill="#8b5cf6"/>
                    <circle cx="460" cy="115" r="4" fill="#8b5cf6"/>
                    <circle cx="520" cy="110" r="4" fill="#8b5cf6"/>

                    {{-- Legend --}}
                    <line x1="80" y1="261" x2="100" y2="261" stroke="#3b82f6" stroke-width="2.5"/>
                    <text x="107" y="265" font-family="sans-serif" font-size="11" fill="#374151" class="dark:fill-gray-300">Involuntary</text>

                    <line x1="200" y1="261" x2="220" y2="261" stroke="#22c55e" stroke-width="2.5"/>
                    <text x="227" y="265" font-family="sans-serif" font-size="11" fill="#374151" class="dark:fill-gray-300">Voluntary</text>

                    <line x1="310" y1="261" x2="330" y2="261" stroke="#8b5cf6" stroke-width="2.5"/>
                    <text x="337" y="265" font-family="sans-serif" font-size="11" fill="#374151" class="dark:fill-gray-300">LPR Staging</text>
                </svg>
            </div>

            <h4 class="text-base font-semibold text-gray-700 dark:text-gray-300 mt-6 mb-4">Recovery Types</h4>
            <div class="overflow-x-auto mb-6">
                <table class="w-full border-collapse">
                    <thead>
                        <tr class="bg-gray-50 dark:bg-gray-800">
                            <th class="px-4 py-3 text-left text-sm font-semibold text-gray-700 dark:text-gray-300 border-b border-gray-200 dark:border-gray-700">Type</th>
                            <th class="px-4 py-3 text-left text-sm font-semibold text-gray-700 dark:text-gray-300 border-b border-gray-200 dark:border-gray-700">Description</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td class="px-4 py-3 text-gray-600 dark:text-gray-400 border-b border-gray-200 dark:border-gray-700"><strong>Voluntary</strong></td>
                            <td class="px-4 py-3 text-gray-600 dark:text-gray-400 border-b border-gray-200 dark:border-gray-700">Borrower surrendered the vehicle</td>
                        </tr>
                        <tr>
                            <td class="px-4 py-3 text-gray-600 dark:text-gray-400 border-b border-gray-200 dark:border-gray-700"><strong>Involuntary</strong></td>
                            <td class="px-4 py-3 text-gray-600 dark:text-gray-400 border-b border-gray-200 dark:border-gray-700">Standard field recovery</td>
                        </tr>
                        <tr>
                            <td class="px-4 py-3 text-gray-600 dark:text-gray-400 border-b border-gray-200 dark:border-gray-700"><strong>Investigate</strong></td>
                            <td class="px-4 py-3 text-gray-600 dark:text-gray-400 border-b border-gray-200 dark:border-gray-700">Required investigation before recovery</td>
                        </tr>
                        <tr>
                            <td class="px-4 py-3 text-gray-600 dark:text-gray-400 border-b border-gray-200 dark:border-gray-700"><strong>Impound</strong></td>
                            <td class="px-4 py-3 text-gray-600 dark:text-gray-400 border-b border-gray-200 dark:border-gray-700">Vehicle was impounded</td>
                        </tr>
                        <tr>
                            <td class="px-4 py-3 text-gray-600 dark:text-gray-400 border-b border-gray-200 dark:border-gray-700"><strong>LPR Staging</strong></td>
                            <td class="px-4 py-3 text-gray-600 dark:text-gray-400 border-b border-gray-200 dark:border-gray-700">Located via LPR scanning</td>
                        </tr>
                        <tr>
                            <td class="px-4 py-3 text-gray-600 dark:text-gray-400 border-b border-gray-200 dark:border-gray-700"><strong>Unknown</strong></td>
                            <td class="px-4 py-3 text-gray-600 dark:text-gray-400 border-b border-gray-200 dark:border-gray-700">Recovery type not specified</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <h4 class="text-base font-semibold text-gray-700 dark:text-gray-300 mt-6 mb-4">How to Read It</h4>
            <p class="text-gray-600 dark:text-gray-400">
                Lower lines are better&mdash;they indicate faster recoveries. Watch for trends moving up or down over time to understand how your recovery speed is changing.
            </p>

            <h3 class="text-lg font-semibold text-gray-800 dark:text-gray-200 mt-8 mb-4">Historical LPR Data Coverage Chart</h3>
            <p class="text-gray-600 dark:text-gray-400 mb-6">
                This stacked bar chart shows the coverage and overlap between your assignments and existing LPR scan data over time, helping you visualize how much of your historical assignment portfolio had prior LPR scan visibility.
            </p>

            {{-- Historical Coverage Breakdown Chart Diagram --}}
            <div class="my-6 text-center">
                <svg viewBox="0 0 600 280" xmlns="http://www.w3.org/2000/svg" class="max-w-full h-auto">
                    {{-- Background --}}
                    <rect x="0" y="0" width="600" height="280" fill="white" class="dark:fill-gray-800"/>

                    {{-- Y-axis --}}
                    <line x1="60" y1="30" x2="60" y2="220" stroke="#e5e7eb" stroke-width="1" class="dark:stroke-gray-600"/>
                    <text x="50" y="35" font-family="sans-serif" font-size="10" fill="#6b7280" text-anchor="end">500</text>
                    <text x="50" y="82" font-family="sans-serif" font-size="10" fill="#6b7280" text-anchor="end">375</text>
                    <text x="50" y="129" font-family="sans-serif" font-size="10" fill="#6b7280" text-anchor="end">250</text>
                    <text x="50" y="176" font-family="sans-serif" font-size="10" fill="#6b7280" text-anchor="end">125</text>
                    <text x="50" y="223" font-family="sans-serif" font-size="10" fill="#6b7280" text-anchor="end">0</text>

                    {{-- Grid lines --}}
                    <line x1="60" y1="30" x2="560" y2="30" stroke="#f3f4f6" stroke-width="1" class="dark:stroke-gray-700"/>
                    <line x1="60" y1="77" x2="560" y2="77" stroke="#f3f4f6" stroke-width="1" class="dark:stroke-gray-700"/>
                    <line x1="60" y1="124" x2="560" y2="124" stroke="#f3f4f6" stroke-width="1" class="dark:stroke-gray-700"/>
                    <line x1="60" y1="171" x2="560" y2="171" stroke="#f3f4f6" stroke-width="1" class="dark:stroke-gray-700"/>
                    <line x1="60" y1="218" x2="560" y2="218" stroke="#e5e7eb" stroke-width="1" class="dark:stroke-gray-600"/>

                    {{-- Stacked bars for each month --}}
                    {{-- Jan --}}
                    <rect x="70" y="138" width="35" height="80" fill="#22c55e" fill-opacity="0.7" rx="2"/>
                    <rect x="70" y="178" width="35" height="40" fill="#8b5cf6" fill-opacity="0.7" rx="2"/>
                    <text x="87" y="235" font-family="sans-serif" font-size="10" fill="#6b7280" text-anchor="middle">Mar</text>

                    {{-- Feb --}}
                    <rect x="115" y="128" width="35" height="90" fill="#22c55e" fill-opacity="0.7" rx="2"/>
                    <rect x="115" y="173" width="35" height="45" fill="#8b5cf6" fill-opacity="0.7" rx="2"/>
                    <text x="132" y="235" font-family="sans-serif" font-size="10" fill="#6b7280" text-anchor="middle">Apr</text>

                    {{-- Mar --}}
                    <rect x="160" y="118" width="35" height="100" fill="#22c55e" fill-opacity="0.7" rx="2"/>
                    <rect x="160" y="168" width="35" height="50" fill="#8b5cf6" fill-opacity="0.7" rx="2"/>
                    <text x="177" y="235" font-family="sans-serif" font-size="10" fill="#6b7280" text-anchor="middle">May</text>

                    {{-- Apr --}}
                    <rect x="205" y="108" width="35" height="110" fill="#22c55e" fill-opacity="0.7" rx="2"/>
                    <rect x="205" y="163" width="35" height="55" fill="#8b5cf6" fill-opacity="0.7" rx="2"/>
                    <text x="222" y="235" font-family="sans-serif" font-size="10" fill="#6b7280" text-anchor="middle">Jun</text>

                    {{-- May --}}
                    <rect x="250" y="98" width="35" height="120" fill="#22c55e" fill-opacity="0.7" rx="2"/>
                    <rect x="250" y="158" width="35" height="60" fill="#8b5cf6" fill-opacity="0.7" rx="2"/>
                    <text x="267" y="235" font-family="sans-serif" font-size="10" fill="#6b7280" text-anchor="middle">Jul</text>

                    {{-- Jun --}}
                    <rect x="295" y="88" width="35" height="130" fill="#22c55e" fill-opacity="0.7" rx="2"/>
                    <rect x="295" y="153" width="35" height="65" fill="#8b5cf6" fill-opacity="0.7" rx="2"/>
                    <text x="312" y="235" font-family="sans-serif" font-size="10" fill="#6b7280" text-anchor="middle">Aug</text>

                    {{-- Jul --}}
                    <rect x="340" y="78" width="35" height="140" fill="#22c55e" fill-opacity="0.7" rx="2"/>
                    <rect x="340" y="148" width="35" height="70" fill="#8b5cf6" fill-opacity="0.7" rx="2"/>
                    <text x="357" y="235" font-family="sans-serif" font-size="10" fill="#6b7280" text-anchor="middle">Sep</text>

                    {{-- Aug --}}
                    <rect x="385" y="68" width="35" height="150" fill="#22c55e" fill-opacity="0.7" rx="2"/>
                    <rect x="385" y="143" width="35" height="75" fill="#8b5cf6" fill-opacity="0.7" rx="2"/>
                    <text x="402" y="235" font-family="sans-serif" font-size="10" fill="#6b7280" text-anchor="middle">Oct</text>

                    {{-- Sep --}}
                    <rect x="430" y="58" width="35" height="160" fill="#22c55e" fill-opacity="0.7" rx="2"/>
                    <rect x="430" y="138" width="35" height="80" fill="#8b5cf6" fill-opacity="0.7" rx="2"/>
                    <text x="447" y="235" font-family="sans-serif" font-size="10" fill="#6b7280" text-anchor="middle">Nov</text>

                    {{-- Oct --}}
                    <rect x="475" y="48" width="35" height="170" fill="#22c55e" fill-opacity="0.7" rx="2"/>
                    <rect x="475" y="133" width="35" height="85" fill="#8b5cf6" fill-opacity="0.7" rx="2"/>
                    <text x="492" y="235" font-family="sans-serif" font-size="10" fill="#6b7280" text-anchor="middle">Dec</text>

                    {{-- Nov --}}
                    <rect x="520" y="38" width="35" height="180" fill="#22c55e" fill-opacity="0.7" rx="2"/>
                    <rect x="520" y="128" width="35" height="90" fill="#8b5cf6" fill-opacity="0.7" rx="2"/>
                    <text x="537" y="235" font-family="sans-serif" font-size="10" fill="#6b7280" text-anchor="middle">Jan</text>

                    {{-- Legend --}}
                    <rect x="150" y="255" width="12" height="12" fill="#22c55e" fill-opacity="0.7" rx="2"/>
                    <text x="167" y="265" font-family="sans-serif" font-size="11" fill="#374151" class="dark:fill-gray-300">Total Assignments</text>

                    <rect x="320" y="255" width="12" height="12" fill="#8b5cf6" fill-opacity="0.7" rx="2"/>
                    <text x="337" y="265" font-family="sans-serif" font-size="11" fill="#374151" class="dark:fill-gray-300">Historical LPR Data</text>
                </svg>
            </div>

            <h4 class="text-base font-semibold text-gray-700 dark:text-gray-300 mt-6 mb-4">What Each Bar Shows</h4>
            <div class="overflow-x-auto mb-6">
                <table class="w-full border-collapse">
                    <thead>
                        <tr class="bg-gray-50 dark:bg-gray-800">
                            <th class="px-4 py-3 text-left text-sm font-semibold text-gray-700 dark:text-gray-300 border-b border-gray-200 dark:border-gray-700">Dataset</th>
                            <th class="px-4 py-3 text-left text-sm font-semibold text-gray-700 dark:text-gray-300 border-b border-gray-200 dark:border-gray-700">Color</th>
                            <th class="px-4 py-3 text-left text-sm font-semibold text-gray-700 dark:text-gray-300 border-b border-gray-200 dark:border-gray-700">Description</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td class="px-4 py-3 text-gray-600 dark:text-gray-400 border-b border-gray-200 dark:border-gray-700"><strong>Total Assignments</strong></td>
                            <td class="px-4 py-3 border-b border-gray-200 dark:border-gray-700">
                                <span class="inline-block w-4 h-4 rounded bg-green-500 opacity-70"></span>
                                <span class="text-gray-600 dark:text-gray-400 ml-2">Green</span>
                            </td>
                            <td class="px-4 py-3 text-gray-600 dark:text-gray-400 border-b border-gray-200 dark:border-gray-700">Count of unique hotlists accepted during each month</td>
                        </tr>
                        <tr>
                            <td class="px-4 py-3 text-gray-600 dark:text-gray-400 border-b border-gray-200 dark:border-gray-700"><strong>Historical LPR Data</strong></td>
                            <td class="px-4 py-3 border-b border-gray-200 dark:border-gray-700">
                                <span class="inline-block w-4 h-4 rounded bg-purple-500 opacity-70"></span>
                                <span class="text-gray-600 dark:text-gray-400 ml-2">Purple</span>
                            </td>
                            <td class="px-4 py-3 text-gray-600 dark:text-gray-400 border-b border-gray-200 dark:border-gray-700">Count of unique historical hits from LPR scanning</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <h4 class="text-base font-semibold text-gray-700 dark:text-gray-300 mt-6 mb-4">How to Read It</h4>
            <p class="text-gray-600 dark:text-gray-400 mb-4">
                Each month shows two overlapping bars. The green bar represents your total assignments for that month, while the purple bar shows how many of those assignments had prior LPR scan data in our system.
            </p>
            <p class="text-gray-600 dark:text-gray-400 mb-4">
                When the purple bar is close in height to the green bar, it indicates good historical coverage&mdash;meaning we had prior location data for many of your assigned vehicles. A smaller purple bar relative to green suggests vehicles that didn't have prior LPR scan history.
            </p>
            <div class="methodology-info-box">
                <div class="font-semibold text-primary-600 dark:text-primary-400 mb-2">Why This Matters</div>
                <p class="text-gray-600 dark:text-gray-400 mb-0">
                    Higher historical coverage means more vehicles in your portfolio were previously spotted by LPR scanning, potentially giving you location intelligence to work with from day one of the assignment.
                </p>
            </div>
        </x-filament::section>

        {{-- Section 8: Glossary --}}
        <x-filament::section id="glossary">
            <x-slot name="heading">
                <div class="flex items-center">
                    <span class="methodology-section-number">8</span>
                    <span>Glossary</span>
                </div>
            </x-slot>

            <p class="text-gray-600 dark:text-gray-400 mb-6">
                Quick reference for common terms used throughout the dashboard.
            </p>

            <div class="methodology-glossary-grid">
                <div class="methodology-glossary-item">
                    <div class="font-semibold text-gray-900 dark:text-white mb-1">Assignment</div>
                    <div class="text-sm text-gray-600 dark:text-gray-400">A request to recover a specific vehicle, also known as a "hotlist" entry.</div>
                </div>
                <div class="methodology-glossary-item">
                    <div class="font-semibold text-gray-900 dark:text-white mb-1">Hotlist</div>
                    <div class="text-sm text-gray-600 dark:text-gray-400">The list of vehicles assigned for recovery.</div>
                </div>
                <div class="methodology-glossary-item">
                    <div class="font-semibold text-gray-900 dark:text-white mb-1">Repossession (Repo)</div>
                    <div class="text-sm text-gray-600 dark:text-gray-400">Successfully recovering and securing a vehicle.</div>
                </div>
                <div class="methodology-glossary-item">
                    <div class="font-semibold text-gray-900 dark:text-white mb-1">LPR</div>
                    <div class="text-sm text-gray-600 dark:text-gray-400">License Plate Recognition&mdash;camera technology that automatically reads license plates to identify target vehicles.</div>
                </div>
                <div class="methodology-glossary-item">
                    <div class="font-semibold text-gray-900 dark:text-white mb-1">Recovery Rate</div>
                    <div class="text-sm text-gray-600 dark:text-gray-400">The percentage of assignments that result in successful vehicle recovery.</div>
                </div>
                <div class="methodology-glossary-item">
                    <div class="font-semibold text-gray-900 dark:text-white mb-1">Open Status</div>
                    <div class="text-sm text-gray-600 dark:text-gray-400">An assignment that is actively being worked.</div>
                </div>
                <div class="methodology-glossary-item">
                    <div class="font-semibold text-gray-900 dark:text-white mb-1">Hold Status</div>
                    <div class="text-sm text-gray-600 dark:text-gray-400">An assignment temporarily paused (e.g., awaiting client instruction).</div>
                </div>
                <div class="methodology-glossary-item">
                    <div class="font-semibold text-gray-900 dark:text-white mb-1">Closed Status</div>
                    <div class="text-sm text-gray-600 dark:text-gray-400">An assignment that has ended without recovery.</div>
                </div>
            </div>
        </x-filament::section>

        {{-- Footer --}}
        <div class="text-center text-gray-500 dark:text-gray-400 text-sm pt-6 border-t border-gray-200 dark:border-gray-700">
            <p class="font-semibold">Clarity by Insight LPR</p>
            <p class="mt-2">Questions? Contact your account representative or visit our support portal.</p>
            <p class="mt-4 text-gray-400 dark:text-gray-500">Document Version 1.1 &bull; February 2026</p>
        </div>
    </div>
</x-filament-panels::page>
