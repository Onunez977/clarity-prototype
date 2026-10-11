import { ref, watch } from 'vue';

// Shared "currently open" key per accordion group, so a set of overlays can behave
// as a single-open accordion on compact screens (tap one open → the others snap shut).
const activeInGroup = {};

// Per-widget open/closed state for the Scout map overlays. An explicit user choice
// (persisted per key) drives the desktop default; on compact the breakpoint default
// applies. Options:
//   - collapseWhenCompact (default true): the compact default is collapsed; pass false
//     for a widget that opens expanded on every breakpoint (the bottom sheet).
//   - defaultOpen (default null): override the breakpoint default outright — e.g. Map
//     Layers passes false so it defaults collapsed on desktop too (its historical look).
//   - group (default null): accordion group name. On compact, opening one member
//     collapses the rest, and grouped members always start collapsed (map-first; the
//     persisted choice still applies on desktop, where everything shows and there is no
//     accordion). A manual toggle always persists and overrides the breakpoint default.
export function useCollapsible(storageKey, { isCompact, collapseWhenCompact = true, defaultOpen = null, group = null }) {
    const breakpointDefault = () => {
        if (defaultOpen !== null) {
            return defaultOpen;
        }
        return collapseWhenCompact ? !isCompact.value : true;
    };
    // Read localStorage live (not a captured snapshot) so a persisted choice survives
    // breakpoint rotation; an explicit '1'/'0' wins, otherwise the breakpoint default.
    const persistedOrDefault = () => {
        const stored = localStorage.getItem(storageKey);
        if (stored === '1' || stored === '0') {
            return stored === '1';
        }
        return breakpointDefault();
    };

    // Grouped overlays start collapsed on compact regardless of a persisted choice, so the
    // map is unobstructed on load and at most one is ever open.
    const expanded = ref(group && isCompact.value ? false : persistedOrDefault());

    watch(isCompact, (compact) => {
        if (group && compact) {
            expanded.value = false;
        } else {
            expanded.value = persistedOrDefault();
        }
    });

    if (group) {
        if (!activeInGroup[group]) {
            activeInGroup[group] = ref(null);
        }
        const active = activeInGroup[group];

        // Opening this widget on a compact screen claims the group's single slot.
        watch(expanded, (open) => {
            if (!isCompact.value) {
                return;
            }
            if (open) {
                active.value = storageKey;
            } else if (active.value === storageKey) {
                active.value = null;
            }
        });

        // When another member claims the slot, collapse this one (automatic, not persisted).
        watch(active, (openKey) => {
            if (isCompact.value && openKey && openKey !== storageKey && expanded.value) {
                expanded.value = false;
            }
        });
    }

    function toggle() {
        expanded.value = !expanded.value;
        try {
            localStorage.setItem(storageKey, expanded.value ? '1' : '0');
        } catch { /* private mode — ignore */ }
    }

    return { expanded, toggle };
}
