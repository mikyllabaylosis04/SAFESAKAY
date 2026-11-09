
<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import {
    Building2,
    CheckCircle2,
    ChevronLeft,
    ChevronRight,
    Eye,
    Search,
    Ship,
    UserRound,
    Users,
    X,
    XCircle,
} from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import StatCards from '@/components/admin/StatCards.vue';
import type { StatCardItem } from '@/components/admin/StatCards.vue';

type PlatformUser = {
    id: number;
    name: string;
    email: string;
    role: 'passenger' | 'operator';
    is_active: boolean;
    organization_name: string | null;
    created_at: string | null;
};

const props = defineProps<{ users: PlatformUser[] }>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Platform', href: '/platform' },
            { title: 'User Management', href: '/platform/users' },
        ],
    },
});

const search = ref('');
const roleFilter = ref<'all' | 'passenger' | 'operator'>('all');
const statusFilter = ref<'all' | 'active' | 'inactive'>('all');
const page = ref(1);
const pageSize = 10;
const selectedUser = ref<PlatformUser | null>(null);

const passengers = computed(
    () => props.users.filter(user => user.role === 'passenger').length,
);

const operators = computed(
    () => props.users.filter(user => user.role === 'operator').length,
);

const inactive = computed(
    () => props.users.filter(user => !user.is_active).length,
);

const summaryCards = computed<StatCardItem[]>(() => [
    { key: 'all', title: 'Total Users', value: props.users.length, hint: 'Passengers and operators', icon: Users, tone: 'blue' },
    { key: 'passenger', title: 'Passengers', value: passengers.value, hint: 'Mobile app accounts', icon: UserRound, tone: 'green' },
    { key: 'operator', title: 'Operators', value: operators.value, hint: 'Boat operator accounts', icon: Ship, tone: 'amber' },
    { key: 'inactive', title: 'Inactive', value: inactive.value, hint: 'Disabled accounts', icon: XCircle, tone: 'red' },
]);

const activeCard = computed(() => {
    if (statusFilter.value === 'inactive' && roleFilter.value === 'all') {
return 'inactive';
}

    if (statusFilter.value === 'all') {
return roleFilter.value;
}

    return null;
});

function selectCard(key: string) {
    roleFilter.value = key === 'passenger' || key === 'operator' ? key : 'all';
    statusFilter.value = key === 'inactive' ? 'inactive' : 'all';
}

const filteredUsers = computed(() => {
    const term = search.value.trim().toLowerCase();

    return props.users.filter(user => {
        const matchesSearch = [
            user.name,
            user.email,
            user.organization_name ?? '',
        ].some(value => value.toLowerCase().includes(term));

        const matchesRole =
            roleFilter.value === 'all' ||
            user.role === roleFilter.value;

        const matchesStatus =
            statusFilter.value === 'all' ||
            (statusFilter.value === 'active'
                ? user.is_active
                : !user.is_active);

        return matchesSearch && matchesRole && matchesStatus;
    });
});

const pages = computed(() =>
    Math.max(1, Math.ceil(filteredUsers.value.length / pageSize)),
);

const visibleUsers = computed(() =>
    filteredUsers.value.slice(
        (page.value - 1) * pageSize,
        page.value * pageSize,
    ),
);

const first = computed(() =>
    filteredUsers.value.length
        ? (page.value - 1) * pageSize + 1
        : 0,
);

const last = computed(() =>
    Math.min(page.value * pageSize, filteredUsers.value.length),
);

watch([search, roleFilter, statusFilter], () => {
    page.value = 1;
});

watch(pages, value => {
    if (page.value > value) {
page.value = value;
}
});

function resetFilters() {
    search.value = '';
    roleFilter.value = 'all';
    statusFilter.value = 'all';
    page.value = 1;
}

function formatDate(value: string | null) {
    if (!value) {
return 'Not available';
}

    const date = new Date(value);

    return Number.isNaN(date.getTime())
        ? value
        : date.toLocaleDateString('en-PH', {
              year: 'numeric',
              month: 'short',
              day: 'numeric',
          });
}
</script>

<template>
    <Head title="User Management" />

    <div class="flex flex-1 flex-col gap-6 p-4 sm:p-6">
        <!-- HEADER -->
        <header>
            <h1
                class="text-2xl font-bold tracking-tight text-[#0f4c8a] dark:text-sky-100"
            >
                User Management
            </h1>
            <p class="mt-1 text-sm text-ink-muted dark:text-slate-400">
                Monitor registered platform users.
            </p>
        </header>

        <StatCards :items="summaryCards" :active="activeCard" selectable @select="selectCard" />

        <!-- USERS TABLE -->
        <section
            class="overflow-hidden rounded-2xl border border-[#d7e8f6] bg-white shadow-sm dark:border-white/10 dark:bg-slate-900"
        >
            <div
                class="flex flex-wrap items-center justify-between gap-3 border-b border-[#e8f1f8] px-5 py-4 dark:border-white/10"
            >
                <div class="flex items-center gap-3">
                    <div class="flex size-10 items-center justify-center rounded-xl bg-[#e8f1fd] text-[#1976d2]">
                        <Users class="size-5" />
                    </div>
                    <div>
                        <h2 class="font-bold text-[#0f4c8a] dark:text-sky-100">
                            Registered Users
                        </h2>
                        <p class="text-xs text-ink-muted dark:text-slate-400">
                            Passenger and operator account directory
                        </p>
                    </div>
                </div>

                <span class="rounded-full bg-[#edf5ff] px-3 py-1 text-xs font-semibold text-[#1769bf]">
                    {{ filteredUsers.length }} results
                </span>
            </div>

            <!-- SEARCH AND FILTERS -->
            <div
                class="flex flex-col gap-3 border-b border-[#e8f1f8] p-4 dark:border-white/10 lg:flex-row"
            >
                <div class="relative flex-1">
                    <Search
                        class="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-ink-muted"
                    />
                    <input
                        v-model="search"
                        type="search"
                        aria-label="Search users"
                        placeholder="Search name, email, or organization..."
                        class="h-11 w-full rounded-xl border border-[#d5e5f4] bg-[#f8fbff] pl-10 pr-3 text-sm text-ink outline-none focus:border-[#1976d2] dark:border-white/10 dark:bg-white/5 dark:text-white"
                    />
                </div>

                <div class="flex flex-wrap gap-2">
                    <select
                        v-model="roleFilter"
                        aria-label="Filter user role"
                        class="h-11 flex-1 rounded-xl border border-[#d5e5f4] bg-[#f8fbff] px-3 text-sm text-ink dark:border-white/10 dark:bg-slate-900 dark:text-white"
                    >
                        <option value="all">All Roles</option>
                        <option value="passenger">Passengers</option>
                        <option value="operator">Operators</option>
                    </select>

                    <select
                        v-model="statusFilter"
                        aria-label="Filter account status"
                        class="h-11 flex-1 rounded-xl border border-[#d5e5f4] bg-[#f8fbff] px-3 text-sm text-ink dark:border-white/10 dark:bg-slate-900 dark:text-white"
                    >
                        <option value="all">All Statuses</option>
                        <option value="active">Active</option>
                        <option value="inactive">Inactive</option>
                    </select>

                    <button
                        v-if="search || roleFilter !== 'all' || statusFilter !== 'all'"
                        type="button"
                        class="inline-flex items-center gap-1 rounded-xl px-3 text-sm font-semibold text-[#1976d2]"
                        @click="resetFilters"
                    >
                        <X class="size-4" />
                        Clear
                    </button>
                </div>
            </div>

            <!-- EMPTY STATE -->
            <div
                v-if="filteredUsers.length === 0"
                class="px-5 py-16 text-center"
            >
                <Users class="mx-auto mb-3 size-10 text-[#a1bad2]" />
                <h3 class="font-semibold text-[#0f4c8a] dark:text-sky-100">
                    No users found
                </h3>
                <p class="mt-1 text-sm text-ink-muted">
                    {{
                        users.length
                            ? 'Try changing your search or filters.'
                            : 'No passenger or operator accounts have been registered yet.'
                    }}
                </p>
            </div>

            <!-- TABLE -->
            <div v-else class="overflow-x-auto">
                <table class="w-full min-w-[850px] text-left text-sm">
                    <thead
                        class="bg-[#f4f9fd] text-xs uppercase tracking-wide text-ink-soft dark:bg-white/5"
                    >
                        <tr>
                            <th class="px-5 py-3.5">User</th>
                            <th class="px-5 py-3.5">Organization</th>
                            <th class="px-5 py-3.5">Role</th>
                            <th class="px-5 py-3.5">Account Status</th>
                            <th class="px-5 py-3.5 text-right">Actions</th>
                        </tr>
                    </thead>

                    <tbody>
                        <tr
                            v-for="user in visibleUsers"
                            :key="user.id"
                            class="border-t border-[#eef4f9] hover:bg-[#f9fcff] dark:border-white/10 dark:hover:bg-white/5"
                        >
                            <td class="px-5 py-4">
                                <div class="flex items-center gap-3">
                                    <div class="flex size-10 shrink-0 items-center justify-center rounded-xl bg-[#edf5ff] font-bold text-[#1976d2]">
                                        {{ user.name.trim().charAt(0).toUpperCase() || '?' }}
                                    </div>
                                    <div>
                                        <p class="font-semibold text-[#0f4c8a] dark:text-sky-100">
                                            {{ user.name }}
                                        </p>
                                        <p class="break-all text-xs text-ink-muted">
                                            {{ user.email }}
                                        </p>
                                    </div>
                                </div>
                            </td>

                            <td class="px-5 py-4 text-ink dark:text-sky-50">
                                <span class="inline-flex items-center gap-2">
                                    <Building2 class="size-4 text-ink-muted" />
                                    {{ user.organization_name || 'Not assigned' }}
                                </span>
                            </td>

                            <td class="px-5 py-4">
                                <span
                                    class="inline-flex rounded-full px-2.5 py-1 text-xs font-semibold"
                                    :class="
                                        user.role === 'operator'
                                            ? 'bg-amber-50 text-amber-700'
                                            : 'bg-blue-50 text-blue-700'
                                    "
                                >
                                    {{ user.role === 'operator' ? 'Boat Operator' : 'Passenger' }}
                                </span>
                            </td>

                            <td class="px-5 py-4">
                                <span
                                    class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-semibold"
                                    :class="
                                        user.is_active
                                            ? 'bg-emerald-50 text-emerald-700'
                                            : 'bg-rose-50 text-rose-700'
                                    "
                                >
                                    <CheckCircle2 v-if="user.is_active" class="size-3.5" />
                                    <XCircle v-else class="size-3.5" />
                                    {{ user.is_active ? 'Active' : 'Inactive' }}
                                </span>
                            </td>

                            <td class="px-5 py-4 text-right">
                                <button
                                    type="button"
                                    class="inline-flex h-9 items-center gap-1.5 rounded-lg border border-[#c5def5] px-3 text-xs font-semibold text-[#1976d2] hover:bg-[#edf5ff]"
                                    @click="selectedUser = user"
                                >
                                    <Eye class="size-3.5" />
                                    View Details
                                </button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- PAGINATION -->
            <div
                v-if="filteredUsers.length"
                class="flex flex-col gap-3 border-t border-[#edf2f7] px-5 py-4 text-xs text-ink-muted sm:flex-row sm:items-center sm:justify-between dark:border-white/10"
            >
                <span>
                    Showing {{ first }}–{{ last }} of {{ filteredUsers.length }} users
                </span>

                <div v-if="pages > 1" class="flex items-center gap-2">
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
                        Page {{ page }} of {{ pages }}
                    </span>

                    <button
                        type="button"
                        :disabled="page === pages"
                        aria-label="Next page"
                        class="flex size-9 items-center justify-center rounded-lg border border-[#d5e5f4] disabled:opacity-40"
                        @click="page++"
                    >
                        <ChevronRight class="size-4" />
                    </button>
                </div>
            </div>
        </section>

        <!-- VIEW USER DETAILS MODAL -->
        <div
            v-if="selectedUser"
            class="fixed inset-0 z-[100] flex items-center justify-center bg-slate-950/50 p-4"
            @click.self="selectedUser = null"
        >
            <section
                role="dialog"
                aria-modal="true"
                aria-labelledby="user-details-title"
                class="w-full max-w-lg rounded-2xl bg-white p-6 shadow-2xl dark:bg-slate-900"
            >
                <div class="mb-5 flex items-start justify-between gap-4">
                    <div>
                        <h2
                            id="user-details-title"
                            class="text-lg font-bold text-[#0f4c8a] dark:text-sky-100"
                        >
                            User Details
                        </h2>
                        <p class="mt-1 text-xs text-ink-muted">
                            Registered account information
                        </p>
                    </div>

                    <button
                        type="button"
                        aria-label="Close details"
                        class="rounded-lg p-2 text-ink-muted hover:bg-slate-100 dark:hover:bg-white/10"
                        @click="selectedUser = null"
                    >
                        <X class="size-5" />
                    </button>
                </div>

                <dl class="space-y-4 text-sm">
                    <div>
                        <dt class="text-xs font-semibold uppercase tracking-wide text-ink-muted">
                            Full Name
                        </dt>
                        <dd class="mt-1 font-medium text-ink dark:text-white">
                            {{ selectedUser.name }}
                        </dd>
                    </div>

                    <div>
                        <dt class="text-xs font-semibold uppercase tracking-wide text-ink-muted">
                            Email
                        </dt>
                        <dd class="mt-1 break-all font-medium text-ink dark:text-white">
                            {{ selectedUser.email }}
                        </dd>
                    </div>

                    <div>
                        <dt class="text-xs font-semibold uppercase tracking-wide text-ink-muted">
                            Role
                        </dt>
                        <dd class="mt-1 font-medium text-ink dark:text-white">
                            {{ selectedUser.role === 'operator' ? 'Boat Operator' : 'Passenger' }}
                        </dd>
                    </div>

                    <div>
                        <dt class="text-xs font-semibold uppercase tracking-wide text-ink-muted">
                            Organization
                        </dt>
                        <dd class="mt-1 font-medium text-ink dark:text-white">
                            {{ selectedUser.organization_name || 'Not assigned' }}
                        </dd>
                    </div>

                    <div>
                        <dt class="text-xs font-semibold uppercase tracking-wide text-ink-muted">
                            Account Status
                        </dt>
                        <dd
                            class="mt-1 font-medium"
                            :class="
                                selectedUser.is_active
                                    ? 'text-emerald-600'
                                    : 'text-rose-600'
                            "
                        >
                            {{ selectedUser.is_active ? 'Active' : 'Inactive' }}
                        </dd>
                    </div>

                    <div>
                        <dt class="text-xs font-semibold uppercase tracking-wide text-ink-muted">
                            Registered On
                        </dt>
                        <dd class="mt-1 font-medium text-ink dark:text-white">
                            {{ formatDate(selectedUser.created_at) }}
                        </dd>
                    </div>
                </dl>

                <div class="mt-6 flex justify-end">
                    <button
                        type="button"
                        class="rounded-xl bg-[#1976d2] px-5 py-2.5 text-sm font-semibold text-white hover:bg-[#1261b2]"
                        @click="selectedUser = null"
                    >
                        Close
                    </button>
                </div>
            </section>
        </div>
    </div>
</template>
