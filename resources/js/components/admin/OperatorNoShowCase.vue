<script setup lang="ts">
import { Link, useForm } from '@inertiajs/vue3';
import { AlertTriangle, Ban, CalendarDays, CheckCircle2, ExternalLink, ShieldAlert, Ship, UserRound, Wallet } from '@lucide/vue';
import { computed, ref } from 'vue';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Spinner } from '@/components/ui/spinner';

export type NoShowCase = {
    id: number;
    stage: 'pending' | 'refund' | 'refunded' | 'rejected';
    stage_label: string;
    report_reason_label: string | null;
    report_note: string | null;
    evidence_photo_url?: string | null;
    admin_notes: string | null;
    reported_at_label: string | null;
    reporter_name: string | null;
    reviewer_name: string | null;
    reviewed_at_label: string | null;
    refund_amount_label: string | null;
    refund_amount_value: number | null;
    refund_status_label: string | null;
    refund_method_label: string | null;
    refund_reference: string | null;
    refund_processed_at_label: string | null;
    refund_receipt_url: string | null;
    refund_processing: boolean;
    can_verify: boolean;
    can_send_xendit_refund: boolean;
    can_record_refund: boolean;
    operator_violation_count?: number;
    operator_violation_max?: number;
    xendit_refund?: { failure_message?: string | null } | null;
    booking: {
        id: number;
        code: string;
        customer_name: string | null;
        contact_number: string | null;
        destination: string | null;
        booking_date_label: string | null;
        start_time_label: string | null;
        boat_name: string | null;
        operator_name: string | null;
        operator_contact_number: string | null;
        passenger_count: number | null;
        down_payment_amount_label: string | null;
    } | null;
};

type Action = 'verify' | 'dismiss' | 'refund_xendit' | 'complete_refund';

const props = withDefaults(
    defineProps<{
        report: NoShowCase;
        canManage: boolean;
        showBookingLink?: boolean;
    }>(),
    { showBookingLink: false },
);

const STAGE_CLASS: Record<NoShowCase['stage'], string> = {
    pending: 'bg-amber-100 text-amber-800 dark:bg-amber-500/15 dark:text-amber-200',
    refund: 'bg-sky-100 text-sky-800 dark:bg-sky-500/15 dark:text-sky-200',
    refunded: 'bg-emerald-100 text-emerald-800 dark:bg-emerald-500/15 dark:text-emerald-200',
    rejected: 'bg-rose-100 text-rose-800 dark:bg-rose-500/15 dark:text-rose-200',
};

const COPY: Record<Action, { title: string; body: string; button: string; danger?: boolean }> = {
    verify: {
        title: 'Verify operator no-show',
        body: 'Confirm the operator did not arrive. The booking becomes Operator No-Show, one violation is added to the operator, and the passenger gets a full refund.',
        button: 'Verify No-Show',
    },
    dismiss: {
        title: 'Reject report',
        body: 'The report is invalid. The booking stays unchanged and the passenger is notified with your reason.',
        button: 'Reject Report',
        danger: true,
    },
    refund_xendit: {
        title: 'Refund through Xendit',
        body: 'Send the full amount back to the GCash account the passenger paid with. The passenger is notified when it is done.',
        button: 'Send Refund',
    },
    complete_refund: {
        title: 'Record refund',
        body: 'Use this after you sent the refund by GCash yourself. Enter the amount, date, and GCash transaction reference.',
        button: 'Record Refund',
    },
};

const dialog = ref<Action | null>(null);
const today = new Date().toISOString().slice(0, 10);
const form = useForm({
    action: '' as Action | '',
    admin_notes: '',
    refund_amount: '' as number | string,
    refunded_at: today,
    refund_reference: '',
    refund_receipt: null as File | null,
    _method: 'patch',
});

const ready = computed(() => {
    switch (dialog.value) {
        case 'verify':
            return form.admin_notes.trim().length >= 3;
        case 'dismiss':
            return form.admin_notes.trim().length >= 5;
        case 'complete_refund':
            return Number(form.refund_amount) > 0 && Boolean(form.refunded_at) && form.refund_reference.trim().length >= 6;
        default:
            return true;
    }
});

const violationLabel = computed(() => {
    const count = props.report.operator_violation_count;

    if (count === undefined) {
        return null;
    }

    return `${count} / ${props.report.operator_violation_max ?? 3} violation${count === 1 ? '' : 's'}`;
});

function open(action: Action) {
    form.reset();
    form.clearErrors();
    form.action = action;
    form.refund_amount = props.report.refund_amount_value ?? '';
    form.refunded_at = today;
    dialog.value = action;
}

function onReceipt(event: Event) {
    form.refund_receipt = (event.target as HTMLInputElement).files?.[0] ?? null;
}

function submit() {
    if (!ready.value) {
        return;
    }

    const url = `/bookings/no-show-reports/${props.report.id}`;
    const options = { preserveScroll: true, onSuccess: () => (dialog.value = null) };

    if (dialog.value === 'complete_refund') {
        form.post(url, { ...options, forceFormData: true });

        return;
    }

    form.transform((data) => ({ action: data.action, admin_notes: data.admin_notes })).patch(url, options);
}
</script>

<template>
    <article class="rounded-[18px] border border-[#d7e5f4] bg-white p-5 shadow-sm dark:border-white/10 dark:bg-[#102036]">
        <!-- Header -->
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div class="min-w-0">
                <h3 class="flex items-center gap-2 text-base font-bold text-[#0F4C8A] dark:text-sky-100">
                    <AlertTriangle class="size-5 text-amber-500" />
                    Operator No-Show
                    <span v-if="report.booking" class="font-semibold text-[#1479E8]">· {{ report.booking.code }}</span>
                </h3>
                <p class="mt-1 text-xs text-ink-muted">
                    Reported {{ report.reported_at_label || '—' }}<span v-if="report.reporter_name"> by {{ report.reporter_name }}</span>
                </p>
            </div>
            <div class="flex items-center gap-2">
                <span class="rounded-full px-3 py-1 text-xs font-semibold" :class="STAGE_CLASS[report.stage]">{{ report.stage_label }}</span>
                <Link
                    v-if="showBookingLink && report.booking"
                    :href="`/bookings/requests/${report.booking.id}`"
                    class="inline-flex items-center gap-1 text-xs font-semibold text-[#1479E8] hover:underline"
                >
                    Booking <ExternalLink class="size-3.5" />
                </Link>
            </div>
        </div>

        <!-- Details -->
        <div class="mt-4 grid gap-4 lg:grid-cols-3">
            <dl class="space-y-2 text-sm">
                <p class="flex items-center gap-1.5 text-xs font-bold uppercase tracking-wide text-ink-muted"><UserRound class="size-3.5" /> Passenger report</p>
                <div><dt class="text-xs text-ink-muted">Passenger</dt><dd class="font-semibold text-ink dark:text-slate-200">{{ report.booking?.customer_name || '—' }}<span v-if="report.booking?.contact_number" class="font-normal text-ink-muted"> · {{ report.booking.contact_number }}</span></dd></div>
                <div><dt class="text-xs text-ink-muted">Reason</dt><dd class="font-semibold text-ink dark:text-slate-200">{{ report.report_reason_label || 'Boat did not arrive' }}</dd></div>
                <div v-if="report.report_note"><dt class="text-xs text-ink-muted">Passenger note</dt><dd class="text-ink dark:text-slate-200">{{ report.report_note }}</dd></div>
                <div v-if="report.evidence_photo_url" class="pt-1">
                    <dt class="text-xs text-ink-muted">Proof photo</dt>
                    <dd class="mt-1.5">
                        <a :href="report.evidence_photo_url" target="_blank" rel="noopener noreferrer" class="block overflow-hidden rounded-xl border border-[#d7e5f4] bg-white dark:border-white/10 dark:bg-white/5">
                            <img :src="report.evidence_photo_url" alt="Passenger no-show proof" class="max-h-56 w-full object-cover" />
                        </a>
                        <a :href="report.evidence_photo_url" target="_blank" rel="noopener noreferrer" class="mt-1.5 inline-block text-xs font-semibold text-[#1479E8] hover:underline">Open full image</a>
                    </dd>
                </div>
            </dl>

            <dl class="space-y-2 text-sm">
                <p class="flex items-center gap-1.5 text-xs font-bold uppercase tracking-wide text-ink-muted"><Ship class="size-3.5" /> Trip &amp; operator</p>
                <div><dt class="text-xs text-ink-muted">Schedule</dt><dd class="flex items-center gap-1 font-semibold text-ink dark:text-slate-200"><CalendarDays class="size-3.5 text-[#1479E8]" />{{ report.booking?.booking_date_label || '—' }} · {{ report.booking?.start_time_label || '—' }}</dd></div>
                <div><dt class="text-xs text-ink-muted">Boat · Operator</dt><dd class="font-semibold text-ink dark:text-slate-200">{{ report.booking?.boat_name || '—' }}<span v-if="report.booking?.operator_name" class="font-normal text-ink-muted"> · {{ report.booking.operator_name }}</span></dd></div>
                <div v-if="violationLabel"><dt class="text-xs text-ink-muted">Operator violations</dt><dd class="flex items-center gap-1 font-semibold text-rose-700 dark:text-rose-300"><ShieldAlert class="size-3.5" />{{ violationLabel }}</dd></div>
            </dl>

            <div class="rounded-xl border border-[#d7e5f4] bg-[#f8fbfe] p-4 text-sm dark:border-white/10 dark:bg-white/5">
                <p class="flex items-center gap-1.5 text-xs font-bold uppercase tracking-wide text-ink-muted"><Wallet class="size-3.5" /> Full refund</p>
                <template v-if="report.stage === 'pending'">
                    <p class="mt-2 text-ink dark:text-slate-200">Verify the report first. Only verified no-shows are refunded.</p>
                </template>
                <template v-else-if="report.stage === 'rejected'">
                    <p class="mt-2 text-ink dark:text-slate-200">No refund — the report was rejected.</p>
                </template>
                <dl v-else class="mt-2 space-y-1">
                    <div class="flex justify-between gap-2"><dt class="text-ink-muted">Amount</dt><dd class="font-bold text-[#0F4C8A] dark:text-sky-100">{{ report.refund_amount_label || '—' }}</dd></div>
                    <div class="flex justify-between gap-2"><dt class="text-ink-muted">Status</dt><dd class="font-semibold">{{ report.stage === 'refunded' ? 'Refunded' : report.refund_processing ? 'Processing (Xendit)' : 'To be refunded' }}</dd></div>
                    <div v-if="report.refund_method_label" class="flex justify-between gap-2"><dt class="text-ink-muted">Method</dt><dd class="font-semibold">{{ report.refund_method_label }}</dd></div>
                    <div v-if="report.refund_processed_at_label" class="flex justify-between gap-2"><dt class="text-ink-muted">Date</dt><dd class="font-semibold">{{ report.refund_processed_at_label }}</dd></div>
                    <div v-if="report.refund_reference" class="flex justify-between gap-2"><dt class="text-ink-muted">Reference</dt><dd class="font-semibold">{{ report.refund_reference }}</dd></div>
                </dl>
                <a v-if="report.refund_receipt_url" :href="report.refund_receipt_url" target="_blank" class="mt-2 inline-block text-xs font-semibold text-[#1479E8] hover:underline">View refund receipt</a>
            </div>
        </div>

        <!-- Admin decision -->
        <p v-if="report.reviewer_name || report.admin_notes" class="mt-4 rounded-lg bg-[#f4f9fd] px-3 py-2 text-sm text-ink dark:bg-white/5 dark:text-slate-200">
            <span class="font-semibold">{{ report.stage === 'rejected' ? 'Rejection reason' : 'Admin notes' }}:</span>
            {{ report.admin_notes || '—' }}
            <span v-if="report.reviewer_name" class="text-ink-muted"> · {{ report.reviewer_name }}, {{ report.reviewed_at_label }}</span>
        </p>
        <p v-if="report.xendit_refund?.failure_message" class="mt-3 rounded-lg bg-rose-50 px-3 py-2 text-sm text-rose-800 dark:bg-rose-500/10 dark:text-rose-200">
            Xendit could not refund: {{ report.xendit_refund.failure_message }}. Retry, or send by GCash and record it.
        </p>

        <!-- Actions -->
        <div v-if="canManage && (report.can_verify || report.can_send_xendit_refund || report.can_record_refund)" class="mt-4 flex flex-wrap gap-2">
            <template v-if="report.can_verify">
                <Button type="button" class="rounded-xl bg-[#1479E8]" @click="open('verify')"><CheckCircle2 class="size-4" /> Verify No-Show</Button>
                <Button type="button" variant="outline" class="rounded-xl border-rose-300 text-rose-700" @click="open('dismiss')"><Ban class="size-4" /> Reject Report</Button>
            </template>
            <Button v-if="report.can_send_xendit_refund" type="button" class="rounded-xl bg-[#16A34A] hover:bg-[#15803d]" @click="open('refund_xendit')">
                <Wallet class="size-4" /> Refund via Xendit
            </Button>
            <Button v-if="report.can_record_refund" type="button" variant="outline" class="rounded-xl" @click="open('complete_refund')">
                <CheckCircle2 class="size-4" /> Record Refund
            </Button>
        </div>
    </article>

    <Dialog :open="dialog !== null" @update:open="(v) => !v && (dialog = null)">
        <DialogContent v-if="dialog" class="max-w-md rounded-2xl">
            <DialogHeader>
                <DialogTitle class="text-[#0F4C8A]">{{ COPY[dialog].title }}</DialogTitle>
                <DialogDescription>{{ COPY[dialog].body }}</DialogDescription>
            </DialogHeader>

            <div v-if="dialog === 'refund_xendit'" class="rounded-xl bg-[#f4f9fd] px-4 py-3 text-sm dark:bg-white/5">
                Amount: <strong>{{ report.refund_amount_label || '—' }}</strong> to {{ report.booking?.customer_name || 'the passenger' }}
            </div>

            <template v-if="dialog === 'complete_refund'">
                <div class="grid grid-cols-2 gap-3">
                    <label class="block text-sm font-medium text-ink dark:text-slate-200">
                        Amount (₱) <span class="text-rose-500">*</span>
                        <input v-model="form.refund_amount" type="number" min="1" step="0.01" class="mt-1 h-10 w-full rounded-xl border border-[#c5def5] px-3 text-sm dark:border-white/10 dark:bg-white/5" />
                    </label>
                    <label class="block text-sm font-medium text-ink dark:text-slate-200">
                        Refund date <span class="text-rose-500">*</span>
                        <input v-model="form.refunded_at" type="date" :max="today" class="mt-1 h-10 w-full rounded-xl border border-[#c5def5] px-3 text-sm dark:border-white/10 dark:bg-white/5" />
                    </label>
                </div>
                <label class="block text-sm font-medium text-ink dark:text-slate-200">
                    GCash transaction reference <span class="text-rose-500">*</span>
                    <input v-model="form.refund_reference" class="mt-1 h-10 w-full rounded-xl border border-[#c5def5] px-3 text-sm dark:border-white/10 dark:bg-white/5" placeholder="e.g. 1012345678901" />
                </label>
                <label class="block text-sm font-medium text-ink dark:text-slate-200">
                    Receipt screenshot (optional)
                    <input type="file" accept="image/*" class="mt-1 block w-full text-sm" @change="onReceipt" />
                </label>
            </template>

            <textarea
                v-if="dialog !== 'refund_xendit'"
                v-model="form.admin_notes"
                rows="3"
                class="w-full rounded-xl border border-[#c5def5] px-3 py-2 text-sm outline-none focus:border-[#1479E8] dark:border-white/10 dark:bg-white/5"
                :placeholder="dialog === 'verify' ? 'Verification notes (required), e.g. Called the operator, no answer.' : dialog === 'dismiss' ? 'Reason for rejecting (required) — the passenger will see this.' : 'Notes (optional)'"
            />
            <p v-for="(message, key) in form.errors" :key="key" class="text-xs text-rose-600">{{ message }}</p>

            <DialogFooter>
                <Button type="button" variant="outline" @click="dialog = null">Cancel</Button>
                <Button
                    type="button"
                    :class="COPY[dialog].danger ? 'bg-rose-600 hover:bg-rose-700' : 'bg-[#16A34A] hover:bg-[#15803d]'"
                    :disabled="form.processing || !ready"
                    @click="submit"
                >
                    <Spinner v-if="form.processing" class="size-4" />
                    {{ COPY[dialog].button }}
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
