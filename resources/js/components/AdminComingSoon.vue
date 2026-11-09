<script setup lang="ts">
defineProps<{
    title: string;
    description: string;
}>();
</script>

<template>
    <div class="flex flex-1 flex-col gap-6 p-6">
        <div>
            <h1 class="text-2xl font-bold text-[#0f4c8a] dark:text-sky-100">
                {{ title }}
            </h1>
            <p class="mt-1 text-sm text-ink-muted dark:text-slate-400">
                {{ description }}
            </p>
        </div>
        <div
            class="flex min-h-64 flex-1 items-center justify-center rounded-2xl border border-[#d7e8f6] bg-white p-8 text-center shadow-sm dark:border-white/10 dark:bg-white/5"
        >
            <p class="max-w-md text-sm text-ink-muted dark:text-slate-400">
                This section is ready in the navigation. Records and workflows
                can be connected here next.
            </p>
        </div>
    </div>
</template>
