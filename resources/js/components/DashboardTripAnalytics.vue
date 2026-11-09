<script setup lang="ts">
import { BarChart3 } from '@lucide/vue';
import { computed, ref } from 'vue';

export type TripAnalyticsDay = {
    date: string;
    label: string;
    regular: number;
    rentals: number;
    total?: number;
    completed?: number;
};

type RangePreset = 'daily' | 'weekly' | 'monthly';

type ChartBar = {
    key: string;
    label: string;
    regular: number;
    rentals: number;
    x: number;
    regularX: number;
    rentalX: number;
    regularH: number;
    rentalH: number;
    baseY: number;
};

const props = defineProps<{
    days: TripAnalyticsDay[];
}>();

const rangePreset = ref<RangePreset>('daily');
const hoverKey = ref<string | null>(null);

function localDate(value = new Date()) {
    const month = String(value.getMonth() + 1).padStart(2, '0');
    const day = String(value.getDate()).padStart(2, '0');

    return `${value.getFullYear()}-${month}-${day}`;
}

function parseLocalDate(value: string) {
    const [year, month, day] = value.split('-').map(Number);

    return new Date(year, (month ?? 1) - 1, day ?? 1);
}

function startOfWeek(date: Date) {
    const copy = new Date(date);
    const day = copy.getDay();
    const diff = day === 0 ? -6 : 1 - day;
    copy.setDate(copy.getDate() + diff);
    copy.setHours(0, 0, 0, 0);

    return copy;
}

function isoWeekKey(date: Date) {
    const start = startOfWeek(date);
    const month = start.toLocaleDateString('en-US', { month: 'short' });

    return {
        key: localDate(start),
        label: `${month} ${start.getDate()}`,
    };
}

const series = computed(() => {
    const now = new Date();
    const today = localDate(now);

    if (rangePreset.value === 'daily') {
        const from = new Date(now);
        from.setDate(from.getDate() - 6);

        return props.days
            .filter((day) => day.date >= localDate(from) && day.date <= today)
            .map((day) => ({
                key: day.date,
                label: day.label,
                regular: day.regular,
                rentals: day.rentals,
            }));
    }

    if (rangePreset.value === 'weekly') {
        const from = new Date(now);
        from.setDate(from.getDate() - 49);
        const buckets = new Map<string, { label: string; regular: number; rentals: number }>();

        props.days
            .filter((day) => day.date >= localDate(from) && day.date <= today)
            .forEach((day) => {
                const { key, label } = isoWeekKey(parseLocalDate(day.date));
                const current = buckets.get(key) ?? { label, regular: 0, rentals: 0 };
                current.regular += day.regular;
                current.rentals += day.rentals;
                buckets.set(key, current);
            });

        return [...buckets.entries()].map(([key, value]) => ({ key, ...value }));
    }

    const from = new Date(now.getFullYear(), now.getMonth() - 5, 1);
    const buckets = new Map<string, { label: string; regular: number; rentals: number }>();

    props.days
        .filter((day) => day.date >= localDate(from) && day.date <= today)
        .forEach((day) => {
            const date = parseLocalDate(day.date);
            const key = `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}`;
            const label = date.toLocaleDateString('en-US', { month: 'short' });
            const current = buckets.get(key) ?? { label, regular: 0, rentals: 0 };
            current.regular += day.regular;
            current.rentals += day.rentals;
            buckets.set(key, current);
        });

    return [...buckets.entries()].map(([key, value]) => ({ key, ...value }));
});

const chart = computed(() => {
    const points = series.value;
    const width = 520;
    const height = 210;
    const padX = 32;
    const padY = 18;
    const plotW = width - padX * 2;
    const plotH = height - padY * 2;
    const maxValue = Math.max(
        4,
        ...points.map((point) => Math.max(point.regular, point.rentals)),
    );
    const step = Math.max(1, Math.ceil(maxValue / 4));
    const yMax = step * 4;
    const groupW = points.length === 0 ? plotW : plotW / points.length;
    const barW = Math.min(14, Math.max(7, groupW * 0.28));
    const gap = 3;
    const baseY = padY + plotH;

    const bars: ChartBar[] = points.map((point, index) => {
        const center = padX + groupW * index + groupW / 2;
        const regularH = (point.regular / yMax) * plotH;
        const rentalH = (point.rentals / yMax) * plotH;

        return {
            ...point,
            x: center,
            regularX: center - gap / 2 - barW,
            rentalX: center + gap / 2,
            regularH,
            rentalH,
            baseY,
        };
    });

    const yTicks = [0, 1, 2, 3, 4].map((i) => ({
        value: i * step,
        y: padY + plotH - (i / 4) * plotH,
    }));

    return { width, height, padX, padY, plotH, barW, bars, yTicks, yMax };
});

const hoverBar = computed(() => chart.value.bars.find((bar) => bar.key === hoverKey.value) ?? null);
</script>

<template>
    <div class="flex items-center justify-between gap-3 border-b border-[#eef2f6] px-4 py-3">
        <div class="flex items-center gap-2.5">
            <div class="flex size-8 items-center justify-center rounded-lg bg-[#e8f1fd] text-[#1b7ad8]">
                <BarChart3 class="size-4" />
            </div>
            <h2 class="text-sm font-semibold text-[#0f2f57]">Trip Analytics</h2>
        </div>
        <div class="flex items-center gap-1">
            <button
                v-for="preset in [
                    { key: 'daily', label: 'Daily' },
                    { key: 'weekly', label: 'Weekly' },
                    { key: 'monthly', label: 'Monthly' },
                ]"
                :key="preset.key"
                type="button"
                class="rounded-md px-2.5 py-1 text-[11px] font-semibold transition"
                :class="
                    rangePreset === preset.key
                        ? 'bg-[#1b7ad8] text-white'
                        : 'bg-[#eef4fb] text-ink-soft hover:bg-[#dceaf8] dark:bg-[#0b1b30] dark:text-slate-100'
                "
                @click="rangePreset = preset.key as RangePreset"
            >
                {{ preset.label }}
            </button>
        </div>
    </div>

    <div class="px-3 pb-3">
    <div class="mt-2 flex items-center justify-center gap-5 text-[11px] font-medium text-ink-soft">
        <span class="inline-flex items-center gap-1.5">
            <span class="size-2.5 rounded-sm bg-[#2f80ed]" />
            Regular Trips
        </span>
        <span class="inline-flex items-center gap-1.5">
            <span class="size-2.5 rounded-sm bg-[#27ae60]" />
            Rental Trips
        </span>
    </div>

    <div v-if="series.length === 0" class="mt-8 flex h-40 items-center justify-center text-sm text-ink-muted">
        No trip records for this period.
    </div>
    <div v-else class="relative mt-1">
        <svg
            :viewBox="`0 0 ${chart.width} ${chart.height}`"
            class="h-48 w-full"
            role="img"
            aria-label="Trip analytics grouped bar chart"
        >
            <line
                v-for="tick in chart.yTicks"
                :key="`grid-${tick.value}`"
                :x1="chart.padX"
                :x2="chart.width - chart.padX"
                :y1="tick.y"
                :y2="tick.y"
                stroke="#edf2f7"
                stroke-width="1"
            />
            <text
                v-for="tick in chart.yTicks"
                :key="`label-${tick.value}`"
                :x="chart.padX - 6"
                :y="tick.y + 3"
                text-anchor="end"
                class="fill-[#9aa8b8]"
                font-size="9"
            >
                {{ tick.value }}
            </text>
            <g v-for="bar in chart.bars" :key="bar.key">
                <rect
                    :x="bar.regularX"
                    :y="bar.baseY - bar.regularH"
                    :width="chart.barW"
                    :height="bar.regularH"
                    rx="2"
                    fill="#2f80ed"
                    class="cursor-pointer"
                    @mouseenter="hoverKey = bar.key"
                    @mouseleave="hoverKey = null"
                />
                <rect
                    :x="bar.rentalX"
                    :y="bar.baseY - bar.rentalH"
                    :width="chart.barW"
                    :height="bar.rentalH"
                    rx="2"
                    fill="#27ae60"
                    class="cursor-pointer"
                    @mouseenter="hoverKey = bar.key"
                    @mouseleave="hoverKey = null"
                />
                <text
                    :x="bar.x"
                    :y="bar.baseY + 14"
                    text-anchor="middle"
                    class="fill-[#7d93a8]"
                    font-size="9"
                >
                    {{ bar.label }}
                </text>
            </g>
        </svg>
        <div
            v-if="hoverBar"
            class="pointer-events-none absolute rounded-lg bg-[#0f4c8a] px-2.5 py-1.5 text-[11px] font-semibold text-white shadow-lg"
            :style="{
                left: `${(hoverBar.x / chart.width) * 100}%`,
                top: '12px',
                transform: 'translateX(-50%)',
            }"
        >
            {{ hoverBar.label }} · Regular {{ hoverBar.regular }} · Rental {{ hoverBar.rentals }}
        </div>
    </div>
    </div>
</template>
