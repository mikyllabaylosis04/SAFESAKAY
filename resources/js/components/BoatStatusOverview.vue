<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import {
    CircleOff,
    Ship,
    Ticket,
    UserRound,
    Wrench,
} from '@lucide/vue';

export type BoatStatusItem = {
    key: string;
    label: string;
    color: string;
    hint: string;
    count: number;
    boats: string[];
};

defineProps<{
    total: number;
    items: BoatStatusItem[];
}>();

const iconFor: Record<string, typeof Ship> = {
    available: Ship,
    on_duty: UserRound,
    on_trip: Ship,
    booked: Ticket,
    maintenance: Wrench,
    unavailable: CircleOff,
};

const surfaceFor: Record<string, string> = {
    available: 'bg-[#D4F9E2]',
    on_duty: 'bg-[#C9E4FF]',
    on_trip: 'bg-[#E8D8FF]',
    booked: 'bg-[#D9E2FF]',
    maintenance: 'bg-[#FFDEC7]',
    unavailable: 'bg-[#FFF0C2]',
};

const iconWrapFor: Record<string, string> = {
    available: 'bg-[#16A34A]',
    on_duty: 'bg-[#1479E8]',
    on_trip: 'bg-[#6D28D9]',
    booked: 'bg-[#3730A3]',
    maintenance: 'bg-[#F97316]',
    unavailable: 'bg-[#E49A00]',
};
</script>

<template>
    <section
        class="overflow-hidden rounded-2xl border border-[#d7e8f6] bg-white p-5 shadow-[0_8px_24px_rgba(15,76,138,0.06)] xl:col-span-3 dark:border-white/10 dark:bg-white/5"
    >
        <div class="mb-4 flex items-center justify-between">
            <div class="flex items-center gap-3">
                <div
                    class="flex size-9 items-center justify-center rounded-[10px] bg-[#1479E8] text-white"
                >
                    <Ship class="size-4" />
                </div>
                <div>
                    <h2 class="text-base font-semibold text-[#0F2854] dark:text-sky-100">
                        Boat Status
                    </h2>
                    <p class="text-xs text-ink-muted">
                        {{ total }} registered boats
                    </p>
                </div>
            </div>
            <Link href="/boats" class="text-sm font-medium text-[#1b7ad8] hover:underline">
                View All →
            </Link>
        </div>

        <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
            <Link
                v-for="item in items"
                :key="item.key"
                href="/boats"
                class="overflow-hidden rounded-[12px] border border-white/50 p-4 shadow-[0_2px_8px_rgba(15,76,138,0.05)] transition hover:-translate-y-0.5"
                :class="surfaceFor[item.key] ?? 'bg-[#C9E4FF]'"
            >
                <div
                    class="mb-3 flex size-9 items-center justify-center rounded-[10px] text-white"
                    :class="iconWrapFor[item.key] ?? 'bg-[#1479E8]'"
                >
                    <component
                        :is="iconFor[item.key] ?? Ship"
                        class="size-4"
                    />
                </div>
                <p class="text-xs font-medium text-[#0F2854]">
                    {{ item.label }}
                </p>
                <p class="mt-0.5 text-2xl font-bold tracking-tight text-[#0F2854]">
                    {{ item.count }}
                </p>
                <p class="mt-0.5 text-[11px] text-ink-soft">
                    {{ item.hint }}
                </p>
                <p
                    v-if="item.boats.length"
                    class="mt-1 truncate text-[11px] text-ink-soft"
                >
                    {{ item.boats.join(', ') }}
                </p>
            </Link>
        </div>
    </section>
</template>
