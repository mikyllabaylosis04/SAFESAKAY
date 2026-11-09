<script setup lang="ts">
defineProps<{
    color?: string;
}>();
</script>

<template>
    <svg
        class="pointer-events-none absolute right-0 bottom-0 h-11 w-20 opacity-70"
        viewBox="0 0 140 80"
        aria-hidden="true"
    >
        <path
            :fill="color || 'currentColor'"
            fill-opacity="0.22"
            d="M0 80 C30 52 50 70 80 48 C108 28 124 42 140 30 V80 Z"
        />
        <path
            :fill="color || 'currentColor'"
            fill-opacity="0.32"
            d="M20 80 C48 58 70 74 96 56 C118 42 130 54 140 48 V80 Z"
        />
    </svg>
</template>
