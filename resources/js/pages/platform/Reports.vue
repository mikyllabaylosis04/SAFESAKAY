
<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import {
    Activity,
    BarChart3,
    Building2,
    CalendarDays,
    ChevronLeft,
    ChevronRight,
    Download,
    FileDown,
    Search,
    TrendingUp,
    UserPlus,
    Percent,
    Users,
    Wallet,
    X,
} from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import StatCards from '@/components/admin/StatCards.vue';
import type { StatCardItem } from '@/components/admin/StatCards.vue';

type ActivityRow = {
    id: number;
    action: string;
    action_label: string;
    organization_id: number | null;
    organization_name: string;
    actor_name: string | null;
    note: string | null;
    created_at_label: string | null;
    created_at?: string | null;
};

type OrganizationRow = {
    id: number;
    name: string;
    address: string;
    status_label: string;
    admin_name: string | null;
    admin_email: string | null;
    boats_count: number;
    users_count: number;
};

type RegistrationRow = {
    id: number;
    name: string;
    email: string;
    role: string;
    organization_name: string | null;
    created_at: string | null;
};

type MonthlyRow = {
    month: string;
    organizations?: number;
    registrations?: number;
    activities?: number;
};

type UsageStat = {
    label: string;
    value: number;
    description?: string;
};

type FinanceRow = {
    id: number;
    name: string;
    status_label: string;
    rentals: number;
    payments: number;
    collected: number;
    commission: number;
    operator_share: number;
    paid_out: number;
    last_payment_label: string | null;
};

const props = withDefaults(
    defineProps<{
        activity: ActivityRow[];
        organizations: OrganizationRow[];
        registrations?: RegistrationRow[];
        monthly_activity?: MonthlyRow[];
        usage_statistics?: UsageStat[];
        finance?: FinanceRow[];
    }>(),
    {
        registrations: () => [],
        monthly_activity: () => [],
        usage_statistics: () => [],
        finance: () => [],
    },
);

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Platform', href: '/platform' },
            { title: 'System Reports', href: '/platform/reports' },
        ],
    },
});

type ReportTab =
    | 'organizations'
    | 'finance'
    | 'registrations'
    | 'usage'
    | 'monthly';

const activeTab = ref<ReportTab>('organizations');
const search = ref('');
const page = ref(1);
const pageSize = 10;

const tabs = [
    {
        key: 'organizations' as const,
        label: 'Organization Activity',
        icon: Building2,
    },
    {
        key: 'finance' as const,
        label: 'Barangay Finance',
        icon: Wallet,
    },
    {
        key: 'registrations' as const,
        label: 'User Registrations',
        icon: UserPlus,
    },
    {
        key: 'usage' as const,
        label: 'System Usage Statistics',
        icon: BarChart3,
    },
    {
        key: 'monthly' as const,
        label: 'Monthly Platform Activity',
        icon: CalendarDays,
    },
];

const totalOrganizations = computed(
    () => props.organizations.length,
);

const totalUsers = computed(() =>
    props.organizations.reduce(
        (sum, organization) =>
            sum + Number(organization.users_count || 0),
        0,
    ),
);

const totalActivities = computed(() => props.activity.length);

const activeOrganizations = computed(() =>
    props.organizations.filter(
        organization =>
            organization.status_label.toLowerCase() === 'active',
    ).length,
);

const passengerRegistrations = computed(() =>
    props.registrations.filter(
        user => user.role.toLowerCase() === 'passenger',
    ).length,
);

const operatorRegistrations = computed(() =>
    props.registrations.filter(
        user => user.role.toLowerCase() === 'operator',
    ).length,
);

const summaryCards = computed<StatCardItem[]>(() => [
    { key: 'organizations', title: 'Organizations', value: totalOrganizations.value.toLocaleString('en-PH'), hint: 'Registered barangays', icon: Building2, tone: 'blue' },
    { key: 'users', title: 'Registered Users', value: totalUsers.value.toLocaleString('en-PH'), hint: 'Across all barangays', icon: Users, tone: 'green' },
    { key: 'activities', title: 'Recorded Activities', value: totalActivities.value.toLocaleString('en-PH'), hint: 'Platform events', icon: Activity, tone: 'amber' },
    { key: 'active', title: 'Active Organizations', value: activeOrganizations.value.toLocaleString('en-PH'), hint: 'Currently operating', icon: TrendingUp, tone: 'violet' },
]);

function peso(value: number) {
    return `₱${Number(value || 0).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
}

const financeTotals = computed(() => props.finance.reduce(
    (sum, row) => ({
        collected: sum.collected + row.collected,
        commission: sum.commission + row.commission,
        operator_share: sum.operator_share + row.operator_share,
        paid_out: sum.paid_out + row.paid_out,
    }),
    { collected: 0, commission: 0, operator_share: 0, paid_out: 0 },
));

const financeCards = computed<StatCardItem[]>(() => [
    { key: 'collected', title: 'Collected', value: peso(financeTotals.value.collected), hint: 'Verified rental payments', icon: Wallet, tone: 'blue' },
    { key: 'commission', title: 'Barangay Commission', value: peso(financeTotals.value.commission), hint: 'All barangays', icon: Percent, tone: 'green' },
    { key: 'operator_share', title: 'Operator Share', value: peso(financeTotals.value.operator_share), hint: 'Collected minus commission', icon: Users, tone: 'violet' },
    { key: 'paid_out', title: 'Paid to Operators', value: peso(financeTotals.value.paid_out), hint: 'Released payouts', icon: TrendingUp, tone: 'indigo' },
]);

const filteredFinance = computed(() => {
    const term = search.value.trim().toLowerCase();

    return props.finance.filter(row =>
        [row.name, row.status_label].some(value => value.toLowerCase().includes(term)),
    );
});

const paginatedFinance = computed(() =>
    filteredFinance.value.slice(
        (page.value - 1) * pageSize,
        page.value * pageSize,
    ),
);

const filteredOrganizations = computed(() => {
    const term = search.value.trim().toLowerCase();

    return props.organizations.filter(organization =>
        [
            organization.name,
            organization.address,
            organization.status_label,
            organization.admin_name ?? '',
        ].some(value => value.toLowerCase().includes(term)),
    );
});

const filteredRegistrations = computed(() => {
    const term = search.value.trim().toLowerCase();

    return props.registrations.filter(user =>
        [
            user.name,
            user.email,
            user.role,
            user.organization_name ?? '',
        ].some(value => value.toLowerCase().includes(term)),
    );
});

const filteredActivity = computed(() => {
    const term = search.value.trim().toLowerCase();

    return props.activity.filter(item =>
        [
            item.action,
            item.action_label,
            item.organization_name,
            item.actor_name ?? '',
            item.note ?? '',
        ].some(value => value.toLowerCase().includes(term)),
    );
});

const filteredMonthly = computed(() => {
    const term = search.value.trim().toLowerCase();

    return props.monthly_activity.filter(row =>
        row.month.toLowerCase().includes(term),
    );
});

const currentRows = computed(() => {
    switch (activeTab.value) {
        case 'organizations':
            return filteredOrganizations.value;
        case 'finance':
            return filteredFinance.value;
        case 'registrations':
            return filteredRegistrations.value;
        case 'usage':
            return filteredActivity.value;
        case 'monthly':
            return filteredMonthly.value;
        default:
            return [];
    }
});

const totalPages = computed(() =>
    Math.max(1, Math.ceil(currentRows.value.length / pageSize)),
);

const firstRecord = computed(() =>
    currentRows.value.length
        ? (page.value - 1) * pageSize + 1
        : 0,
);

const lastRecord = computed(() =>
    Math.min(page.value * pageSize, currentRows.value.length),
);

const paginatedOrganizations = computed(() =>
    filteredOrganizations.value.slice(
        (page.value - 1) * pageSize,
        page.value * pageSize,
    ),
);

const paginatedRegistrations = computed(() =>
    filteredRegistrations.value.slice(
        (page.value - 1) * pageSize,
        page.value * pageSize,
    ),
);

const paginatedActivity = computed(() =>
    filteredActivity.value.slice(
        (page.value - 1) * pageSize,
        page.value * pageSize,
    ),
);

const paginatedMonthly = computed(() =>
    filteredMonthly.value.slice(
        (page.value - 1) * pageSize,
        page.value * pageSize,
    ),
);

watch([activeTab, search], () => {
    page.value = 1;
});

watch(totalPages, pages => {
    if (page.value > pages) {
        page.value = pages;
    }
});

function selectTab(tab: ReportTab) {
    activeTab.value = tab;
    search.value = '';
    page.value = 1;
}

function formatDate(value: string | null) {
    if (!value) {
return '—';
}

    const date = new Date(value);

    if (Number.isNaN(date.getTime())) {
        return value;
    }

    return date.toLocaleDateString('en-PH', {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
    });
}

function statusClass(status: string) {
    const normalized = status.toLowerCase();

    if (normalized === 'active') {
        return 'bg-emerald-50 text-emerald-700';
    }

    if (normalized === 'pending') {
        return 'bg-amber-50 text-amber-700';
    }

    if (normalized === 'suspended') {
        return 'bg-rose-50 text-rose-700';
    }

    return 'bg-slate-100 text-slate-600';
}

function csvCell(value: unknown) {
    const text = String(value ?? '');

    // Keep spreadsheet applications from interpreting
    // untrusted values as formulas.
    const safe = /^[\s]*[=+\-@\t\r]/.test(text)
        ? `'${text}`
        : text;

    return `"${safe.replace(/"/g, '""')}"`;
}

function downloadCsv(
    filename: string,
    headers: string[],
    rows: unknown[][],
) {
    const csv = [
        headers.map(csvCell).join(','),
        ...rows.map(row => row.map(csvCell).join(',')),
    ].join('\r\n');

    const blob = new Blob(
        ['\uFEFF', csv],
        { type: 'text/csv;charset=utf-8;' },
    );

    const url = URL.createObjectURL(blob);
    const anchor = document.createElement('a');

    anchor.href = url;
    anchor.download = filename;

    document.body.appendChild(anchor);
    anchor.click();
    anchor.remove();

    URL.revokeObjectURL(url);
}

function exportCurrentReport() {
    switch (activeTab.value) {
        case 'organizations':
            // Existing server-side CSV export.
            window.location.href =
                '/platform/reports/export/organizations';
            break;

        case 'finance':
            window.location.href = '/platform/reports/export/finance';
            break;

        case 'registrations':
            downloadCsv(
                'safesakay-user-registrations.csv',
                [
                    'Name',
                    'Email',
                    'Role',
                    'Organization',
                    'Registered On',
                ],
                filteredRegistrations.value.map(user => [
                    user.name,
                    user.email,
                    user.role,
                    user.organization_name,
                    user.created_at,
                ]),
            );
            break;

        case 'usage':
            // Existing server-side activity CSV export.
            window.location.href =
                '/platform/reports/export/activity';
            break;

        case 'monthly':
            downloadCsv(
                'safesakay-monthly-platform-activity.csv',
                [
                    'Month',
                    'New Organizations',
                    'User Registrations',
                    'Recorded Activities',
                ],
                filteredMonthly.value.map(row => [
                    row.month,
                    row.organizations ?? 0,
                    row.registrations ?? 0,
                    row.activities ?? 0,
                ]),
            );
            break;
    }
}
</script>

<template>
    <Head title="System Reports" />

    <div class="flex flex-1 flex-col gap-6 p-4 sm:p-6">
        <!-- PAGE HEADER -->
        <header
            class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between"
        >
            <div>
                <h1
                    class="text-2xl font-bold tracking-tight text-[#0f4c8a] dark:text-sky-100"
                >
                    System Reports
                </h1>

                <p
                    class="mt-1 text-sm text-ink-muted dark:text-slate-400"
                >
                    Monitor overall platform usage, organization activity,
                    user registrations, and monthly system performance.
                </p>
            </div>

            <button
                type="button"
                class="inline-flex h-11 items-center justify-center gap-2 rounded-xl bg-[#1976d2] px-4 text-sm font-semibold text-white shadow-sm transition hover:bg-[#1261b2]"
                @click="exportCurrentReport"
            >
                <FileDown class="size-4" />
                Export Current Report
            </button>
        </header>

        <!-- SUMMARY CARDS -->
        <section aria-label="Platform statistics">
            <StatCards :items="summaryCards" />
        </section>

        <!-- REPORT CONTENT -->
        <section
            class="overflow-hidden rounded-2xl border border-[#d7e8f6] bg-white shadow-sm dark:border-white/10 dark:bg-slate-900"
        >
            <div
                class="border-b border-[#e8f1f8] p-4 dark:border-white/10 sm:p-5"
            >
                <h2
                    class="font-bold text-[#0f4c8a] dark:text-sky-100"
                >
                    Platform Reporting
                </h2>

                <p
                    class="mt-1 text-xs text-ink-muted dark:text-slate-400"
                >
                    Select a report category to view platform-wide data.
                </p>
            </div>

            <!-- TABS -->
            <div
                class="flex gap-2 overflow-x-auto border-b border-[#e8f1f8] p-4 dark:border-white/10"
            >
                <button
                    v-for="tab in tabs"
                    :key="tab.key"
                    type="button"
                    class="inline-flex shrink-0 items-center gap-2 rounded-xl px-4 py-2.5 text-sm font-semibold transition"
                    :class="
                        activeTab === tab.key
                            ? 'bg-[#1976d2] text-white'
                            : 'bg-[#edf5ff] text-[#1976d2] hover:bg-[#dcecff] dark:bg-white/10 dark:text-sky-200'
                    "
                    @click="selectTab(tab.key)"
                >
                    <component :is="tab.icon" class="size-4" />
                    {{ tab.label }}
                </button>
            </div>

            <!-- SEARCH -->
            <div
                class="flex flex-wrap items-center justify-between gap-3 border-b border-[#e8f1f8] p-4 dark:border-white/10"
            >
                <div class="relative w-full max-w-lg">
                    <Search
                        class="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-ink-muted"
                    />

                    <input
                        v-model="search"
                        type="search"
                        aria-label="Search report"
                        :placeholder="
                            activeTab === 'organizations' || activeTab === 'finance'
                                ? 'Search organizations...'
                                : activeTab === 'registrations'
                                  ? 'Search users or organizations...'
                                  : activeTab === 'monthly'
                                    ? 'Search month...'
                                    : 'Search recorded activities...'
                        "
                        class="h-11 w-full rounded-xl border border-[#d5e5f4] bg-[#f8fbff] pl-10 pr-3 text-sm text-ink outline-none focus:border-[#1976d2] dark:border-white/10 dark:bg-white/5 dark:text-white"
                    />
                </div>

                <div class="flex items-center gap-3">
                    <span
                        class="rounded-full bg-[#edf5ff] px-3 py-1 text-xs font-semibold text-[#1769bf]"
                    >
                        {{ currentRows.length }} records
                    </span>

                    <button
                        v-if="search"
                        type="button"
                        class="inline-flex items-center gap-1 text-xs font-semibold text-[#1976d2]"
                        @click="search = ''"
                    >
                        <X class="size-3.5" />
                        Clear
                    </button>
                </div>
            </div>

            <div v-if="activeTab === 'finance'">
                <div class="border-b border-[#e8f1f8] p-4 dark:border-white/10">
                    <StatCards :items="financeCards" />
                </div>

                <div
                    v-if="filteredFinance.length === 0"
                    class="px-5 py-14 text-center text-sm text-ink-muted"
                >
                    No finance records found.
                </div>

                <div v-else class="overflow-x-auto">
                    <table class="w-full min-w-[960px] text-left text-sm">
                        <thead class="bg-[#f4f9fd] text-xs uppercase tracking-wide text-ink-soft dark:bg-white/5">
                            <tr>
                                <th class="px-5 py-3.5">Barangay</th>
                                <th class="px-5 py-3.5">Rentals</th>
                                <th class="px-5 py-3.5">Collected</th>
                                <th class="px-5 py-3.5">Commission</th>
                                <th class="px-5 py-3.5">Operator Share</th>
                                <th class="px-5 py-3.5">Paid Out</th>
                                <th class="px-5 py-3.5">Last Payment</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="row in paginatedFinance"
                                :key="row.id"
                                class="border-t border-[#eef4f9] hover:bg-[#f9fcff] dark:border-white/10 dark:hover:bg-white/5"
                            >
                                <td class="px-5 py-4">
                                    <p class="font-semibold text-[#0f4c8a] dark:text-sky-100">{{ row.name }}</p>
                                    <span
                                        class="mt-1 inline-flex rounded-full px-2 py-0.5 text-[11px] font-semibold"
                                        :class="statusClass(row.status_label)"
                                    >
                                        {{ row.status_label }}
                                    </span>
                                </td>
                                <td class="px-5 py-4 dark:text-white">
                                    <p class="font-semibold">{{ row.rentals }}</p>
                                    <p class="text-xs text-ink-muted">{{ row.payments }} payments</p>
                                </td>
                                <td class="px-5 py-4 font-semibold text-[#0f4c8a] dark:text-sky-100">{{ peso(row.collected) }}</td>
                                <td class="px-5 py-4 font-semibold text-emerald-700 dark:text-emerald-300">{{ peso(row.commission) }}</td>
                                <td class="px-5 py-4 dark:text-white">{{ peso(row.operator_share) }}</td>
                                <td class="px-5 py-4 dark:text-white">{{ peso(row.paid_out) }}</td>
                                <td class="px-5 py-4 text-xs text-ink-muted">{{ row.last_payment_label || '—' }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- ORGANIZATION ACTIVITY -->
            <div v-else-if="activeTab === 'organizations'">
                <div class="border-b border-[#e8f1f8] px-5 py-4 dark:border-white/10">
                    <h3
                        class="font-semibold text-[#0f4c8a] dark:text-sky-100"
                    >
                        Organization Activity
                    </h3>

                    <p class="mt-1 text-xs text-ink-muted">
                        Organization status, administrator information,
                        registered users, and fleet size.
                    </p>
                </div>

                <div
                    v-if="filteredOrganizations.length === 0"
                    class="px-5 py-14 text-center text-sm text-ink-muted"
                >
                    No organization records found.
                </div>

                <div v-else class="overflow-x-auto">
                    <table class="w-full min-w-[850px] text-left text-sm">
                        <thead
                            class="bg-[#f4f9fd] text-xs uppercase tracking-wide text-ink-soft dark:bg-white/5"
                        >
                            <tr>
                                <th class="px-5 py-3.5">Organization</th>
                                <th class="px-5 py-3.5">Status</th>
                                <th class="px-5 py-3.5">Administrator</th>
                                <th class="px-5 py-3.5">Users</th>
                                <th class="px-5 py-3.5">Boats</th>
                            </tr>
                        </thead>

                        <tbody>
                            <tr
                                v-for="organization in paginatedOrganizations"
                                :key="organization.id"
                                class="border-t border-[#eef4f9] hover:bg-[#f9fcff] dark:border-white/10 dark:hover:bg-white/5"
                            >
                                <td class="px-5 py-4">
                                    <p
                                        class="font-semibold text-[#0f4c8a] dark:text-sky-100"
                                    >
                                        {{ organization.name }}
                                    </p>

                                    <p class="mt-1 text-xs text-ink-muted">
                                        {{ organization.address }}
                                    </p>
                                </td>

                                <td class="px-5 py-4">
                                    <span
                                        class="inline-flex rounded-full px-2.5 py-1 text-xs font-semibold"
                                        :class="statusClass(organization.status_label)"
                                    >
                                        {{ organization.status_label }}
                                    </span>
                                </td>

                                <td class="px-5 py-4">
                                    <p class="font-medium dark:text-white">
                                        {{ organization.admin_name || '—' }}
                                    </p>

                                    <p class="text-xs text-ink-muted">
                                        {{ organization.admin_email || '—' }}
                                    </p>
                                </td>

                                <td class="px-5 py-4 font-semibold dark:text-white">
                                    {{ organization.users_count }}
                                </td>

                                <td class="px-5 py-4 font-semibold dark:text-white">
                                    {{ organization.boats_count }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- USER REGISTRATION REPORTS -->
            <div v-else-if="activeTab === 'registrations'">
                <div
                    class="grid grid-cols-1 gap-3 border-b border-[#e8f1f8] p-4 dark:border-white/10 sm:grid-cols-3"
                >
                    <div class="rounded-xl bg-[#edf5ff] p-4">
                        <p class="text-xs text-ink-soft">
                            Registration Records
                        </p>
                        <p class="mt-1 text-2xl font-bold text-[#0f4c8a]">
                            {{ registrations.length }}
                        </p>
                    </div>

                    <div class="rounded-xl bg-[#eaf9f0] p-4 dark:bg-emerald-950/40">
                        <p class="text-xs text-ink-soft">
                            Passenger Registrations
                        </p>
                        <p class="mt-1 text-2xl font-bold text-[#0f4c8a]">
                            {{ passengerRegistrations }}
                        </p>
                    </div>

                    <div class="rounded-xl bg-[#fff5e5] p-4 dark:bg-amber-950/40">
                        <p class="text-xs text-ink-soft">
                            Operator Registrations
                        </p>
                        <p class="mt-1 text-2xl font-bold text-[#0f4c8a]">
                            {{ operatorRegistrations }}
                        </p>
                    </div>
                </div>

                <div class="px-5 py-4">
                    <h3 class="font-semibold text-[#0f4c8a] dark:text-sky-100">
                        User Registration Reports
                    </h3>
                    <p class="mt-1 text-xs text-ink-muted">
                        Passenger and operator registration records
                        across the SafeSakay platform.
                    </p>
                </div>

                <div
                    v-if="filteredRegistrations.length === 0"
                    class="px-5 py-12 text-center"
                >
                    <UserPlus class="mx-auto mb-3 size-9 text-[#a1bad2]" />
                    <p class="text-sm font-medium text-[#0f4c8a] dark:text-sky-100">
                        No registration data available
                    </p>
                    <p class="mt-1 text-xs text-ink-muted">
                        Registration records require data from the
                        platform reports controller.
                    </p>
                </div>

                <div v-else class="overflow-x-auto">
                    <table class="w-full min-w-[800px] text-left text-sm">
                        <thead
                            class="bg-[#f4f9fd] text-xs uppercase tracking-wide text-ink-soft dark:bg-white/5"
                        >
                            <tr>
                                <th class="px-5 py-3.5">User</th>
                                <th class="px-5 py-3.5">Role</th>
                                <th class="px-5 py-3.5">Organization</th>
                                <th class="px-5 py-3.5">Registered On</th>
                            </tr>
                        </thead>

                        <tbody>
                            <tr
                                v-for="user in paginatedRegistrations"
                                :key="user.id"
                                class="border-t border-[#eef4f9] dark:border-white/10"
                            >
                                <td class="px-5 py-4">
                                    <p class="font-semibold text-[#0f4c8a] dark:text-sky-100">
                                        {{ user.name }}
                                    </p>
                                    <p class="text-xs text-ink-muted">
                                        {{ user.email }}
                                    </p>
                                </td>

                                <td class="px-5 py-4 capitalize">
                                    {{ user.role }}
                                </td>

                                <td class="px-5 py-4">
                                    {{ user.organization_name || '—' }}
                                </td>

                                <td class="px-5 py-4 text-ink-muted">
                                    {{ formatDate(user.created_at) }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- SYSTEM USAGE STATISTICS -->
            <div v-else-if="activeTab === 'usage'">
                <div class="p-5">
                    <h3 class="font-semibold text-[#0f4c8a] dark:text-sky-100">
                        System Usage Statistics
                    </h3>

                    <p class="mt-1 text-xs text-ink-muted">
                        Recorded platform events and available
                        usage measurements.
                    </p>

                    <div
                        v-if="usage_statistics.length"
                        class="mt-4 grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-4"
                    >
                        <div
                            v-for="stat in usage_statistics"
                            :key="stat.label"
                            class="rounded-xl border border-[#d7e8f6] bg-[#f8fbff] p-4 dark:bg-[#0b1b30] dark:text-slate-100"
                        >
                            <p class="text-xs font-medium text-ink-soft">
                                {{ stat.label }}
                            </p>

                            <p class="mt-2 text-2xl font-bold text-[#0f4c8a]">
                                {{ stat.value.toLocaleString('en-PH') }}
                            </p>

                            <p
                                v-if="stat.description"
                                class="mt-1 text-xs text-ink-muted"
                            >
                                {{ stat.description }}
                            </p>
                        </div>
                    </div>

                    <div
                        v-else
                        class="mt-4 rounded-xl bg-[#edf5ff] p-4 text-sm text-ink-soft"
                    >
                        Detailed usage metrics are not yet supplied by
                        the backend. The activity table below shows
                        existing recorded platform events.
                    </div>
                </div>

                <div class="border-t border-[#e8f1f8] px-5 py-4 dark:border-white/10">
                    <h4 class="font-semibold text-[#0f4c8a] dark:text-sky-100">
                        Recorded Platform Events
                    </h4>
                </div>

                <div
                    v-if="filteredActivity.length === 0"
                    class="px-5 py-12 text-center text-sm text-ink-muted"
                >
                    No recorded activity found.
                </div>

                <div v-else class="overflow-x-auto">
                    <table class="w-full min-w-[900px] text-left text-sm">
                        <thead
                            class="bg-[#f4f9fd] text-xs uppercase tracking-wide text-ink-soft dark:bg-white/5"
                        >
                            <tr>
                                <th class="px-5 py-3.5">When</th>
                                <th class="px-5 py-3.5">Action</th>
                                <th class="px-5 py-3.5">Organization</th>
                                <th class="px-5 py-3.5">Performed By</th>
                                <th class="px-5 py-3.5">Details</th>
                            </tr>
                        </thead>

                        <tbody>
                            <tr
                                v-for="item in paginatedActivity"
                                :key="item.id"
                                class="border-t border-[#eef4f9] dark:border-white/10"
                            >
                                <td class="px-5 py-4 text-ink-muted">
                                    {{ item.created_at_label || '—' }}
                                </td>

                                <td class="px-5 py-4 font-semibold text-[#0f4c8a] dark:text-sky-100">
                                    {{ item.action_label }}
                                </td>

                                <td class="px-5 py-4">
                                    {{ item.organization_name }}
                                </td>

                                <td class="px-5 py-4">
                                    {{ item.actor_name || '—' }}
                                </td>

                                <td class="px-5 py-4 text-ink-muted">
                                    {{ item.note || '—' }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- MONTHLY PLATFORM ACTIVITY -->
            <div v-else-if="activeTab === 'monthly'">
                <div class="p-5">
                    <h3 class="font-semibold text-[#0f4c8a] dark:text-sky-100">
                        Monthly Platform Activity
                    </h3>

                    <p class="mt-1 text-xs text-ink-muted">
                        Monthly organization registrations, user
                        registrations, and recorded platform events.
                    </p>
                </div>

                <div
                    v-if="filteredMonthly.length === 0"
                    class="px-5 py-12 text-center"
                >
                    <CalendarDays class="mx-auto mb-3 size-9 text-[#a1bad2]" />
                    <p class="text-sm font-medium text-[#0f4c8a] dark:text-sky-100">
                        No monthly statistics available
                    </p>
                    <p class="mt-1 text-xs text-ink-muted">
                        Monthly totals will appear when the backend
                        provides monthly activity data.
                    </p>
                </div>

                <div v-else class="overflow-x-auto">
                    <table class="w-full min-w-[760px] text-left text-sm">
                        <thead
                            class="bg-[#f4f9fd] text-xs uppercase tracking-wide text-ink-soft dark:bg-white/5"
                        >
                            <tr>
                                <th class="px-5 py-3.5">Month</th>
                                <th class="px-5 py-3.5">New Organizations</th>
                                <th class="px-5 py-3.5">User Registrations</th>
                                <th class="px-5 py-3.5">Recorded Activities</th>
                            </tr>
                        </thead>

                        <tbody>
                            <tr
                                v-for="item in paginatedMonthly"
                                :key="item.month"
                                class="border-t border-[#eef4f9] dark:border-white/10"
                            >
                                <td class="px-5 py-4 font-semibold text-[#0f4c8a] dark:text-sky-100">
                                    {{ item.month }}
                                </td>

                                <td class="px-5 py-4">
                                    {{ item.organizations ?? '—' }}
                                </td>

                                <td class="px-5 py-4">
                                    {{ item.registrations ?? '—' }}
                                </td>

                                <td class="px-5 py-4">
                                    {{ item.activities ?? '—' }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- PAGINATION -->
            <div
                v-if="currentRows.length"
                class="flex flex-col gap-3 border-t border-[#edf2f7] px-5 py-4 text-xs text-ink-muted dark:border-white/10 sm:flex-row sm:items-center sm:justify-between"
            >
                <span>
                    Showing {{ firstRecord }}–{{ lastRecord }}
                    of {{ currentRows.length }} records
                </span>

                <div
                    v-if="totalPages > 1"
                    class="flex items-center gap-2"
                >
                    <button
                        type="button"
                        :disabled="page === 1"
                        aria-label="Previous page"
                        class="flex size-9 items-center justify-center rounded-lg border border-[#d5e5f4] disabled:opacity-40"
                        @click="page--"
                    >
                        <ChevronLeft class="size-4" />
                    </button>

                    <span class="px-2 font-semibold">
                        Page {{ page }} of {{ totalPages }}
                    </span>

                    <button
                        type="button"
                        :disabled="page === totalPages"
                        aria-label="Next page"
                        class="flex size-9 items-center justify-center rounded-lg border border-[#d5e5f4] disabled:opacity-40"
                        @click="page++"
                    >
                        <ChevronRight class="size-4" />
                    </button>
                </div>
            </div>
        </section>

        <!-- FOOTER -->
        <div class="flex items-center gap-2 text-xs text-ink-muted">
            <Download class="size-4" />
            Reports reflect records supplied by the SafeSakay platform.
        </div>
    </div>
</template>
