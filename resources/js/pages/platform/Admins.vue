
<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import {
    Building2,
    CheckCircle2,
    ChevronLeft,
    ChevronRight,
    Eye,
    Search,
    ShieldAlert,
    ShieldCheck,
    Users,
    X,
} from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import StatCards from '@/components/admin/StatCards.vue';
import type { StatCardItem } from '@/components/admin/StatCards.vue';

import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogTitle,
} from '@/components/ui/dialog';

type OrganizationState =
    | 'pending'
    | 'active'
    | 'suspended'
    | 'inactive'
    | 'archived'
    | null;

type PlatformAdmin = {
    id: number;
    name: string;
    email: string;
    is_active: boolean;
    deactivated_reason: string | null;
    can_deactivate: boolean;
    organization_id: number | null;
    organization_name: string | null;
    organization_status: string | null;
    organization_state: OrganizationState;
};

const props = defineProps<{
    admins: PlatformAdmin[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Platform', href: '/platform' },
            {
                title: 'Admin Management',
                href: '/platform/admins',
            },
        ],
    },
});

// SEARCH AND FILTER
const query = ref('');
const accountFilter = ref<
    'all' | 'active' | 'deactivated'
>('all');

// PAGINATION
const currentPage = ref(1);
const pageSize = 10;

// STATUS DIALOG
const statusAdmin = ref<PlatformAdmin | null>(null);
const reason = ref('');
const error = ref('');
const processing = ref(false);

// STATISTICS
const totals = computed(() => ({
    all: props.admins.length,

    active: props.admins.filter(
        (admin) => admin.is_active,
    ).length,

    deactivated: props.admins.filter(
        (admin) => !admin.is_active,
    ).length,

    organizations: new Set(
        props.admins
            .map((admin) => admin.organization_id)
            .filter((id) => id !== null),
    ).size,
}));

const cards = computed<StatCardItem[]>(() => [
    { key: 'all', title: 'Total Admins', value: totals.value.all, hint: 'Administrator accounts', icon: Users, tone: 'blue' },
    { key: 'active', title: 'Active Admins', value: totals.value.active, hint: 'Can sign in', icon: CheckCircle2, tone: 'green' },
    { key: 'deactivated', title: 'Deactivated Admins', value: totals.value.deactivated, hint: 'Access disabled', icon: ShieldAlert, tone: 'red' },
    { key: 'organizations', title: 'Barangays', value: totals.value.organizations, hint: 'With listed admins', icon: Building2, tone: 'amber' },
]);

function selectCard(key: string) {
    if (key === 'all' || key === 'active' || key === 'deactivated') {
        accountFilter.value = key;
    }
}

// FILTERED ADMINS
const filteredAdmins = computed(() => {
    const term = query.value.trim().toLowerCase();

    return props.admins.filter((admin) => {
        const searchable = [
            admin.name,
            admin.email,
            admin.organization_name,
        ]
            .filter(Boolean)
            .join(' ')
            .toLowerCase();

        const matchesText =
            !term || searchable.includes(term);

        const matchesStatus =
            accountFilter.value === 'all' ||
            (accountFilter.value === 'active'
                ? admin.is_active
                : !admin.is_active);

        return matchesText && matchesStatus;
    });
});

// PAGINATION
const totalPages = computed(() =>
    Math.max(
        1,
        Math.ceil(filteredAdmins.value.length / pageSize),
    ),
);

const visibleAdmins = computed(() =>
    filteredAdmins.value.slice(
        (currentPage.value - 1) * pageSize,
        currentPage.value * pageSize,
    ),
);

const firstRecord = computed(() =>
    filteredAdmins.value.length
        ? (currentPage.value - 1) * pageSize + 1
        : 0,
);

const lastRecord = computed(() =>
    Math.min(
        currentPage.value * pageSize,
        filteredAdmins.value.length,
    ),
);

watch([query, accountFilter], () => {
    currentPage.value = 1;
});

watch(totalPages, (pages) => {
    if (currentPage.value > pages) {
        currentPage.value = pages;
    }
});

function resetFilters() {
    query.value = '';
    accountFilter.value = 'all';
    currentPage.value = 1;
}

// ORGANIZATION STATUS COLORS
function organizationBadge(
    state: OrganizationState,
): string {
    switch (state) {
        case 'active':
            return 'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-300';

        case 'pending':
            return 'bg-amber-50 text-amber-700 dark:bg-amber-500/15 dark:text-amber-300';

        case 'suspended':
            return 'bg-rose-50 text-rose-700 dark:bg-rose-500/15 dark:text-rose-300';

        default:
            return 'bg-slate-100 text-slate-600 dark:bg-white/10 dark:text-slate-300';
    }
}

// OPEN STATUS DIALOG
function openStatus(admin: PlatformAdmin) {
    statusAdmin.value = admin;
    reason.value = '';
    error.value = '';
}

// CLOSE STATUS DIALOG
function closeStatus() {
    if (processing.value) {
return;
}

    statusAdmin.value = null;
    reason.value = '';
    error.value = '';
}

// DEACTIVATE / RESTORE
function submitStatus() {
    const admin = statusAdmin.value;

    if (!admin || processing.value) {
return;
}

    const restoring = !admin.is_active;

    if (!restoring && !admin.can_deactivate) {
        error.value =
            'The last active administrator cannot be deactivated.';

        return;
    }

    if (
        !restoring &&
        reason.value.trim().length < 3
    ) {
        error.value =
            'Please provide a reason of at least 3 characters.';

        return;
    }

    processing.value = true;
    error.value = '';

    router.patch(
        `/platform/admins/${admin.id}`,
        {
            is_active: restoring,
            reason: restoring
                ? null
                : reason.value.trim(),
        },
        {
            preserveScroll: true,

            onSuccess: () => {
                statusAdmin.value = null;
                reason.value = '';
            },

            onError: (errors) => {
                error.value =
                    errors.is_active ||
                    errors.reason ||
                    'Could not update this admin.';
            },

            onFinish: () => {
                processing.value = false;
            },
        },
    );
}
</script>

<template>
    <Head title="Admin Management" />

    <div class="flex flex-1 flex-col gap-6 p-4 sm:p-6">

        <!-- PAGE HEADER -->
        <header>
            <h1
                class="text-2xl font-bold tracking-tight text-[#0f4c8a] dark:text-sky-100"
            >
                Admin Management
            </h1>

            <p
                class="mt-1 text-sm text-ink-muted dark:text-slate-400"
            >
                Manage barangay administrator accounts
                across registered organizations.
            </p>
        </header>

        <!-- SUMMARY CARDS -->
        <section aria-label="Administrator statistics">
            <StatCards :items="cards" :active="accountFilter" selectable @select="selectCard" />
        </section>

        <!-- ADMINISTRATORS TABLE -->
        <section
            class="overflow-hidden rounded-2xl border border-[#d7e8f6] bg-white shadow-sm dark:border-white/10 dark:bg-white/5"
        >
            <!-- SECTION HEADER -->
            <div
                class="flex flex-wrap items-center justify-between gap-3 border-b border-[#e8f1f8] px-5 py-4 dark:border-white/10"
            >
                <div class="flex items-center gap-3">
                    <span
                        class="flex size-10 items-center justify-center rounded-xl bg-[#e8f1fd] text-[#1b7ad8]"
                    >
                        <ShieldCheck class="size-5" />
                    </span>

                    <div>
                        <h2
                            class="font-bold text-[#0f4c8a] dark:text-sky-100"
                        >
                            Barangay Administrators
                        </h2>

                        <p
                            class="text-xs text-ink-muted dark:text-slate-400"
                        >
                            Account access and organization assignments
                        </p>
                    </div>
                </div>

                <span
                    class="rounded-full bg-[#edf5ff] px-3 py-1 text-xs font-semibold text-[#1769bf]"
                >
                    {{ filteredAdmins.length }} results
                </span>
            </div>

            <!-- SEARCH AND FILTER -->
            <div
                class="flex flex-col gap-3 border-b border-[#e8f1f8] p-4 sm:flex-row sm:items-center dark:border-white/10"
            >
                <div class="relative flex-1">
                    <Search
                        class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-ink-muted"
                    />

                    <input
                        v-model="query"
                        type="search"
                        aria-label="Search administrators"
                        placeholder="Search admin name, email, or organization..."
                        class="h-11 w-full rounded-xl border border-[#d5e5f4] bg-[#f8fbff] pr-3 pl-10 text-sm text-ink outline-none focus:border-[#1976d2] dark:border-white/10 dark:bg-white/5 dark:text-sky-50"
                    />
                </div>

                <div class="flex items-center gap-2">
                    <select
                        v-model="accountFilter"
                        aria-label="Filter account status"
                        class="h-11 min-w-40 flex-1 rounded-xl border border-[#d5e5f4] bg-[#f8fbff] px-3 text-sm text-ink dark:border-white/10 dark:bg-slate-900 dark:text-sky-50 sm:flex-none"
                    >
                        <option value="all">
                            All Accounts
                        </option>
                        <option value="active">
                            Active
                        </option>
                        <option value="deactivated">
                            Deactivated
                        </option>
                    </select>

                    <button
                        v-if="
                            query ||
                            accountFilter !== 'all'
                        "
                        type="button"
                        class="inline-flex h-11 items-center gap-1 rounded-xl px-3 text-sm font-semibold text-[#1976d2] hover:bg-[#edf5ff]"
                        @click="resetFilters"
                    >
                        <X class="size-4" />
                        Clear
                    </button>
                </div>
            </div>

            <!-- EMPTY STATE -->
            <div
                v-if="filteredAdmins.length === 0"
                class="flex flex-col items-center px-5 py-16 text-center"
            >
                <Users
                    class="mb-3 size-10 text-[#a1bad2]"
                />

                <h3
                    class="font-semibold text-[#0f4c8a] dark:text-sky-100"
                >
                    No administrators found
                </h3>

                <p
                    class="mt-1 text-sm text-ink-muted dark:text-slate-400"
                >
                    {{
                        props.admins.length
                            ? 'Try changing your search or filter.'
                            : 'There are no barangay administrator accounts yet.'
                    }}
                </p>
            </div>

            <!-- TABLE -->
            <div
                v-else
                class="overflow-x-auto"
            >
                <table
                    class="w-full min-w-[850px] text-left text-sm"
                >
                    <thead
                        class="bg-[#f4f9fd] text-xs tracking-wide text-ink-soft uppercase dark:bg-white/5 dark:text-slate-400"
                    >
                        <tr>
                            <th class="px-5 py-3.5">
                                Administrator
                            </th>

                            <th class="px-5 py-3.5">
                                Organization
                            </th>

                            <th class="px-5 py-3.5">
                                Org Status
                            </th>

                            <th class="px-5 py-3.5">
                                Account Status
                            </th>

                            <th
                                class="px-5 py-3.5 text-right"
                            >
                                Actions
                            </th>
                        </tr>
                    </thead>

                    <tbody>
                        <tr
                            v-for="admin in visibleAdmins"
                            :key="admin.id"
                            class="border-t border-[#eef4f9] hover:bg-[#f9fcff] dark:border-white/10 dark:hover:bg-white/5"
                        >
                            <!-- ADMIN -->
                            <td class="px-5 py-4">
                                <div
                                    class="flex items-center gap-3"
                                >
                                    <span
                                        class="flex size-10 shrink-0 items-center justify-center rounded-xl bg-[#edf5ff] text-sm font-bold text-[#1976d2]"
                                    >
                                        {{
                                            admin.name
                                                .trim()
                                                .charAt(0)
                                                .toUpperCase() || '?'
                                        }}
                                    </span>

                                    <div class="min-w-0">
                                        <p
                                            class="font-semibold text-[#0f4c8a] dark:text-sky-100"
                                        >
                                            {{ admin.name }}
                                        </p>

                                        <p
                                            class="break-all text-xs text-ink-muted dark:text-slate-400"
                                        >
                                            {{ admin.email }}
                                        </p>
                                    </div>
                                </div>
                            </td>

                            <!-- ORGANIZATION -->
                            <td
                                class="px-5 py-4 font-medium text-ink dark:text-sky-50"
                            >
                                {{
                                    admin.organization_name ||
                                    'Not assigned'
                                }}
                            </td>

                            <!-- ORGANIZATION STATUS -->
                            <td class="px-5 py-4">
                                <span
                                    v-if="admin.organization_state"
                                    class="inline-flex rounded-full px-2.5 py-1 text-xs font-semibold"
                                    :class="
                                        organizationBadge(
                                            admin.organization_state
                                        )
                                    "
                                >
                                    {{
                                        admin.organization_status ||
                                        admin.organization_state
                                    }}
                                </span>

                                <span
                                    v-else
                                    class="text-ink-muted"
                                >
                                    —
                                </span>
                            </td>

                            <!-- ACCOUNT STATUS -->
                            <td class="px-5 py-4">
                                <span
                                    class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-semibold"
                                    :class="
                                        admin.is_active
                                            ? 'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-300'
                                            : 'bg-rose-50 text-rose-700 dark:bg-rose-500/15 dark:text-rose-300'
                                    "
                                >
                                    <span
                                        class="size-1.5 rounded-full"
                                        :class="
                                            admin.is_active
                                                ? 'bg-emerald-500'
                                                : 'bg-rose-500'
                                        "
                                    />

                                    {{
                                        admin.is_active
                                            ? 'Active'
                                            : 'Deactivated'
                                    }}
                                </span>

                                <p
                                    v-if="admin.deactivated_reason"
                                    class="mt-1 max-w-52 break-words text-xs text-ink-muted dark:text-slate-400"
                                >
                                    {{
                                        admin.deactivated_reason
                                    }}
                                </p>
                            </td>

                            <!-- ACTIONS -->
                            <td class="px-5 py-4">
                                <div
                                    class="flex items-center justify-end gap-2"
                                >
                                    <Link
                                        v-if="
                                            admin.organization_id !== null
                                        "
                                        :href="`/platform/organizations/${admin.organization_id}`"
                                        class="inline-flex h-9 items-center gap-1 rounded-lg border border-[#c5def5] px-3 text-xs font-semibold text-[#1b7ad8] hover:bg-[#edf5ff] dark:border-white/10"
                                    >
                                        <Eye class="size-3.5" />
                                        View Org
                                    </Link>

                                    <button
                                        v-if="!admin.is_active"
                                        type="button"
                                        class="h-9 rounded-lg bg-emerald-50 px-3 text-xs font-semibold text-emerald-700 hover:bg-emerald-100 dark:bg-emerald-500/15 dark:text-emerald-300"
                                        @click="openStatus(admin)"
                                    >
                                        Restore
                                    </button>

                                    <button
                                        v-else-if="
                                            admin.can_deactivate
                                        "
                                        type="button"
                                        class="h-9 rounded-lg bg-rose-50 px-3 text-xs font-semibold text-rose-700 hover:bg-rose-100 dark:bg-rose-500/15 dark:text-rose-300"
                                        @click="openStatus(admin)"
                                    >
                                        Deactivate
                                    </button>

                                    <span
                                        v-else
                                        class="rounded-lg bg-slate-100 px-2.5 py-2 text-xs font-medium text-slate-600 dark:bg-white/10 dark:text-slate-300"
                                        title="Last active administrator is protected"
                                    >
                                        Last Admin
                                    </span>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- PAGINATION -->
            <div
                v-if="filteredAdmins.length"
                class="flex flex-col gap-3 border-t border-[#edf2f7] px-5 py-4 text-xs text-ink-muted dark:border-white/10 dark:text-slate-400 sm:flex-row sm:items-center sm:justify-between"
            >
                <span>
                    Showing
                    {{ firstRecord }}–{{ lastRecord }}
                    of {{ filteredAdmins.length }}
                    administrators
                </span>

                <div
                    v-if="totalPages > 1"
                    class="flex items-center gap-2"
                >
                    <button
                        type="button"
                        :disabled="currentPage === 1"
                        aria-label="Previous page"
                        class="flex size-9 items-center justify-center rounded-lg border border-[#d5e5f4] disabled:opacity-40"
                        @click="currentPage--"
                    >
                        <ChevronLeft class="size-4" />
                    </button>

                    <span class="px-2 font-semibold">
                        Page {{ currentPage }}
                        of {{ totalPages }}
                    </span>

                    <button
                        type="button"
                        :disabled="
                            currentPage === totalPages
                        "
                        aria-label="Next page"
                        class="flex size-9 items-center justify-center rounded-lg border border-[#d5e5f4] disabled:opacity-40"
                        @click="currentPage++"
                    >
                        <ChevronRight class="size-4" />
                    </button>
                </div>
            </div>
        </section>
    </div>

    <!-- DEACTIVATE / RESTORE DIALOG -->
    <Dialog
        :open="Boolean(statusAdmin)"
        @update:open="
            (open) => {
                if (!open) closeStatus();
            }
        "
    >
        <DialogContent
            :show-close-button="false"
            class="w-full max-w-[calc(100%-1.5rem)] gap-0 overflow-hidden rounded-2xl border-none p-0 shadow-2xl sm:max-w-md"
        >
            <!-- DIALOG HEADER -->
            <div
                class="bg-[#1b7ad8] px-5 py-4 text-white"
            >
                <DialogTitle
                    class="text-lg font-semibold text-white"
                >
                    {{
                        statusAdmin?.is_active
                            ? 'Deactivate Administrator'
                            : 'Restore Administrator'
                    }}
                </DialogTitle>

                <DialogDescription
                    class="mt-1 text-sm text-white/85"
                >
                    {{
                        statusAdmin?.is_active
                            ? 'This account will no longer be able to sign in. The organization remains accessible to other active admins.'
                            : 'This administrator account will be enabled again.'
                    }}
                </DialogDescription>
            </div>

            <!-- DIALOG BODY -->
            <div
                class="space-y-4 bg-white p-5 dark:bg-background"
            >
                <p
                    class="text-sm text-ink dark:text-slate-200"
                >
                    {{
                        statusAdmin?.is_active
                            ? 'Deactivate'
                            : 'Restore'
                    }}

                    <strong
                        class="text-[#0f4c8a] dark:text-sky-100"
                    >
                        {{ statusAdmin?.name }}
                    </strong>?
                </p>

                <!-- REASON -->
                <div
                    v-if="statusAdmin?.is_active"
                    class="space-y-1.5"
                >
                    <label
                        for="admin-deactivation-reason"
                        class="text-xs font-semibold text-[#0f4c8a] dark:text-sky-100"
                    >
                        Reason for deactivation
                        <span class="text-rose-600">*</span>
                    </label>

                    <textarea
                        id="admin-deactivation-reason"
                        v-model="reason"
                        rows="3"
                        maxlength="180"
                        class="w-full rounded-xl border border-[#c5def5] bg-[#f7fbfe] px-3 py-2 text-sm text-ink outline-none focus:border-[#1b7ad8] dark:border-white/10 dark:bg-white/5 dark:text-sky-50"
                        placeholder="e.g. Unused account or temporary leave"
                    />
                </div>

                <!-- ERROR -->
                <p
                    v-if="error"
                    role="alert"
                    class="text-xs text-rose-600"
                >
                    {{ error }}
                </p>

                <!-- DIALOG ACTIONS -->
                <div class="flex justify-end gap-3">
                    <Button
                        type="button"
                        variant="outline"
                        class="h-10 rounded-xl px-5"
                        :disabled="processing"
                        @click="closeStatus"
                    >
                        Cancel
                    </Button>

                    <Button
                        type="button"
                        class="h-10 rounded-xl px-5 text-white"
                        :class="
                            statusAdmin?.is_active
                                ? 'bg-rose-600 hover:bg-rose-700'
                                : 'bg-emerald-600 hover:bg-emerald-700'
                        "
                        :disabled="processing"
                        @click="submitStatus"
                    >
                        {{
                            processing
                                ? 'Processing...'
                                : statusAdmin?.is_active
                                  ? 'Deactivate'
                                  : 'Restore'
                        }}
                    </Button>
                </div>
            </div>
        </DialogContent>
    </Dialog>
</template>
