<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { Check, Eye, Mail, MapPin, Phone, X } from '@lucide/vue';
import { ref } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogTitle } from '@/components/ui/dialog';
import { Spinner } from '@/components/ui/spinner';

type PlatformOrganization = {
    id: number;
    name: string;
    address: string;
    contact_number: string | null;
    admin_name: string | null;
    admin_email: string | null;
    registered_at_label: string | null;
    waiting_label: string | null;
};

defineProps<{
    organizations: PlatformOrganization[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Platform', href: '/platform' },
            { title: 'Pending Approval', href: '/platform/pending' },
        ],
    },
});

const approving = ref<PlatformOrganization | null>(null);
const approveBusy = ref(false);
const rejecting = ref<PlatformOrganization | null>(null);
const rejectForm = useForm({ reason: '' });

function confirmApprove() {
    if (!approving.value) {
        return;
    }

    approveBusy.value = true;
    router.patch(`/platform/organizations/${approving.value.id}/approve`, {}, {
        preserveScroll: true,
        onFinish: () => {
            approveBusy.value = false;
            approving.value = null;
        },
    });
}

function openReject(organization: PlatformOrganization) {
    rejectForm.reset();
    rejectForm.clearErrors();
    rejecting.value = organization;
}

function confirmReject() {
    if (!rejecting.value) {
        return;
    }

    rejectForm.patch(`/platform/organizations/${rejecting.value.id}/reject`, {
        preserveScroll: true,
        onSuccess: () => {
            rejecting.value = null;
        },
    });
}
</script>

<template>
    <Head title="Pending Approval" />

    <div class="flex flex-1 flex-col gap-6 p-6">
        <div>
            <h1 class="text-2xl font-bold tracking-tight text-[#0f4c8a] dark:text-sky-100">Pending Approval</h1>
            <p class="mt-1 text-sm text-ink-muted dark:text-slate-400">
                {{ organizations.length }} {{ organizations.length === 1 ? 'registration' : 'registrations' }} waiting for review
            </p>
        </div>

        <section
            v-if="organizations.length === 0"
            class="rounded-2xl border border-[#d7e8f6] bg-white px-5 py-16 text-center text-sm text-ink-muted shadow-sm dark:border-white/10 dark:bg-white/5 dark:text-slate-400"
        >
            No pending organizations.
        </section>

        <div v-else class="grid gap-4 lg:grid-cols-2">
            <article
                v-for="organization in organizations"
                :key="organization.id"
                class="flex flex-col rounded-2xl border border-[#d7e8f6] bg-white p-5 shadow-sm dark:border-white/10 dark:bg-white/5"
            >
                <div class="flex items-start justify-between gap-3">
                    <div class="min-w-0">
                        <h2 class="truncate text-base font-bold text-[#0f4c8a] dark:text-sky-100">{{ organization.name }}</h2>
                        <p class="mt-0.5 text-xs text-ink-muted dark:text-slate-400">
                            Registered {{ organization.registered_at_label || '—' }}
                        </p>
                    </div>
                    <span class="shrink-0 rounded-full bg-amber-50 px-2.5 py-1 text-xs font-semibold text-amber-700 dark:bg-amber-500/15 dark:text-amber-300">
                        {{ organization.waiting_label || 'Pending' }}
                    </span>
                </div>

                <dl class="mt-4 grid gap-2.5 text-sm">
                    <div class="flex items-start gap-2.5">
                        <MapPin class="mt-0.5 size-4 shrink-0 text-[#1b7ad8]" />
                        <dd class="text-ink dark:text-sky-50">{{ organization.address || '—' }}</dd>
                    </div>
                    <div class="flex items-start gap-2.5">
                        <Phone class="mt-0.5 size-4 shrink-0 text-[#1b7ad8]" />
                        <dd class="text-ink dark:text-sky-50">{{ organization.contact_number || '—' }}</dd>
                    </div>
                    <div class="flex items-start gap-2.5">
                        <Mail class="mt-0.5 size-4 shrink-0 text-[#1b7ad8]" />
                        <dd class="min-w-0 text-ink dark:text-sky-50">
                            <span class="font-semibold">{{ organization.admin_name || '—' }}</span>
                            <span v-if="organization.admin_email" class="block truncate text-xs text-ink-muted dark:text-slate-400">
                                {{ organization.admin_email }}
                            </span>
                        </dd>
                    </div>
                </dl>

                <div class="mt-5 flex flex-wrap justify-end gap-2 border-t border-[#eef4f9] pt-4 dark:border-white/10">
                    <Link
                        :href="`/platform/organizations/${organization.id}`"
                        class="inline-flex h-9 items-center gap-1.5 rounded-lg border border-[#c5def5] px-3 text-xs font-semibold text-[#1b7ad8] dark:border-white/15 dark:text-sky-200"
                    >
                        <Eye class="size-3.5" />
                        View
                    </Link>
                    <button
                        type="button"
                        class="inline-flex h-9 items-center gap-1.5 rounded-lg bg-rose-50 px-3 text-xs font-semibold text-rose-700 hover:bg-rose-100 dark:bg-rose-500/20 dark:text-rose-200"
                        @click="openReject(organization)"
                    >
                        <X class="size-3.5" />
                        Reject
                    </button>
                    <button
                        type="button"
                        class="inline-flex h-9 items-center gap-1.5 rounded-lg bg-emerald-600 px-3 text-xs font-semibold text-white hover:bg-emerald-700"
                        @click="approving = organization"
                    >
                        <Check class="size-3.5" />
                        Approve
                    </button>
                </div>
            </article>
        </div>
    </div>

    <Dialog :open="approving !== null" @update:open="(open) => { if (!open) approving = null }">
        <DialogContent class="sm:max-w-md">
            <DialogTitle>Approve {{ approving?.name }}?</DialogTitle>
            <DialogDescription>
                {{ approving?.admin_name || 'The barangay admin' }} can sign in and start using SafeSakay right away.
            </DialogDescription>
            <div class="flex justify-end gap-2">
                <Button type="button" variant="outline" class="rounded-xl" @click="approving = null">Cancel</Button>
                <Button type="button" class="rounded-xl bg-emerald-600 text-white hover:bg-emerald-700" :disabled="approveBusy" @click="confirmApprove">
                    <Spinner v-if="approveBusy" class="size-4" />
                    Approve
                </Button>
            </div>
        </DialogContent>
    </Dialog>

    <Dialog :open="rejecting !== null" @update:open="(open) => { if (!open) rejecting = null }">
        <DialogContent class="sm:max-w-md">
            <DialogTitle>Reject {{ rejecting?.name }}?</DialogTitle>
            <DialogDescription>The registration is removed. They can register again later.</DialogDescription>
            <form class="space-y-3" @submit.prevent="confirmReject">
                <div class="grid gap-1.5">
                    <label for="reject_reason" class="text-sm font-medium text-ink dark:text-sky-100">Reason</label>
                    <textarea
                        id="reject_reason"
                        v-model="rejectForm.reason"
                        rows="3"
                        maxlength="500"
                        class="w-full resize-none rounded-xl border border-[#c5def5] px-3 py-2 text-sm text-ink outline-none focus:border-[#1b7ad8] dark:border-white/10 dark:bg-white/5 dark:text-sky-50"
                        placeholder="e.g. Duplicate registration, incomplete details"
                    />
                    <InputError :message="rejectForm.errors.reason" />
                </div>
                <div class="flex justify-end gap-2">
                    <Button type="button" variant="outline" class="rounded-xl" @click="rejecting = null">Cancel</Button>
                    <Button type="submit" class="rounded-xl bg-rose-600 text-white hover:bg-rose-700" :disabled="rejectForm.processing">
                        <Spinner v-if="rejectForm.processing" class="size-4" />
                        Reject
                    </Button>
                </div>
            </form>
        </DialogContent>
    </Dialog>
</template>
