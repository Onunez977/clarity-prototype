<template>
    <!-- No UI of its own. Mounting this component (only while Live mode is
         active, via MapShell's v-if) starts the kit-position poll; unmounting
         it stops the poll via the composable's onBeforeUnmount cleanup. -->
</template>

<script setup>
import { useMapStore } from '../stores/map';
import { useAuthStore } from '../stores/auth';
import { useKitPositionPolling } from '../composables/useKitPositionPolling';

// This component exists only while Live mode is active (gated by MapShell's
// v-if). Mounting it starts the kit-position poll; unmounting it stops the poll
// via the composable's own onBeforeUnmount cleanup. That ties live tracking to
// Live mode without touching the persistent map.
const mapStore = useMapStore();
const authStore = useAuthStore();

// Paint the last-known dot from cache immediately (Live mode only) so a reload
// doesn't show a blank map; the kit-position poll overrides it.
mapStore.hydrateKitPosition(authStore.user?.id);

// Seed the live trail from the start of the local day's sc_positions so the
// line isn't empty before the first poll (and survives a mid-drive reload).
// The poll then extends it incrementally via the cursor (DEV-2012).
mapStore.fetchLivePath();

useKitPositionPolling();
</script>
