<script setup lang="ts">
import { ChevronDown, ChevronLeft, ChevronRight, FileDown, Search } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import StatCards from '@/components/admin/StatCards.vue';
import type { StatCardItem } from '@/components/admin/StatCards.vue';
import { Button } from '@/components/ui/button';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { downloadReport } from '@/lib/reportExport';
import type { ReportDownloadFormat, ReportExportRow } from '@/lib/reportExport';

export type InsightRow = Record<string, string | number | boolean | null | undefined>;
export type InsightColumn = { key: string; header: string; align?: 'right'; format?: 'money' };
export type InsightFilter = { key: string; label: string; options: { value: string; label: string }[] };

const props = withDefaults(
    defineProps<{
        title: string;
        description: string;
        filename: string;
        rows: InsightRow[];
        columns: InsightColumn[];
        summarize: (rows: InsightRow[]) => StatCardItem[];
        aggregate?: ((rows: InsightRow[]) => InsightRow[]) | null;
        filters?: InsightFilter[];
        searchKeys?: string[];
        organization: string;
        generatedBy: string;
        emptyMessage?: string;
    }>(),
    { aggregate: null, filters: () => [], searchKeys: () => [], emptyMessage: 'No records for this period.' },
);

type Preset = 'today' | 'week' | 'month' | 'year' | 'all' | 'custom';

function ymd(date: Date): string {
    return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`;
}

function presetRange(preset: Preset): [string, string] {
    const now = new Date();

    switch (preset) {
        case 'today':
            return [ymd(now), ymd(now)];
        case 'week': {
            const start = new Date(now);
            start.setDate(now.getDate() - ((now.getDay() + 6) % 7));
            const end = new Date(start);
            end.setDate(start.getDate() + 6);

            return [ymd(start), ymd(end)];
        }
        case 'year':
            return [`${now.getFullYear()}-01-01`, `${now.getFullYear()}-12-31`];
        case 'all':
            return ['', ''];
        default:
            return [ymd(new Date(now.getFullYear(), now.getMonth(), 1)), ymd(new Date(now.getFullYear(), now.getMonth() + 1, 0))];
    }
}

const preset = ref<Preset>('month');
const [initialFrom, initialTo] = presetRange('month');
const from = ref(initialFrom);
const to = ref(initialTo);
const search = ref('');
const filterValues = ref<Record<string, string>>(Object.fromEntries(props.filters.map((filter) => [filter.key, 'all'])));
const page = ref(1);
const pageSize = 10;

watch(preset, (value) => {
    if (value !== 'custom') {
        [from.value, to.value] = presetRange(value);
    }
});

const inPeriod = computed(() =>
    props.rows.filter((row) => {
        const date = String(row.date ?? '');

        return (!from.value || !date || date >= from.value) && (!to.value || !date || date <= to.value);
    }),
);

const scoped = computed(() => (props.aggregate ? props.aggregate(inPeriod.value) : inPeriod.value));

const filtered = computed(() => {
    const needle = search.value.trim().toLowerCase();

    return scoped.value.filter((row) => {
        for (const filter of props.filters) {
            const wanted = filterValues.value[filter.key];

            if (wanted && wanted !== 'all' && String(row[filter.key]) !== wanted) {
                return false;
            }
        }

        return !needle || props.searchKeys.some((key) => String(row[key] ?? '').toLowerCase().includes(needle));
    });
});

const cards = computed(() => props.summarize(filtered.value));
const pageCount = computed(() => Math.max(1, Math.ceil(filtered.value.length / pageSize)));
const shown = computed(() => filtered.value.slice((page.value - 1) * pageSize, page.value * pageSize));
watch([search, filterValues, from, to], () => (page.value = 1), { deep: true });

const peso = (value: unknown) => `₱${Number(value ?? 0).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;

function display(row: InsightRow, column: InsightColumn): string {
    const value = row[column.key];

    if (column.format === 'money') {
        return peso(value);
    }

    if (typeof value === 'boolean') {
        return value ? 'Yes' : 'No';
    }

    return value === null || value === undefined || value === '' ? '—' : String(value);
}

function periodLabel(): string {
    if (!from.value && !to.value) {
        return 'All records';
    }

    const format = (value: string) =>
        value ? new Date(`${value}T00:00:00`).toLocaleDateString('en-PH', { month: 'short', day: '2-digit', year: 'numeric' }) : '…';

    return `${format(from.value)} – ${format(to.value)}`;
}

function exportAs(format: ReportDownloadFormat): void {
    downloadReport(format, {
        title: props.title,
        subtitle: periodLabel(),
        filename: `${props.filename}-${from.value || 'all'}-to-${to.value || 'all'}`,
        organization: props.organization,
        tagline: 'Official report',
        sectionTitle: props.title,
        meta: [
            { label: 'Organization', value: props.organization },
            { label: 'Period', value: periodLabel() },
            { label: 'Generated', value: new Date().toLocaleString('en-PH') },
            { label: 'Generated By', value: props.generatedBy },
        ],
        stats: cards.value.map((card) => ({ label: card.title, value: String(card.value) })),
        columns: [{ key: 'index', header: '#' }, ...props.columns.map((column) => ({ key: column.key, header: column.header }))],
        rows: filtered.value.map(
            (row, index): ReportExportRow => ({
                index: index + 1,
                ...Object.fromEntries(props.columns.map((column) => [column.key, display(row, column)])),
            }),
        ),
        emptyMessage: props.emptyMessage,
    });
}
</script>

<template>
    <section class="space-y-4">
        <div class="flex flex-wrap items-end justify-between gap-3">
            <div>
                <h2 class="text-lg font-bold text-[#0f4c8a] dark:text-sky-100">{{ title }}</h2>
                <p class="text-sm text-ink-muted dark:text-slate-400">{{ description }}</p>
            </div>
            <DropdownMenu>
                <DropdownMenuTrigger as-child>
                    <Button class="h-9 rounded-xl bg-[#1b7ad8] text-white" :disabled="filtered.length === 0">
                        <FileDown class="size-4" />
                        Export
                        <ChevronDown class="size-4" />
                    </Button>
                </DropdownMenuTrigger>
                <DropdownMenuContent align="end">
                    <DropdownMenuItem @click="exportAs('pdf')">PDF</DropdownMenuItem>
                    <DropdownMenuItem @click="exportAs('excel')">Excel</DropdownMenuItem>
                    <DropdownMenuItem @click="exportAs('csv')">CSV</DropdownMenuItem>
                    <DropdownMenuItem @click="exportAs('print')">Print</DropdownMenuItem>
                </DropdownMenuContent>
            </DropdownMenu>
        </div>

        <StatCards :items="cards" />

        <div class="overflow-hidden rounded-2xl border border-[#d7e8f6] bg-white shadow-sm dark:border-white/10 dark:bg-white/5">
            <div class="grid gap-3 border-b border-[#eef4f9] p-4 sm:grid-cols-2 xl:flex xl:flex-wrap xl:items-end dark:border-white/10">
                <label class="flex flex-col gap-1 text-xs font-medium text-ink-soft">
                    Period
                    <select v-model="preset" class="h-10 rounded-xl border border-[#c5def5] bg-white px-3 text-sm dark:border-white/10 dark:bg-[#102036] dark:text-white">
                        <option value="today">Today</option>
                        <option value="week">This week</option>
                        <option value="month">This month</option>
                        <option value="year">This year</option>
                        <option value="all">All time</option>
                        <option value="custom">Custom</option>
                    </select>
                </label>
                <label class="flex flex-col gap-1 text-xs font-medium text-ink-soft">
                    From
                    <input v-model="from" type="date" class="h-10 rounded-xl border border-[#c5def5] bg-white px-2 text-sm dark:border-white/10 dark:bg-[#102036] dark:text-white" @change="preset = 'custom'" />
                </label>
                <label class="flex flex-col gap-1 text-xs font-medium text-ink-soft">
                    To
                    <input v-model="to" type="date" class="h-10 rounded-xl border border-[#c5def5] bg-white px-2 text-sm dark:border-white/10 dark:bg-[#102036] dark:text-white" @change="preset = 'custom'" />
                </label>
                <label v-for="filter in filters" :key="filter.key" class="flex flex-col gap-1 text-xs font-medium text-ink-soft">
                    {{ filter.label }}
                    <select v-model="filterValues[filter.key]" class="h-10 rounded-xl border border-[#c5def5] bg-white px-3 text-sm dark:border-white/10 dark:bg-[#102036] dark:text-white">
                        <option value="all">All</option>
                        <option v-for="option in filter.options" :key="option.value" :value="option.value">{{ option.label }}</option>
                    </select>
                </label>
                <label v-if="searchKeys.length" class="relative flex-1 xl:min-w-[220px]">
                    <Search class="pointer-events-none absolute top-3 left-3 size-4 text-[#94a8bd]" />
                    <input v-model="search" type="search" placeholder="Search..." class="h-10 w-full rounded-xl border border-[#c5def5] bg-white pr-3 pl-9 text-sm dark:border-white/10 dark:bg-white/5 dark:text-white" />
                </label>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full min-w-[820px] text-left text-sm">
                    <thead class="bg-[#f5f8fc] text-[11px] font-bold text-ink-muted uppercase dark:bg-white/5">
                        <tr>
                            <th v-for="column in columns" :key="column.key" class="px-4 py-3" :class="column.align === 'right' ? 'text-right' : ''">{{ column.header }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-[#eef3f8] dark:divide-white/10">
                        <tr v-for="(row, index) in shown" :key="String(row.id ?? row.operator_id ?? index)" class="hover:bg-[#f8fbfe] dark:hover:bg-white/5">
                            <td
                                v-for="column in columns"
                                :key="column.key"
                                class="px-4 py-3 text-ink dark:text-slate-200"
                                :class="column.align === 'right' ? 'text-right tabular-nums' : ''"
                            >
                                {{ display(row, column) }}
                            </td>
                        </tr>
                        <tr v-if="!shown.length">
                            <td :colspan="columns.length" class="px-4 py-10 text-center text-sm text-ink-muted">{{ emptyMessage }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div class="flex items-center justify-between border-t border-[#eef4f9] px-4 py-3 text-xs text-ink-muted dark:border-white/10">
                <span>{{ filtered.length }} {{ filtered.length === 1 ? 'record' : 'records' }} · {{ periodLabel() }}</span>
                <div class="flex items-center gap-2">
                    <button type="button" class="rounded-lg border border-[#d8e5f3] p-1.5 disabled:opacity-40 dark:border-white/10" :disabled="page <= 1" aria-label="Previous page" @click="page--">
                        <ChevronLeft class="size-4" />
                    </button>
                    <span>Page {{ page }} of {{ pageCount }}</span>
                    <button type="button" class="rounded-lg border border-[#d8e5f3] p-1.5 disabled:opacity-40 dark:border-white/10" :disabled="page >= pageCount" aria-label="Next page" @click="page++">
                        <ChevronRight class="size-4" />
                    </button>
                </div>
            </div>
        </div>
    </section>
</template>
