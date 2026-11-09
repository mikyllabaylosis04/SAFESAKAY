<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import type { Component } from 'vue';

export type StatTone = 'blue' | 'green' | 'amber' | 'red' | 'violet' | 'indigo' | 'teal' | 'slate';

export type StatCardItem = {
    key: string;
    title: string;
    value: string | number;
    hint?: string;
    icon: Component;
    tone: StatTone;
    href?: string;
    badge?: number;
};

const props = withDefaults(
    defineProps<{
        items: StatCardItem[];
        active?: string | null;
        selectable?: boolean;
        loading?: boolean;
    }>(),
    { active: null, selectable: false, loading: false },
);

const emit = defineEmits<{ select: [key: string] }>();

const TONES: Record<StatTone, { surface: string; icon: string }> = {
    blue: { surface: 'bg-[#E8F2FF] dark:bg-blue-500/15', icon: 'bg-[#1479E8]' },
    green: { surface: 'bg-[#E7F8EE] dark:bg-emerald-500/15', icon: 'bg-[#16A34A]' },
    amber: { surface: 'bg-[#FFF4E2] dark:bg-amber-500/15', icon: 'bg-[#F59E0B]' },
    red: { surface: 'bg-[#FFECEC] dark:bg-rose-500/15', icon: 'bg-[#E25C5C]' },
    violet: { surface: 'bg-[#F0E9FF] dark:bg-violet-500/15', icon: 'bg-[#6D28D9]' },
    indigo: { surface: 'bg-[#E9EDFF] dark:bg-indigo-500/15', icon: 'bg-[#3730A3]' },
    teal: { surface: 'bg-[#E3F7F5] dark:bg-teal-500/15', icon: 'bg-[#0F9F8F]' },
    slate: { surface: 'bg-[#EEF2F6] dark:bg-white/5', icon: 'bg-[#5B7C99]' },
};

const gridClass = {
    2: 'sm:grid-cols-2',
    3: 'sm:grid-cols-3',
    4: 'sm:grid-cols-2 xl:grid-cols-4',
    5: 'sm:grid-cols-3 xl:grid-cols-5',
    6: 'sm:grid-cols-3 xl:grid-cols-6',
} as Record<number, string>;
</script>

<template>
    <div class="grid grid-cols-2 gap-3" :class="gridClass[props.items.length] ?? 'xl:grid-cols-4'">
        <component
            :is="item.href ? Link : props.selectable ? 'button' : 'div'"
            v-for="item in props.items"
            :key="item.key"
            :href="item.href"
            :type="props.selectable && !item.href ? 'button' : undefined"
            class="relative flex min-h-[132px] flex-col rounded-xl border p-4 text-left shadow-sm transition-all duration-200"
            :class="[
                TONES[item.tone].surface,
                props.selectable || item.href ? 'cursor-pointer hover:-translate-y-0.5 hover:shadow-md focus-visible:ring-2 focus-visible:ring-[#1479E8] focus-visible:outline-none' : '',
                props.active === item.key ? 'border-[#1479E8] ring-2 ring-[#1479E8]/15' : 'border-white/70 dark:border-white/10',
            ]"
            @click="props.selectable && !item.href && emit('select', item.key)"
        >
            <span
                v-if="item.badge"
                class="absolute top-3 right-3 inline-flex min-w-5 items-center justify-center rounded-full bg-red-500 px-1.5 text-[11px] font-bold text-white"
            >
                {{ item.badge }}
            </span>
            <div class="mb-3 flex size-9 items-center justify-center rounded-lg text-white" :class="TONES[item.tone].icon">
                <component :is="item.icon" class="size-4" />
            </div>
            <p class="text-[13px] font-semibold text-ink dark:text-slate-200">{{ item.title }}</p>
            <div v-if="props.loading" class="mt-2 h-7 w-12 animate-pulse rounded bg-white/70 dark:bg-white/10" />
            <p v-else class="mt-1 text-2xl leading-tight font-bold text-[#0F2854] dark:text-white">{{ item.value }}</p>
            <p v-if="item.hint" class="mt-1 text-xs leading-snug text-ink-soft dark:text-slate-400">{{ item.hint }}</p>
        </component>
    </div>
</template>
