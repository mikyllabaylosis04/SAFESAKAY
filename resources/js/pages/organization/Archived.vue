<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

const props = defineProps<{
    organization: {
        id: number;
        name: string;
        status_label: string;
        archived_at_label: string | null;
        archived_reason: string | null;
        reactivation_requested: boolean;
        reactivation_reason: string | null;
        reactivation_details?: string | null;
        reactivation_requested_at_label: string | null;
        reactivation_reasons?: string[];
    };
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Archived Organization', href: '/organization/archived' }],
    },
});

const open = ref(false);
const reason = ref('');
const specifyReason = ref('');
const details = ref('');
const error = ref('');
const processing = ref(false);
const otherReason = 'Other reason';
const isOtherReason = computed(() => reason.value === otherReason);
const reactivationReasons = computed(
    () => props.organization.reactivation_reasons ?? [
        'Resuming pump boat operations',
        'Ready to use SafeSakay again',
        'Organization operations have resumed',
        'Financial or subscription concerns resolved',
        'Returning from another management system',
        'Other reason',
    ],
);
const canSubmit = computed(() => {
    if (processing.value || !reason.value) {
        return false;
    }

    return !isOtherReason.value || specifyReason.value.trim().length >= 3;
});

function openModal() {
    reason.value = '';
    specifyReason.value = '';
    details.value = '';
    error.value = '';
    open.value = true;
}

function submit() {
    if (!canSubmit.value) {
        error.value = isOtherReason.value
            ? 'Specify the reason for reactivation.'
            : 'Select a reason for reactivation.';
        return;
    }

    processing.value = true;
    router.post(
        '/organization/reactivate',
        {
            reason: reason.value,
            specify_reason: isOtherReason.value ? specifyReason.value.trim() : null,
            details: details.value.trim() || null,
        },
        {
            preserveScroll: true,
            onFinish: () => {
                processing.value = false;
            },
            onSuccess: () => {
                open.value = false;
            },
            onError: (errors) => {
                error.value = errors.reason || errors.specify_reason || errors.details || 'Could not send this request.';
            },
        },
    );
}
</script>

<template>
    <Head title="Archived Organization" />

    <div class="mx-auto flex w-full max-w-xl flex-1 flex-col justify-center gap-5 p-6">
        <section class="rounded-2xl border border-slate-200 bg-slate-50 p-5 dark:border-white/10 dark:bg-white/5">
            <p class="text-xs font-semibold uppercase tracking-wide text-slate-600 dark:text-slate-300">Archived Organization</p>
            <h1 class="mt-1 text-2xl font-bold text-[#0f4c8a] dark:text-sky-100">{{ props.organization.name }}</h1>
            <p class="mt-2 text-sm text-ink dark:text-sky-100/80">
                This barangay is archived. Boats, rentals, payments, and reports stay saved, but operational modules are locked until Super Admin approves reactivation.
            </p>
            <p class="mt-3 text-sm text-ink-muted">
                Status: {{ props.organization.status_label }}
                <span v-if="props.organization.archived_at_label"> · {{ props.organization.archived_at_label }}</span>
            </p>
            <p v-if="props.organization.archived_reason" class="mt-1 text-sm text-ink-muted">
                {{ props.organization.archived_reason }}
            </p>
        </section>

        <section class="rounded-2xl border border-[#d7e8f6] bg-white p-5 dark:border-white/10 dark:bg-white/5">
            <h2 class="font-semibold text-[#0f4c8a] dark:text-sky-100">Request Reactivation</h2>
            <p v-if="props.organization.reactivation_requested" class="mt-2 text-sm text-amber-800">
                Super Admin is reviewing your request
                <span v-if="props.organization.reactivation_requested_at_label">
                    ({{ props.organization.reactivation_requested_at_label }})
                </span>.
            </p>
            <p v-else class="mt-2 text-sm text-ink dark:text-sky-100/80">
                Ask Super Admin to restore this organization if your barangay is ready to use SafeSakay again.
            </p>
            <Button
                v-if="!props.organization.reactivation_requested"
                type="button"
                class="mt-4 bg-[#1b7ad8] hover:bg-[#1567c4]"
                @click="openModal"
            >
                Request Reactivation
            </Button>
        </section>
    </div>

    <Dialog :open="open" @update:open="(value) => (open = value)">
        <DialogContent class="flex max-h-[90vh] w-full max-w-[calc(100%-1.5rem)] flex-col gap-0 overflow-hidden rounded-2xl border-none p-0 shadow-2xl sm:max-w-md">
            <div class="shrink-0 bg-[#1b7ad8] px-5 py-4 text-white">
                <DialogTitle class="text-lg font-semibold text-white">Request Reactivation</DialogTitle>
                <DialogDescription class="text-sm text-white/80">
                    Super Admin will review this before access is restored.
                </DialogDescription>
            </div>
            <div class="min-h-0 space-y-4 overflow-y-auto bg-white p-5 dark:bg-background">
                <div class="grid gap-1.5">
                    <Label for="reactivation-reason">Reason for Reactivation</Label>
                    <select
                        id="reactivation-reason"
                        v-model="reason"
                        required
                        class="h-10 w-full rounded-xl border border-[#c5def5] bg-[#f7fbfe] px-3 text-sm text-ink outline-none focus:border-[#1b7ad8] focus:ring-2 focus:ring-[#1b7ad8]/20 dark:border-white/10 dark:bg-white/5 dark:text-sky-50"
                    >
                        <option disabled value="">Select reason for reactivation</option>
                        <option v-for="option in reactivationReasons" :key="option" :value="option">
                            {{ option }}
                        </option>
                    </select>
                </div>
                <div v-if="isOtherReason" class="grid gap-1.5">
                    <Label for="reactivation-specify-reason">Specify Reason</Label>
                    <Input
                        id="reactivation-specify-reason"
                        v-model="specifyReason"
                        maxlength="160"
                        required
                        placeholder="Type the reason for reactivation"
                    />
                </div>
                <div class="grid gap-1.5">
                    <Label for="reactivation-details">Additional Details</Label>
                    <textarea
                        id="reactivation-details"
                        v-model="details"
                        rows="4"
                        maxlength="1000"
                        class="w-full rounded-xl border border-[#c5def5] bg-[#f7fbfe] px-3 py-2 text-sm text-ink outline-none focus:border-[#1b7ad8] focus:ring-2 focus:ring-[#1b7ad8]/20 dark:bg-[#0b1b30] dark:text-slate-100"
                        placeholder="Optional notes for Super Admin"
                    />
                </div>
                <p v-if="error" class="text-xs text-[#d45b5b]">{{ error }}</p>
                <div class="flex justify-end gap-3">
                    <Button type="button" variant="outline" :disabled="processing" @click="open = false">Cancel</Button>
                    <Button
                        type="button"
                        class="bg-[#1b7ad8] hover:bg-[#1567c4]"
                        :disabled="!canSubmit"
                        @click="submit"
                    >
                        Submit Request
                    </Button>
                </div>
            </div>
        </DialogContent>
    </Dialog>
</template>
