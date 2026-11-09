<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';

type ClosureRequestRow = {
    id: number;
    name: string;
    admin_name: string | null;
    admin_email: string | null;
    closure_requested_at_label: string | null;
    closure_reason: string | null;
    closure_details: string | null;
    closure_requested_by_name: string | null;
    closure_requested_by_email: string | null;
};

type RestoreRequestRow = {
    id: number;
    name: string;
    admin_name: string | null;
    admin_email: string | null;
    restore_requested_at_label: string | null;
    restore_request_reason: string | null;
    restore_request_details: string | null;
    restore_requested_by_name: string | null;
    restore_requested_by_email: string | null;
};

defineProps<{
    closure_requests: ClosureRequestRow[];
    restore_requests: RestoreRequestRow[];
    reactivation_requests: RestoreRequestRow[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Platform', href: '/platform' },
            { title: 'Organization Request', href: '/platform/organization-requests' },
        ],
    },
});

function approveClosure(id: number) {
    router.patch(`/platform/organizations/${id}/closure/approve`, {}, { preserveScroll: true });
}

function rejectClosure(id: number) {
    router.patch(`/platform/organizations/${id}/closure/reject`, { reason: 'Closure request was rejected.' }, { preserveScroll: true });
}

function approveReactivation(id: number) {
    router.patch(`/platform/organizations/${id}/reactivation/approve`, {}, { preserveScroll: true });
}

function rejectReactivation(id: number) {
    router.patch(`/platform/organizations/${id}/reactivation/reject`, {}, { preserveScroll: true });
}

function restore(id: number) {
    router.patch(`/platform/organizations/${id}/restore`, {}, { preserveScroll: true });
}

function dismissRestore(id: number) {
    router.patch(`/platform/organizations/${id}/restore-request`, {}, { preserveScroll: true });
}
</script>

<template>
    <Head title="Organization Request" />

    <div class="flex flex-1 flex-col gap-6 p-6">
        <div>
            <h1 class="text-2xl font-bold tracking-tight text-[#0f4c8a] dark:text-sky-100">
                Organization Request
            </h1>
            <p class="mt-1 text-sm text-ink-muted dark:text-slate-400">
                Review closure, restore, and reactivation appeals without cluttering the organizations list.
            </p>
        </div>

        <section class="overflow-hidden rounded-2xl border border-[#d7e8f6] bg-white dark:border-white/10 dark:bg-white/5 shadow-sm">
            <div class="border-b border-[#e8f1f8] px-5 py-4 dark:border-white/10">
                <h2 class="font-semibold text-[#0f4c8a] dark:text-sky-100">Closure requests</h2>
                <p class="mt-1 text-xs text-ink-muted dark:text-slate-400">
                    Barangay admins asked to close their SafeSakay account. Approve to archive; historical data stays saved.
                </p>
            </div>
            <div v-if="closure_requests.length === 0" class="px-5 py-10 text-center text-sm text-ink-muted dark:text-slate-400">
                No closure requests.
            </div>
            <div v-else class="overflow-x-auto">
                <table class="w-full min-w-[860px] text-left text-sm">
                    <thead class="bg-[#f4f9fd] text-xs tracking-wide text-ink-soft uppercase dark:bg-white/5 dark:text-slate-400">
                        <tr>
                            <th class="px-5 py-3">Organization</th>
                            <th class="px-5 py-3">Requested by</th>
                            <th class="px-5 py-3">Reason</th>
                            <th class="px-5 py-3 text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="organization in closure_requests" :key="organization.id" class="border-t border-[#eef4f9] dark:border-white/10">
                            <td class="px-5 py-3">
                                <p class="font-semibold text-[#0f4c8a] dark:text-sky-100">{{ organization.name }}</p>
                                <p class="text-xs text-ink-muted">{{ organization.closure_requested_at_label }}</p>
                            </td>
                            <td class="px-5 py-3">
                                <p>{{ organization.closure_requested_by_name || organization.admin_name || '—' }}</p>
                                <p class="text-xs text-ink-muted">{{ organization.closure_requested_by_email || organization.admin_email }}</p>
                            </td>
                            <td class="px-5 py-3">
                                <p>{{ organization.closure_reason || '—' }}</p>
                                <p v-if="organization.closure_details" class="mt-1 text-xs text-ink-muted">{{ organization.closure_details }}</p>
                            </td>
                            <td class="px-5 py-3">
                                <div class="flex justify-end gap-2">
                                    <Link
                                        :href="`/platform/organizations/${organization.id}`"
                                        class="inline-flex h-8 items-center rounded-lg border border-[#c5def5] px-2.5 text-xs font-medium text-[#1b7ad8]"
                                    >
                                        View
                                    </Link>
                                    <button type="button" class="inline-flex h-8 items-center rounded-lg bg-slate-100 px-2.5 text-xs font-semibold text-slate-700" @click="approveClosure(organization.id)">
                                        Archive
                                    </button>
                                    <button type="button" class="inline-flex h-8 items-center rounded-lg bg-amber-50 px-2.5 text-xs font-semibold text-amber-800" @click="rejectClosure(organization.id)">
                                        Reject
                                    </button>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <section class="overflow-hidden rounded-2xl border border-[#d7e8f6] bg-white dark:border-white/10 dark:bg-white/5 shadow-sm">
            <div class="border-b border-[#e8f1f8] px-5 py-4 dark:border-white/10">
                <h2 class="font-semibold text-[#0f4c8a] dark:text-sky-100">Restore requests</h2>
                <p class="mt-1 text-xs text-ink-muted dark:text-slate-400">
                    Deactivated barangay admins asked to turn their organization back on from the login page. Restore gives them access again; Dismiss keeps them deactivated.
                </p>
            </div>
            <div v-if="restore_requests.length === 0" class="px-5 py-10 text-center text-sm text-ink-muted dark:text-slate-400">
                No restore requests.
            </div>
            <div v-else class="overflow-x-auto">
                <table class="w-full min-w-[860px] text-left text-sm">
                    <thead class="bg-[#f4f9fd] text-xs tracking-wide text-ink-soft uppercase dark:bg-white/5 dark:text-slate-400">
                        <tr>
                            <th class="px-5 py-3">Organization</th>
                            <th class="px-5 py-3">Requested by</th>
                            <th class="px-5 py-3">Reason</th>
                            <th class="px-5 py-3 text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="organization in restore_requests" :key="organization.id" class="border-t border-[#eef4f9] dark:border-white/10">
                            <td class="px-5 py-3">
                                <p class="font-semibold text-[#0f4c8a] dark:text-sky-100">{{ organization.name }}</p>
                                <p class="text-xs text-ink-muted">{{ organization.restore_requested_at_label }}</p>
                            </td>
                            <td class="px-5 py-3">
                                <p>{{ organization.restore_requested_by_name || organization.admin_name || '—' }}</p>
                                <p class="text-xs text-ink-muted">{{ organization.restore_requested_by_email || organization.admin_email }}</p>
                            </td>
                            <td class="px-5 py-3">
                                <p>{{ organization.restore_request_reason || '—' }}</p>
                                <p v-if="organization.restore_request_details" class="mt-1 text-xs text-ink-muted">
                                    {{ organization.restore_request_details }}
                                </p>
                            </td>
                            <td class="px-5 py-3">
                                <div class="flex justify-end gap-2">
                                    <Link
                                        :href="`/platform/organizations/${organization.id}`"
                                        class="inline-flex h-8 items-center rounded-lg border border-[#c5def5] px-2.5 text-xs font-medium text-[#1b7ad8]"
                                    >
                                        View
                                    </Link>
                                    <button type="button" class="inline-flex h-8 items-center rounded-lg bg-emerald-50 px-2.5 text-xs font-semibold text-emerald-700" @click="restore(organization.id)">
                                        Restore
                                    </button>
                                    <button type="button" class="inline-flex h-8 items-center rounded-lg bg-amber-50 px-2.5 text-xs font-semibold text-amber-800" @click="dismissRestore(organization.id)">
                                        Dismiss
                                    </button>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <section class="overflow-hidden rounded-2xl border border-[#d7e8f6] bg-white dark:border-white/10 dark:bg-white/5 shadow-sm">
            <div class="border-b border-[#e8f1f8] px-5 py-4 dark:border-white/10">
                <h2 class="font-semibold text-[#0f4c8a] dark:text-sky-100">Reactivation requests</h2>
                <p class="mt-1 text-xs text-ink-muted dark:text-slate-400">
                    Archived barangay admins asked to restore normal access.
                </p>
            </div>
            <div v-if="reactivation_requests.length === 0" class="px-5 py-10 text-center text-sm text-ink-muted dark:text-slate-400">
                No reactivation requests.
            </div>
            <div v-else class="overflow-x-auto">
                <table class="w-full min-w-[860px] text-left text-sm">
                    <thead class="bg-[#f4f9fd] text-xs tracking-wide text-ink-soft uppercase dark:bg-white/5 dark:text-slate-400">
                        <tr>
                            <th class="px-5 py-3">Organization</th>
                            <th class="px-5 py-3">Requested by</th>
                            <th class="px-5 py-3">Reason</th>
                            <th class="px-5 py-3 text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="organization in reactivation_requests" :key="organization.id" class="border-t border-[#eef4f9] dark:border-white/10">
                            <td class="px-5 py-3">
                                <p class="font-semibold text-[#0f4c8a] dark:text-sky-100">{{ organization.name }}</p>
                                <p class="text-xs text-ink-muted">{{ organization.restore_requested_at_label }}</p>
                            </td>
                            <td class="px-5 py-3">
                                <p>{{ organization.restore_requested_by_name || organization.admin_name || '—' }}</p>
                                <p class="text-xs text-ink-muted">{{ organization.restore_requested_by_email || organization.admin_email }}</p>
                            </td>
                            <td class="px-5 py-3">
                                <p>{{ organization.restore_request_reason || '—' }}</p>
                                <p v-if="organization.restore_request_details" class="mt-1 text-xs text-ink-muted">
                                    {{ organization.restore_request_details }}
                                </p>
                            </td>
                            <td class="px-5 py-3">
                                <div class="flex justify-end gap-2">
                                    <Link
                                        :href="`/platform/organizations/${organization.id}`"
                                        class="inline-flex h-8 items-center rounded-lg border border-[#c5def5] px-2.5 text-xs font-medium text-[#1b7ad8]"
                                    >
                                        View
                                    </Link>
                                    <button type="button" class="inline-flex h-8 items-center rounded-lg bg-emerald-50 px-2.5 text-xs font-semibold text-emerald-700" @click="approveReactivation(organization.id)">
                                        Reactivate
                                    </button>
                                    <button type="button" class="inline-flex h-8 items-center rounded-lg bg-amber-50 px-2.5 text-xs font-semibold text-amber-800" @click="rejectReactivation(organization.id)">
                                        Reject
                                    </button>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</template>
