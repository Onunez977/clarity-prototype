<template>
    <svg
        v-if="variant === 'filled'"
        xmlns="http://www.w3.org/2000/svg"
        viewBox="0 0 24 24"
        fill="currentColor"
        aria-hidden="true"
        :class="svgClass"
    >
        <path d="M16 3a2 2 0 0 0-2-2h-4a2 2 0 1 0 0 4h.25c.414 0 .75.336.75.75V9.3c0 .398-.158.78-.44 1.06l-1.78.9A3.5 3.5 0 0 0 6.85 14.4l-.1.35A1.75 1.75 0 0 0 8.44 17h7.12a1.75 1.75 0 0 0 1.69-2.25l-.1-.35a3.5 3.5 0 0 0-1.93-3.14l-1.78-.9A1.5 1.5 0 0 1 13 9.3V5.75c0-.414.336-.75.75-.75H14a2 2 0 0 0 2-2Z" />
        <path d="M12 17.25a.75.75 0 0 1 .75.75v3.25a.75.75 0 0 1-1.5 0V18a.75.75 0 0 1 .75-.75Z" />
    </svg>
    <svg
        v-else
        xmlns="http://www.w3.org/2000/svg"
        fill="none"
        viewBox="0 0 24 24"
        stroke-width="1.5"
        stroke="currentColor"
        aria-hidden="true"
        :class="svgClass"
    >
        <path
            stroke-linecap="round"
            stroke-linejoin="round"
            d="M8.25 1.5h7.5a.75.75 0 0 1 0 1.5h-.5a.75.75 0 0 0-.75.75V9.3c0 .398.158.78.44 1.06l1.78.9A2.75 2.75 0 0 1 18.25 13.7V15a.75.75 0 0 1-.75.75H6.5A.75.75 0 0 1 5.75 15v-1.3a2.75 2.75 0 0 1 1.53-2.44l1.78-.9A1.5 1.5 0 0 0 9.5 9.3V3.75a.75.75 0 0 0-.75-.75h-.5a.75.75 0 0 1 0-1.5Z"
        />
        <path stroke-linecap="round" stroke-linejoin="round" d="M12 15.75v6" />
    </svg>
</template>

<script setup>
defineProps({
    variant: { type: String, default: 'outline' },
    svgClass: { type: String, default: 'h-4 w-4' },
});
</script>
