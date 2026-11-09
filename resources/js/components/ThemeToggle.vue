<script setup lang="ts">
import { Moon, Sun } from '@lucide/vue';
import { useAppearance } from '@/composables/useAppearance';

const { resolvedAppearance, updateAppearance } = useAppearance();
</script>

<template>
    <div
        class="inline-flex items-center rounded-full border border-[#c5def5] bg-white p-1 shadow-sm dark:border-white/15 dark:bg-white/10"
        role="group"
        aria-label="Color theme"
    >
        <button
            type="button"
            class="flex size-8 items-center justify-center rounded-full transition"
            :class="
                resolvedAppearance === 'light'
                    ? 'bg-[#1b7ad8] text-white shadow-sm'
                    : 'text-ink-muted hover:text-[#1b7ad8] dark:text-slate-400 dark:hover:text-sky-200'
            "
            aria-label="Light mode"
            :aria-pressed="resolvedAppearance === 'light'"
            @click="updateAppearance('light')"
        >
            <Sun class="size-4" />
        </button>
        <button
            type="button"
            class="flex size-8 items-center justify-center rounded-full transition"
            :class="
                resolvedAppearance === 'dark'
                    ? 'bg-[#1b7ad8] text-white shadow-sm'
                    : 'text-ink-muted hover:text-[#1b7ad8] dark:text-slate-400 dark:hover:text-sky-200'
            "
            aria-label="Dark mode"
            :aria-pressed="resolvedAppearance === 'dark'"
            @click="updateAppearance('dark')"
        >
            <Moon class="size-4" />
        </button>
    </div>
</template>
