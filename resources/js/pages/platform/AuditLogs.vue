
<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import {
    Activity,
    CalendarDays,
    ChevronLeft,
    ChevronRight,
    Clock3,
    Download,
    Eye,
    FileClock,
    Search,
    ShieldCheck,
    UserRound,
    X,
} from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import StatCards from '@/components/admin/StatCards.vue';
import type { StatCardItem } from '@/components/admin/StatCards.vue';

type AuditLog = {
    id: number;
    action: string;
    action_label: string;
    actor_name: string;
    organization_name: string;
    note: string | null;
    created_at: string | null;
};

type PaginatedLogs = {
    data: AuditLog[];
    current_page: number;
    last_page: number;
    total: number;
    prev_page_url: string | null;
    next_page_url: string | null;
};

const props = defineProps<{
    logs: PaginatedLogs;
    filters: { search: string; action: string; date: string };
    action_options: { value: string; label: string }[];
    stats: { total: number; today: number; this_month: number };
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Platform', href: '/platform' },
            { title: 'Audit Logs', href: '/platform/audit-logs' },
        ],
    },
});

const search = ref(props.filters.search);
const selectedAction = ref(props.filters.action || 'all');
const selectedDate = ref(props.filters.date);
const selectedLog = ref<AuditLog | null>(null);

const filteredLogs = computed(() => props.logs.data);

const hasFilters = computed(() => Boolean(search.value || selectedDate.value || selectedAction.value !== 'all'));

const summaryCards = computed<StatCardItem[]>(() => [
    { key: 'total', title: 'Total Events', value: props.stats.total.toLocaleString('en-PH'), hint: 'All recorded activity', icon: FileClock, tone: 'blue' },
    { key: 'today', title: 'Today', value: props.stats.today.toLocaleString('en-PH'), hint: 'Events recorded today', icon: Activity, tone: 'green' },
    { key: 'month', title: 'This Month', value: props.stats.this_month.toLocaleString('en-PH'), hint: 'Events this month', icon: CalendarDays, tone: 'violet' },
]);

function filterQuery() {
    return {
        search: search.value.trim() || undefined,
        action: selectedAction.value !== 'all' ? selectedAction.value : undefined,
        date: selectedDate.value || undefined,
    };
}

function applyFilters() {
    router.get('/platform/audit-logs', filterQuery(), {
        preserveState: true,
        preserveScroll: true,
        replace: true,
    });
}

let searchTimer: ReturnType<typeof setTimeout> | undefined;

watch(search, () => {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(applyFilters, 350);
});

watch([selectedAction, selectedDate], applyFilters);

watch(
    () => props.logs.current_page,
    () => {
        selectedLog.value = null;
    },
);

function category(action: string): string {
    const value = action.toLowerCase();

    if (value.includes('login') || value.includes('logout')) {
        return 'Authentication';
    }

    if (
        value.includes('activat') ||
        value.includes('deactivat') ||
        value.includes('suspend') ||
        value.includes('restore')
    ) {
        return 'Account / Access';
    }

    if (
        value.includes('user') ||
        value.includes('account') ||
        value.includes('register')
    ) {
        return 'Account Management';
    }

    return 'Administrative Action';
}

function badgeClass(action: string): string {
    const group = category(action);

    if (group === 'Authentication') {
        return 'bg-blue-50 text-blue-700';
    }

    if (group === 'Account / Access') {
        return 'bg-amber-50 text-amber-700';
    }

    if (group === 'Account Management') {
        return 'bg-emerald-50 text-emerald-700';
    }

    return 'bg-violet-50 text-violet-700';
}

function clearFilters() {
    search.value = '';
    selectedAction.value = 'all';
    selectedDate.value = '';
}

function exportCsv() {
    const params = new URLSearchParams(
        Object.entries(filterQuery()).filter((entry): entry is [string, string] => Boolean(entry[1])),
    ).toString();

    window.location.href = `/platform/audit-logs/export${params ? `?${params}` : ''}`;
}
</script>

<template>
    <Head title="Audit Logs" />

    <div class="flex flex-1 flex-col gap-6 p-4 sm:p-6">
        <!-- HEADER -->
        <div
            class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between"
        >
            <div>
                <h1
                    class="text-2xl font-bold tracking-tight text-[#0f4c8a] dark:text-sky-100"
                >
                    Audit Logs
                </h1>

                <p class="mt-1 text-sm text-ink-muted">
                    Track recorded platform activities, account actions,
                    and important administrative events.
                </p>
            </div>

            <button
                type="button"
                class="inline-flex h-10 items-center justify-center gap-2 rounded-xl bg-[#1976d2] px-4 text-sm font-semibold text-white transition hover:bg-[#1261b2]"
                @click="exportCsv"
            >
                <Download class="size-4" />
                Export CSV
            </button>
        </div>

        <StatCards :items="summaryCards" />

        <!-- MAIN TABLE -->
        <section
            class="overflow-hidden rounded-2xl border border-[#d7e8f6] bg-white shadow-sm dark:border-white/10 dark:bg-slate-900"
        >
            <div class="border-b border-[#e8f1f8] p-5 dark:border-white/10">
                <h2 class="font-semibold text-[#0f4c8a] dark:text-sky-100">
                    Platform Audit History
                </h2>

                <p class="mt-1 text-xs text-ink-muted">
                    Search and review events recorded in the SafeSakay platform.
                </p>
            </div>

            <!-- FILTERS -->
            <div
                class="grid grid-cols-1 gap-3 border-b border-[#e8f1f8] p-4 dark:border-white/10 md:grid-cols-4"
            >
                <div class="relative md:col-span-2">
                    <Search
                        class="absolute left-3 top-1/2 size-4 -translate-y-1/2 text-ink-muted"
                    />

                    <input
                        v-model="search"
                        type="search"
                        placeholder="Search action, user, organization..."
                        aria-label="Search audit logs"
                        class="h-10 w-full rounded-xl border border-[#d7e8f6] bg-[#f8fbff] pl-10 pr-3 text-sm outline-none focus:border-[#1976d2] dark:border-white/10 dark:bg-white/5 dark:text-white"
                    />
                </div>

                <select
                    v-model="selectedAction"
                    aria-label="Filter by action"
                    class="h-10 rounded-xl border border-[#d7e8f6] bg-[#f8fbff] px-3 text-sm text-[#0f4c8a] dark:border-white/10 dark:bg-slate-800 dark:text-white"
                >
                    <option value="all">All Actions</option>

                    <option
                        v-for="option in action_options"
                        :key="option.value"
                        :value="option.value"
                    >
                        {{ option.label }}
                    </option>
                </select>

                <input
                    v-model="selectedDate"
                    type="date"
                    aria-label="Filter by date"
                    class="h-10 rounded-xl border border-[#d7e8f6] bg-[#f8fbff] px-3 text-sm text-[#0f4c8a] dark:border-white/10 dark:bg-slate-800 dark:text-white"
                />
            </div>

            <div
                class="flex items-center justify-between gap-3 border-b border-[#e8f1f8] px-5 py-3 dark:border-white/10"
            >
                <span class="text-xs text-ink-muted">
                    {{ logs.total.toLocaleString('en-PH') }} matching records
                </span>

                <button
                    v-if="hasFilters"
                    type="button"
                    class="inline-flex items-center gap-1 text-xs font-semibold text-[#1976d2]"
                    @click="clearFilters"
                >
                    <X class="size-3.5" />
                    Clear Filters
                </button>
            </div>

            <div
                v-if="filteredLogs.length === 0"
                class="px-5 py-16 text-center"
            >
                <FileClock class="mx-auto mb-3 size-10 text-[#a1bad2]" />

                <p class="font-semibold text-[#0f4c8a] dark:text-sky-100">
                    No audit logs found
                </p>

                <p class="mt-1 text-sm text-ink-muted">
                    No recorded events match the current filters.
                </p>
            </div>

            <div v-else class="overflow-x-auto">
                <table class="w-full min-w-[950px] text-left text-sm">
                    <thead
                        class="bg-[#f4f9fd] text-xs uppercase tracking-wide text-ink-soft dark:bg-white/5"
                    >
                        <tr>
                            <th class="px-5 py-3.5">Date & Time</th>
                            <th class="px-5 py-3.5">Activity</th>
                            <th class="px-5 py-3.5">Category</th>
                            <th class="px-5 py-3.5">Performed By</th>
                            <th class="px-5 py-3.5">Organization</th>
                            <th class="px-5 py-3.5">Details</th>
                        </tr>
                    </thead>

                    <tbody>
                        <tr
                            v-for="log in filteredLogs"
                            :key="log.id"
                            class="border-t border-[#eef4f9] transition hover:bg-[#f8fbff] dark:border-white/10 dark:hover:bg-white/5"
                        >
                            <td class="whitespace-nowrap px-5 py-4 text-ink-muted">
                                <div class="flex items-center gap-2">
                                    <Clock3 class="size-3.5" />
                                    {{ log.created_at || '—' }}
                                </div>
                            </td>

                            <td class="px-5 py-4">
                                <span
                                    class="font-semibold text-[#0f4c8a] dark:text-sky-100"
                                >
                                    {{ log.action_label }}
                                </span>
                            </td>

                            <td class="px-5 py-4">
                                <span
                                    class="inline-flex whitespace-nowrap rounded-full px-2.5 py-1 text-xs font-semibold"
                                    :class="badgeClass(log.action)"
                                >
                                    {{ category(log.action) }}
                                </span>
                            </td>

                            <td class="px-5 py-4 dark:text-white">
                                <div class="flex items-center gap-2">
                                    <UserRound class="size-4 text-ink-muted" />
                                    {{ log.actor_name }}
                                </div>
                            </td>

                            <td class="px-5 py-4 dark:text-white">
                                {{ log.organization_name }}
                            </td>

                            <td class="px-5 py-4">
                                <button
                                    type="button"
                                    class="inline-flex items-center gap-1.5 rounded-lg bg-[#e8f3ff] px-3 py-1.5 text-xs font-semibold text-[#1976d2] hover:bg-[#d6e9ff]"
                                    @click="selectedLog = log"
                                >
                                    <Eye class="size-3.5" />
                                    View
                                </button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- SERVER PAGINATION -->
            <div
                class="flex flex-wrap items-center justify-between gap-3 border-t border-[#e8f1f8] px-5 py-4 dark:border-white/10"
            >
                <span class="text-xs text-ink-muted">
                    Page {{ logs.current_page }} of {{ logs.last_page }}
                    · {{ logs.total }} total records
                </span>

                <div class="flex gap-2">
                    <Link
                        v-if="logs.prev_page_url"
                        :href="logs.prev_page_url"
                        preserve-state
                        preserve-scroll
                        class="inline-flex items-center gap-1 rounded-lg border border-[#d7e8f6] px-3 py-2 text-xs font-semibold text-[#1976d2]"
                    >
                        <ChevronLeft class="size-4" />
                        Previous
                    </Link>

                    <Link
                        v-if="logs.next_page_url"
                        :href="logs.next_page_url"
                        preserve-state
                        preserve-scroll
                        class="inline-flex items-center gap-1 rounded-lg border border-[#d7e8f6] px-3 py-2 text-xs font-semibold text-[#1976d2]"
                    >
                        Next
                        <ChevronRight class="size-4" />
                    </Link>
                </div>
            </div>
        </section>

        <!-- DETAILS MODAL -->
        <div
            v-if="selectedLog"
            class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4"
            @click.self="selectedLog = null"
        >
            <div
                role="dialog"
                aria-modal="true"
                aria-labelledby="audit-log-title"
                class="w-full max-w-lg rounded-2xl bg-white p-6 shadow-xl dark:bg-slate-900"
            >
                <div class="mb-5 flex items-center justify-between">
                    <div class="flex items-center gap-3">
                        <div
                            class="flex size-10 items-center justify-center rounded-xl bg-[#e8f3ff] text-[#1976d2]"
                        >
                            <ShieldCheck class="size-5" />
                        </div>

                        <h3
                            id="audit-log-title"
                            class="text-lg font-bold text-[#0f4c8a] dark:text-sky-100"
                        >
                            Audit Log Details
                        </h3>
                    </div>

                    <button
                        type="button"
                        aria-label="Close details"
                        class="rounded-lg p-2 text-slate-500 hover:bg-slate-100"
                        @click="selectedLog = null"
                    >
                        <X class="size-5" />
                    </button>
                </div>

                <div class="space-y-4 text-sm">
                    <div>
                        <p class="text-xs text-ink-muted">Activity</p>
                        <p class="mt-1 font-semibold text-[#0f4c8a] dark:text-sky-100">
                            {{ selectedLog.action_label }}
                        </p>
                    </div>

                    <div>
                        <p class="text-xs text-ink-muted">Category</p>
                        <p class="mt-1 dark:text-white">
                            {{ category(selectedLog.action) }}
                        </p>
                    </div>

                    <div>
                        <p class="text-xs text-ink-muted">Performed By</p>
                        <p class="mt-1 dark:text-white">
                            {{ selectedLog.actor_name }}
                        </p>
                    </div>

                    <div>
                        <p class="text-xs text-ink-muted">Organization</p>
                        <p class="mt-1 dark:text-white">
                            {{ selectedLog.organization_name }}
                        </p>
                    </div>

                    <div>
                        <p class="text-xs text-ink-muted">Date & Time</p>
                        <p class="mt-1 dark:text-white">
                            {{ selectedLog.created_at || '—' }}
                        </p>
                    </div>

                    <div>
                        <p class="text-xs text-ink-muted">Details / Note</p>
                        <p
                            class="mt-1 whitespace-pre-wrap rounded-xl bg-[#f4f9fd] p-3 text-ink-soft dark:bg-white/5 dark:text-slate-200"
                        >
                            {{ selectedLog.note || 'No additional details recorded.' }}
                        </p>
                    </div>
                </div>

                <button
                    type="button"
                    class="mt-6 w-full rounded-xl bg-[#1976d2] py-2.5 text-sm font-semibold text-white"
                    @click="selectedLog = null"
                >
                    Close
                </button>
            </div>
        </div>
    </div>
</template>
