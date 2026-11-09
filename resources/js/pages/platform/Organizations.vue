
<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import {
    ArrowRight,
    Building2,
    CheckCircle2,
    ChevronLeft,
    ChevronRight,
    Clock3,
    ClipboardList,
    Eye,
    Plus,
    PowerOff,
    RotateCcw,
    Search,
    ShieldCheck,
    SlidersHorizontal,
    Users,
    X,
} from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import StatCards from '@/components/admin/StatCards.vue';
import type { StatCardItem } from '@/components/admin/StatCards.vue';
import DeactivateOrganizationDialog from '@/components/DeactivateOrganizationDialog.vue';

type OrganizationStatus =
    | 'pending'
    | 'active'
    | 'suspended'
    | 'inactive'
    | 'archived';

type PlatformOrganization = {
    id: number;
    name: string;
    address: string;
    contact_number: string | null;
    is_active: boolean;
    access_state: OrganizationStatus;
    status_label: string;
    admin_name: string | null;
    admin_email: string | null;
    boats_count: number;
    users_count: number;
    restore_requested?: boolean;
    closure_requested?: boolean;
    last_change_action?: string | null;
    last_change_label?: string | null;
    last_change_at_label?: string | null;
    last_change_note?: string | null;
};

type StatusFilter = 'all' | OrganizationStatus;
type ConfirmAction = 'approve' | 'restore';

const props = defineProps<{
    organizations: PlatformOrganization[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Platform', href: '/platform' },
            {
                title: 'Organizations',
                href: '/platform/organizations',
            },
        ],
    },
});

// SEARCH AND FILTER
const query = ref('');
const status = ref<StatusFilter>('all');

// PAGINATION
const currentPage = ref(1);
const perPage = 10;

// DIALOGS
const deactivateTarget = ref<PlatformOrganization | null>(null);
const confirmationTarget = ref<PlatformOrganization | null>(null);
const confirmationAction = ref<ConfirmAction | null>(null);
const processing = ref(false);

// ORGANIZATION STATISTICS
const stats = computed(() => ({
    total: props.organizations.length,

    active: props.organizations.filter(
        (item) => item.access_state === 'active',
    ).length,

    pending: props.organizations.filter(
        (item) => item.access_state === 'pending',
    ).length,

    deactivated: props.organizations.filter((item) =>
        isDeactivated(item.access_state),
    ).length,
}));

function isDeactivated(state: OrganizationStatus): boolean {
    return state === 'inactive' || state === 'suspended';
}

const summaryCards = computed<StatCardItem[]>(() => [
    { key: 'all', title: 'Total Organizations', value: stats.value.total, hint: 'Registered barangays', icon: Building2, tone: 'blue' },
    { key: 'active', title: 'Active', value: stats.value.active, hint: 'Currently operational', icon: CheckCircle2, tone: 'green' },
    { key: 'pending', title: 'Pending Approval', value: stats.value.pending, hint: 'Awaiting review', icon: Clock3, tone: 'amber' },
    { key: 'inactive', title: 'Deactivated', value: stats.value.deactivated, hint: 'Access turned off', icon: PowerOff, tone: 'red' },
]);

// FILTERED ORGANIZATIONS
const filtered = computed(() => {
    const searchTerm = query.value.trim().toLowerCase();

    return props.organizations.filter((organization) => {
        const searchable = [
            organization.name,
            organization.address,
            organization.admin_name,
            organization.admin_email,
            organization.contact_number,
        ]
            .filter(Boolean)
            .join(' ')
            .toLowerCase();

        const matchesQuery =
            searchTerm === '' ||
            searchable.includes(searchTerm);

        const matchesStatus =
            status.value === 'all' ||
            organization.access_state === status.value ||
            (status.value === 'inactive' &&
                isDeactivated(organization.access_state));

        return matchesQuery && matchesStatus;
    });
});

// PAGINATION COMPUTED
const totalPages = computed(() =>
    Math.max(
        1,
        Math.ceil(filtered.value.length / perPage),
    ),
);

const paginatedOrganizations = computed(() => {
    const start = (currentPage.value - 1) * perPage;

    return filtered.value.slice(
        start,
        start + perPage,
    );
});

const startRecord = computed(() =>
    filtered.value.length === 0
        ? 0
        : (currentPage.value - 1) * perPage + 1,
);

const endRecord = computed(() =>
    Math.min(
        currentPage.value * perPage,
        filtered.value.length,
    ),
);

const hasFilters = computed(
    () =>
        query.value.trim() !== '' ||
        status.value !== 'all',
);

watch([query, status], () => {
    currentPage.value = 1;
});

watch(totalPages, (pages) => {
    if (currentPage.value > pages) {
        currentPage.value = pages;
    }
});

function resetFilters() {
    query.value = '';
    status.value = 'all';
    currentPage.value = 1;
}

// STATUS STYLING
function statusClass(state: OrganizationStatus): string {
    switch (state) {
        case 'active':
            return 'bg-emerald-50 text-emerald-700 ring-emerald-100';

        case 'pending':
            return 'bg-amber-50 text-amber-700 ring-amber-100';

        case 'inactive':
        case 'suspended':
            return 'bg-rose-50 text-rose-700 ring-rose-100';

        case 'archived':
            return 'bg-slate-100 text-slate-600 ring-slate-200';

        default:
            return 'bg-slate-100 text-slate-600 ring-slate-200';
    }
}

function statusDot(state: OrganizationStatus): string {
    switch (state) {
        case 'active':
            return 'bg-emerald-500';

        case 'pending':
            return 'bg-amber-500';

        case 'inactive':
        case 'suspended':
            return 'bg-rose-500';

        default:
            return 'bg-slate-400';
    }
}

// ACTION LABELS
function actionLabel(
    organization: PlatformOrganization,
): string {
    switch (organization.access_state) {
        case 'pending':
            return 'Approve';

        case 'active':
            return 'Deactivate';

        case 'archived':
            return 'Review';

        default:
            return organization.restore_requested
                ? 'Restore Request'
                : 'Restore';
    }
}

function actionClass(
    organization: PlatformOrganization,
): string {
    switch (organization.access_state) {
        case 'pending':
            return 'bg-emerald-50 text-emerald-700 hover:bg-emerald-100';

        case 'active':
            return 'bg-rose-50 text-rose-700 hover:bg-rose-100';

        case 'archived':
            return 'bg-slate-100 text-slate-700 hover:bg-slate-200';

        default:
            return 'bg-blue-50 text-blue-700 hover:bg-blue-100';
    }
}

// OPEN ORGANIZATION ACTION
function openAction(
    organization: PlatformOrganization,
) {
    if (processing.value) {
return;
}

    if (organization.access_state === 'archived') {
        router.visit(
            `/platform/organizations/${organization.id}`,
        );

        return;
    }

    if (organization.access_state === 'active') {
        deactivateTarget.value = organization;

        return;
    }

    if (organization.restore_requested) {
        router.visit('/platform/organization-requests');

        return;
    }

    confirmationTarget.value = organization;

    // CORRECTED: Vue ref requires .value
    confirmationAction.value =
        organization.access_state === 'pending'
            ? 'approve'
            : 'restore';
}

// CLOSE CONFIRMATION
function closeConfirmation() {
    if (processing.value) {
return;
}

    confirmationTarget.value = null;
    confirmationAction.value = null;
}

// CONFIRM APPROVE / RESTORE
function confirmAction() {
    if (
        !confirmationTarget.value ||
        !confirmationAction.value ||
        processing.value
    ) {
        return;
    }

    const id = confirmationTarget.value.id;
    const action = confirmationAction.value;

    processing.value = true;

    router.patch(
        `/platform/organizations/${id}/${action}`,
        {},
        {
            preserveScroll: true,

            onSuccess: () => {
                confirmationTarget.value = null;
                confirmationAction.value = null;
            },

            onFinish: () => {
                processing.value = false;
            },
        },
    );
}
</script>

<template>
    <Head title="Organization Management" />

    <div class="flex flex-1 flex-col gap-6 p-4 sm:p-6">

        <!-- PAGE HEADER -->
        <header
            class="flex flex-wrap items-center justify-between gap-4"
        >
            <div>
                <h1
                    class="text-2xl font-bold tracking-tight text-[#0f4c8a] dark:text-sky-100"
                >
                    Organization Management
                </h1>

                <p
                    class="mt-1 text-sm text-ink-muted dark:text-slate-400"
                >
                    Manage registered barangays and their
                    SafeSakay platform access.
                </p>
            </div>

            <Link
                href="/platform/organizations/create"
                class="inline-flex h-10 items-center justify-center gap-2 rounded-xl bg-[#1976d2] px-4 text-sm font-semibold text-white shadow-sm transition hover:bg-[#1261b2]"
            >
                <Plus class="size-4" />
                Add Organization
            </Link>
        </header>

        <!-- SUMMARY CARDS -->
        <section aria-label="Organization statistics">
            <StatCards
                :items="summaryCards"
                :active="status"
                selectable
                @select="(key) => (status = key as StatusFilter)"
            />
        </section>

        <!-- ORGANIZATIONS TABLE -->
        <section
            class="overflow-hidden rounded-2xl border border-[#d7e8f6] bg-white shadow-sm dark:border-white/10 dark:bg-white/5"
        >
            <!-- TABLE HEADER -->
            <div
                class="flex flex-wrap items-center justify-between gap-3 border-b border-[#e8f1f8] px-5 py-4 dark:border-white/10"
            >
                <div class="flex items-center gap-3">
                    <div
                        class="flex size-10 items-center justify-center rounded-xl bg-[#e8f1fd] text-[#1b7ad8]"
                    >
                        <Building2 class="size-5" />
                    </div>

                    <div>
                        <h2
                            class="text-sm font-bold text-[#0f4c8a] dark:text-sky-100"
                        >
                            Registered Organizations
                        </h2>

                        <p
                            class="mt-0.5 text-xs text-ink-muted dark:text-slate-400"
                        >
                            View and manage organization accounts
                        </p>
                    </div>
                </div>

                <span
                    class="rounded-full bg-[#edf5ff] px-3 py-1.5 text-xs font-semibold text-[#1769bf]"
                >
                    {{ filtered.length }}
                    {{
                        filtered.length === 1
                            ? 'result'
                            : 'results'
                    }}
                </span>
            </div>

            <!-- SEARCH AND FILTER -->
            <div
                class="flex flex-col gap-3 border-b border-[#edf2f7] px-5 py-4 dark:border-white/10 lg:flex-row lg:items-center"
            >
                <div class="relative min-w-0 flex-1">
                    <Search
                        class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-ink-muted"
                    />

                    <input
                        v-model="query"
                        type="search"
                        aria-label="Search organizations"
                        placeholder="Search barangay, address, or admin..."
                        class="h-10 w-full rounded-xl border border-[#d5e5f4] bg-[#f8fbff] pr-3 pl-10 text-sm text-ink outline-none transition placeholder:text-[#9aabbc] focus:border-[#1976d2] focus:ring-2 focus:ring-[#1976d2]/10 dark:border-white/10 dark:bg-white/5 dark:text-sky-50"
                    />
                </div>

                <div class="flex items-center gap-2">
                    <SlidersHorizontal
                        class="hidden size-4 text-ink-muted sm:block"
                    />

                    <select
                        v-model="status"
                        aria-label="Filter organization status"
                        class="h-10 min-w-0 flex-1 rounded-xl border border-[#d5e5f4] bg-[#f8fbff] px-3 text-sm font-medium text-ink outline-none focus:border-[#1976d2] dark:border-white/10 dark:bg-slate-900 dark:text-sky-50 lg:min-w-[170px]"
                    >
                        <option value="all">
                            All Statuses
                        </option>
                        <option value="active">
                            Active
                        </option>
                        <option value="pending">
                            Pending
                        </option>
                        <option value="inactive">
                            Deactivated
                        </option>
                        <option value="archived">
                            Archived
                        </option>
                    </select>

                    <button
                        v-if="hasFilters"
                        type="button"
                        class="inline-flex h-10 shrink-0 items-center gap-1 rounded-xl px-3 text-xs font-semibold text-[#1976d2] hover:bg-[#edf5ff]"
                        @click="resetFilters"
                    >
                        <X class="size-4" />
                        Clear
                    </button>
                </div>
            </div>

            <!-- EMPTY STATE -->
            <div
                v-if="filtered.length === 0"
                class="flex flex-col items-center justify-center px-5 py-16 text-center"
            >
                <div
                    class="mb-4 flex size-14 items-center justify-center rounded-2xl bg-[#edf5ff]"
                >
                    <Building2
                        class="size-7 text-[#80b3e5]"
                    />
                </div>

                <h3
                    class="text-sm font-semibold text-[#0f4c8a] dark:text-sky-100"
                >
                    No organizations found
                </h3>

                <p
                    class="mt-1 max-w-sm text-sm text-ink-muted dark:text-slate-400"
                >
                    {{
                        hasFilters
                            ? 'Try changing your search or status filter.'
                            : 'No organizations have been registered yet.'
                    }}
                </p>

                <button
                    v-if="hasFilters"
                    type="button"
                    class="mt-4 text-sm font-semibold text-[#1976d2] hover:underline"
                    @click="resetFilters"
                >
                    Clear filters
                </button>
            </div>

            <!-- ORGANIZATIONS TABLE -->
            <div v-else class="overflow-x-auto">
                <table
                    class="w-full min-w-[900px] text-left text-sm"
                >
                    <thead
                        class="bg-[#f5f9fd] text-xs font-semibold tracking-wide text-ink-soft uppercase dark:bg-white/5 dark:text-slate-400"
                    >
                        <tr>
                            <th class="px-5 py-3.5">
                                Organization
                            </th>
                            <th class="px-5 py-3.5">
                                Barangay Admin
                            </th>
                            <th class="px-5 py-3.5 text-center">
                                Users
                            </th>
                            <th class="px-5 py-3.5 text-center">
                                Boats
                            </th>
                            <th class="px-5 py-3.5">
                                Status
                            </th>
                            <th class="px-5 py-3.5 text-right">
                                Actions
                            </th>
                        </tr>
                    </thead>

                    <tbody>
                        <tr
                            v-for="organization in paginatedOrganizations"
                            :key="organization.id"
                            class="border-t border-[#edf2f7] transition hover:bg-[#f9fcff] dark:border-white/10 dark:hover:bg-white/5"
                        >
                            <!-- ORGANIZATION -->
                            <td class="px-5 py-4">
                                <div
                                    class="flex items-start gap-3"
                                >
                                    <div
                                        class="flex size-10 shrink-0 items-center justify-center rounded-xl bg-[#edf5ff] text-[#1976d2]"
                                    >
                                        <Building2
                                            class="size-5"
                                        />
                                    </div>

                                    <div class="min-w-0">
                                        <p
                                            class="font-semibold text-[#0f4c8a] dark:text-sky-100"
                                        >
                                            {{ organization.name }}
                                        </p>

                                        <p
                                            class="mt-1 max-w-[230px] break-words text-xs text-ink-muted dark:text-slate-400"
                                        >
                                            {{
                                                organization.address ||
                                                'No address recorded'
                                            }}
                                        </p>
                                    </div>
                                </div>
                            </td>

                            <!-- BARANGAY ADMIN -->
                            <td class="px-5 py-4">
                                <div
                                    v-if="organization.admin_name"
                                    class="flex items-start gap-2"
                                >
                                    <ShieldCheck
                                        class="mt-0.5 size-4 shrink-0 text-[#5b91c8]"
                                    />

                                    <div class="min-w-0">
                                        <p
                                            class="font-medium text-ink dark:text-sky-50"
                                        >
                                            {{
                                                organization.admin_name
                                            }}
                                        </p>

                                        <p
                                            class="mt-1 max-w-[190px] break-all text-xs text-ink-muted dark:text-slate-400"
                                        >
                                            {{
                                                organization.admin_email ||
                                                '—'
                                            }}
                                        </p>
                                    </div>
                                </div>

                                <span
                                    v-else
                                    class="text-xs text-[#9aabbc]"
                                >
                                    Not assigned
                                </span>
                            </td>

                            <!-- USERS -->
                            <td
                                class="px-5 py-4 text-center"
                            >
                                <span
                                    class="inline-flex items-center gap-1.5 font-semibold text-ink dark:text-sky-50"
                                >
                                    <Users
                                        class="size-3.5 text-ink-muted"
                                    />
                                    {{ organization.users_count }}
                                </span>
                            </td>

                            <!-- BOATS -->
                            <td
                                class="px-5 py-4 text-center font-semibold text-ink dark:text-sky-50"
                            >
                                {{ organization.boats_count }}
                            </td>

                            <!-- STATUS -->
                            <td class="px-5 py-4">
                                <span
                                    class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-semibold ring-1 ring-inset"
                                    :class="
                                        statusClass(
                                            organization.access_state
                                        )
                                    "
                                >
                                    <span
                                        class="size-1.5 rounded-full"
                                        :class="
                                            statusDot(
                                                organization.access_state
                                            )
                                        "
                                    />

                                    {{
                                        organization.status_label
                                    }}
                                </span>

                                <p
                                    v-if="organization.last_change_label"
                                    class="mt-1.5 max-w-[160px] text-xs text-ink-muted dark:text-slate-400"
                                >
                                    {{
                                        organization.last_change_label
                                    }}

                                    <span
                                        v-if="organization.last_change_at_label"
                                    >
                                        ·
                                        {{
                                            organization.last_change_at_label
                                        }}
                                    </span>
                                </p>
                            </td>

                            <!-- ACTIONS -->
                            <td class="px-5 py-4">
                                <div
                                    class="flex items-center justify-end gap-2"
                                >
                                    <Link
                                        :href="`/platform/organizations/${organization.id}`"
                                        class="inline-flex h-9 items-center gap-1.5 rounded-lg border border-[#cbdff3] px-3 text-xs font-semibold text-[#1976d2] transition hover:bg-[#edf5ff] dark:border-white/10"
                                    >
                                        <Eye class="size-3.5" />
                                        View
                                    </Link>

                                    <button
                                        type="button"
                                        :disabled="processing"
                                        class="inline-flex h-9 items-center gap-1.5 rounded-lg px-3 text-xs font-semibold transition disabled:cursor-not-allowed disabled:opacity-50"
                                        :class="
                                            actionClass(organization)
                                        "
                                        @click="
                                            openAction(organization)
                                        "
                                    >
                                        <PowerOff
                                            v-if="organization.access_state === 'active'"
                                            class="size-3.5"
                                        />

                                        <CheckCircle2
                                            v-else-if="organization.access_state === 'pending'"
                                            class="size-3.5"
                                        />

                                        <ClipboardList
                                            v-else-if="organization.restore_requested && organization.access_state !== 'archived'"
                                            class="size-3.5"
                                        />

                                        <RotateCcw
                                            v-else-if="organization.access_state !== 'archived'"
                                            class="size-3.5"
                                        />

                                        <ArrowRight
                                            v-else
                                            class="size-3.5"
                                        />

                                        {{
                                            actionLabel(organization)
                                        }}
                                    </button>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- PAGINATION -->
            <div
                v-if="filtered.length > 0"
                class="flex flex-col gap-3 border-t border-[#edf2f7] px-5 py-4 sm:flex-row sm:items-center sm:justify-between dark:border-white/10"
            >
                <p
                    class="text-xs text-ink-muted dark:text-slate-400"
                >
                    Showing

                    <span class="font-semibold">
                        {{ startRecord }}–{{ endRecord }}
                    </span>

                    of

                    <span class="font-semibold">
                        {{ filtered.length }}
                    </span>

                    organizations
                </p>

                <div
                    v-if="totalPages > 1"
                    class="flex items-center gap-2"
                >
                    <button
                        type="button"
                        :disabled="currentPage === 1"
                        class="inline-flex size-9 items-center justify-center rounded-lg border border-[#d5e5f4] text-[#1976d2] transition hover:bg-[#edf5ff] disabled:cursor-not-allowed disabled:opacity-40"
                        aria-label="Previous page"
                        @click="currentPage--"
                    >
                        <ChevronLeft class="size-4" />
                    </button>

                    <span
                        class="px-2 text-xs font-semibold text-ink-soft dark:text-slate-400"
                    >
                        Page {{ currentPage }} of {{ totalPages }}
                    </span>

                    <button
                        type="button"
                        :disabled="currentPage === totalPages"
                        class="inline-flex size-9 items-center justify-center rounded-lg border border-[#d5e5f4] text-[#1976d2] transition hover:bg-[#edf5ff] disabled:cursor-not-allowed disabled:opacity-40"
                        aria-label="Next page"
                        @click="currentPage++"
                    >
                        <ChevronRight class="size-4" />
                    </button>
                </div>
            </div>
        </section>

        <DeactivateOrganizationDialog
            :open="Boolean(deactivateTarget)"
            :organization="deactivateTarget"
            @update:open="
                (open) => {
                    if (!open) deactivateTarget = null;
                }
            "
        />

        <!-- APPROVE / RESTORE CONFIRMATION -->
        <div
            v-if="confirmationTarget && confirmationAction"
            class="fixed inset-0 z-[100] flex items-center justify-center bg-slate-950/50 p-4"
            @click.self="closeConfirmation"
        >
            <section
                role="alertdialog"
                aria-modal="true"
                aria-labelledby="organization-confirm-title"
                aria-describedby="organization-confirm-description"
                class="w-full max-w-md rounded-2xl bg-white p-6 shadow-2xl dark:bg-slate-900"
            >
                <div
                    class="mb-4 flex size-12 items-center justify-center rounded-xl bg-[#e8f3ff] text-[#1976d2]"
                >
                    <CheckCircle2
                        v-if="confirmationAction === 'approve'"
                        class="size-6"
                    />

                    <RotateCcw
                        v-else
                        class="size-6"
                    />
                </div>

                <h2
                    id="organization-confirm-title"
                    class="text-lg font-bold text-[#0f4c8a] dark:text-sky-100"
                >
                    {{
                        confirmationAction === 'approve'
                            ? 'Approve Organization?'
                            : 'Restore Organization?'
                    }}
                </h2>

                <p
                    id="organization-confirm-description"
                    class="mt-2 text-sm leading-6 text-ink-soft dark:text-slate-400"
                >
                    You are about to

                    {{
                        confirmationAction === 'approve'
                            ? 'approve'
                            : 'restore'
                    }}

                    <strong
                        class="text-[#0f4c8a] dark:text-sky-100"
                    >
                        {{ confirmationTarget.name }}
                    </strong>.

                    This will update the organization's access status.
                </p>

                <div
                    class="mt-6 flex justify-end gap-2"
                >
                    <button
                        type="button"
                        :disabled="processing"
                        class="h-10 rounded-xl border border-[#d5e5f4] px-4 text-sm font-semibold text-ink-soft disabled:opacity-50"
                        @click="closeConfirmation"
                    >
                        Cancel
                    </button>

                    <button
                        type="button"
                        :disabled="processing"
                        class="h-10 rounded-xl bg-[#1976d2] px-4 text-sm font-semibold text-white transition hover:bg-[#1261b2] disabled:opacity-50"
                        @click="confirmAction"
                    >
                        {{
                            processing
                                ? 'Processing...'
                                : confirmationAction === 'approve'
                                  ? 'Confirm Approval'
                                  : 'Confirm Restore'
                        }}
                    </button>
                </div>
            </section>
        </div>
    </div>
</template>
