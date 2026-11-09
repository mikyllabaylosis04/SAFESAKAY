
<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import {
    Activity,
    ArrowRight,
    Ban,
    Building2,
    CheckCircle2,
    Clock3,
    ShieldCheck,
    Ticket,
    Ship,
    Users,
    Wallet,
} from '@lucide/vue';
import { computed } from 'vue';
import StatCards from '@/components/admin/StatCards.vue';
import type { StatCardItem } from '@/components/admin/StatCards.vue';

type PlatformOrganization = {
    id: number;
    name: string;
    address: string;
    admin_name: string | null;
    admin_email: string | null;
    is_active: boolean;
    access_state: 'pending' | 'active' | 'suspended' | 'inactive';
    status_label: string;
    boats_count: number;
    users_count: number;
};

type PlatformEventRow = {
    id: number;
    action: string;
    action_label: string;
    organization_id: number | null;
    organization_name: string;
    actor_name: string | null;
    note: string | null;
    created_at_label: string | null;
};

const props = defineProps<{
    stats: {
        organizations: number;
        pending: number;
        active: number;
        deactivated: number;
        boats: number;
        admins: number;
        rentals: number;
        operators?: number;
        passengers?: number;
        commission?: number;
    };
    organizations: PlatformOrganization[];
    recent_events: PlatformEventRow[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Platform',
                href: '/platform',
            },
        ],
    },
});

const summaryCards = computed<StatCardItem[]>(() => [
    { key: 'organizations', title: 'Organizations', value: displayCount(props.stats.organizations), hint: 'Registered barangays', icon: Building2, href: '/platform/organizations', tone: 'blue' },
    { key: 'admins', title: 'Barangay Admins', value: displayCount(props.stats.admins), hint: 'Administrator accounts', icon: ShieldCheck, href: '/platform/admins', tone: 'green' },
    { key: 'operators', title: 'Boat Operators', value: displayCount(props.stats.operators), hint: 'Across all barangays', icon: Ship, href: '/platform/users', tone: 'amber' },
    { key: 'passengers', title: 'Passengers', value: displayCount(props.stats.passengers), hint: 'Mobile app accounts', icon: Users, href: '/platform/users', tone: 'violet' },
    { key: 'commission', title: 'Barangay Commission', value: `₱${Number(props.stats.commission ?? 0).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`, hint: 'All barangays combined', icon: Wallet, href: '/platform/reports', tone: 'indigo' },
]);

const organizationStatuses = computed<StatCardItem[]>(() => [
    { key: 'active', title: 'Active', value: displayCount(props.stats.active), hint: 'Operational barangays', icon: CheckCircle2, href: '/platform/organizations', tone: 'green' },
    { key: 'pending', title: 'Pending', value: displayCount(props.stats.pending), hint: 'Awaiting approval', icon: Clock3, href: '/platform/pending', tone: 'amber' },
    { key: 'deactivated', title: 'Deactivated', value: displayCount(props.stats.deactivated), hint: 'Access turned off', icon: Ban, href: '/platform/organizations', tone: 'red' },
]);

const recentOrganizations = computed(() =>
    props.organizations.slice(0, 5),
);

const recentActivities = computed(() =>
    props.recent_events.slice(0, 5),
);

function displayCount(value: number | undefined | null): string {
    if (value === undefined || value === null) {
        return '—';
    }

    return value.toLocaleString('en-PH');
}

function organizationStatusClass(
    state: PlatformOrganization['access_state'],
): string {
    switch (state) {
        case 'active':
            return 'bg-emerald-50 text-emerald-700';
        case 'pending':
            return 'bg-amber-50 text-amber-700';
        case 'suspended':
        case 'inactive':
            return 'bg-rose-50 text-rose-700';
        default:
            return 'bg-slate-100 text-slate-600';
    }
}
</script>

<template>
    <Head title="Super Admin Dashboard" />

    <div class="flex flex-1 flex-col gap-6 p-4 sm:p-6">
        <!-- PAGE HEADER -->
        <header
            class="flex flex-wrap items-center justify-between gap-4"
        >
            <div>
                <h1
                    class="text-2xl font-bold tracking-tight text-[#0f4c8a] dark:text-sky-100"
                >
                    Platform Dashboard
                </h1>

                <p
                    class="mt-1 text-sm text-ink-muted dark:text-slate-400"
                >
                    Overview of SafeSakay organizations, users,
                    and platform activity.
                </p>
            </div>

            <div
                class="inline-flex items-center gap-2 rounded-full border border-[#d7e8f6] bg-white px-3 py-2 text-xs font-semibold text-[#0f4c8a] shadow-sm dark:border-white/10 dark:bg-white/5 dark:text-sky-100"
            >
                <span class="size-2 rounded-full bg-emerald-500" />
                Super Admin Panel
            </div>
        </header>

        <!-- MAIN STATISTICS -->
        <section aria-label="Platform statistics">
            <StatCards :items="summaryCards" />
        </section>

        <!-- ORGANIZATION STATUS AND QUICK ACTIONS -->
        <div class="grid items-start gap-5 xl:grid-cols-3">
            <!-- ORGANIZATION STATUS -->
            <section
                class="overflow-hidden rounded-2xl border border-[#d7e8f6] bg-white shadow-sm dark:border-white/10 dark:bg-white/5 xl:col-span-2"
            >
                <div
                    class="flex items-center justify-between gap-3 border-b border-[#e8f1f8] px-5 py-4 dark:border-white/10"
                >
                    <div class="flex items-center gap-3">
                        <div
                            class="flex size-9 items-center justify-center rounded-lg bg-[#e8f1fd] text-[#1b7ad8]"
                        >
                            <Building2 class="size-5" />
                        </div>

                        <div>
                            <h2
                                class="text-sm font-semibold text-[#0f4c8a] dark:text-sky-100"
                            >
                                Organization Status
                            </h2>
                            <p
                                class="mt-0.5 text-xs text-ink-muted dark:text-slate-400"
                            >
                                Access status of registered barangays
                            </p>
                        </div>
                    </div>

                    <Link
                        href="/platform/organizations"
                        class="text-xs font-semibold text-[#1b7ad8] hover:underline"
                    >
                        View All
                    </Link>
                </div>

                <div class="p-5">
                    <StatCards :items="organizationStatuses" />
                </div>
            </section>

            <!-- QUICK ACTIONS -->
            <section
                class="overflow-hidden rounded-2xl border border-[#d7e8f6] bg-white shadow-sm dark:border-white/10 dark:bg-white/5"
            >
                <div
                    class="border-b border-[#e8f1f8] px-5 py-4 dark:border-white/10"
                >
                    <h2
                        class="text-sm font-semibold text-[#0f4c8a] dark:text-sky-100"
                    >
                        Quick Actions
                    </h2>

                    <p
                        class="mt-0.5 text-xs text-ink-muted dark:text-slate-400"
                    >
                        Platform management shortcuts
                    </p>
                </div>

                <div class="space-y-2 p-4">
                    <Link
                        href="/platform/organizations"
                        class="flex items-center justify-between rounded-xl bg-[#f2f8ff] px-4 py-3 text-sm font-medium text-[#0f4c8a] transition hover:bg-[#e4f0ff]"
                    >
                        <span class="flex items-center gap-2">
                            <Building2 class="size-4" />
                            Manage Organizations
                        </span>
                        <ArrowRight class="size-4" />
                    </Link>

                    <Link
                        href="/platform/admins"
                        class="flex items-center justify-between rounded-xl bg-[#f2f8ff] px-4 py-3 text-sm font-medium text-[#0f4c8a] transition hover:bg-[#e4f0ff]"
                    >
                        <span class="flex items-center gap-2">
                            <ShieldCheck class="size-4" />
                            Barangay Admins
                        </span>
                        <ArrowRight class="size-4" />
                    </Link>

                    <Link
                        href="/platform/reports"
                        class="flex items-center justify-between rounded-xl bg-[#f2f8ff] px-4 py-3 text-sm font-medium text-[#0f4c8a] transition hover:bg-[#e4f0ff]"
                    >
                        <span class="flex items-center gap-2">
                            <Activity class="size-4" />
                            System Reports
                        </span>
                        <ArrowRight class="size-4" />
                    </Link>
                </div>
            </section>
        </div>

        <!-- ORGANIZATIONS AND RECENT ACTIVITY -->
        <div class="grid items-start gap-5 xl:grid-cols-5">
            <!-- RECENT ORGANIZATIONS -->
            <section
                class="overflow-hidden rounded-2xl border border-[#d7e8f6] bg-white shadow-sm dark:border-white/10 dark:bg-white/5 xl:col-span-3"
            >
                <div
                    class="flex items-center justify-between gap-3 border-b border-[#e8f1f8] px-5 py-4 dark:border-white/10"
                >
                    <div class="flex items-center gap-3">
                        <div
                            class="flex size-9 items-center justify-center rounded-lg bg-[#e8f1fd] text-[#1b7ad8]"
                        >
                            <Building2 class="size-5" />
                        </div>

                        <div>
                            <h2
                                class="text-sm font-semibold text-[#0f4c8a] dark:text-sky-100"
                            >
                                Organizations
                            </h2>

                            <p
                                class="mt-0.5 text-xs text-ink-muted dark:text-slate-400"
                            >
                                Registered SafeSakay barangays
                            </p>
                        </div>
                    </div>

                    <Link
                        href="/platform/organizations"
                        class="text-xs font-semibold text-[#1b7ad8] hover:underline"
                    >
                        View All
                    </Link>
                </div>

                <div
                    v-if="recentOrganizations.length === 0"
                    class="px-5 py-14 text-center"
                >
                    <Building2
                        class="mx-auto mb-3 size-9 text-[#b5cce2]"
                    />

                    <p class="text-sm text-ink-muted">
                        No organizations registered yet.
                    </p>
                </div>

                <div v-else class="overflow-x-auto">
                    <table
                        class="w-full min-w-[560px] text-left text-sm"
                    >
                        <thead
                            class="bg-[#f4f9fd] text-xs text-ink-soft dark:bg-white/5 dark:text-slate-400"
                        >
                            <tr>
                                <th class="px-5 py-3 font-semibold">
                                    Organization
                                </th>
                                <th class="px-5 py-3 font-semibold">
                                    Admin
                                </th>
                                <th class="px-5 py-3 font-semibold">
                                    Status
                                </th>
                                <th class="px-5 py-3 text-right font-semibold">
                                    Action
                                </th>
                            </tr>
                        </thead>

                        <tbody>
                            <tr
                                v-for="organization in recentOrganizations"
                                :key="organization.id"
                                class="border-t border-[#eef4f9] dark:border-white/10"
                            >
                                <td class="px-5 py-4">
                                    <p
                                        class="font-semibold text-[#0f4c8a] dark:text-sky-100"
                                    >
                                        {{ organization.name }}
                                    </p>

                                    <p
                                        class="mt-1 max-w-[220px] truncate text-xs text-ink-muted dark:text-slate-400"
                                    >
                                        {{ organization.address }}
                                    </p>
                                </td>

                                <td class="px-5 py-4">
                                    <p
                                        class="text-sm text-ink dark:text-sky-50"
                                    >
                                        {{
                                            organization.admin_name ||
                                            'Not assigned'
                                        }}
                                    </p>
                                </td>

                                <td class="px-5 py-4">
                                    <span
                                        class="inline-flex rounded-full px-2.5 py-1 text-xs font-semibold"
                                        :class="
                                            organizationStatusClass(
                                                organization.access_state,
                                            )
                                        "
                                    >
                                        {{ organization.status_label }}
                                    </span>
                                </td>

                                <td class="px-5 py-4 text-right">
                                    <Link
                                        :href="`/platform/organizations/${organization.id}`"
                                        class="inline-flex items-center gap-1 text-xs font-semibold text-[#1b7ad8] hover:underline"
                                    >
                                        View
                                        <ArrowRight class="size-3" />
                                    </Link>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <!-- RECENT PLATFORM ACTIVITY -->
            <section
                class="overflow-hidden rounded-2xl border border-[#d7e8f6] bg-white shadow-sm dark:border-white/10 dark:bg-white/5 xl:col-span-2"
            >
                <div
                    class="flex items-center justify-between gap-3 border-b border-[#e8f1f8] px-5 py-4 dark:border-white/10"
                >
                    <div class="flex items-center gap-3">
                        <div
                            class="flex size-9 items-center justify-center rounded-lg bg-[#e8f1fd] text-[#1b7ad8]"
                        >
                            <Activity class="size-5" />
                        </div>

                        <div>
                            <h2
                                class="text-sm font-semibold text-[#0f4c8a] dark:text-sky-100"
                            >
                                Recent Platform Activity
                            </h2>

                            <p
                                class="mt-0.5 text-xs text-ink-muted dark:text-slate-400"
                            >
                                Administrative actions and updates
                            </p>
                        </div>
                    </div>

                    <Link
                        href="/platform/audit-logs"
                        class="text-xs font-semibold text-[#1b7ad8] hover:underline"
                    >
                        View All
                    </Link>
                </div>

                <div
                    v-if="recentActivities.length === 0"
                    class="px-5 py-14 text-center"
                >
                    <Activity
                        class="mx-auto mb-3 size-9 text-[#b5cce2]"
                    />

                    <p class="text-sm text-ink-muted">
                        No recent platform activity.
                    </p>
                </div>

                <ul
                    v-else
                    class="divide-y divide-[#eef4f9] dark:divide-white/10"
                >
                    <li
                        v-for="event in recentActivities"
                        :key="event.id"
                        class="flex items-start gap-3 px-5 py-4"
                    >
                        <div
                            class="mt-0.5 flex size-8 shrink-0 items-center justify-center rounded-full bg-[#e8f1fd] text-[#1b7ad8]"
                        >
                            <Activity class="size-4" />
                        </div>

                        <div class="min-w-0 flex-1">
                            <p
                                class="text-sm font-semibold text-[#0f4c8a] dark:text-sky-100"
                            >
                                {{ event.action_label }}
                            </p>

                            <p
                                class="mt-0.5 text-xs text-ink-soft dark:text-slate-400"
                            >
                                {{ event.organization_name }}
                            </p>

                            <p
                                class="mt-1 text-xs text-ink-muted dark:text-slate-400"
                            >
                                {{ event.actor_name || 'Super Admin' }}
                                <span v-if="event.note">
                                    · {{ event.note }}
                                </span>
                            </p>

                            <p
                                v-if="event.created_at_label"
                                class="mt-1.5 text-[11px] text-[#9aabbc]"
                            >
                                {{ event.created_at_label }}
                            </p>
                        </div>
                    </li>
                </ul>
            </section>
        </div>

        <!-- PLATFORM MONITORING -->
        <section
            class="overflow-hidden rounded-2xl border border-[#d7e8f6] bg-white shadow-sm dark:border-white/10 dark:bg-white/5"
        >
            <div
                class="border-b border-[#e8f1f8] px-5 py-4 dark:border-white/10"
            >
                <h2
                    class="text-sm font-semibold text-[#0f4c8a] dark:text-sky-100"
                >
                    Platform Monitoring
                </h2>

                <p
                    class="mt-0.5 text-xs text-ink-muted dark:text-slate-400"
                >
                    Additional system-wide information
                </p>
            </div>

            <div class="grid gap-4 p-5 sm:grid-cols-2">
                <div
                    class="flex items-center gap-4 rounded-xl bg-[#f2f8ff] p-4"
                >
                    <div
                        class="flex size-11 shrink-0 items-center justify-center rounded-xl bg-[#3730a3] text-white"
                    >
                        <Ship class="size-5" />
                    </div>

                    <div>
                        <p class="text-xs text-ink-soft">
                            Registered Boats
                        </p>

                        <p
                            class="text-2xl font-bold text-[#0f2854]"
                        >
                            {{ displayCount(stats.boats) }}
                        </p>

                        <p class="text-xs text-ink-muted">
                            Across all organizations
                        </p>
                    </div>
                </div>

                <div
                    class="flex items-center gap-4 rounded-xl bg-[#f2f8ff] p-4"
                >
                    <div
                        class="flex size-11 shrink-0 items-center justify-center rounded-xl bg-[#7c3aed] text-white"
                    >
                        <Ticket class="size-5" />
                    </div>

                    <div>
                        <p class="text-xs text-ink-soft">
                            Rental Bookings
                        </p>

                        <p
                            class="text-2xl font-bold text-[#0f2854]"
                        >
                            {{ displayCount(stats.rentals) }}
                        </p>

                        <p class="text-xs text-ink-muted">
                            Across all organizations
                        </p>
                    </div>
                </div>
            </div>
        </section>
    </div>
</template>
