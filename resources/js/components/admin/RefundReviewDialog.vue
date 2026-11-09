<script setup lang="ts">
import { Link, useForm } from '@inertiajs/vue3';
import {
    AlertTriangle,
    Ban,
    CalendarDays,
    CheckCircle2,
    Clock,
    CreditCard,
    ExternalLink,
    FileText,
    History,
    Info,
    Layers,
    MapPin,
    RefreshCw,
    RotateCcw,
    Ship,
    Tag,
    User,
    Wallet,
    XCircle,
} from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogTitle } from '@/components/ui/dialog';
import { Spinner } from '@/components/ui/spinner';

export type RefundRecord = {
    id: number;
    reference: string;
    type: string;
    type_label: string;
    status: string;
    status_label: string;
    status_badge_class: string;
    reason_label: string;
    details: string | null;
    refund_amount: number | null;
    refund_amount_label: string | null;
    requested_amount_label: string;
    requested_by: string | null;
    requested_at: string | null;
    requested_at_label: string | null;
    reviewed_by: string | null;
    reviewed_at_label: string | null;
    refunded_at_label: string | null;
    processed_at_label: string | null;
    admin_notes: string | null;
    rejection_reason: string | null;
    failure_message: string | null;
    manual_reference: string | null;
    provider_refund_id: string | null;
    refund_method_label: string;
    is_test_mode: boolean;
    booking: {
        id: number;
        code: string;
        href: string;
        passenger_name: string | null;
        boat_name: string | null;
        operator_name: string | null;
        date_label: string;
        pickup: string | null;
        destination: string | null;
        status_label: string | null;
        incident_label: string | null;
    };
    payment: {
        rental_price_label: string;
        payment_type_label: string;
        amount_paid: number;
        amount_paid_label: string;
        remaining_balance_label: string;
        refunded_label: string;
        status_label?: string;
        max_refundable: number;
        max_refundable_label: string;
        policy_refund: number;
        policy_refund_label: string;
        policy_note: string;
        payment_references: string[];
    };
    timeline: { title: string; detail: string | null; actor: string | null; at_label: string | null }[];
    can_approve: boolean;
    can_reject: boolean;
    can_retry: boolean;
    can_record_manual: boolean;
    can_sync: boolean;
};

const props = defineProps<{ refund: RefundRecord | null; canManage: boolean }>();
const emit = defineEmits<{ close: [] }>();

const mode = ref<'view' | 'approve' | 'reject' | 'confirm' | 'manual'>('view');
const form = useForm({
    action: '',
    approved_amount: '' as number | string,
    admin_notes: '',
    rejection_reason: '',
    manual_reference: '',
    confirm: false,
});

watch(
    () => props.refund?.id,
    () => {
        mode.value = 'view';
        form.reset();
        form.clearErrors();
    },
);

const maxPesos = computed(() => (props.refund ? props.refund.payment.max_refundable / 100 : 0));
const policyPesos = computed(() => (props.refund ? props.refund.payment.policy_refund / 100 : 0));
const approvedPesos = computed(() => Number(form.approved_amount) || 0);
const approveReady = computed(() => approvedPesos.value >= 1 && approvedPesos.value <= maxPesos.value && form.admin_notes.trim().length >= 5);
const peso = (value: number) => `₱${value.toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
const route = computed(() => [props.refund?.booking.pickup, props.refund?.booking.destination].filter(Boolean).join(' → ') || '—');
const hasActions = computed(() => {
    const refund = props.refund;

    return Boolean(props.canManage && refund && (refund.can_approve || refund.can_reject || refund.can_retry || refund.can_sync || refund.can_record_manual));
});

const refundAmount = computed(() => {
    const refund = props.refund;

    if (!refund) {
        return { label: '', value: '—' };
    }

    switch (refund.status) {
        case 'pending_review':
            return { label: 'Refund Amount (Suggested)', value: refund.payment.policy_refund_label };
        case 'rejected':
            return { label: 'Refund Amount', value: '₱0.00' };
        case 'refunded':
            return { label: 'Refunded Amount', value: refund.refund_amount_label ?? '—' };
        default:
            return { label: 'Refund Amount (Approved)', value: refund.refund_amount_label ?? '—' };
    }
});

const panel = computed(() => {
    switch (props.refund?.status) {
        case 'pending_review':
            return { tone: 'border-amber-200 bg-amber-50/80 dark:border-amber-500/30 dark:bg-amber-500/10', icon: Info, iconTone: 'bg-amber-100 text-amber-600', title: 'Waiting for your decision' };
        case 'refunding':
            return { tone: 'border-sky-200 bg-sky-50/80 dark:border-sky-500/30 dark:bg-sky-500/10', icon: Clock, iconTone: 'bg-sky-100 text-sky-600', title: 'Sent to Xendit' };
        case 'failed':
            return { tone: 'border-rose-200 bg-rose-50/80 dark:border-rose-500/30 dark:bg-rose-500/10', icon: AlertTriangle, iconTone: 'bg-rose-100 text-rose-600', title: 'Refund did not go through' };
        case 'refunded':
            return { tone: 'border-emerald-200 bg-emerald-50/80 dark:border-emerald-500/30 dark:bg-emerald-500/10', icon: CheckCircle2, iconTone: 'bg-emerald-100 text-emerald-600', title: 'Refund completed' };
        case 'rejected':
            return { tone: 'border-rose-200 bg-rose-50/80 dark:border-rose-500/30 dark:bg-rose-500/10', icon: Ban, iconTone: 'bg-rose-100 text-rose-600', title: 'Refund rejected' };
        default:
            return { tone: 'border-[#d7e5f4] bg-[#f5f9ff] dark:border-white/10 dark:bg-white/5', icon: Info, iconTone: 'bg-[#e8f1ff] text-[#1479E8]', title: props.refund?.status_label ?? '' };
    }
});

const panelBody = computed(() => {
    const refund = props.refund;

    if (!refund) {
        return '';
    }

    switch (refund.status) {
        case 'pending_review':
            return refund.payment.policy_note;
        case 'refunding':
            return 'Waiting for Xendit to confirm. Press Check Status to refresh.';
        case 'failed':
            return `${refund.failure_message ? `${refund.failure_message}. ` : ''}Retry it, or send it by GCash yourself and record it.`;
        case 'refunded':
            return `${refund.refund_amount_label ?? ''} returned ${refund.manual_reference ? `by GCash (ref ${refund.manual_reference})` : 'through Xendit'}${refund.refunded_at_label ? ` on ${refund.refunded_at_label}` : ''}.`;
        case 'rejected':
            return `${refund.reviewed_by ? `By ${refund.reviewed_by}. ` : ''}${refund.rejection_reason ?? ''}`;
        default:
            return '';
    }
});

const paymentStatusTone = computed(() => {
    switch (props.refund?.payment.status_label) {
        case 'Refunded':
            return 'bg-sky-100 text-sky-700';
        case 'Partially Refunded':
            return 'bg-amber-100 text-amber-700';
        default:
            return 'bg-emerald-100 text-emerald-700';
    }
});

function startApprove() {
    if (!props.refund) {
        return;
    }

    form.clearErrors();
    form.approved_amount = policyPesos.value > 0 ? policyPesos.value : maxPesos.value;
    mode.value = 'approve';
}

function submit(action: 'approve' | 'reject' | 'retry' | 'sync' | 'record_manual') {
    if (!props.refund) {
        return;
    }

    form.action = action;
    form.confirm = action === 'approve';
    form.transform((data) => ({
        action: data.action,
        ...(action === 'approve' ? { approved_amount: data.approved_amount, admin_notes: data.admin_notes, confirm: 1 } : {}),
        ...(action === 'reject' ? { rejection_reason: data.rejection_reason } : {}),
        ...(action === 'record_manual' ? { manual_reference: data.manual_reference, admin_notes: data.admin_notes || null } : {}),
    })).patch(`/bookings/refund-requests/${props.refund.id}`, {
        preserveScroll: true,
        onSuccess: () => {
            mode.value = 'view';
            form.reset();
        },
        onError: () => {
            if (action === 'approve') {
                mode.value = 'approve';
            }
        },
    });
}
</script>

<template>
    <Dialog :open="refund !== null" @update:open="(open) => !open && emit('close')">
        <DialogContent
            v-if="refund"
            class="flex max-h-[94vh] w-[calc(100vw-2rem)] max-w-4xl flex-col gap-0 overflow-hidden rounded-3xl border border-[#e3ecf6] p-0 shadow-2xl sm:max-w-4xl dark:border-white/10"
        >
            <!-- Header -->
            <header class="relative overflow-hidden border-b border-[#e8f0f8] bg-gradient-to-br from-[#f3f8ff] via-white to-[#eaf3ff] px-6 py-5 pr-14 sm:px-8 dark:border-white/10 dark:from-[#0f1d33] dark:via-[#102036] dark:to-[#0f1d33]">
                <div class="flex flex-wrap items-center gap-x-6 gap-y-4">
                    <div class="flex min-w-0 flex-1 items-center gap-4">
                        <div class="flex size-14 shrink-0 items-center justify-center rounded-2xl bg-[#e3efff] text-[#1479E8] shadow-sm dark:bg-white/10">
                            <RefreshCw class="size-7" />
                        </div>
                        <div class="min-w-0">
                            <DialogTitle class="text-xl font-bold text-[#0F3B73] sm:text-2xl dark:text-sky-100">Refund Request</DialogTitle>
                            <p class="mt-0.5 text-base font-semibold text-[#2d4a6b] dark:text-slate-200">Refund {{ refund.reference }}</p>
                            <DialogDescription class="mt-0.5 truncate text-sm text-[#6b819a]">
                                Related Rental: {{ refund.booking.code }}<span v-if="refund.booking.passenger_name"> ({{ refund.booking.passenger_name }})</span>
                            </DialogDescription>
                        </div>
                    </div>
                    <div class="flex flex-wrap items-center gap-2 sm:border-l sm:border-[#dbe7f3] sm:pl-6 dark:sm:border-white/10">
                        <span class="inline-flex items-center gap-1.5 rounded-full px-3.5 py-1.5 text-sm font-semibold" :class="refund.status_badge_class">
                            <component :is="panel.icon" class="size-4" /> {{ refund.status_label }}
                        </span>
                    </div>
                </div>
            </header>

            <div class="flex-1 space-y-5 overflow-y-auto bg-[#fafcff] px-6 py-6 sm:px-8 dark:bg-[#0d1a2d]">
                <!-- Payment Summary -->
                <section class="overflow-hidden rounded-2xl border border-[#dfe9f4] bg-white shadow-sm dark:border-white/10 dark:bg-[#102036]">
                    <h3 class="flex items-center gap-2 border-b border-[#eef3f9] bg-[#f6f9fd] px-5 py-3 text-base font-bold text-[#0F3B73] dark:border-white/10 dark:bg-white/5 dark:text-sky-100">
                        <CreditCard class="size-5 text-[#1479E8]" /> Payment Summary
                    </h3>
                    <div class="grid gap-3 p-4 sm:grid-cols-3 sm:gap-0 sm:divide-x sm:divide-[#e6eef7] dark:sm:divide-white/10">
                        <div class="px-3 py-2">
                            <p class="text-sm text-[#6b819a]">Total Rental Amount</p>
                            <p class="mt-1 text-2xl font-bold text-[#0F2A4A] tabular-nums dark:text-slate-100">{{ refund.payment.rental_price_label }}</p>
                            <p class="mt-0.5 text-sm text-[#6b819a]">{{ refund.payment.payment_type_label }}</p>
                        </div>
                        <div class="px-3 py-2 sm:px-6">
                            <p class="text-sm text-[#6b819a]">Amount Paid</p>
                            <p class="mt-1 text-2xl font-bold text-[#0F2A4A] tabular-nums dark:text-slate-100">{{ refund.payment.amount_paid_label }}</p>
                        </div>
                        <div class="rounded-xl bg-[#eef5ff] px-4 py-3 sm:ml-4 dark:bg-sky-500/10">
                            <p class="text-sm text-[#4b6a8f] dark:text-sky-200">{{ refundAmount.label }}</p>
                            <p class="mt-1 text-2xl font-bold text-[#1479E8] tabular-nums">{{ refundAmount.value }}</p>
                        </div>
                    </div>
                </section>

                <!-- Decision panel -->
                <section class="rounded-2xl border p-5" :class="panel.tone">
                    <div v-if="mode === 'view'" class="flex flex-col gap-4 md:flex-row md:items-center">
                        <div class="flex flex-1 items-start gap-3">
                            <span class="flex size-9 shrink-0 items-center justify-center rounded-full" :class="panel.iconTone">
                                <component :is="panel.icon" class="size-5" />
                            </span>
                            <div>
                                <p class="text-base font-bold text-[#0F2A4A] dark:text-slate-100">{{ panel.title }}</p>
                                <p class="mt-1 text-sm leading-relaxed text-[#4a5f78] dark:text-slate-300">{{ panelBody }}</p>
                                <p v-if="refund.admin_notes" class="mt-1 text-xs text-[#6b819a]">Admin remark: {{ refund.admin_notes }}</p>
                            </div>
                        </div>
                        <div v-if="hasActions" class="flex flex-wrap gap-2 md:border-l md:border-black/5 md:pl-5 dark:md:border-white/10">
                            <Button v-if="refund.can_approve" type="button" class="h-11 rounded-xl bg-[#16A34A] px-5 text-sm font-semibold hover:bg-[#15803d]" @click="startApprove">
                                <CheckCircle2 class="size-4" /> Approve Refund
                            </Button>
                            <Button v-if="refund.can_reject" type="button" variant="outline" class="h-11 rounded-xl border-rose-300 bg-white px-5 text-sm font-semibold text-rose-600 hover:bg-rose-50 dark:bg-[#102036] dark:text-slate-100 dark:border-white/10" @click="mode = 'reject'">
                                <XCircle class="size-4" /> Reject
                            </Button>
                            <Button v-if="refund.can_retry" type="button" class="h-11 rounded-xl bg-[#1479E8] px-5" :disabled="form.processing" @click="submit('retry')">
                                <RotateCcw class="size-4" /> Retry Refund
                            </Button>
                            <Button v-if="refund.can_sync" type="button" variant="outline" class="h-11 rounded-xl bg-white px-5 dark:bg-[#102036] dark:text-slate-100" :disabled="form.processing" @click="submit('sync')">
                                <Spinner v-if="form.processing" class="size-4" />
                                <RefreshCw v-else class="size-4" />
                                Check Status
                            </Button>
                            <Button v-if="refund.can_record_manual" type="button" variant="outline" class="h-11 rounded-xl bg-white px-5 dark:bg-[#102036] dark:text-slate-100" @click="mode = 'manual'">
                                <Wallet class="size-4" /> Record Manual Refund
                            </Button>
                        </div>
                    </div>

                    <form v-else-if="mode === 'approve'" class="space-y-3" @submit.prevent="approveReady && (mode = 'confirm')">
                        <p class="text-base font-bold text-[#0F2A4A] dark:text-slate-100">How much to refund?</p>
                        <div class="flex flex-wrap gap-2">
                            <button
                                v-for="option in [
                                    { label: 'Suggested', value: policyPesos },
                                    { label: 'Full amount', value: maxPesos },
                                ].filter((item, index, list) => item.value > 0 && list.findIndex((other) => other.value === item.value) === index)"
                                :key="option.label"
                                type="button"
                                class="rounded-full border px-3 py-1 text-xs font-semibold"
                                :class="approvedPesos === option.value ? 'border-[#1479E8] bg-[#1479E8] text-white' : 'border-[#c5def5] bg-white text-[#1479E8] hover:bg-[#eef6ff] dark:bg-[#102036] dark:text-slate-100 dark:border-white/10'"
                                @click="form.approved_amount = option.value"
                            >
                                {{ option.label }} · {{ peso(option.value) }}
                            </button>
                        </div>
                        <div class="grid gap-3 sm:grid-cols-2">
                            <div class="relative">
                                <span class="pointer-events-none absolute top-1/2 left-3 -translate-y-1/2 text-sm font-semibold text-[#6b819a]">₱</span>
                                <input v-model="form.approved_amount" type="number" min="1" :max="maxPesos" step="0.01" class="h-11 w-full rounded-xl border border-[#c5def5] bg-white pr-3 pl-7 text-sm dark:border-white/10 dark:bg-white/5" />
                                <p class="mt-1 text-xs text-[#6b819a]">Maximum {{ refund.payment.max_refundable_label }}</p>
                            </div>
                            <textarea v-model="form.admin_notes" rows="2" class="w-full rounded-xl border border-[#c5def5] bg-white px-3 py-2 text-sm dark:border-white/10 dark:bg-white/5" placeholder="Remark (required), e.g. Downpayment kept per policy" />
                        </div>
                        <p v-for="(message, key) in form.errors" :key="key" class="text-xs text-rose-600">{{ message }}</p>
                        <div class="flex justify-end gap-2">
                            <Button type="button" variant="outline" class="rounded-xl bg-white dark:bg-[#102036] dark:text-slate-100" @click="mode = 'view'">Back</Button>
                            <Button type="submit" class="rounded-xl bg-[#16A34A] hover:bg-[#15803d]" :disabled="!approveReady">Continue</Button>
                        </div>
                    </form>

                    <div v-else-if="mode === 'confirm'" class="space-y-3">
                        <p class="text-base font-bold text-[#0F2A4A] dark:text-slate-100">
                            Refund <span class="text-[#16A34A]">{{ peso(approvedPesos) }}</span> to {{ refund.booking.passenger_name || 'the passenger' }} through Xendit?
                        </p>
                        <div class="flex justify-end gap-2">
                            <Button type="button" variant="outline" class="rounded-xl bg-white dark:bg-[#102036] dark:text-slate-100" :disabled="form.processing" @click="mode = 'approve'">Back</Button>
                            <Button type="button" class="rounded-xl bg-[#16A34A] hover:bg-[#15803d]" :disabled="form.processing" @click="submit('approve')">
                                <Spinner v-if="form.processing" class="size-4" />
                                Approve &amp; Refund
                            </Button>
                        </div>
                    </div>

                    <form v-else-if="mode === 'reject'" class="space-y-3" @submit.prevent="submit('reject')">
                        <p class="text-base font-bold text-[#0F2A4A] dark:text-slate-100">Why is this refund rejected?</p>
                        <textarea v-model="form.rejection_reason" rows="3" class="w-full rounded-xl border border-[#c5def5] bg-white px-3 py-2 text-sm dark:border-white/10 dark:bg-white/5" placeholder="The passenger will see this reason" />
                        <p v-for="(message, key) in form.errors" :key="key" class="text-xs text-rose-600">{{ message }}</p>
                        <div class="flex justify-end gap-2">
                            <Button type="button" variant="outline" class="rounded-xl bg-white dark:bg-[#102036] dark:text-slate-100" @click="mode = 'view'">Back</Button>
                            <Button type="submit" class="rounded-xl bg-rose-600 hover:bg-rose-700" :disabled="form.processing || form.rejection_reason.trim().length < 5">
                                <Spinner v-if="form.processing" class="size-4" />
                                Reject Refund
                            </Button>
                        </div>
                    </form>

                    <form v-else-if="mode === 'manual'" class="space-y-3" @submit.prevent="submit('record_manual')">
                        <p class="text-base font-bold text-[#0F2A4A] dark:text-slate-100">Record a refund you sent by GCash</p>
                        <input v-model="form.manual_reference" class="h-11 w-full rounded-xl border border-[#c5def5] bg-white px-3 text-sm dark:border-white/10 dark:bg-white/5" placeholder="GCash reference number" />
                        <p v-for="(message, key) in form.errors" :key="key" class="text-xs text-rose-600">{{ message }}</p>
                        <div class="flex justify-end gap-2">
                            <Button type="button" variant="outline" class="rounded-xl bg-white dark:bg-[#102036] dark:text-slate-100" @click="mode = 'view'">Back</Button>
                            <Button type="submit" class="rounded-xl bg-[#1479E8]" :disabled="form.processing || form.manual_reference.trim().length < 6">Record Refund</Button>
                        </div>
                    </form>
                </section>

                <!-- Details grid -->
                <div class="grid gap-5 md:grid-cols-2">
                    <div class="space-y-5">
                        <section class="overflow-hidden rounded-2xl border border-[#dfe9f4] bg-white shadow-sm dark:border-white/10 dark:bg-[#102036]">
                            <h3 class="flex items-center gap-2 border-b border-[#eef3f9] bg-[#f6f9fd] px-5 py-3 text-base font-bold text-[#0F3B73] dark:border-white/10 dark:bg-white/5 dark:text-sky-100">
                                <FileText class="size-5 text-[#1479E8]" /> Refund Request Details
                            </h3>
                            <dl class="divide-y divide-[#eef3f9] px-5 dark:divide-white/10">
                                <div class="flex items-center gap-3 py-3 text-sm">
                                    <User class="size-4 shrink-0 text-[#7d93a8]" />
                                    <dt class="w-28 shrink-0 text-[#6b819a]">Passenger</dt>
                                    <dd class="font-semibold text-[#0F2A4A] dark:text-slate-100">{{ refund.booking.passenger_name || '—' }}</dd>
                                </div>
                                <div class="flex items-center gap-3 py-3 text-sm">
                                    <CalendarDays class="size-4 shrink-0 text-[#7d93a8]" />
                                    <dt class="w-28 shrink-0 text-[#6b819a]">Submitted On</dt>
                                    <dd class="font-semibold text-[#0F2A4A] dark:text-slate-100">{{ refund.requested_at_label || '—' }}</dd>
                                </div>
                                <div class="flex items-start gap-3 py-3 text-sm">
                                    <Tag class="mt-0.5 size-4 shrink-0 text-[#7d93a8]" />
                                    <dt class="w-28 shrink-0 text-[#6b819a]">Reason</dt>
                                    <dd class="font-semibold text-[#0F2A4A] dark:text-slate-100">
                                        {{ refund.reason_label }}
                                        <span v-if="refund.details" class="mt-0.5 block font-normal text-[#4a5f78] dark:text-slate-300">“{{ refund.details }}”</span>
                                    </dd>
                                </div>
                            </dl>
                        </section>

                        <section class="overflow-hidden rounded-2xl border border-[#dfe9f4] bg-white shadow-sm dark:border-white/10 dark:bg-[#102036]">
                            <h3 class="flex items-center gap-2 border-b border-[#eef3f9] bg-[#f6f9fd] px-5 py-3 text-base font-bold text-[#0F3B73] dark:border-white/10 dark:bg-white/5 dark:text-sky-100">
                                <Ship class="size-5 text-[#1479E8]" /> Rental / Trip Information
                            </h3>
                            <dl class="divide-y divide-[#eef3f9] px-5 dark:divide-white/10">
                                <div class="flex items-center gap-3 py-3 text-sm">
                                    <Ship class="size-4 shrink-0 text-[#7d93a8]" />
                                    <dt class="w-28 shrink-0 text-[#6b819a]">Boat</dt>
                                    <dd class="font-semibold text-[#0F2A4A] uppercase dark:text-slate-100">
                                        {{ refund.booking.boat_name || '—' }}<span v-if="refund.booking.operator_name"> – {{ refund.booking.operator_name }}</span>
                                    </dd>
                                </div>
                                <div class="flex items-center gap-3 py-3 text-sm">
                                    <MapPin class="size-4 shrink-0 text-[#7d93a8]" />
                                    <dt class="w-28 shrink-0 text-[#6b819a]">Route</dt>
                                    <dd class="font-semibold text-[#0F2A4A] dark:text-slate-100">{{ route }}</dd>
                                </div>
                                <div class="flex items-center gap-3 py-3 text-sm">
                                    <CalendarDays class="size-4 shrink-0 text-[#7d93a8]" />
                                    <dt class="w-28 shrink-0 text-[#6b819a]">Trip Date</dt>
                                    <dd class="font-semibold text-[#0F2A4A] dark:text-slate-100">{{ refund.booking.date_label || '—' }}</dd>
                                </div>
                            </dl>
                        </section>
                    </div>

                    <section class="overflow-hidden rounded-2xl border border-[#dfe9f4] bg-white shadow-sm dark:border-white/10 dark:bg-[#102036]">
                        <h3 class="flex items-center gap-2 border-b border-[#eef3f9] bg-[#f6f9fd] px-5 py-3 text-base font-bold text-[#0F3B73] dark:border-white/10 dark:bg-white/5 dark:text-sky-100">
                            <History class="size-5 text-[#1479E8]" /> Request History
                        </h3>
                        <ol class="space-y-0 px-5 py-4">
                            <li v-for="(item, index) in refund.timeline" :key="index" class="relative flex gap-3 pb-4 last:pb-0">
                                <span v-if="index < refund.timeline.length - 1" class="absolute top-5 left-[7px] h-full w-px bg-[#d7e5f4] dark:bg-white/10" />
                                <span class="relative mt-1 size-4 shrink-0 rounded-full border-[3px] border-[#1479E8] bg-white dark:bg-[#102036]" />
                                <div class="min-w-0">
                                    <p class="text-sm font-bold text-[#0F3B73] dark:text-sky-100">{{ item.title }}</p>
                                    <p v-if="item.detail || item.actor" class="text-sm text-[#4b7ab8] dark:text-sky-300">
                                        {{ item.detail }}<span v-if="item.detail && item.actor"> · </span>{{ item.actor }}
                                    </p>
                                    <p v-if="item.at_label" class="text-sm text-[#6b819a]">{{ item.at_label }}</p>
                                </div>
                            </li>
                            <li v-if="!refund.timeline.length" class="text-sm text-[#6b819a]">No history yet.</li>
                        </ol>

                        <div class="mx-4 mb-4 overflow-hidden rounded-xl border border-[#dfe9f4] dark:border-white/10">
                            <h4 class="flex items-center gap-2 border-b border-[#eef3f9] bg-[#f6f9fd] px-4 py-2.5 text-sm font-bold text-[#0F3B73] dark:border-white/10 dark:bg-white/5 dark:text-sky-100">
                                <Layers class="size-4 text-[#1479E8]" /> Xendit References
                            </h4>
                            <dl class="divide-y divide-[#eef3f9] px-4 text-sm dark:divide-white/10">
                                <div class="flex items-center justify-between gap-3 py-2.5">
                                    <dt class="text-[#6b819a]">Payment Reference</dt>
                                    <dd class="font-mono text-xs break-all text-[#0F2A4A] dark:text-slate-200">{{ refund.payment.payment_references.join(', ') || '—' }}</dd>
                                </div>
                                <div class="flex items-center justify-between gap-3 py-2.5">
                                    <dt class="text-[#6b819a]">Refund Reference</dt>
                                    <dd class="text-right font-mono text-xs break-all text-[#0F2A4A] dark:text-slate-200">{{ refund.provider_refund_id || refund.reference }}</dd>
                                </div>
                                <div class="flex items-center justify-between gap-3 py-2.5">
                                    <dt class="text-[#6b819a]">Payment Channel</dt>
                                    <dd class="font-semibold text-[#0F2A4A] dark:text-slate-200">{{ refund.manual_reference ? 'Manual GCash' : 'GCash' }}</dd>
                                </div>
                                <div class="flex items-center justify-between gap-3 py-2.5">
                                    <dt class="text-[#6b819a]">Payment Status</dt>
                                    <dd>
                                        <span class="inline-flex items-center gap-1 rounded-full px-2.5 py-0.5 text-xs font-semibold" :class="paymentStatusTone">
                                            <CheckCircle2 class="size-3.5" /> {{ refund.payment.status_label || 'Paid' }}
                                        </span>
                                    </dd>
                                </div>
                            </dl>
                        </div>
                    </section>
                </div>
            </div>

            <!-- Footer -->
            <footer class="flex items-center justify-between gap-3 border-t border-[#e8f0f8] bg-white px-6 py-4 sm:px-8 dark:border-white/10 dark:bg-[#102036]">
                <Link
                    :href="refund.booking.href"
                    class="inline-flex h-11 items-center gap-2 rounded-xl border border-[#1479E8] px-5 text-sm font-semibold text-[#1479E8] transition hover:bg-[#eef6ff] dark:hover:bg-white/5"
                >
                    <ExternalLink class="size-4" /> Open Rental
                </Link>
                <Button type="button" variant="outline" class="h-11 rounded-xl px-6 text-sm font-semibold" @click="emit('close')">Close</Button>
            </footer>
        </DialogContent>
    </Dialog>
</template>
