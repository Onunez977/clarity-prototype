<template>
    <SidePanel :open="open" title="Help" width="520px" @update:open="$emit('close')">
        <!-- Hero / Welcome card -->
        <div class="bg-gradient-to-br from-blue-600 to-blue-800 rounded-2xl p-6 mb-6 text-white shadow-lg">
            <h1 class="text-2xl font-bold mb-1">Welcome to Clarity Scout</h1>
            <p class="text-blue-100 text-base leading-relaxed">
                Your mobile companion for license plate scanning. Follow your live
                position, watch hits land in real time, review your trips, and wrap up
                with an end-of-shift summary — all from your phone.
            </p>
        </div>

        <!-- Quick start cards -->
        <h2 class="text-sm font-semibold text-gray-400 uppercase tracking-wide mb-3">Quick Start</h2>
        <div class="grid grid-cols-2 gap-4 mb-6">
            <button
                @click="scrollTo('guide')"
                class="bg-white rounded-2xl p-5 text-left shadow-sm shadow-gray-200/60 transition-shadow hover:shadow-md hover:shadow-gray-300/40 active:scale-[0.99]"
            >
                <div class="w-10 h-10 rounded-xl bg-blue-100 flex items-center justify-center mb-2">
                    <svg class="w-5 h-5 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 6.042A8.967 8.967 0 006 3.75c-1.052 0-2.062.18-3 .512v14.25A8.987 8.987 0 016 18c2.305 0 4.408.867 6 2.292m0-14.25a8.966 8.966 0 016-2.292c1.052 0 2.062.18 3 .512v14.25A8.987 8.987 0 0018 18a8.967 8.967 0 00-6 2.292m0-14.25v14.25" />
                    </svg>
                </div>
                <p class="text-base font-semibold text-gray-900">User Guide</p>
                <p class="text-sm text-gray-500 mt-0.5">Learn each screen</p>
            </button>
            <button
                @click="scrollTo('install')"
                class="bg-white rounded-2xl p-5 text-left shadow-sm shadow-gray-200/60 transition-shadow hover:shadow-md hover:shadow-gray-300/40 active:scale-[0.99]"
            >
                <div class="w-10 h-10 rounded-xl bg-green-100 flex items-center justify-center mb-2">
                    <svg class="w-5 h-5 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 8.25H7.5a2.25 2.25 0 00-2.25 2.25v9a2.25 2.25 0 002.25 2.25h9a2.25 2.25 0 002.25-2.25v-9a2.25 2.25 0 00-2.25-2.25H15M9 12l3 3m0 0l3-3m-3 3V2.25" />
                    </svg>
                </div>
                <p class="text-base font-semibold text-gray-900">Install App</p>
                <p class="text-sm text-gray-500 mt-0.5">Add to your device</p>
            </button>
            <button
                @click="scrollTo('glossary')"
                class="bg-white rounded-2xl p-5 text-left shadow-sm shadow-gray-200/60 transition-shadow hover:shadow-md hover:shadow-gray-300/40 active:scale-[0.99]"
            >
                <div class="w-10 h-10 rounded-xl bg-purple-100 flex items-center justify-center mb-2">
                    <svg class="w-5 h-5 text-purple-600" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M8.25 6.75h12M8.25 12h12m-12 5.25h12M3.75 6.75h.007v.008H3.75V6.75zm.375 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zM3.75 12h.007v.008H3.75V12zm.375 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zm-.375 5.25h.007v.008H3.75v-.008zm.375 0a.375.375 0 11-.75 0 .375.375 0 01.75 0z" />
                    </svg>
                </div>
                <p class="text-base font-semibold text-gray-900">Glossary</p>
                <p class="text-sm text-gray-500 mt-0.5">What terms mean</p>
            </button>
            <button
                @click="$emit('close')"
                class="bg-white rounded-2xl p-5 text-left shadow-sm shadow-gray-200/60 transition-shadow hover:shadow-md hover:shadow-gray-300/40 active:scale-[0.99]"
            >
                <div class="w-10 h-10 rounded-xl bg-amber-100 flex items-center justify-center mb-2">
                    <svg class="w-5 h-5 text-amber-600" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 13.5l10.5-11.25L12 10.5h8.25L9.75 21.75 12 13.5H3.75z" />
                    </svg>
                </div>
                <p class="text-base font-semibold text-gray-900">Back to Map</p>
                <p class="text-sm text-gray-500 mt-0.5">Start scanning</p>
            </button>
        </div>

        <!-- Sections -->
        <div class="space-y-4">
            <!-- Usage Guide -->
            <div ref="guideRef">
                <AccordionSection ref="guideAccordion" title="How to Use Clarity Scout" icon="book" icon-color="blue" :default-open="true">
                    <div class="space-y-3">
                        <AccordionSection title="The Map (Home)" icon="map" icon-color="blue" :nested="true">
                            <div class="space-y-3">
                                <div class="flex items-start gap-3">
                                    <span class="flex-shrink-0 w-7 h-7 rounded-full bg-blue-100 text-blue-600 text-sm font-bold flex items-center justify-center mt-0.5">1</span>
                                    <div>
                                        <p class="text-base font-medium text-gray-900">One Full-Screen Map</p>
                                        <p class="text-sm text-gray-500 mt-0.5">The whole app lives on a single map. Help and your Profile (and Trips, where enabled) slide over it as panels — you never lose your place. In Historical mode your scan paths draw onto the map: today's drive is red, and older trips fade out over the last 60 days.</p>
                                    </div>
                                </div>
                                <div class="flex items-start gap-3">
                                    <span class="flex-shrink-0 w-7 h-7 rounded-full bg-blue-100 text-blue-600 text-sm font-bold flex items-center justify-center mt-0.5">2</span>
                                    <div>
                                        <p class="text-base font-medium text-gray-900">Live &amp; Historical</p>
                                        <p class="text-sm text-gray-500 mt-0.5">It's one map, driven by the date control in the bottom sheet (Today / 7 / 15 / 30 / 60). You start in <strong>Live</strong> — the green <strong>Today</strong> pill — following your moving position and live trail. Tap 7, 15, 30, or 60 to look back over <strong>Historical</strong> scan paths for that window; tap <strong>Today</strong> to go back live.</p>
                                    </div>
                                </div>
                                <div class="flex items-start gap-3">
                                    <span class="flex-shrink-0 w-7 h-7 rounded-full bg-blue-100 text-blue-600 text-sm font-bold flex items-center justify-center mt-0.5">3</span>
                                    <div>
                                        <p class="text-base font-medium text-gray-900">Date Range &amp; Map Layers</p>
                                        <p class="text-sm text-gray-500 mt-0.5">The date control in the bottom sheet (Today, 7, 15, 30, 60 days) sets the range for your stats and scan paths. The <strong>Map Layers</strong> panel (top-right — a layers chip on a phone) toggles the Live Hit Density, Direct Orders, and Staging Orders overlays, plus the Color Map and Direction Arrows display options; its legend shows only the layers you currently have on.</p>
                                    </div>
                                </div>
                                <div class="flex items-start gap-3">
                                    <span class="flex-shrink-0 w-7 h-7 rounded-full bg-blue-100 text-blue-600 text-sm font-bold flex items-center justify-center mt-0.5">4</span>
                                    <div>
                                        <p class="text-base font-medium text-gray-900">Getting Around</p>
                                        <p class="text-sm text-gray-500 mt-0.5">On a computer, a floating controls widget sits top-left ("Clarity Scout" + connection dot) with your Help, notifications bell, Profile, and — while you're on shift — End Shift buttons. On a phone, that widget is hidden: Help, Notifications, and End Shift move to a row of buttons just above the bottom sheet, and your Profile (with the connection dot) sits top-right next to Map Layers.</p>
                                    </div>
                                </div>
                            </div>
                        </AccordionSection>

                        <AccordionSection title="Stat Cards" icon="grid" icon-color="blue" :nested="true">
                            <div class="space-y-3">
                                <p class="text-sm text-gray-500">The bottom sheet carries the date control and a row of metrics for the selected range. On a phone the sheet collapses to a compact "scans · hits" handle — tap it to expand the full set:</p>
                                <div class="flex items-start gap-3">
                                    <span class="flex-shrink-0 w-7 h-7 rounded-full bg-blue-100 text-blue-600 text-sm font-bold flex items-center justify-center mt-0.5">1</span>
                                    <div>
                                        <p class="text-base font-medium text-gray-900">Scans &amp; My Hits</p>
                                        <p class="text-sm text-gray-500 mt-0.5">The two large cards: plates read by your camera, and how many of <em>your</em> scans matched an order (your hits — distinct from the org-wide Live Hit Density layer). In Historical mode your hits also drop as tappable company-logo markers on the map.</p>
                                    </div>
                                </div>
                                <div class="flex items-start gap-3">
                                    <span class="flex-shrink-0 w-7 h-7 rounded-full bg-blue-100 text-blue-600 text-sm font-bold flex items-center justify-center mt-0.5">2</span>
                                    <div>
                                        <p class="text-base font-medium text-gray-900">Repossessions &amp; Trips</p>
                                        <p class="text-sm text-gray-500 mt-0.5">Smaller cards: recoveries that came from your hits, and the number of driving sessions.</p>
                                    </div>
                                </div>
                                <div class="flex items-start gap-3">
                                    <span class="flex-shrink-0 w-7 h-7 rounded-full bg-blue-100 text-blue-600 text-sm font-bold flex items-center justify-center mt-0.5">3</span>
                                    <div>
                                        <p class="text-base font-medium text-gray-900">Direct &amp; Staging Check-ins</p>
                                        <p class="text-sm text-gray-500 mt-0.5">Check-in counts against your <strong>Direct Orders</strong> (live, active orders) versus <strong>Staging Orders</strong> (orders still being prepared).</p>
                                    </div>
                                </div>
                                <p class="text-sm text-gray-500">Switch to Live mode and the map follows your live position; the Live Hits Pending Repossession card sits at the top-left (a tappable pill on a phone).</p>
                            </div>
                        </AccordionSection>

                        <AccordionSection title="Live Mode" icon="map" icon-color="green" :nested="true">
                            <div class="space-y-3">
                                <div class="flex items-start gap-3">
                                    <span class="flex-shrink-0 w-7 h-7 rounded-full bg-green-100 text-green-600 text-sm font-bold flex items-center justify-center mt-0.5">1</span>
                                    <div>
                                        <p class="text-base font-medium text-gray-900">Your Live Position</p>
                                        <p class="text-sm text-gray-500 mt-0.5">The green <strong>Today</strong> pill keeps you in Live, following your scanner's vehicle icon and trail as you drive. The <strong>My Location</strong> button (the crosshair, off the bottom sheet's right edge — in the phone action row) recenters the map on you. The first time, you'll be asked to "Enable live tracking" — your location stays on your device and is only used to draw your position.</p>
                                    </div>
                                </div>
                                <div class="flex items-start gap-3">
                                    <span class="flex-shrink-0 w-7 h-7 rounded-full bg-green-100 text-green-600 text-sm font-bold flex items-center justify-center mt-0.5">2</span>
                                    <div>
                                        <p class="text-base font-medium text-gray-900">Live Hit Density &amp; Live Hits</p>
                                        <p class="text-sm text-gray-500 mt-0.5">Turn on the <strong>Live Hit Density</strong> layer to see where live hits are concentrating org-wide — Direct and Staging combined — so you can head toward likely matches. The <strong>Live Hits Pending Repossession</strong> card lists your latest open matches with their plate, time, and status — tap one to center it on the map.</p>
                                    </div>
                                </div>
                            </div>
                        </AccordionSection>

                        <AccordionSection title="Zones" icon="map" icon-color="purple" :nested="true">
                            <div class="space-y-3">
                                <div class="flex items-start gap-3">
                                    <span class="flex-shrink-0 w-7 h-7 rounded-full bg-purple-100 text-purple-600 text-sm font-bold flex items-center justify-center mt-0.5">1</span>
                                    <div>
                                        <p class="text-base font-medium text-gray-900">Your Zones</p>
                                        <p class="text-sm text-gray-500 mt-0.5">Your assigned zone(s) outline the area you're responsible for. The <strong>Staging Orders</strong> layer is always clipped to your zone(s). <strong>Direct Orders</strong> is clipped to your zone(s) when you have any assigned, and shows across the whole organization when you don't; Live Hit Density always shows across the whole organization.</p>
                                    </div>
                                </div>
                                <div class="flex items-start gap-3">
                                    <span class="flex-shrink-0 w-7 h-7 rounded-full bg-purple-100 text-purple-600 text-sm font-bold flex items-center justify-center mt-0.5">2</span>
                                    <div>
                                        <p class="text-base font-medium text-gray-900">Reassignment in Real Time</p>
                                        <p class="text-sm text-gray-500 mt-0.5">If your coverage area changes, the map updates instantly and you'll get a notification — tap "View zone" to jump straight to it.</p>
                                    </div>
                                </div>
                            </div>
                        </AccordionSection>

                        <AccordionSection v-if="auth.tripsEnabled" title="Trips" icon="route" icon-color="purple" :nested="true">
                            <div class="space-y-3">
                                <div class="flex items-start gap-3">
                                    <span class="flex-shrink-0 w-7 h-7 rounded-full bg-purple-100 text-purple-600 text-sm font-bold flex items-center justify-center mt-0.5">1</span>
                                    <div>
                                        <p class="text-base font-medium text-gray-900">Trips List</p>
                                        <p class="text-sm text-gray-500 mt-0.5">Open your Profile and tap <strong>View all</strong> in Recent Trips to slide out your days, grouped by month. Each card shows the date, number of trips, total duration, scans, hits, and miles. Filter by date range or scroll to load more.</p>
                                    </div>
                                </div>
                                <div class="flex items-start gap-3">
                                    <span class="flex-shrink-0 w-7 h-7 rounded-full bg-purple-100 text-purple-600 text-sm font-bold flex items-center justify-center mt-0.5">2</span>
                                    <div>
                                        <p class="text-base font-medium text-gray-900">Day View</p>
                                        <p class="text-sm text-gray-500 mt-0.5">Tap a day to draw all its trips on the map. The overview bar above the bottom sheet opens a "Route Overview" sheet — tap a trip to highlight it, or its chevron to open just that trip.</p>
                                    </div>
                                </div>
                                <div class="flex items-start gap-3">
                                    <span class="flex-shrink-0 w-7 h-7 rounded-full bg-purple-100 text-purple-600 text-sm font-bold flex items-center justify-center mt-0.5">3</span>
                                    <div>
                                        <p class="text-base font-medium text-gray-900">Trip View &amp; Hits</p>
                                        <p class="text-sm text-gray-500 mt-0.5">A single trip draws in salmon with green START and gray END markers. The Hits panel groups matches by plate with plate and vehicle images — tap any image for a full-size view.</p>
                                    </div>
                                </div>
                            </div>
                        </AccordionSection>

                        <AccordionSection title="Shifts &amp; End-of-Shift Summary" icon="book" icon-color="amber" :nested="true">
                            <div class="space-y-3">
                                <div class="flex items-start gap-3">
                                    <span class="flex-shrink-0 w-7 h-7 rounded-full bg-amber-100 text-amber-600 text-sm font-bold flex items-center justify-center mt-0.5">1</span>
                                    <div>
                                        <p class="text-base font-medium text-gray-900">End Shift</p>
                                        <p class="text-sm text-gray-500 mt-0.5">While you're on shift, an <strong>End Shift</strong> button shows in the controls widget (top-left on a computer; in the action row above the bottom sheet on a phone). Tapping it ends your shift and emails a summary of the day's miles, scans, hits, and zones covered to you and your company admins.</p>
                                    </div>
                                </div>
                                <div class="flex items-start gap-3">
                                    <span class="flex-shrink-0 w-7 h-7 rounded-full bg-amber-100 text-amber-600 text-sm font-bold flex items-center justify-center mt-0.5">2</span>
                                    <div>
                                        <p class="text-base font-medium text-gray-900">Shift Status</p>
                                        <p class="text-sm text-gray-500 mt-0.5">After you end a shift, a "Shift ended" pill replaces the button. You can also check whether you're on shift in your Profile under Current Shift.</p>
                                    </div>
                                </div>
                            </div>
                        </AccordionSection>

                        <AccordionSection title="Notifications" icon="list" icon-color="amber" :nested="true">
                            <div class="space-y-3">
                                <div class="flex items-start gap-3">
                                    <span class="flex-shrink-0 w-7 h-7 rounded-full bg-amber-100 text-amber-600 text-sm font-bold flex items-center justify-center mt-0.5">1</span>
                                    <div>
                                        <p class="text-base font-medium text-gray-900">The Bell</p>
                                        <p class="text-sm text-gray-500 mt-0.5">The notifications bell — in the controls widget on a computer, in the action row above the bottom sheet on a phone — shows a red badge with your unread count. Open it for your notifications, such as zone reassignments, and tap "Mark all read" to clear them. Tapping a zone notification focuses that zone on the map.</p>
                                    </div>
                                </div>
                                <div class="flex items-start gap-3">
                                    <span class="flex-shrink-0 w-7 h-7 rounded-full bg-amber-100 text-amber-600 text-sm font-bold flex items-center justify-center mt-0.5">2</span>
                                    <div>
                                        <p class="text-base font-medium text-gray-900">Connection Dot</p>
                                        <p class="text-sm text-gray-500 mt-0.5">A small colored dot shows your real-time connection: green = connected, amber = connecting, red = offline. It sits next to "Clarity Scout" in the controls widget on a computer, and on your Profile button (top-right) on a phone. Real-time alerts also pop briefly as a toast.</p>
                                    </div>
                                </div>
                            </div>
                        </AccordionSection>

                        <AccordionSection title="Profile" icon="grid" icon-color="blue" :nested="true">
                            <div class="space-y-3">
                                <div class="flex items-start gap-3">
                                    <span class="flex-shrink-0 w-7 h-7 rounded-full bg-blue-100 text-blue-600 text-sm font-bold flex items-center justify-center mt-0.5">1</span>
                                    <div>
                                        <p class="text-base font-medium text-gray-900">Your Details</p>
                                        <p class="text-sm text-gray-500 mt-0.5">Tap the profile icon (in the controls widget on a computer; top-right on a phone) for your name, organization, and scanner kit, your current shift, and this week's activity.</p>
                                    </div>
                                </div>
                                <div class="flex items-start gap-3">
                                    <span class="flex-shrink-0 w-7 h-7 rounded-full bg-blue-100 text-blue-600 text-sm font-bold flex items-center justify-center mt-0.5">2</span>
                                    <div>
                                        <p class="text-base font-medium text-gray-900">Quick Links &amp; Log Out</p>
                                        <p class="text-sm text-gray-500 mt-0.5">Jump back to the Map or Help, or log out — all from the bottom of the Profile panel.</p>
                                    </div>
                                </div>
                            </div>
                        </AccordionSection>

                        <!-- Map Legend -->
                        <div class="bg-white rounded-2xl shadow-sm shadow-gray-200/60 p-5">
                            <p class="text-sm font-semibold text-gray-500 uppercase tracking-wide mb-3">Map Legend</p>

                            <!-- Markers -->
                            <p class="text-sm font-semibold text-gray-700 mb-2">Markers</p>
                            <div class="space-y-2.5 mb-4">
                                <div class="flex items-center gap-3">
                                    <span class="relative flex-shrink-0">
                                        <span class="absolute inline-flex h-3.5 w-3.5 rounded-full bg-blue-400 opacity-40 animate-ping"></span>
                                        <span class="relative inline-flex h-3.5 w-3.5 rounded-full bg-blue-500 border-2 border-white shadow"></span>
                                    </span>
                                    <div>
                                        <p class="text-sm font-medium text-gray-900">You (Scanner Position)</p>
                                        <p class="text-xs text-gray-400">Your kit's live position, shown as a vehicle icon (sedan, SUV, truck, or van) with a heading arrow when you're moving and a "minutes ago" timestamp. The "You" legend matches this marker to your vehicle.</p>
                                    </div>
                                </div>
                                <div class="flex items-center gap-3">
                                    <span class="relative flex-shrink-0 opacity-45">
                                        <span class="relative inline-flex h-3.5 w-3.5 rounded-full bg-blue-500 border-2 border-white shadow"></span>
                                    </span>
                                    <div>
                                        <p class="text-sm font-medium text-gray-900">Faded Driver (Last Scan Position)</p>
                                        <p class="text-xs text-gray-400">On the org map only. A faded icon means that kit isn't reporting live GPS, so the marker shows where it last <em>scanned</em> rather than where it is now. It lags by at least the 15-minute scan bucket and can be as much as 24 hours old (anything older is dropped), and it stops advancing entirely once the driver stops scanning — so read it as a last-known position, never a live one. Hover it to see "last scan position (no live GPS)". On the org map, a full-opacity icon is a live GPS fix.</p>
                                    </div>
                                </div>
                                <div class="flex items-center gap-3">
                                    <img :src="'/icons/icon-192.png'" alt="" class="flex-shrink-0 w-4 h-4 rounded-sm" />
                                    <div>
                                        <p class="text-sm font-medium text-gray-900">Hit (Historical)</p>
                                        <p class="text-xs text-gray-400">Your hits for the selected date range, shown as company-logo markers that cluster as you zoom out. Tap one for the full details — plate image, vehicle image, time, and status. Capped at the 100 most recent.</p>
                                    </div>
                                </div>
                                <template v-if="auth.tripsEnabled">
                                    <div class="flex items-center gap-3">
                                        <span class="flex-shrink-0 inline-flex items-center justify-center px-2 py-0.5 rounded-full bg-green-600 text-[8px] font-bold text-white tracking-wide">START</span>
                                        <div>
                                            <p class="text-sm font-medium text-gray-900">Trip Start</p>
                                            <p class="text-xs text-gray-400">Where the trip began (day and trip views)</p>
                                        </div>
                                    </div>
                                    <div class="flex items-center gap-3">
                                        <span class="flex-shrink-0 inline-flex items-center justify-center px-2 py-0.5 rounded-full bg-gray-800 text-[8px] font-bold text-white tracking-wide">END</span>
                                        <div>
                                            <p class="text-sm font-medium text-gray-900">Trip End</p>
                                            <p class="text-xs text-gray-400">Where the trip ended (day and trip views)</p>
                                        </div>
                                    </div>
                                    <div class="flex items-center gap-3">
                                        <span class="flex-shrink-0 inline-flex h-3.5 w-3.5 rounded-full bg-red-500 border-2 border-white shadow"></span>
                                        <div>
                                            <p class="text-sm font-medium text-gray-900">Hit Location</p>
                                            <p class="text-xs text-gray-400">Where a plate matched an order during a trip (day and trip views)</p>
                                        </div>
                                    </div>
                                </template>
                            </div>

                            <!-- Lines -->
                            <p class="text-sm font-semibold text-gray-700 mb-2">Lines</p>
                            <div class="space-y-2.5 mb-4">
                                <div class="flex items-center gap-3">
                                    <span class="flex-shrink-0 w-8 h-1 rounded-full" style="background: linear-gradient(to right, rgba(30,41,55,0.1), rgb(30,41,55), rgb(255,102,102))"></span>
                                    <div>
                                        <p class="text-sm font-medium text-gray-900">Scan Path (Historical)</p>
                                        <p class="text-xs text-gray-400">Roads your scanner has traveled. Today's drive is red; older trips fade from slate to transparent as they age out over 60 days.</p>
                                    </div>
                                </div>
                                <div v-if="auth.tripsEnabled" class="flex items-center gap-3">
                                    <span class="flex-shrink-0 w-6 h-0.5 rounded-full" style="background-color: rgb(255,102,102)"></span>
                                    <div>
                                        <p class="text-sm font-medium text-gray-900">Trip Route</p>
                                        <p class="text-xs text-gray-400">The path traveled during an individual trip or day view</p>
                                    </div>
                                </div>
                            </div>

                            <!-- Map layers (heatmap overlays) -->
                            <p class="text-sm font-semibold text-gray-700 mb-2">Map Layers</p>
                            <p class="text-[11px] text-gray-400 mb-2">Toggle these in the Map Layers panel (top-right; a layers chip on a phone). The legend below only shows the layers you have on.</p>
                            <div class="space-y-2.5 mb-4">
                                <div class="flex items-center gap-3">
                                    <span class="flex-shrink-0 w-5 h-5 rounded border border-gray-200 overflow-hidden flex flex-col">
                                        <span class="flex-1" style="background-color: #ef4444"></span>
                                        <span class="flex-1" style="background-color: #eab308"></span>
                                        <span class="flex-1" style="background-color: #22c55e"></span>
                                    </span>
                                    <div>
                                        <p class="text-sm font-medium text-gray-900">Live Hit Density</p>
                                        <p class="text-xs text-gray-400">Where live hits concentrate org-wide — Direct and Staging combined. Each circle covers a ~0.1 mi radius: green = 1 hit, yellow = 2–4, red = 5+.</p>
                                    </div>
                                </div>
                                <div class="flex items-center gap-3">
                                    <span class="flex-shrink-0 w-5 h-5 rounded border border-gray-200 overflow-hidden flex flex-col">
                                        <span class="flex-1" style="background-color: #4A148C"></span>
                                        <span class="flex-1" style="background-color: #7B1FA2"></span>
                                        <span class="flex-1" style="background-color: #AB47BC"></span>
                                        <span class="flex-1" style="background-color: #CE93D8"></span>
                                        <span class="flex-1" style="background-color: #E1BEE7"></span>
                                    </span>
                                    <div>
                                        <p class="text-sm font-medium text-gray-900">Orders (Direct &amp; Staging)</p>
                                        <p class="text-xs text-gray-400">Where vehicles on your orders have been seen. Direct and Staging toggle separately but share one shaded layer, deepening purple with the address count per cell (1 → 25+). Direct shows org-wide when you have no zones assigned and is clipped to your zone(s) when you do; Staging is always clipped to your zone(s).</p>
                                    </div>
                                </div>
                            </div>

                            <!-- Display options -->
                            <p class="text-sm font-semibold text-gray-700 mb-2">Display Options</p>
                            <p class="text-[11px] text-gray-400 mb-2">In the same Map Layers panel, below the layers.</p>
                            <div class="space-y-2.5">
                                <div class="flex items-center gap-3">
                                    <span class="flex-shrink-0 w-3.5 h-3.5 rounded-full" style="background: linear-gradient(135deg, #ef4444, #eab308, #22c55e, #3b82f6)"></span>
                                    <div>
                                        <p class="text-sm font-medium text-gray-900">Color Map</p>
                                        <p class="text-xs text-gray-400">Switch between the full-color road map and the muted monochrome basemap.</p>
                                    </div>
                                </div>
                                <div class="flex items-center gap-3">
                                    <svg class="flex-shrink-0 w-3.5 h-3.5" viewBox="0 0 24 24" fill="#6B7280" stroke="#FF6666" stroke-width="2" stroke-linejoin="round"><path d="M12 3l8 18-8-5-8 5z" /></svg>
                                    <div>
                                        <p class="text-sm font-medium text-gray-900">Direction Arrows</p>
                                        <p class="text-xs text-gray-400">Show arrows along your live trail indicating travel direction (Live mode).</p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </AccordionSection>
            </div>

            <!-- Install as App (PWA) -->
            <div ref="installRef">
                <AccordionSection ref="installAccordion" title="Install as App" icon="download" icon-color="green">
                    <div class="space-y-4">
                        <!-- Quick install banner -->
                        <div v-if="canInstall" class="bg-blue-50 border border-blue-200 rounded-2xl p-5 flex items-center justify-between gap-3">
                            <div>
                                <p class="text-base font-semibold text-blue-900">Ready to install</p>
                                <p class="text-sm text-blue-700 mt-0.5">Your browser supports one-tap install.</p>
                            </div>
                            <div class="flex items-center gap-2 flex-shrink-0">
                                <button
                                    @click="promptInstall"
                                    class="px-4 py-2 bg-blue-600 text-white text-sm font-medium rounded-lg min-h-[44px] hover:bg-blue-700 transition-colors"
                                >
                                    Install
                                </button>
                                <button
                                    @click="dismiss"
                                    class="px-3 py-2 text-sm text-gray-500 hover:text-gray-700 min-h-[44px] transition-colors"
                                >
                                    Dismiss
                                </button>
                            </div>
                        </div>

                        <!-- What you get -->
                        <div class="bg-green-50 border border-green-200 rounded-2xl p-5">
                            <p class="text-base font-semibold text-green-900 mb-2">Why install?</p>
                            <ul class="space-y-1.5">
                                <li class="flex items-center gap-2 text-sm text-green-800">
                                    <svg class="w-4 h-4 text-green-500 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" />
                                    </svg>
                                    Opens in its own window without browser controls
                                </li>
                                <li class="flex items-center gap-2 text-sm text-green-800">
                                    <svg class="w-4 h-4 text-green-500 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" />
                                    </svg>
                                    Loads faster with offline support
                                </li>
                                <li class="flex items-center gap-2 text-sm text-green-800">
                                    <svg class="w-4 h-4 text-green-500 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" />
                                    </svg>
                                    Launch from your home screen or dock
                                </li>
                                <li class="flex items-center gap-2 text-sm text-green-800">
                                    <svg class="w-4 h-4 text-green-500 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" />
                                    </svg>
                                    Works like a native app — no app store needed
                                </li>
                            </ul>
                        </div>

                        <p class="text-sm font-semibold text-gray-400 uppercase tracking-wide">Choose your device</p>

                        <AccordionSection title="iPhone / iPad (Safari)" icon="phone" icon-color="blue" :nested="true">
                            <ol class="space-y-3">
                                <li v-for="(step, i) in iosSteps" :key="i" class="flex items-start gap-3">
                                    <span class="flex-shrink-0 w-7 h-7 rounded-full bg-blue-100 text-blue-600 text-sm font-bold flex items-center justify-center mt-0.5">{{ i + 1 }}</span>
                                    <p class="text-sm text-gray-700 leading-relaxed" v-html="step"></p>
                                </li>
                            </ol>
                        </AccordionSection>

                        <AccordionSection title="Android (Chrome)" icon="phone" icon-color="green" :nested="true">
                            <ol class="space-y-3">
                                <li v-for="(step, i) in androidSteps" :key="i" class="flex items-start gap-3">
                                    <span class="flex-shrink-0 w-7 h-7 rounded-full bg-green-100 text-green-600 text-sm font-bold flex items-center justify-center mt-0.5">{{ i + 1 }}</span>
                                    <p class="text-sm text-gray-700 leading-relaxed" v-html="step"></p>
                                </li>
                            </ol>
                        </AccordionSection>

                        <AccordionSection title="Desktop — Chrome / Edge" icon="desktop" icon-color="purple" :nested="true">
                            <ol class="space-y-3">
                                <li v-for="(step, i) in desktopChromeSteps" :key="i" class="flex items-start gap-3">
                                    <span class="flex-shrink-0 w-7 h-7 rounded-full bg-purple-100 text-purple-600 text-sm font-bold flex items-center justify-center mt-0.5">{{ i + 1 }}</span>
                                    <p class="text-sm text-gray-700 leading-relaxed" v-html="step"></p>
                                </li>
                            </ol>
                        </AccordionSection>

                        <AccordionSection title="Desktop — Safari (macOS)" icon="desktop" icon-color="amber" :nested="true">
                            <ol class="space-y-3">
                                <li v-for="(step, i) in macSafariSteps" :key="i" class="flex items-start gap-3">
                                    <span class="flex-shrink-0 w-7 h-7 rounded-full bg-amber-100 text-amber-600 text-sm font-bold flex items-center justify-center mt-0.5">{{ i + 1 }}</span>
                                    <p class="text-sm text-gray-700 leading-relaxed" v-html="step"></p>
                                </li>
                            </ol>
                        </AccordionSection>
                    </div>
                </AccordionSection>
            </div>

            <!-- Glossary -->
            <div ref="glossaryRef">
                <AccordionSection ref="glossaryAccordion" title="Glossary" icon="list" icon-color="purple">
                    <div class="space-y-1">
                        <div v-for="term in glossaryTerms" :key="term.name" class="flex items-start gap-3 py-3 border-b border-gray-100 last:border-0">
                            <span
                                class="flex-shrink-0 w-9 h-9 rounded-xl flex items-center justify-center text-sm font-bold mt-0.5"
                                :class="term.badgeClass"
                            >{{ term.abbr }}</span>
                            <div>
                                <p class="text-base font-medium text-gray-900">{{ term.name }}</p>
                                <p class="text-sm text-gray-500 mt-0.5 leading-relaxed">{{ term.definition }}</p>
                            </div>
                        </div>
                    </div>
                </AccordionSection>
            </div>
        </div>
    </SidePanel>
</template>

<script setup>
import { computed, ref } from 'vue';
import SidePanel from './SidePanel.vue';
import AccordionSection from './AccordionSection.vue';
import { useInstallPrompt } from '../composables/useInstallPrompt';
import { useAuthStore } from '../stores/auth';

defineProps({
    open: { type: Boolean, default: false },
});
defineEmits(['close']);

const auth = useAuthStore();
const { canInstall, promptInstall, dismiss } = useInstallPrompt();

const guideRef = ref(null);
const glossaryRef = ref(null);
const installRef = ref(null);

const guideAccordion = ref(null);
const installAccordion = ref(null);
const glossaryAccordion = ref(null);

function scrollTo(section) {
    const refs = { guide: guideRef, glossary: glossaryRef, install: installRef };
    const accordions = { guide: guideAccordion, glossary: glossaryAccordion, install: installAccordion };

    const accordion = accordions[section]?.value;
    if (accordion) {
        accordion.isOpen = true;
    }

    refs[section]?.value?.scrollIntoView({ behavior: 'smooth', block: 'start' });
}

const allGlossaryTerms = [
    { name: 'Scans', abbr: 'SC', badgeClass: 'bg-blue-100 text-blue-600', definition: 'Individual license plate reads captured by the LPR camera on your vehicle. Each time the camera reads a plate, it counts as one scan.' },
    { name: 'Hits', abbr: 'HT', badgeClass: 'bg-amber-100 text-amber-600', definition: 'Plates that match a vehicle on one of your active orders. A hit means the scanned plate is one you are looking for.' },
    { name: 'Repossessions', abbr: 'RP', badgeClass: 'bg-green-100 text-green-600', definition: 'Recoveries that resulted from hits — vehicles that were successfully located and recovered. (Also called repos affected.)' },
    { name: 'Trips', abbr: 'TR', badgeClass: 'bg-purple-100 text-purple-600', tripsOnly: true, definition: 'Driving sessions automatically detected by the system. A new trip begins after 30 minutes of idle time with no scans.' },
    { name: 'Direct Orders', abbr: 'DO', badgeClass: 'bg-red-100 text-red-600', definition: 'Your organization\'s active orders — the live working list matched against your scans in real time. (The underlying list is sometimes called a hotlist.)' },
    { name: 'Staging Orders', abbr: 'SO', badgeClass: 'bg-yellow-100 text-yellow-600', definition: 'Orders still being prepared or staged (including from staging organizations) that aren\'t yet active for live matching.' },
    { name: 'Direct / Staging Check-ins', abbr: 'CI', badgeClass: 'bg-blue-100 text-blue-600', definition: 'The counts on the Direct Check-ins and Staging Check-ins stat cards — plate check-ins recorded against your direct orders versus your staging orders.' },
    { name: 'Zone', abbr: 'ZN', badgeClass: 'bg-purple-100 text-purple-600', definition: 'A geographic area on the map. Your assigned zone(s) are the area you cover.' },
    { name: 'Live Hit Density', abbr: 'HD', badgeClass: 'bg-red-100 text-red-600', definition: 'A map layer showing where live hits concentrate org-wide — Direct and Staging combined — drawn as ~0.1 mi shaded circles (green = 1 hit, yellow = 2–4, red = 5+) — a likely place to head toward while scanning.' },
    { name: 'Shift', abbr: 'SH', badgeClass: 'bg-rose-100 text-rose-600', definition: 'Your active working session. End it with the End Shift button to send an end-of-shift summary email.' },
    { name: 'End-of-Shift Summary', abbr: 'ES', badgeClass: 'bg-rose-100 text-rose-600', definition: 'An email of the day\'s miles, scans, hits, and zones covered, sent to you and your company admins when you end a shift.' },
    { name: 'Active Duration', abbr: 'AD', badgeClass: 'bg-purple-100 text-purple-600', tripsOnly: true, definition: 'The total time spent actively scanning during a trip, from the first scan to the last.' },
    { name: 'Miles Driven', abbr: 'MI', badgeClass: 'bg-green-100 text-green-600', tripsOnly: true, definition: 'The distance traveled during a trip, calculated from GPS coordinates recorded by your scanner.' },
    { name: 'Hit Types', abbr: 'TY', badgeClass: 'bg-amber-100 text-amber-600', definition: 'Live — matched an active direct order. Historical — matched a previously closed case. Staging — matched an order still being prepared.' },
];

const glossaryTerms = computed(() =>
    allGlossaryTerms.filter((term) => auth.tripsEnabled || !term.tripsOnly),
);

const iosSteps = [
    'Open this app in <strong>Safari</strong> (not Chrome or another browser)',
    'Tap the <strong>Share</strong> button (square with an arrow pointing up) at the bottom of the screen',
    'Scroll down and tap <strong>"Add to Home Screen"</strong>',
    'Tap <strong>"Add"</strong> in the top right corner',
    'The app icon will appear on your home screen — you\'re all set!',
];

const androidSteps = [
    'Open this app in <strong>Chrome</strong>',
    'Tap the <strong>three-dot menu</strong> in the top right corner',
    'Tap <strong>"Add to Home Screen"</strong> or <strong>"Install App"</strong>',
    'Tap <strong>"Install"</strong> to confirm',
    'The app will appear in your app drawer and home screen',
];

const desktopChromeSteps = [
    'Look for the <strong>install icon</strong> in the address bar (a monitor with a down arrow)',
    'Click it and select <strong>"Install"</strong>',
    'Alternatively, click the three-dot menu, then <strong>"Install Clarity Scout..."</strong>',
    'The app will open in its own window — pin it to your taskbar for quick access',
];

const macSafariSteps = [
    'Requires <strong>macOS Sonoma</strong> or later',
    'Click <strong>File</strong> in the menu bar',
    'Select <strong>"Add to Dock"</strong>',
    'The app will appear in your Dock for quick access',
];
</script>
