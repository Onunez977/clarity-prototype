Insight AI — Build Record

## TL;DR
Org Map Live Hits now shows a loading state while a date range loads, and admins see when map hits are truncated at 100. Builds on companion work that removed the silent 20-row list cap. Cold load times measured on Cloud org 1271.

## WHAT changed
- DashboardOverlay: loading spinner / Loading… / Updating… on Live Hits; admin truncation copy when rangeHitsCapped
- map.js: rangeHitsLoading while /map/hits is in flight
- Rebuilt clarity-scout assets

## WHY
- Demo could not tell if the list was complete; wide ranges felt hung with no feedback
- Map markers already capped at MAX_MAP_HITS=100 with capped:true; admin UI never showed it
- List cap of 20 was the companion defect (admin silent 20-row list cap)

## HOW numbers were derived
- Cloud CH read-only probe: top org by hits in 30d = kit_org_id 1271 (~16272 hits, 77 kits)
- Query shape ≈ BirdsEye recentHits+hits (FINAL + open hotlist semi-join + limits)
- Cold timings: last_7=1952.8ms, last_15=1319.8ms, last_30=2570.0ms
- last_7 cold target adjusted 1s→2s after measure; warm judged via app Cache::remember

## What this ticket did NOT do
- Remove recentHits limit(20) (companion ticket)
- Change MAX_MAP_HITS value from 100
- CH query rewrite / index work for sub-second last_7

## PR
https://github.com/insight-lpr/platform/pull/426
