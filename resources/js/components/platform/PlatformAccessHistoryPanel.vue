<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { ChevronLeft, ChevronRight, Search } from '@lucide/vue';
import { computed, ref } from 'vue';

export type AccessHistoryRow = {
    id: number;
    action: string;
    action_label: string;
    organization_id: number | null;
    organization_name: string;
    actor_name: string | null;
    note: string | null;
    created_at_label: string | null;
    period: string | null;
};

const props = defineProps<{
    access_history: AccessHistoryRow[];
}>();

const MONTH_LABELS = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];

type HistoryFilter = 'all' | 'suspended' | 'deactivated' | 'restored' | 'archived';

const historyQuery = ref('');
const historyFilter = ref<HistoryFilter>('all');
const historyPeriod = ref(new Date().toISOString().slice(0, 7));
const historyYear = ref(Number(historyPeriod.value.slice(0, 4)) || new Date().getFullYear());

const historyRows = computed(() => props.access_history ?? []);

function monthKey(year: number, month: number) {
    return `${year}-${String(month).padStart(2, '0')}`;
}

function matchesHistoryFilter(action: string, filter: HistoryFilter) {
    if (filter === 'all') {
        return true;
    }

    if (filter === 'suspended') {
        return action === 'suspended' || action === 'billing_locked';
    }

    if (filter === 'deactivated') {
        return action === 'deactivated';
    }

    if (filter === 'restored') {
        return action === 'restored' || action === 'approved';
    }

    return action === 'archived';
}

const calendarMonths = computed(() =>
    MONTH_LABELS.map((label, index) => {
        const key = monthKey(historyYear.value, index + 1);
        const rows = historyRows.value.filter((row) => row.period === key);

        return {
            key,
            label,
            short: label.slice(0, 3),
            count: rows.length,
            isCurrent: key === new Date().toISOString().slice(0, 7),
        };
    }),
);

const monthFilteredHistory = computed(() =>
    historyRows.value.filter((row) => historyPeriod.value === 'all' || row.period === historyPeriod.value),
);

const historyCards = computed(() => [
    {
        key: 'suspended' as const,
        title: 'Suspended',
        value: monthFilteredHistory.value.filter((row) => matchesHistoryFilter(row.action, 'suspended')).length,
        surface: 'bg-[#FECACA]',
        ring: 'border-[#DC2626] ring-2 ring-[#DC2626]/30',
    },
    {
        key: 'deactivated' as const,
        title: 'Inactive',
        value: monthFilteredHistory.value.filter((row) => matchesHistoryFilter(row.action, 'deactivated')).length,
        surface: 'bg-[#E2E8F0]',
        ring: 'border-[#475569] ring-2 ring-[#475569]/30',
    },
    {
        key: 'restored' as const,
        title: 'Restored',
        value: monthFilteredHistory.value.filter((row) => matchesHistoryFilter(row.action, 'restored')).length,
        surface: 'bg-[#D4F9E2]',
        ring: 'border-[#16A34A] ring-2 ring-[#16A34A]/30',
    },
    {
        key: 'archived' as const,
        title: 'Archived',
        value: monthFilteredHistory.value.filter((row) => matchesHistoryFilter(row.action, 'archived')).length,
        surface: 'bg-[#FFDEC7]',
        ring: 'border-[#EA580C] ring-2 ring-[#EA580C]/30',
    },
]);

const filteredHistory = computed(() => {
    const search = historyQuery.value.trim().toLowerCase();

    return monthFilteredHistory.value.filter((row) => {
        if (!matchesHistoryFilter(row.action, historyFilter.value)) {
            return false;
        }

        return !search || `${row.organization_name} ${row.actor_name} ${row.note} ${row.action_label}`.toLowerCase().includes(search);
    });
});

function selectHistoryCard(key: HistoryFilter) {
    historyFilter.value = historyFilter.value === key ? 'all' : key;
}

function selectHistoryMonth(key: string) {
    historyPeriod.value = historyPeriod.value === key ? 'all' : key;
}

function showAllHistoryMonths() {
    historyPeriod.value = 'all';
}

function actionTone(action: string) {
    if (['approved', 'restored', 'onboarded'].includes(action)) {
        return 'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/20 dark:text-emerald-200';
    }

    if (['suspended', 'rejected', 'restore_request_dismissed', 'billing_locked'].includes(action)) {
        return 'bg-rose-50 text-rose-700 dark:bg-rose-500/20 dark:text-rose-200';
    }

    if (['deactivated', 'archived'].includes(action)) {
        return 'bg-slate-100 text-slate-700 dark:bg-white/10 dark:text-slate-200';
    }

    return 'bg-amber-50 text-amber-800 dark:bg-amber-500/20 dark:text-amber-200';
}
</script>

<template>
    <section class="overflow-hidden rounded-2xl border border-[#d7e8f6] bg-white dark:border-white/10 dark:bg-white/5 shadow-sm">
        <div class="flex flex-col gap-3 border-b border-[#e8f1f8] px-5 py-4 sm:flex-row sm:items-center sm:justify-between dark:border-white/10">
            <div>
                <h2 class="font-semibold text-[#0f4c8a] dark:text-sky-100">Access history</h2>
                <p class="text-xs text-ink-muted dark:text-slate-400">
                    When each barangay was suspended, set inactive, restored, or archived — including who did it and why.
                </p>
            </div>
            <div class="flex items-center gap-2">
                <button
                    type="button"
                    class="inline-flex size-9 items-center justify-center rounded-lg border border-[#c5def5] text-[#1b7ad8] transition hover:bg-[#e8f4fc] dark:border-white/10 dark:hover:bg-white/10"
                    @click="historyYear -= 1"
                >
                    <ChevronLeft class="size-4" />
                </button>
                <p class="min-w-[72px] text-center text-sm font-semibold text-[#0f4c8a] dark:text-sky-100">{{ historyYear }}</p>
                <button
                    type="button"
                    class="inline-flex size-9 items-center justify-center rounded-lg border border-[#c5def5] text-[#1b7ad8] transition hover:bg-[#e8f4fc] dark:border-white/10 dark:hover:bg-white/10"
                    @click="historyYear += 1"
                >
                    <ChevronRight class="size-4" />
                </button>
                <button
                    type="button"
                    class="h-9 rounded-lg border px-3 text-xs font-semibold transition"
                    :class="historyPeriod === 'all'
                        ? 'border-[#1b7ad8] bg-[#e8f4fc] text-[#1b7ad8]'
                        : 'border-[#c5def5] text-[#1b7ad8] hover:bg-[#e8f4fc] dark:border-white/10'"
                    @click="showAllHistoryMonths"
                >
                    All months
                </button>
            </div>
        </div>

        <div class="grid grid-cols-3 gap-2 p-4 sm:grid-cols-4 xl:grid-cols-6">
            <button
                v-for="month in calendarMonths"
                :key="month.key"
                type="button"
                class="rounded-xl border px-3 py-3 text-left transition"
                :class="[
                    historyPeriod === month.key
                        ? 'border-[#1b7ad8] bg-[#e8f4fc] ring-2 ring-[#1b7ad8]/20'
                        : month.isCurrent
                          ? 'border-[#c5def5] bg-white hover:border-[#1b7ad8] dark:bg-white/5'
                          : 'border-transparent bg-[#f7fbfe] hover:border-[#d7e8f6] dark:bg-white/[0.03]',
                ]"
                @click="selectHistoryMonth(month.key)"
            >
                <p class="text-xs font-semibold text-ink-soft dark:text-slate-400">{{ month.short }}</p>
                <p class="mt-1 text-sm font-bold text-[#0f4c8a] dark:text-sky-100">{{ month.label }}</p>
                <p class="mt-1 text-[11px] text-ink-muted dark:text-slate-400">
                    {{ month.count > 0 ? `${month.count} ${month.count === 1 ? 'change' : 'changes'}` : 'No changes' }}
                </p>
            </button>
        </div>

        <div class="grid gap-3 px-4 pb-4 sm:grid-cols-2 xl:grid-cols-4">
            <button
                v-for="card in historyCards"
                :key="card.key"
                type="button"
                class="rounded-[12px] border px-4 py-3 text-left shadow-[0_2px_8px_rgba(15,76,138,0.05)]"
                :class="[card.surface, historyFilter === card.key ? card.ring : 'border-white/50']"
                :aria-pressed="historyFilter === card.key"
                @click="selectHistoryCard(card.key)"
            >
                <p class="text-xs font-semibold text-[#0F2854]">{{ card.title }}</p>
                <p class="mt-1 text-2xl font-bold text-[#0F2854]">{{ card.value }}</p>
            </button>
        </div>

        <div class="px-4 pb-4">
            <label class="flex h-11 items-center gap-2 rounded-full border border-[#d7e8f6] bg-white px-4 dark:border-white/10 dark:bg-white/5">
                <Search class="size-4 text-ink-muted" />
                <input
                    v-model="historyQuery"
                    type="search"
                    placeholder="Search organization, actor, or reason..."
                    class="min-w-0 flex-1 border-0 bg-transparent text-sm outline-none dark:text-sky-50"
                />
            </label>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full min-w-[860px] text-left text-sm">
                <thead class="bg-[#f4f9fd] text-xs tracking-wide text-ink-soft uppercase dark:bg-white/5 dark:text-slate-400">
                    <tr>
                        <th class="px-5 py-3">When</th>
                        <th class="px-5 py-3">Action</th>
                        <th class="px-5 py-3">Organization</th>
                        <th class="px-5 py-3">By</th>
                        <th class="px-5 py-3">Reason</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-if="filteredHistory.length === 0">
                        <td colspan="5" class="px-5 py-12 text-center text-ink-muted dark:text-slate-400">
                            No access changes for this filter.
                        </td>
                    </tr>
                    <tr v-for="event in filteredHistory" :key="event.id" class="border-t border-[#eef4f9] dark:border-white/10">
                        <td class="px-5 py-3 text-ink-muted dark:text-slate-400">{{ event.created_at_label }}</td>
                        <td class="px-5 py-3">
                            <span class="rounded-full px-2.5 py-1 text-xs font-semibold" :class="actionTone(event.action)">
                                {{ event.action_label }}
                            </span>
                        </td>
                        <td class="px-5 py-3">
                            <Link
                                v-if="event.organization_id"
                                :href="`/platform/organizations/${event.organization_id}`"
                                class="font-semibold text-[#0f4c8a] hover:underline dark:text-sky-100"
                            >
                                {{ event.organization_name }}
                            </Link>
                            <span v-else class="font-semibold text-[#0f4c8a] dark:text-sky-100">{{ event.organization_name }}</span>
                        </td>
                        <td class="px-5 py-3 dark:text-sky-50">{{ event.actor_name || '—' }}</td>
                        <td class="px-5 py-3 text-ink-muted dark:text-slate-400">{{ event.note || '—' }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </section>
</template>
