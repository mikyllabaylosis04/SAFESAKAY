
<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import {
    CalendarDays,
    Eye,
    Info,
    Pause,
    Pencil,
    Plus,
    Search,
    Shield,
    Smartphone,
    UserRound,
    Users,
} from '@lucide/vue';
import { computed, onMounted, ref, watch } from 'vue';

import AddUserModal from '@/components/AddUserModal.vue';
import StatCards from '@/components/admin/StatCards.vue';
import type { StatCardItem } from '@/components/admin/StatCards.vue';
import { Button } from '@/components/ui/button';

import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogTitle,
} from '@/components/ui/dialog';
import ViewUserModal from '@/components/ViewUserModal.vue';

import type {
    ManagedUser,
    OperatorBoatOption,
    UserOrganizationOption,
    UserRole,
    UserRoleOption,
} from '@/types/user';

type StatusFilter = 'all' | UserRole | 'inactive';

const props = defineProps<{
    users: ManagedUser[];
    roleOptions: UserRoleOption[];
    organization?: UserOrganizationOption | null;
    boats?: OperatorBoatOption[];
    stats: {
        total: number;
        administrators: number;
        operators: number;
        customers: number;
        inactive: number;
    };
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'User Management',
                href: '/users',
            },
        ],
    },
});

const addOpen = ref(false);
const viewOpen = ref(false);
const selectedUser = ref<ManagedUser | null>(null);
const editingUser = ref<ManagedUser | null>(null);
const defaultRole = ref<UserRole>('user');
const highlightedUserId = ref<number | null>(null);

const filter = ref<StatusFilter>('all');
const listQuery = ref('');
const operatorStatus = ref<'all' | 'active' | 'suspended' | 'inactive'>('all');
const operatorViolations = ref<'all' | '0' | '1' | '2' | '3'>('all');

const statusUser = ref<ManagedUser | null>(null);
const updatingStatus = ref(false);
const deactivationReason = ref('');
const deactivationError = ref('');

// Summary cards
const cards = computed<StatCardItem[]>(() => [
    { key: 'all', title: 'Total Users', value: props.stats.total, hint: 'All accounts in this barangay', icon: Users, tone: 'blue' },
    { key: 'administrator', title: 'Administrators', value: props.stats.administrators, hint: 'Barangay staff accounts', icon: Shield, tone: 'green' },
    { key: 'operator', title: 'Operators', value: props.stats.operators, hint: 'Boat operator accounts', icon: UserRound, tone: 'violet' },
    { key: 'passenger', title: 'Passengers', value: props.stats.customers, hint: 'Mobile app accounts', icon: Smartphone, tone: 'indigo' },
]);

watch(filter, () => {
    listQuery.value = '';
    operatorStatus.value = 'all';
    operatorViolations.value = 'all';
});

const filteredUsers = computed(() => {
    let rows = props.users;

    if (filter.value === 'inactive') {
        rows = rows.filter((user) => !user.is_active);
    } else if (filter.value !== 'all') {
        rows = rows.filter((user) => user.role === filter.value);
    }

    const query = listQuery.value.trim().toLowerCase();

    if (query !== '') {
        rows = rows.filter((user) => {
            const boat = `${user.boat_name ?? ''} ${user.boat_label ?? ''}`.toLowerCase();

            return (
                user.name.toLowerCase().includes(query) ||
                user.email.toLowerCase().includes(query) ||
                (filter.value === 'operator' && boat.includes(query))
            );
        });
    }

    if (filter.value === 'operator') {
        if (operatorStatus.value === 'suspended') {
            rows = rows.filter((user) => user.is_suspended);
        } else if (operatorStatus.value === 'inactive') {
            rows = rows.filter((user) => !user.is_active && !user.is_suspended);
        } else if (operatorStatus.value === 'active') {
            rows = rows.filter((user) => user.is_active && !user.is_suspended);
        }

        if (operatorViolations.value !== 'all') {
            const count = Number(operatorViolations.value);

            rows = rows.filter((user) => {
                const violations = user.violation_count ?? 0;

                return count === 3 ? violations >= 3 : violations === count;
            });
        }
    }

    return rows;
});

const listFiltersActive = computed(
    () =>
        listQuery.value.trim() !== '' ||
        operatorStatus.value !== 'all' ||
        operatorViolations.value !== 'all',
);

const tableTitle = computed(() => {
    if (filter.value === 'all') {
        return 'All Users';
    }

    if (filter.value === 'inactive') {
        return 'Inactive Users';
    }

    return (
        cards.value.find(
            (card) => card.key === filter.value,
        )?.title ?? 'All Users'
    );
});

function displayRole(user: ManagedUser): string {
    if (user.role === 'passenger') {
        return 'Passenger';
    }

    return user.role_label;
}

function violationTone(count: number): string {
    if (count >= 3) {
        return 'bg-red-100 text-red-700';
    }

    if (count === 2) {
        return 'bg-orange-100 text-orange-700';
    }

    if (count === 1) {
        return 'bg-amber-100 text-amber-700';
    }

    return 'bg-emerald-50 text-emerald-700';
}

// Add user
function openAdd() {
    editingUser.value = null;
    addOpen.value = true;
}

onMounted(() => {
    const params = new URLSearchParams(
        window.location.search,
    );
    const viewId = Number(params.get('view') ?? '');
    const viewedUser = Number.isFinite(viewId)
        ? props.users.find((user) => user.id === viewId) ?? null
        : null;

    if (params.get('role') === 'operator' || viewedUser?.role === 'operator') {
        defaultRole.value = 'operator';
        filter.value = 'operator';
    }

    if (viewedUser) {
        highlightedUserId.value = viewedUser.id;
        openView(viewedUser);
        params.delete('view');
        params.delete('role');
        const query = params.toString();
        window.history.replaceState(
            {},
            '',
            `${window.location.pathname}${query ? `?${query}` : ''}`,
        );
        return;
    }

    if (params.get('add') === '1') {
        openAdd();

        params.delete('add');
        params.delete('role');

        const query = params.toString();

        window.history.replaceState(
            {},
            '',
            `${window.location.pathname}${query ? `?${query}` : ''}`,
        );
    }
});

// Edit user
function openEdit(user: ManagedUser) {
    viewOpen.value = false;
    selectedUser.value = user;
    editingUser.value = user;
    addOpen.value = true;
}

// View user
function openView(user: ManagedUser) {
    selectedUser.value = user;
    viewOpen.value = true;
}

function onAddOpenChange(open: boolean) {
    addOpen.value = open;

    if (!open) {
        editingUser.value = null;
    }
}

// Deactivate / Restore
function openStatusChange(user: ManagedUser) {
    if (user.is_current) {
        return;
    }

    deactivationReason.value = '';
    deactivationError.value = '';
    statusUser.value = user;
}

function closeStatusChange() {
    if (updatingStatus.value) {
        return;
    }

    statusUser.value = null;
    deactivationReason.value = '';
    deactivationError.value = '';
}

function confirmStatusChange() {
    if (!statusUser.value || updatingStatus.value) {
        return;
    }

    const deactivating = statusUser.value.is_active;
    const reason = deactivationReason.value.trim();

    if (deactivating && reason.length < 5) {
        deactivationError.value =
            'Please provide a reason of at least 5 characters.';

        return;
    }

    updatingStatus.value = true;
    deactivationError.value = '';

    router.patch(
        `/users/${statusUser.value.id}/status`,
        {
            is_active: deactivating ? 0 : 1,
            reason: deactivating ? reason : null,
        },
        {
            preserveScroll: true,
            onSuccess: () => {
                statusUser.value = null;
                deactivationReason.value = '';
                deactivationError.value = '';
                viewOpen.value = false;
            },
            onError: (errors) => {
                deactivationError.value =
                    String(
                        errors.reason ??
                        errors.is_active ??
                        'Unable to update account status.',
                    );
            },
            onFinish: () => {
                updatingStatus.value = false;
            },
        },
    );
}
</script>

<template>
    <Head title="User Management" />

    <div
        class="flex min-w-0 flex-1 flex-col gap-5 px-4 py-5 sm:px-6 lg:px-7"
    >
        <!-- PAGE HEADER -->
        <div
            class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between"
        >
            <div>
                <h1
                    class="text-2xl font-bold tracking-tight text-[#0f4c8a] dark:text-sky-100"
                >
                    User Management
                </h1>

                <p
                    class="mt-1 text-sm text-ink-muted dark:text-slate-400"
                >
                    Manage administrators, boat operators, and passengers.
                </p>
            </div>

            <button
                type="button"
                class="inline-flex h-10 items-center justify-center gap-2 rounded-lg bg-[#1b7ad8] px-4 text-sm font-semibold text-white shadow-sm transition hover:bg-[#1567c4]"
                @click="openAdd"
            >
                <Plus class="size-4" />
                Add User
            </button>
        </div>

        <!-- SUMMARY CARDS -->
        <StatCards
            :items="cards"
            :active="filter"
            selectable
            @select="(key) => (filter = key as StatusFilter)"
        />

        <!-- USER TABLE -->
        <section
            class="min-w-0 overflow-hidden rounded-2xl border border-[#d7e8f6] bg-white shadow-sm dark:border-white/10 dark:bg-white/5"
        >
            <div
                class="flex items-center justify-between gap-3 border-b border-[#e8f1f8] px-5 py-4 dark:border-white/10"
            >
                <div>
                    <h2
                        class="text-base font-bold text-[#0f4c8a] dark:text-sky-100"
                    >
                        {{ tableTitle }}
                    </h2>

                    <p
                        class="mt-1 text-xs text-ink-muted dark:text-slate-400"
                    >
                        {{ filteredUsers.length }}
                        {{
                            filteredUsers.length === 1
                                ? 'account'
                                : 'accounts'
                        }}
                    </p>
                </div>
            </div>

            <div
                v-if="filter === 'operator' || filter === 'passenger'"
                class="border-b border-[#e8f1f8] px-5 py-4 dark:border-white/10"
            >
                <label
                    class="flex min-w-0 items-center gap-2 rounded-full border border-[#d7e8f6] bg-white px-4 dark:border-white/10 dark:bg-white/5"
                >
                    <Search class="size-4 shrink-0 text-ink-muted" />
                    <input
                        v-model="listQuery"
                        type="search"
                        :placeholder="
                            filter === 'operator'
                                ? 'Search name, email, or boat...'
                                : 'Search name or email...'
                        "
                        class="h-11 min-w-0 w-full flex-1 border-0 bg-transparent text-sm text-ink outline-none placeholder:text-[#9BB0C2] dark:text-sky-100"
                    />
                </label>

                <div
                    v-if="filter === 'operator'"
                    class="mt-3 grid gap-3 sm:grid-cols-2"
                >
                    <label class="block">
                        <span class="mb-1.5 block text-sm font-semibold text-[#0f4c8a] dark:text-sky-100">
                            Status
                        </span>
                        <select
                            v-model="operatorStatus"
                            aria-label="Filter by status"
                            class="h-11 w-full rounded-full border border-[#d7e8f6] bg-white px-4 text-sm text-ink outline-none focus:border-[#1479E8] dark:border-white/10 dark:bg-[#163A5F] dark:text-sky-100"
                        >
                            <option value="all">All Status</option>
                            <option value="active">Active</option>
                            <option value="suspended">Suspended</option>
                            <option value="inactive">Inactive</option>
                        </select>
                    </label>

                    <label class="block">
                        <span class="mb-1.5 block text-sm font-semibold text-[#0f4c8a] dark:text-sky-100">
                            Violations
                        </span>
                        <select
                            v-model="operatorViolations"
                            aria-label="Filter by violations"
                            class="h-11 w-full rounded-full border border-[#d7e8f6] bg-white px-4 text-sm text-ink outline-none focus:border-[#1479E8] dark:border-white/10 dark:bg-[#163A5F] dark:text-sky-100"
                        >
                            <option value="all">All Violations</option>
                            <option value="0">0</option>
                            <option value="1">1</option>
                            <option value="2">2</option>
                            <option value="3">3</option>
                        </select>
                    </label>
                </div>
            </div>

            <!-- EMPTY STATE -->
            <div
                v-if="filteredUsers.length === 0"
                class="px-5 py-14 text-center"
            >
                <Users
                    class="mx-auto size-10 text-[#c5def5]"
                />

                <p
                    class="mt-3 font-medium text-[#0f4c8a] dark:text-sky-100"
                >
                    {{
                        listFiltersActive
                            ? 'No accounts match this search'
                            : 'No users in this list yet'
                    }}
                </p>

                <p
                    v-if="!listFiltersActive"
                    class="mt-1 text-sm text-ink-muted dark:text-slate-400"
                >
                    Administrators and operators are added here.
                    Passengers appear after they register through the mobile app.
                </p>
            </div>

            <!-- TABLE -->
            <div v-else class="overflow-x-auto">
                <table
                    class="w-full min-w-[700px] border-collapse text-left text-sm"
                >
                    <thead
                        class="border-b border-[#d7e8f6] bg-[#f4f9fd] text-xs tracking-wide text-ink-soft uppercase dark:border-white/10 dark:bg-white/10"
                    >
                        <tr>
                            <th
                                class="w-[40%] px-5 py-3 font-semibold"
                            >
                                Name / Email
                            </th>

                            <th
                                class="px-5 py-3 font-semibold"
                            >
                                Role
                            </th>

                            <th
                                class="px-5 py-3 font-semibold"
                            >
                                Status
                            </th>

                            <th
                                v-if="filter === 'operator'"
                                class="px-5 py-3 font-semibold"
                            >
                                Boat Name
                            </th>

                            <th
                                v-if="filter === 'operator'"
                                class="px-5 py-3 text-center font-semibold"
                            >
                                Violations
                            </th>

                            <th
                                class="px-5 py-3 text-right font-semibold"
                            >
                                Actions
                            </th>
                        </tr>
                    </thead>

                    <tbody>
                        <template
                            v-for="user in filteredUsers"
                            :key="user.id"
                        >
                        <tr
                            class="border-b border-[#eef4f9] transition-colors hover:bg-[#f7fbff] dark:border-white/10 dark:hover:bg-white/5"
                            :class="[
                                user.id === highlightedUserId
                                    ? 'bg-[#e8f4fc] dark:bg-sky-500/15'
                                    : '',
                                user.is_suspended ? 'bg-rose-50/70 dark:bg-rose-500/10' : '',
                            ]"
                        >
                            <!-- NAME / EMAIL -->
                            <td class="px-5 py-4">
                                <div class="min-w-0">
                                    <div
                                        class="flex flex-wrap items-center gap-2"
                                    >
                                        <p
                                            class="font-semibold text-[#0f4c8a] dark:text-sky-100"
                                        >
                                            {{ user.name }}
                                        </p>

                                        <span
                                            v-if="user.is_current"
                                            class="rounded-full bg-[#e8f4fc] px-2 py-0.5 text-[11px] font-semibold text-[#1b7ad8]"
                                        >
                                            You
                                        </span>
                                    </div>

                                    <p
                                        class="mt-1 break-all text-xs text-ink-muted dark:text-slate-400"
                                    >
                                        {{ user.email }}
                                    </p>
                                </div>
                            </td>

                            <!-- ROLE -->
                            <td class="px-5 py-4">
                                <span
                                    class="text-sm font-medium text-ink dark:text-slate-200"
                                >
                                    {{ displayRole(user) }}
                                </span>
                            </td>

                            <!-- STATUS -->
                            <td class="px-5 py-4">
                                <span
                                    class="inline-flex items-center gap-1 rounded-full px-2.5 py-1 text-xs font-semibold"
                                    :class="
                                        user.is_suspended
                                            ? 'bg-red-100 text-red-700 dark:bg-red-500/20 dark:text-red-200'
                                            : user.is_active
                                              ? 'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-200'
                                              : 'bg-red-50 text-red-700 dark:bg-red-500/15 dark:text-red-200'
                                    "
                                >
                                    <Pause v-if="user.is_suspended" class="size-3" />
                                    {{ user.status_label }}
                                </span>

                                <p
                                    v-if="user.deactivated_reason"
                                    class="mt-1 max-w-[15rem] text-xs leading-snug text-ink-muted dark:text-slate-400"
                                >
                                    {{ user.deactivated_reason }}
                                </p>
                            </td>

                            <td
                                v-if="filter === 'operator'"
                                class="px-5 py-4 text-sm text-ink dark:text-slate-200"
                            >
                                {{ user.boat_label || '—' }}
                            </td>

                            <td
                                v-if="filter === 'operator'"
                                class="px-5 py-4 text-center"
                            >
                                <span
                                    class="inline-flex min-w-8 items-center justify-center rounded-full px-2.5 py-1 text-xs font-semibold tabular-nums"
                                    :class="violationTone(user.violation_count ?? 0)"
                                >
                                    {{ user.violation_count ?? 0 }}
                                </span>
                            </td>

                            <!-- ACTIONS -->
                            <td class="px-5 py-4">
                                <div
                                    class="flex items-center justify-end gap-2"
                                >
                                    <button
                                        type="button"
                                        class="inline-flex h-9 items-center gap-1.5 rounded-lg border border-[#c5def5] bg-white px-3 text-xs font-semibold text-[#1b7ad8] transition hover:bg-[#e8f4fc] dark:border-white/10 dark:bg-white/5"
                                        @click="openView(user)"
                                    >
                                        <Eye class="size-3.5" />
                                        View
                                    </button>

                                    <button
                                        v-if="user.role !== 'passenger'"
                                        type="button"
                                        class="inline-flex h-9 items-center gap-1.5 rounded-lg bg-[#1b7ad8] px-3 text-xs font-semibold text-white transition hover:bg-[#1567c4]"
                                        @click="openEdit(user)"
                                    >
                                        <Pencil class="size-3.5" />
                                        Edit
                                    </button>
                                </div>
                            </td>
                        </tr>
                        <tr
                            v-if="user.is_suspended && user.suspension"
                            class="border-b border-rose-100 bg-rose-50 dark:border-rose-500/20 dark:bg-rose-500/10"
                        >
                            <td :colspan="filter === 'operator' ? 6 : 4" class="px-5 py-3">
                                <div class="grid gap-3 sm:grid-cols-4">
                                    <div class="border-l-2 border-red-400 pl-3">
                                        <p class="text-[11px] font-medium text-ink-muted">Suspension Reason</p>
                                        <p class="text-sm font-semibold text-[#0f4c8a] dark:text-sky-100">{{ user.suspension.reason }}</p>
                                    </div>
                                    <div>
                                        <p class="flex items-center gap-1 text-[11px] font-medium text-ink-muted">
                                            <CalendarDays class="size-3" />
                                            Suspension Start
                                        </p>
                                        <p class="text-sm font-semibold text-[#0f4c8a] dark:text-sky-100">{{ user.suspension.started_at_label }}</p>
                                    </div>
                                    <div>
                                        <p class="flex items-center gap-1 text-[11px] font-medium text-ink-muted">
                                            <CalendarDays class="size-3" />
                                            Suspension End
                                        </p>
                                        <p class="text-sm font-semibold text-[#0f4c8a] dark:text-sky-100">{{ user.suspension.ends_at_label }}</p>
                                    </div>
                                    <div class="flex items-center gap-2 rounded-xl bg-rose-100 px-3 py-2 text-sm font-medium text-rose-700 dark:bg-rose-500/20 dark:text-rose-100">
                                        <Info class="size-4 shrink-0" />
                                        {{ user.suspension.remaining_label }}
                                    </div>
                                </div>
                            </td>
                        </tr>
                        </template>
                    </tbody>
                </table>
            </div>
        </section>
    </div>

    <!-- ADD / EDIT USER MODAL -->
    <AddUserModal
        :open="addOpen"
        :user="editingUser"
        :roles="roleOptions"
        :organization="organization"
        :boats="boats ?? []"
        :default-role="defaultRole"
        @update:open="onAddOpenChange"
    />

    <!-- VIEW USER MODAL -->
    <ViewUserModal
        v-model:open="viewOpen"
        :user="selectedUser"
        @edit="openEdit"
        @status="openStatusChange"
    />

    <!-- DEACTIVATE / RESTORE DIALOG -->
    <Dialog
        :open="Boolean(statusUser)"
        @update:open="(open) => !open && closeStatusChange()"
    >
        <DialogContent
            :show-close-button="false"
            class="w-full max-w-[calc(100%-1.5rem)] gap-0 overflow-hidden rounded-2xl border-none p-0 shadow-2xl sm:max-w-md"
        >
            <div class="bg-[#1b7ad8] px-5 py-4 text-white">
                <DialogTitle
                    class="text-lg font-semibold text-white"
                >
                    {{
                        statusUser?.is_active
                            ? 'Deactivate account'
                            : 'Restore account'
                    }}
                </DialogTitle>

                <DialogDescription class="text-sm text-white/80">
                    {{
                        statusUser?.is_active
                            ? 'Temporarily disable this account. The reason will be recorded.'
                            : 'Restore access to this account.'
                    }}
                </DialogDescription>
            </div>

            <div class="space-y-4 bg-white p-5 dark:bg-background">
                <p class="text-sm text-ink dark:text-slate-200">
                    {{
                        statusUser?.is_active
                            ? 'Deactivate'
                            : 'Restore'
                    }}

                    <span
                        class="font-semibold text-[#0f4c8a] dark:text-sky-100"
                    >
                        {{ statusUser?.name }}
                    </span>
                    ?
                </p>

                <div
                    v-if="statusUser?.is_active"
                    class="grid gap-1.5"
                >
                    <label
                        for="deactivation-reason"
                        class="text-xs font-semibold text-[#0f4c8a] dark:text-sky-100"
                    >
                        Reason
                    </label>

                    <textarea
                        id="deactivation-reason"
                        v-model="deactivationReason"
                        rows="3"
                        maxlength="180"
                        class="w-full rounded-xl border border-[#c5def5] bg-[#f7fbfe] px-3 py-2 text-sm text-ink outline-none focus:border-[#1b7ad8] focus:ring-2 focus:ring-[#1b7ad8]/20 dark:border-white/10 dark:bg-white/5 dark:text-slate-100"
                        placeholder="Enter reason for deactivation"
                    />

                    <p
                        v-if="deactivationError"
                        class="text-xs text-[#d45b5b]"
                    >
                        {{ deactivationError }}
                    </p>
                </div>

                <p
                    v-if="!statusUser?.is_active && deactivationError"
                    class="text-xs text-[#d45b5b]"
                >
                    {{ deactivationError }}
                </p>

                <div class="flex justify-end gap-3">
                    <Button
                        type="button"
                        variant="outline"
                        class="h-10 rounded-xl border-[#c5def5] px-5 text-[#1b7ad8] hover:bg-[#e8f4fc]"
                        :disabled="updatingStatus"
                        @click="closeStatusChange"
                    >
                        Cancel
                    </Button>

                    <Button
                        type="button"
                        class="h-10 rounded-xl px-5 text-white"
                        :class="
                            statusUser?.is_active
                                ? 'bg-[#d45b5b] hover:bg-[#c14c4c]'
                                : 'bg-[#0d9b8a] hover:bg-[#0b8778]'
                        "
                        :disabled="updatingStatus"
                        @click="confirmStatusChange"
                    >
                        {{
                            updatingStatus
                                ? 'Updating...'
                                : statusUser?.is_active
                                  ? 'Deactivate'
                                  : 'Restore'
                        }}
                    </Button>
                </div>
            </div>
        </DialogContent>
    </Dialog>
</template>
