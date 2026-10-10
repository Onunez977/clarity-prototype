<?php

return [
    'releases' => [
        'v3.10.3' => [
            'date' => 'October 8th, 2026',
            'changes' => [
                'Fixed Client Analytics Stats by Location so the branch filter returns matching data.',
                'Improved LPR Assists and Missed Opportunities so they respect the current branch filter.',
                'Updated the LPR Repossessions CSV export filename and tooltip to show the exported date window.',
                'Fixed RDN case lookups so unauthorized cases are treated as a miss instead of retrying.',
            ],
        ],
        'v3.10.2' => [
            'date' => 'October 7th, 2026',
            'changes' => [
                'Added CSV export of LPR Repossessions when the chart is expanded.',
                'Let Otto read Company Metrics and other page widgets the user can see.',
                'Added a collapse control for Otto\'s fullscreen history sidebar.',
            ],
        ],
        'v3.10.1' => [
            'date' => 'October 1st, 2026',
            'changes' => [
                'Fixed Hit Alerts so bad notification settings no longer drop SMS, email, or Slack alerts.',
                'Fixed Distance From Given and Nearest Given Addresses to use any geocoded hotlist address.',
                'Improved LPR Repossessions recovery counting accuracy on Company Metrics.',
                'Scout: Made the Orders density legend clickable to filter map bands.',
                'Fixed Otto follow-up replies so they appear under the user message.',
            ],
        ],
        'v3.10.0' => [
            'date' => 'September 28th, 2026',
            'changes' => [
                'Added Scan Counts, LPR Case Claims, LPR Repossessions, and Missed Opportunities to Company Metrics.',
                'Added Scans, LPR Recovery, and Staging Live Hit leaderboards to Company Metrics.',
                'Moved Active Kit Users into the Company Metrics header.',
                'Renamed Repossessions to Total Repossessions and Recovery Leaderboard to LPR Recovery Leaderboard.',
                'Combined paired Company Metrics cards and removed Unique from Scan Counts and LPR Case Claims.',
                'Fixed Company Metrics ratios to show scans per result in your timezone.',
                'Fixed the LPR Recovery Leaderboard to show the Kit User for the selected period.',
                'Improved Company Metrics loading speed and the accuracy of claims and repossession counts.',
                'Improved LPR Assist recovery counting accuracy.',
                'Added filter-reactive Live, Historical, and Staging hit cards to the Hits page.',
                'Pro Feature: Fixed Distance From Given and Nearest Given Addresses on the Hits page.',
                'Fixed the GL Kits page to list GLs, with last-scan and reporting sorting.',
                'Fixed Zone Manager ZIP chips in Light Mode and editing a premade ZIP zone.',
                'Improved Scans search so plate lookups finish instead of timing out.',
                'Scout: Fixed recent hits and hit density to match the current hotlist status.',
                'Scout: Fixed zone creation from ZIP codes.',
                'Scout: Clarified all-driver totals on the Org Map.',
                'Scout: Fixed the Org Map date range and sped up the hit list on wide ranges.',
                'Scout: Fixed opening the latest live hit and enlarged its scan image.',
                'Scout: Fixed resending driver invites and account recovery when the email is forgotten.',
                'Scout: Fixed Login as Scout User so it no longer fails without an error.',
                'Scout: Fixed shifts ending while an admin is viewing as a driver.',
            ],
        ],
        'v3.9.2' => [
            'date' => 'September 1st, 2026',
            'changes' => [
                'Company Metrics hit cards now show the unique plate count as the main figure, with total hits alongside; expanded charts plot both.',
                'Refined the Company Metrics card layout so every card matches the top row and sparklines have more room.',
                'Kit Users card now describes inactive kits rather than total cameras.',
                'Kit User Path now falls back to scan history when enriched location data is unavailable, so paths appear for scan-only kits.',
                'Added a GL Kits page listing kits by company with their owner, last scan received, and recent reporting status.',
                'Fixed branch filtering and date handling on Client Analytics so totals match the selected branch and time range.',
                'Scout: Fixed the invalid signature error that blocked some driver invite links.',
                'Scout: Improved the accuracy of hit counts in shift summaries and map hit density.',
            ],
        ],
        'v3.9.1' => [
            'date' => 'August 20th, 2026',
            'changes' => [
                'Renamed the Dashboard to Company Metrics.',
                'Redesigned Company Metrics as compact cards with month-to-date totals and trend sparklines; select a card to expand its full chart.',
                'Scout: Added a Teammate Activity map layer so drivers can see which streets have already been covered.',
                'Scout: Added org-level control over which map layers drivers can see.',
                'Scout: Org Map historical paths now render as continuous driving routes, matching Kit User Path.',
            ],
        ],
        'v3.9.0' => [
            'date' => 'August 12th, 2026',
            'changes' => [
                'Scout: Updates User creation',
                'Scout: Update date ranges',
                'Scout: Show Live pins regardless of date range filter',
                'Updates Kit User Path',
            ],
        ],
        'v3.8.2' => [
            'date' => 'August 5th, 2026',
            'changes' => [
                'Scout: Enable Hit Disposition on Org Map',
                'Scout: Show Scout Drivers with scanning activity',
                'Fixed an issue with the RDN Metrics toggle',
            ],
        ],
        'v3.8.1' => [
            'date' => 'July 24th, 2026',
            'changes' => [
                'Fixed Missed Opportunity Dispositions saving',
            ],
        ],
        'v3.8.0' => [
            'date' => 'July 22nd, 2026',
            'changes' => [
                'Scout: .kml import option for Zones',
                'Scout: Disposition Hits',
            ],
        ],
        'v3.7.4-3.7.5' => [
            'date' => 'July 14th, 2026',
            'changes' => [
                'Scout: UI Changes',
            ],
        ],
        'v3.7.3' => [
            'date' => 'July 10th, 2026',
            'changes' => [
                'Fixed Missed Opportunity Dispositions taking too long to sync',
            ],
        ],
        'v3.7.2' => [
            'date' => 'July 2nd, 2026',
            'changes' => [
                'Scout: UI Changes',
            ],
        ],
        'v3.7.1' => [
            'date' => 'July 1st, 2026',
            'changes' => [
                'Scout: UI Changes',
            ],
        ],
        'v3.7.0' => [
            'date' => 'June 30th, 2026',
            'changes' => [
                'Beta: Clarity Scout',
            ],
        ],
        'v3.6.2' => [
            'date' => 'June 25th, 2026',
            'changes' => [
                'Fixed Dashboard hit counts over-counting and fluctuating for the current month; live, historical, and staging totals are now stable and accurate.',
                'Fixed the "Error while loading page" message that could appear on the Subscription page.',
            ],
        ],
        'v3.6.1' => [
            'date' => 'June 16th, 2026',
            'changes' => [
                'Pro Plus Feature: Hit Webhooks — receive a real-time HTTP POST to your own endpoint whenever a hit fires, with the full vehicle, scan, and order details.',
                'Fixed Missed Opportunity alerts firing against closed or repossessed hotlists.',
                'Fixed Invalid-marked Missed Opportunities still appearing in the default view.',
                'Fixed Dashboard bar graphs showing a duplicate month and dropping another.',
                'Fixed new client logins landing on the subscription page with no way to navigate.',
            ],
        ],
        'v3.6.0' => [
            'date' => 'May 20th, 2026',
            'changes' => [
                'Split Repo Heatmap, Staging Hotlists, and Hit Density into independent, toggleable layers on Kit User Path.',
                'Renamed Missed Opportunity Historical and added a Repo Date column for clearer record context.',
                'Replaced the Beamer notification bell with a "What\'s New" link in the topbar.',
                'Added visual differentiation for FlexSearch close matches.',
                'Fixed Hit resend so an independent Scan can be sent to ClearPlan.',
            ],
        ],
        'v3.5.1' => [
            'date' => 'April 22nd, 2026',
            'changes' => [
                'Pro Feature: FlexMatch alert for Scans lets you send a probable match to ClearPlan.',
            ],
        ],
        'v3.5.0' => [
            'date' => 'April 14th, 2026',
            'changes' => [
                'Added Missed Opportunity Historical type for distinguishing between standard and historical records.',
                'Added tooltips to Dashboard Hotlist Count stats for clearer metric descriptions.',
                'Pro Feature: Hit Address Distance showing proximity to hotlist addresses on the Hits page.',
                'Pro Feature: Hit Density Heatmap for visualizing hit concentration areas on Kit User Path.',
                'Pro Feature: Resend a selected Hit to Clearplan',
            ],
        ],
        'v3.4.1' => [
            'date' => 'March 27th, 2026',
            'changes' => [
                'Updating LPR Visit counting on Location Clusters.',
            ],
        ],
        'v3.4.0' => [
            'date' => 'March 19th, 2026',
            'changes' => [
                'Added interactive Stat Drilldown on Client Analytics for detailed metric breakdowns.',
                'Added LPR Assist tracking with plate lookup to see which plates were involved in assisted recoveries.',
                'Added Pro subscription management.',
            ],
        ],
        'v3.3.2' => [
            'date' => 'March 4th, 2026',
            'changes' => [
                'Improved data accuracy and loading speed.',
            ],
        ],
        'v3.3.0-v3.3.1' => [
            'date' => 'February 26th, 2026',
            'changes' => [
                'Improved performance with faster data loading.',
                'Pro Feature: Hit Clustering',
            ],
        ],
        'v3.2.1' => [
            'date' => 'February 19th, 2026',
            'changes' => [
                'Added 30-day date range option to Kit User Path.',
                'Improved heatmap display by density on Kit User Path.',
            ],
        ],
        'v3.2.0' => [
            'date' => 'February 16th, 2026',
            'changes' => [
                'Added address-based, heatmap filtering to Kit User Path.',
            ],
        ],
        'v3.1.0' => [
            'date' => 'February 12th, 2026',
            'changes' => [
                'Alert notifications now support bundling multiple scans into a single notification.',
                'Fixed duplicate notifications.',
            ],
        ],
        'v3.0.1' => [
            'date' => 'February 4th, 2026',
            'changes' => [
                'Added "How-To" Guide for Client Analytics.',
                'Updated notification formatting.',
            ],
        ],
        'v3.0.0' => [
            'date' => 'February 1st, 2026',
            'changes' => [
                'Pro Feature: FlexSearch',
                'Pro Feature: Missed Opportunities',
                'Pro Feature: Alert Settings',
                'Pro Feature: Scan Clustering',
                'Pro Feature: Client Analytics',
            ],
        ],
        'v2.3.1' => [
            'date' => 'December 15th, 2025',
            'changes' => [
                'Updated Dashboards.',
            ],
        ],
        'v2.3.0' => [
            'date' => 'December 1st, 2025',
            'changes' => [
                'Added Notification Logs for tracking email, SMS, and Slack notifications.',
                'Improved Client Dashboard performance with optimized data loading.',
            ],
        ],
        'v2.2.1' => [
            'date' => 'November 12th, 2025',
            'changes' => [
                'Improved error handling to prevent disruptions when external services are unavailable.',
            ],
        ],
        'v2.2.0' => [
            'date' => 'November 11th, 2025',
            'changes' => [
                'Added custom client analytics dashboard.',
            ],
        ],
        'v2.1.6' => [
            'date' => 'November 5th, 2025',
            'changes' => [
                'Added Kit User filter to Hits page.',
                'Added Close Date to Missed Opportunities page.',
            ],
        ],
        'v2.1.5' => [
            'date' => 'October 28th, 2025',
            'changes' => [
                'Updates performance.',
            ],
        ],
        'v2.1.3-v2.1.4' => [
            'date' => 'October 24th, 2025',
            'changes' => [
                'Updated Dashboard Hotlist widgets.',
                'Updated Dashboard Leader widgets.',
            ],
        ],
        'v2.1.2' => [
            'date' => 'October 16th, 2025',
            'changes' => [
                'Fixed scan cluster display not updating properly when performing new searches.',
            ],
        ],
        'v2.1.1' => [
            'date' => 'October 13th, 2025',
            'changes' => [
                'Fixed scan cluster sorting to properly organize scan groups.',
                'Improved plate image popup sizing for better visibility.',
                'Updated table pagination for improved performance.',
            ],
        ],
        'v2.1.0' => [
            'date' => 'October 2nd, 2025',
            'changes' => [
                'Revamp dashboard to show more historical data for month-over-month analytics.',
                'Improved scan clustering to better group scans by location and time.',
            ],
        ],
        'v2.0.6' => [
            'date' => 'September 30th, 2025',
            'changes' => [
                'Fixed sorting error in Kit User Recoveries report.',
                'Resolved pagination issue when navigating between different page types.',
            ],
        ],
        'v2.0.5' => [
            'date' => 'September 29th, 2025',
            'changes' => [
                'Optimized performance to lower timeouts.',
            ],
        ],
        'v2.0.4' => [
            'date' => 'September 15th, 2025',
            'changes' => [
                'Fixed pagination errors.',
                'Improved alert settings email validation.',
                'Enhanced monitoring performance.',
            ],
        ],
        'v2.0.3' => [
            'date' => 'September 12th, 2025',
            'changes' => [
                'Improved email template styling for better visual appearance.',
            ],
        ],
        'v2.0.2' => [
            'date' => 'September 10th, 2025',
            'changes' => [
                'Removed 30 day option from Kit User Path for better performance.',
                'Fixed alert settings form layout issues.',
                'Updated Missed Opportunities email template.',
            ],
        ],
        'v2.0.1' => [
            'date' => 'September 5th, 2025',
            'changes' => [
                'Email formatting.',
            ],
        ],
        'v2.0.0' => [
            'date' => 'September 3rd, 2025',
            'changes' => [
                'Upgraded backend services for improved performance.',
            ],
        ],
        'v1.4.0' => [
            'date' => 'August 25th, 2025',
            'changes' => [
                'Added <a href="/app/profile">Profile</a> page.',
                'Added Hotlist CSV Export.',
                'Improved Heatmap colors on Kit User Path for better visibility.',
                'Added Bulk Disposition feature.<br/><small>Click on a table\'s row checkbox for the "Bulk Disposition" button to appear at the top of the table.</small>',
            ],
        ],
        'v1.3.2' => [
            'date' => 'August 12th, 2025',
            'changes' => [
                'Slack Webhook Notifications.',
            ],
        ],
        'v1.3.1' => [
            'date' => 'August 4th, 2025',
            'changes' => [
                'Custom Alert Settings.',
                '"Copy to Clipboard" on clustered Scan groups.',
            ],
        ],
        'v1.3.0' => [
            'date' => 'July 28th, 2025',
            'changes' => [
                'New notifications queue.',
            ],
        ],
        'v1.2.1' => [
            'date' => 'July 21st, 2025',
            'changes' => [
                'Improved Plate Image popup performance.',
            ],
        ],
        'v1.2.0' => [
            'date' => 'July 14th, 2025',
            'changes' => [
                'Improved GeoHash display on Kit User Path.',
                'Scan clustering and grouping options.',
            ],
        ],
        'v1.1.2' => [
            'date' => 'June 30th, 2025',
            'changes' => [
                'Minor backend improvements and processing updates.',
            ],
        ],
        'v1.1.1' => [
            'date' => 'June 23rd, 2025',
            'changes' => [
                'Plate searching improves: "fuzzy" search and search by pressing "Enter."',
            ],
        ],
        'v1.1.0' => [
            'date' => 'June 20th, 2025',
            'changes' => [
                'Added granular Feature Flags at a company-level.',
            ],
        ],
        'v1.0.0' => [
            'date' => 'June 3rd, 2025',
            'changes' => [
                'New and improved design.',
                'Entire backend overhaul.',
            ],
        ],
    ],
];
