<script setup lang="ts">
import { computed, ref } from 'vue';

export type ComboPoint = {
    key: string;
    label: string;
    regular: number;
    rentals: number;
    passengers: number;
};

const props = defineProps<{
    points: ComboPoint[];
}>();

const hoverKey = ref<string | null>(null);

const chart = computed(() => {
    const points = props.points;
    const width = 680;
    const height = 240;
    const padX = 48;
    const padY = 18;
    const plotW = width - padX * 2;
    const plotH = height - padY * 2 - 8;
    const maxBar = Math.max(4, ...points.map((point) => Math.max(point.regular, point.rentals)));
    const maxPassengers = Math.max(4, ...points.map((point) => point.passengers));
    const barStep = Math.max(1, Math.ceil(maxBar / 4));
    const barMax = barStep * 4;
    const passengerStep = Math.max(1, Math.ceil(maxPassengers / 4));
    const passengerMax = passengerStep * 4;
    const groupW = points.length === 0 ? plotW : plotW / points.length;
    const barW = Math.min(16, Math.max(8, groupW * 0.22));
    const gap = 4;
    const baseY = padY + plotH;

    const bars = points.map((point, index) => {
        const center = padX + groupW * index + groupW / 2;
        const passengerY = baseY - (point.passengers / passengerMax) * plotH;

        return {
            ...point,
            x: center,
            regularX: center - gap / 2 - barW,
            rentalX: center + gap / 2,
            regularH: (point.regular / barMax) * plotH,
            rentalH: (point.rentals / barMax) * plotH,
            passengerY,
            baseY,
        };
    });

    const line = bars
        .map((bar, index) => `${index === 0 ? 'M' : 'L'} ${bar.x} ${bar.passengerY}`)
        .join(' ');

    const yTicks = [0, 1, 2, 3, 4].map((i) => ({
        value: i * barStep,
        passenger: i * passengerStep,
        y: padY + plotH - (i / 4) * plotH,
    }));

    return { width, height, padX, padY, plotH, barW, bars, line, yTicks };
});

const hoverBar = computed(() => chart.value.bars.find((bar) => bar.key === hoverKey.value) ?? null);
</script>

<template>
    <div v-if="points.length === 0" class="flex h-48 items-center justify-center text-sm text-ink-muted">
        No trip records for this period.
    </div>
    <div v-else class="relative">
        <div class="mb-1 flex flex-wrap items-center justify-start gap-4 text-[11px] font-medium text-ink-soft">
            <span class="inline-flex items-center gap-1.5">
                <span class="size-2.5 rounded-sm bg-[#2f80ed]" />
                Regular Trips
            </span>
            <span class="inline-flex items-center gap-1.5">
                <span class="size-2.5 rounded-sm bg-[#27ae60]" />
                Exclusive Rental Trips
            </span>
            <span class="inline-flex items-center gap-1.5">
                <span class="h-0.5 w-4 bg-[#f97316]" />
                Total Passengers
            </span>
        </div>
        <svg
            :viewBox="`0 0 ${chart.width} ${chart.height}`"
            class="h-56 w-full"
            role="img"
            aria-label="Monthly trips and passengers"
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
                :key="`yl-${tick.value}`"
                :x="chart.padX - 8"
                :y="tick.y + 3"
                text-anchor="end"
                class="fill-[#9aa8b8]"
                font-size="9"
            >
                {{ tick.value }}
            </text>
            <text
                v-for="tick in chart.yTicks"
                :key="`yr-${tick.passenger}`"
                :x="chart.width - chart.padX + 8"
                :y="tick.y + 3"
                text-anchor="start"
                class="fill-[#9aa8b8]"
                font-size="9"
            >
                {{ tick.passenger }}
            </text>
            <text
                :x="12"
                :y="chart.height / 2"
                text-anchor="middle"
                class="fill-[#9aa8b8]"
                font-size="9"
                transform="rotate(-90 12 120)"
            >
                Number of Trips
            </text>
            <text
                :x="chart.width - 10"
                :y="chart.height / 2"
                text-anchor="middle"
                class="fill-[#9aa8b8]"
                font-size="9"
                :transform="`rotate(90 ${chart.width - 10} 120)`"
            >
                Number of Passengers
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
            <path
                v-if="chart.line"
                :d="chart.line"
                fill="none"
                stroke="#f97316"
                stroke-width="2.4"
                stroke-linecap="round"
                stroke-linejoin="round"
            />
            <circle
                v-for="bar in chart.bars"
                :key="`p-${bar.key}`"
                :cx="bar.x"
                :cy="bar.passengerY"
                r="3.5"
                fill="#f97316"
                class="cursor-pointer"
                @mouseenter="hoverKey = bar.key"
                @mouseleave="hoverKey = null"
            />
        </svg>
        <div
            v-if="hoverBar"
            class="pointer-events-none absolute rounded-lg bg-[#0F2854] px-2.5 py-1.5 text-[11px] font-semibold text-white shadow-lg"
            :style="{
                left: `${(hoverBar.x / chart.width) * 100}%`,
                top: '8px',
                transform: 'translateX(-50%)',
            }"
        >
            {{ hoverBar.label }} · Regular {{ hoverBar.regular }} · Rentals {{ hoverBar.rentals }} · Passengers {{ hoverBar.passengers }}
        </div>
    </div>
</template>
