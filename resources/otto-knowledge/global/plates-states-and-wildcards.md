---
title: Plates, states, and wildcards
scope: global
areas: [lpr, basics, scans, search]
summary: Plate formats, * wildcards for partial plates, and how to ask Otto to search.
keywords: [plate, partial plate, wildcard, asterisk, state, search, how do I search]
surfaces: [chat]
---

# Plates, states, and wildcards

**Plate formats.** Plates are usually a short alphanumeric string (for example
`ABC1234` or `33EZE9`). A plate on its own is enough to start a search with Otto.

**Wildcards.** When only part of a plate is known, use `*` as a wildcard —
`ABC*` matches anything starting with `ABC`, and `*123` matches anything ending in
`123`. Otto supports these wildcards in scan and hit searches.

**States.** When relevant, include the two-letter US state with the plate (for
example `TN`, `FL`, `TX`).

**Asking Otto.** For live lookups, include the plate (or wildcard pattern) and a
time range — for example `Find scans for ABC1234 last 7 days` or
`Hits for 33EZE* yesterday`. If you omit a range, Otto defaults to the last 30 days.
