<script setup lang="ts">
import { computed } from 'vue';

export type ChartPoint = {
    label: string;
    value: number;
};

const props = withDefaults(
    defineProps<{
        points: ChartPoint[];
        color?: string;
        emptyLabel?: string;
        valueSuffix?: string;
    }>(),
    {
        color: '#1b7ad8',
        emptyLabel: 'No data for this period',
        valueSuffix: '',
    },
);

const maxValue = computed(() =>
    Math.max(1, ...props.points.map((point) => point.value)),
);
</script>

<template>
    <div
        v-if="points.length === 0"
        class="flex h-48 items-center justify-center rounded-2xl border border-dashed border-[#d4e6f5] bg-[#f8fbfe] text-sm text-ink-muted dark:border-white/10 dark:bg-white/5"
    >
        {{ emptyLabel }}
    </div>
    <div v-else class="flex h-52 items-end gap-2 overflow-x-auto px-1 pb-1 pt-6">
        <div
            v-for="point in points"
            :key="point.label"
            class="flex min-w-[2.4rem] flex-1 flex-col items-center justify-end gap-2"
        >
            <span class="text-[11px] font-semibold text-ink-soft dark:text-slate-300">
                {{ point.value }}{{ valueSuffix }}
            </span>
            <div
                class="w-full max-w-10 rounded-t-lg transition-all"
                :style="{
                    height: `${Math.max(8, (point.value / maxValue) * 140)}px`,
                    background: color,
                    opacity: point.value === 0 ? 0.25 : 1,
                }"
            />
            <span class="text-[10px] font-medium text-ink-muted">
                {{ point.label }}
            </span>
        </div>
    </div>
</template>
