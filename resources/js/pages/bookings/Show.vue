<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import {
    AlertTriangle,
    ArrowLeft,
    CalendarDays,
    Check,
    CheckCircle2,
    ChevronDown,
    Clock,
    CloudRain,
    Coins,
    CreditCard,
    FileText,
    History,
    Hourglass,
    MapPin,
    Phone,
    ShieldCheck,
    Ship,
    Star,
    StickyNote,
    Tag,
    UserRound,
    Users,
    Wallet,
    X,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import RentalIncidentPanels from '@/components/admin/RentalIncidentPanels.vue';
import type { NoShowReport, PassengerNoShowRecord } from '@/components/admin/RentalIncidentPanels.vue';
import RentalRefundPanel from '@/components/admin/RentalRefundPanel.vue';
import type { RefundRequest } from '@/components/admin/RentalRefundPanel.vue';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Spinner } from '@/components/ui/spinner';
import type { BoatBookingRecord } from '@/types/booking';

const props = defineProps<{
    booking: BoatBookingRecord;
    no_show_report?: NoShowReport | null;
    passenger_no_show_report?: PassengerNoShowRecord | null;
    refund_request?: RefundRequest | null;
    can_manage?: boolean;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Rental Requests', href: '/bookings/requests' },
            { title: 'Rental Details', href: '/bookings/requests' },
        ],
    },
});

const PAYMENT_REJECT_REASONS = [
    'Invalid or fake receipt',
    'Incorrect amount',
    'No matching transaction',
    'Payment to wrong account',
    'Unclear receipt',
    'Other',
] as const;

const cancelOpen = ref(false);
const approveOpen = ref(false);
const rejectOpen = ref(false);
const rejectChoice = ref('');
const rejectNotes = ref('');
const rejectLocalError = ref('');
const cancelForm = useForm({
    action: 'reject',
    cancellation_reason: '',
});
const approveForm = useForm({
    action: 'approve',
});
const verifyForm = useForm({
    confirmed_gcash_transaction: false,
});
const rejectForm = useForm({
    reason: '',
});
const rescheduleForm = useForm({
    action: 'approve' as 'approve' | 'reject',
    rejection_reason: '',
});

const payment = computed(() => props.booking.payment);

function numeric(value: unknown): number | null {
    if (value === null || value === undefined || value === '') {
        return null;
    }

    if (typeof value === 'number') {
        return Number.isFinite(value) ? value : null;
    }

    const cleaned = String(value).replace(/,/g, '').replace(/[^\d.-]/g, '');

    if (!cleaned) {
        return null;
    }

    const parsed = Number(cleaned);

    return Number.isFinite(parsed) ? parsed : null;
}

function peso(value: number | null): string {
    if (value === null) {
        return '—';
    }

    return new Intl.NumberFormat('en-PH', {
        style: 'currency',
        currency: 'PHP',
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    }).format(value);
}

const weatherRescheduleAlert = computed(() => {
    const booking = props.booking;

    if (booking.status === 'weather_hold' && !booking.requested_booking_date_label) {
    return {
            title: 'Trip Cannot Proceed (Weather Hold)',
            message: `The scheduled trip on ${booking.original_booking_date_label ?? booking.booking_date_label} is canceled due to bad weather. The passenger must pick a new date. Verified down payment stays with this rental.`,
        };
    }

    if (booking.can_review_reschedule && booking.requested_booking_date_label) {
        return {
            title: 'Passenger requested a new date',
            message: `Original trip ${booking.original_booking_date_label ?? booking.booking_date_label} → requested ${booking.requested_booking_date_label}. Down payment stays with this rental. Review below.`,
        };
    }

    return null;
});

function confirmReschedule(action: 'approve' | 'reject') {
    rescheduleForm.action = action;
    rescheduleForm.patch(`/boat-bookings/${props.booking.id}/reschedule`, {
        preserveScroll: true,
    });
}

const tripDateLabel = computed(() => {
    const base = props.booking.booking_date_label;

    if (!props.booking.booking_date) {
        return base;
    }

    const weekday = new Date(`${props.booking.booking_date}T12:00:00`).toLocaleDateString('en-US', {
        weekday: 'short',
    });

    return `${base} (${weekday})`;
});

const dateRequestedLabel = computed(() => {
    if (props.booking.created_at_label && props.booking.created_at_time_label) {
        return `${props.booking.created_at_label} · ${props.booking.created_at_time_label}`;
    }

    return props.booking.created_at_label || '—';
});

const listStatusKey = computed(() => props.booking.display_status ?? props.booking.status);

const isWalkInCash = computed(
    () => Boolean(props.booking.walk_in) && props.booking.walk_in_payment_method === 'cash',
);

const isWalkInGcash = computed(
    () => Boolean(props.booking.walk_in) && props.booking.walk_in_payment_method === 'gcash',
);

const tripNotesDisplay = computed(() => props.booking.remarks_display?.trim() || null);

const statusHint = computed(() => {
    if (isWalkInCash.value) {
        return 'Walk-in cash rental — down payment was recorded at the barangay desk.';
    }

    if (isWalkInGcash.value) {
        return 'Walk-in GCash rental — verify the reference and receipt before confirming payment.';
    }

    if (props.booking.display_status === 'reschedule_confirmed' || props.booking.weather_reschedule_confirmed) {
        return 'The operator approved this reschedule. The rental is confirmed for the new schedule.';
    }

    if (props.booking.status === 'pending') {
        return 'Waiting for the boat operator. Barangay admin can also approve or reject this request.';
    }

    if (props.booking.status === 'confirmed') {
        return 'The boat operator has accepted this request.';
    }

    if (props.booking.status === 'reschedule_pending') {
        return 'No-show reschedule in progress — waiting for passenger details or replacement operator confirmation.';
    }

    if (props.booking.status === 'awaiting_payment') {
        return 'Accepted — waiting for down payment verification.';
    }

    return `This rental is marked as ${props.booking.status_label}.`;
});

const downPaymentVerified = computed(
    () => isWalkInCash.value || payment.value?.payment_status === 'verified',
);

const downPaymentBadge = computed(() => {
    if (downPaymentVerified.value) {
        return 'Verified';
    }

    const status = payment.value?.payment_status;

    if (status === 'for_verification') {
        return 'For Verification';
    }

    if (status === 'rejected') {
        return 'Rejected';
    }

    return 'Unpaid';
});

const balancePaid = computed(() => payment.value?.balance_status === 'paid');

const rentalTotal = computed(() => numeric(payment.value?.rental_price));
const downPaymentAmount = computed(() => numeric(payment.value?.down_payment_amount));

const paidAmount = computed<number | null>(() => {
    if (rentalTotal.value === null) {
        return null;
    }

    if (balancePaid.value) {
        return rentalTotal.value;
    }

    return downPaymentVerified.value ? (downPaymentAmount.value ?? 0) : 0;
});

const remainingAmount = computed<number | null>(() => {
    if (rentalTotal.value === null || paidAmount.value === null) {
        return numeric(payment.value?.remaining_balance);
    }

    return Math.max(0, rentalTotal.value - paidAmount.value);
});

const paymentStateLabel = computed(() => {
    if (payment.value?.overall_status_label) {
        return payment.value.overall_status_label;
    }

    if (paidAmount.value === null || paidAmount.value <= 0) {
        return 'Awaiting Payment';
    }

    return remainingAmount.value === 0 ? 'Fully Paid' : 'Partially Paid';
});

const paymentWarning = computed(() => {
    if (
        rentalTotal.value !== null
        && downPaymentAmount.value !== null
        && downPaymentAmount.value > rentalTotal.value + 0.01
    ) {
        return 'The down payment is higher than the rental total. Please review the payment records.';
    }

    return '';
});

const paymentMethodLabel = computed(() => {
    if (isWalkInCash.value) {
        return 'Cash';
    }

    return payment.value?.downpayment_method_label || 'GCash';
});

const isGcashMethod = computed(() => paymentMethodLabel.value.toLowerCase().includes('gcash'));

const amountPaidLabel = computed(() => {
    if (downPaymentVerified.value) {
        return payment.value?.down_payment_amount_label || '—';
    }

    return payment.value?.payment_amount_submitted_label || payment.value?.down_payment_amount_label || '—';
});

function statusStyle(value: string): string {
    const v = value.toLowerCase();

    if (v.includes('reschedule') && v.includes('pending')) {
        return 'bg-violet-100 text-violet-800 dark:bg-violet-500/20 dark:text-violet-100';
    }

    if (['confirmed', 'completed', 'verified', 'paid', 'fully paid', 'approved'].includes(v) || v.includes('confirmed') || v.includes('partial')) {
        return 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-300';
    }

    if (v.includes('pending') || v.includes('awaiting') || v.includes('verification') || v.includes('unpaid')) {
        return 'bg-amber-100 text-amber-800 dark:bg-amber-500/15 dark:text-amber-300';
    }

    if (v.includes('cancel') || v.includes('reject') || v.includes('fail') || v.includes('no-show') || v.includes('weather')) {
        return 'bg-red-100 text-red-700 dark:bg-red-500/15 dark:text-red-300';
    }

    return 'bg-blue-100 text-[#1479E8] dark:bg-blue-500/15 dark:text-blue-300';
}

function activityActionLabel(item: { event: string; title: string }) {
    if (
        item.event === 'walk_in_cash_created'
        || item.event === 'walk_in_gcash_created'
        || (item.event === 'submitted' && isWalkInCash.value)
    ) {
        return 'Walk-in Booking Created';
    }

    if (item.event === 'submitted') {
        return 'Rental Request Submitted';
    }

    if (item.event === 'payment_verified') {
        return 'Downpayment Verified';
    }

    if (item.event === 'operator_accepted' || item.event === 'admin_approved') {
        return 'Booking Confirmed';
    }

    return item.title;
}

function activityDetails(item: { event: string; actor_name: string | null; title: string }) {
    if (item.event === 'walk_in_cash_created' || (item.event === 'submitted' && isWalkInCash.value)) {
        return 'Booking recorded with cash payment.';
    }

    if (item.event === 'walk_in_gcash_created' || (item.event === 'submitted' && isWalkInGcash.value)) {
        return 'Walk-in booking recorded. GCash payment is awaiting verification.';
    }

    if (item.event === 'payment_submitted') {
        const ref = payment.value?.gcash_reference;

        return ref ? `GCash receipt uploaded · Ref ${ref}` : 'GCash receipt uploaded.';
    }

    if (item.event === 'payment_rejected' && payment.value?.payment_rejection_reason) {
        return payment.value.payment_rejection_reason;
    }

    if (item.event === 'payment_verified') {
        return `Downpayment of ${payment.value?.down_payment_amount_label || '—'} verified (${paymentMethodLabel.value}).`;
    }

    if (item.event === 'submitted') {
        return `Request created by ${item.actor_name || props.booking.customer_name}.`;
    }

    if (item.event === 'operator_accepted') {
        return 'Boat operator accepted the request.';
    }

    if (item.event === 'admin_approved') {
        return `Approved by ${item.actor_name || 'barangay admin'}.`;
    }

    if (item.event === 'cancelled') {
        return item.actor_name ? `Cancelled by ${item.actor_name}.` : 'Rental cancelled.';
    }

    return item.actor_name ? `Updated by ${item.actor_name}.` : 'Recorded in SafeSakay.';
}

function activityStatus(item: { event: string }) {
    if (item.event === 'walk_in_cash_created' || (item.event === 'submitted' && isWalkInCash.value)) {
        return 'Confirmed';
    }

    if (item.event === 'walk_in_gcash_created' || (item.event === 'submitted' && isWalkInGcash.value)) {
        return props.booking.status === 'confirmed' ? 'Confirmed' : 'Pending';
    }

    if (item.event === 'payment_rejected') {
        return 'Rejected';
    }

    if (item.event === 'payment_submitted' && payment.value?.payment_status === 'for_verification') {
        return 'Pending';
    }

    if (item.event === 'submitted') {
        return 'Submitted';
    }

    if (item.event === 'operator_accepted' || item.event === 'admin_approved') {
        return 'Confirmed';
    }

    if (item.event === 'cancelled') {
        return props.booking.status === 'rejected' ? 'Rejected' : 'Cancelled';
    }

    return 'Completed';
}

const canApprove = computed(() => props.booking.can_approve ?? props.booking.status === 'pending');

const canCancel = computed(
    () => props.booking.can_cancel ?? ['pending', 'awaiting_payment'].includes(props.booking.status),
);

function openCancel() {
    cancelForm.action = props.booking.status === 'pending' ? 'reject' : 'cancel';
    cancelForm.cancellation_reason = '';
    cancelForm.clearErrors();
    cancelOpen.value = true;
}

const completeOpen = ref(false);
const completeForm = useForm({});
const completeError = computed(() => (completeForm.errors as Record<string, string | undefined>).booking);

function confirmComplete() {
    completeForm.post(`/boat-bookings/${props.booking.id}/complete`, {
        preserveScroll: true,
        onSuccess: () => {
            completeOpen.value = false;
        },
    });
}

function confirmApprove() {
    approveForm.patch(`/boat-bookings/${props.booking.id}/review`, {
        preserveScroll: true,
        onSuccess: () => {
            approveOpen.value = false;
        },
    });
}

function confirmVerify() {
    verifyForm.post(`/boat-bookings/${props.booking.id}/verify-payment`, {
            preserveScroll: true,
            onFinish: () => {
            verifyForm.reset();
        },
    });
}

function confirmCommission() {
    if (!payment.value?.commission_id) {
        return;
    }

    useForm({}).post(`/commissions/${payment.value.commission_id}/verify`, {
        preserveScroll: true,
    });
}

function resetReject() {
    rejectChoice.value = '';
    rejectNotes.value = '';
    rejectLocalError.value = '';
    rejectForm.reason = '';
    rejectForm.clearErrors();
}

function openReject() {
    resetReject();
    rejectOpen.value = true;
}

function confirmReject() {
    if (!rejectChoice.value) {
        rejectLocalError.value = 'Select a reason for rejection.';

        return;
    }

    const notes = rejectNotes.value.trim();

    if (rejectChoice.value === 'Other' && notes.length < 4) {
        rejectLocalError.value = 'Please specify why you are rejecting this payment.';

        return;
    }

    rejectLocalError.value = '';
    rejectForm.reason = notes ? `${rejectChoice.value}. ${notes}` : rejectChoice.value;
    rejectForm.post(`/boat-bookings/${props.booking.id}/reject-payment`, {
        preserveScroll: true,
        onSuccess: () => {
            rejectOpen.value = false;
            resetReject();
        },
    });
}

function confirmCancel() {
    cancelForm.patch(`/boat-bookings/${props.booking.id}/review`, {
        preserveScroll: true,
        onSuccess: () => {
            cancelOpen.value = false;
        },
    });
}
</script>

<template>
    <Head :title="`Rental Request #${booking.code}`" />

    <div class="min-h-full space-y-5 bg-[#f6f9fe] p-4 text-ink lg:p-6 dark:bg-transparent dark:text-slate-200">
        <header class="space-y-3">
        <Link
            href="/bookings/requests"
                class="inline-flex w-fit items-center gap-2 text-sm font-medium text-[#1479E8] hover:text-[#0F4C8A] dark:text-sky-300"
        >
            <ArrowLeft class="size-4" />
                Back to Rental Requests
        </Link>

            <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                    <h1 class="flex items-center gap-3 text-2xl font-bold tracking-tight text-[#0F4C8A] dark:text-sky-100">
                        <FileText class="size-7 text-[#1479E8]" />
                        Rental Request Details
                </h1>
                    <p class="mt-1.5 text-sm text-ink-muted dark:text-slate-400">
                        View the complete details of this rental request, including booking, payment, and trip information.
                </p>
            </div>

                <div class="flex flex-col items-start gap-1.5 lg:items-end">
            <span
                        class="inline-flex items-center gap-2 rounded-full px-4 py-1.5 text-sm font-semibold"
                        :class="statusStyle(booking.status_label)"
                    >
                        <CheckCircle2
                            v-if="listStatusKey === 'confirmed' || listStatusKey === 'weather_reschedule_confirmed' || listStatusKey === 'reschedule_confirmed' || listStatusKey === 'completed'"
                            class="size-4"
                        />
                        <Clock v-else class="size-4" />
                {{ booking.status_label }}
            </span>
                    <p class="max-w-xs text-xs text-ink-muted lg:text-right dark:text-slate-400">{{ statusHint }}</p>
                    <p
                        v-if="!booking.can_complete && booking.complete_blocked_reason"
                        class="max-w-xs text-xs font-medium text-amber-700 lg:text-right dark:text-amber-300"
                    >
                        {{ booking.complete_blocked_reason }}
                    </p>

                    <div v-if="canApprove || canCancel || booking.can_complete" class="mt-1 flex flex-wrap gap-2 lg:justify-end">
                        <button
                            v-if="booking.can_complete"
                            type="button"
                            class="inline-flex h-9 items-center gap-2 rounded-xl bg-[#1479E8] px-4 text-sm font-semibold text-white hover:bg-[#0f63c4]"
                            @click="completeOpen = true"
                        >
                            <CheckCircle2 class="size-4" />
                            Complete Trip
                        </button>
                        <button
                            v-if="canApprove"
                            type="button"
                            class="inline-flex h-9 items-center gap-2 rounded-xl bg-[#16A34A] px-4 text-sm font-semibold text-white hover:bg-[#15803d]"
                            @click="approveOpen = true"
                        >
                            <Check class="size-4" />
                            Approve Request
                        </button>
                        <button
                            v-if="canApprove"
                            type="button"
                            class="inline-flex h-9 items-center gap-2 rounded-xl border border-[#d45b5b] bg-white px-4 text-sm font-semibold text-[#d45b5b] hover:bg-red-50 dark:bg-[#102036] dark:text-slate-100 dark:border-white/10"
                            @click="openCancel"
                        >
                            <X class="size-4" />
                            Reject Request
                        </button>
                        <button
                            v-else-if="canCancel"
                            type="button"
                            class="inline-flex h-9 items-center gap-2 rounded-xl border border-[#d45b5b] bg-white px-4 text-sm font-semibold text-[#d45b5b] hover:bg-red-50 dark:bg-[#102036] dark:text-slate-100 dark:border-white/10"
                            @click="openCancel"
                        >
                            <X class="size-4" />
                            Cancel Request
                        </button>
        </div>
                </div>
            </div>
        </header>

                <section
            v-if="weatherRescheduleAlert"
            class="rounded-2xl border border-red-200 border-l-4 border-l-red-500 bg-red-50 p-4 shadow-sm dark:border-red-900/40 dark:bg-red-950/20"
        >
            <div class="flex gap-3">
                <div class="flex size-10 shrink-0 items-center justify-center rounded-full bg-red-100 text-red-600">
                    <CloudRain class="size-5" />
                    </div>
                <div class="min-w-0 flex-1 space-y-2">
                    <p class="text-sm font-bold text-red-900 dark:text-red-100">{{ weatherRescheduleAlert.title }}</p>
                    <p class="text-sm leading-relaxed text-red-800 dark:text-red-200">{{ weatherRescheduleAlert.message }}</p>
                    <div
                        v-if="booking.can_review_reschedule && booking.requested_booking_date_label"
                        class="grid gap-2 sm:grid-cols-2"
                    >
                        <div class="rounded-xl border border-red-100 bg-white px-3 py-2 text-xs dark:border-red-900/30 dark:bg-white/5">
                            <p class="font-semibold text-red-700">Original date</p>
                            <p class="mt-0.5 font-bold text-[#0f4c8a]">
                                {{ booking.original_booking_date_label ?? booking.booking_date_label }}
                            </p>
                        </div>
                        <div class="rounded-xl border border-red-200 bg-white px-3 py-2 text-xs dark:border-red-900/30 dark:bg-white/5">
                            <p class="font-semibold text-red-700">Requested new date</p>
                            <p class="mt-0.5 font-bold text-[#0f4c8a]">{{ booking.requested_booking_date_label }}</p>
                        </div>
                    </div>
                    <div v-if="booking.can_review_reschedule" class="flex flex-wrap gap-2 pt-1">
                        <button
                            type="button"
                            class="inline-flex h-9 items-center rounded-lg border border-red-300 bg-white px-4 text-sm font-semibold text-red-700 dark:bg-[#102036] dark:text-slate-100 dark:border-white/10"
                            :disabled="rescheduleForm.processing"
                            @click="confirmReschedule('reject')"
                        >
                            Decline date
                        </button>
                        <button
                            type="button"
                            class="inline-flex h-9 items-center rounded-lg bg-emerald-600 px-4 text-sm font-semibold text-white"
                            :disabled="rescheduleForm.processing"
                            @click="confirmReschedule('approve')"
                        >
                            Approve date
                        </button>
                    </div>
                </div>
            </div>
        </section>

        <RentalIncidentPanels
            :booking="booking"
            :no-show-report="no_show_report ?? null"
            :passenger-no-show="passenger_no_show_report ?? null"
            :can-manage="can_manage ?? true"
        />

        <RentalRefundPanel v-if="refund_request" :refund-request="refund_request" :can-manage="can_manage ?? true" />

        <section class="grid gap-4 xl:grid-cols-3">
            <article class="ss-card">
                <h2 class="ss-heading">
                    <Ship class="size-5 text-[#1479E8]" />
                    Boat Information
                </h2>

                <div class="mt-4 flex gap-3">
                            <img
                                v-if="booking.boat.picture_url"
                                :src="booking.boat.picture_url"
                                :alt="booking.boat.name"
                        class="size-20 shrink-0 rounded-xl object-cover"
                            />
                    <div v-else class="flex size-20 shrink-0 items-center justify-center rounded-xl bg-blue-50 dark:bg-white/5">
                        <Ship class="size-8 text-[#1479E8] opacity-50" />
                        </div>

                    <div class="min-w-0">
                        <p class="font-bold text-[#1479E8] dark:text-sky-300">
                                {{ booking.boat.name }}
                            <span v-if="booking.boat.code" class="text-sm">({{ booking.boat.code }})</span>
                        </p>
                        <p class="mt-1 text-sm font-semibold">{{ booking.boat.type || 'Pump Boat' }}</p>
                        <p class="mt-1.5 text-sm">
                            <span class="text-ink-muted">Capacity:</span>
                            <span class="ml-1 font-bold">{{ booking.boat.capacity }} passengers</span>
                                        </p>
                                    </div>
                                </div>

                <div class="mt-4 grid grid-cols-2 gap-x-4 gap-y-3 border-t border-slate-100 pt-4 dark:border-white/10">
                                    <div>
                        <p class="ss-label">Homeport</p>
                        <p class="ss-value">{{ booking.boat.home_port || '—' }}</p>
                    </div>
                    <div>
                        <p class="ss-label">License No.</p>
                        <p class="ss-value">{{ booking.boat.license_no || '—' }}</p>
                    </div>
                    <div>
                        <p class="ss-label">Registered Date</p>
                        <p class="ss-value">{{ booking.boat.registration_date_label || '—' }}</p>
                    </div>
                    <div>
                        <p class="ss-label">Boat Operator</p>
                        <p class="ss-value">{{ booking.boat.operator_name ?? 'Unassigned' }}</p>
                                        <p
                                            v-if="booking.boat.operator_contact_number"
                            class="mt-0.5 flex items-center gap-1 text-xs font-semibold text-[#1479E8]"
                                        >
                            <Phone class="size-3.5" />
                                            {{ booking.boat.operator_contact_number }}
                                        </p>
                                    </div>
                                </div>

                <details class="group mt-4">
                    <summary
                        class="flex cursor-pointer list-none items-center justify-center gap-2 rounded-xl border border-blue-200 bg-blue-50 px-4 py-2.5 text-sm font-semibold text-[#1479E8] hover:bg-blue-100 dark:border-sky-500/30 dark:bg-sky-500/10 dark:text-sky-300 [&::-webkit-details-marker]:hidden"
                    >
                        <ShieldCheck class="size-4" />
                        View Safety Equipment
                        <ChevronDown class="size-4 transition group-open:rotate-180" />
                    </summary>
                    <div class="mt-3 rounded-xl border border-[#d7e5f4] bg-[#f4f9fd] p-3 dark:border-white/10 dark:bg-white/5">
                        <ul v-if="booking.boat.safety_equipment?.length" class="space-y-2">
                            <li
                                v-for="item in booking.boat.safety_equipment"
                                :key="item"
                                class="flex items-start gap-2 text-sm"
                            >
                                <CheckCircle2 class="mt-0.5 size-4 shrink-0 text-emerald-500" />
                                <span>{{ item }}</span>
                            </li>
                        </ul>
                        <p v-else class="text-sm text-ink-muted">No safety equipment recorded.</p>
                            </div>
                </details>
            </article>

            <article class="ss-card">
                <h2 class="ss-heading">
                    <MapPin class="size-5 text-[#1479E8]" />
                    Trip Details
                </h2>

                <div class="mt-4 space-y-4">
                    <div class="ss-icon-row">
                        <MapPin class="ss-row-icon" />
                        <div>
                            <p class="ss-label">Destination</p>
                            <p class="ss-value">{{ booking.destination || '—' }}</p>
                        </div>
                    </div>
                    <div class="ss-icon-row">
                        <MapPin class="ss-row-icon" />
                        <div>
                            <p class="ss-label">Pickup Location</p>
                            <p class="ss-value">{{ booking.pickup_point || '—' }}</p>
                        </div>
                    </div>
                    <div class="grid grid-cols-2 gap-4">
                        <div class="ss-icon-row">
                            <CalendarDays class="ss-row-icon" />
                            <div>
                                <p class="ss-label">Date</p>
                                <p class="ss-value">{{ tripDateLabel }}</p>
                            </div>
                        </div>
                        <div class="ss-icon-row">
                            <Clock class="ss-row-icon" />
                            <div>
                                <p class="ss-label">Time</p>
                                <p class="ss-value">{{ booking.start_time_label || '—' }}</p>
                            </div>
                        </div>
                    </div>
                    <div class="grid grid-cols-2 gap-4">
                        <div class="ss-icon-row">
                            <Tag class="ss-row-icon" />
                            <div>
                                <p class="ss-label">Purpose</p>
                                <p class="ss-value">{{ booking.purpose || '—' }}</p>
                            </div>
                        </div>
                        <div class="ss-icon-row">
                            <Users class="ss-row-icon" />
                            <div>
                                <p class="ss-label">Passengers</p>
                                <p class="ss-value">{{ booking.passenger_count }}</p>
                            </div>
                        </div>
                    </div>
                    <div v-if="tripNotesDisplay" class="ss-icon-row">
                        <StickyNote class="ss-row-icon" />
                        <div>
                            <p class="ss-label">Additional Notes</p>
                            <p class="ss-value">{{ tripNotesDisplay }}</p>
                        </div>
                    </div>
                </div>
            </article>

            <article class="ss-card">
                <h2 class="ss-heading">
                    <UserRound class="size-5 text-[#1479E8]" />
                    Request Details
                            </h2>

                <div class="mt-4 space-y-4">
                    <div class="ss-icon-row">
                        <FileText class="ss-row-icon" />
                        <div>
                            <p class="ss-label">Reference Number</p>
                            <p class="font-semibold text-[#1479E8] dark:text-sky-300">{{ booking.code }}</p>
                        </div>
                    </div>
                    <div class="ss-icon-row">
                        <UserRound class="ss-row-icon" />
                                <div>
                            <p class="ss-label">Passenger</p>
                            <p class="ss-value">{{ booking.customer_name }}</p>
                            <p
                                v-if="booking.contact_number"
                                class="mt-0.5 flex items-center gap-1 text-xs font-semibold text-[#1479E8]"
                            >
                                <Phone class="size-3.5" />
                                {{ booking.contact_number }}
                                    </p>
                                </div>
                    </div>
                    <div class="ss-icon-row">
                        <CalendarDays class="ss-row-icon" />
                                <div>
                            <p class="ss-label">Requested Date</p>
                            <p class="ss-value">{{ dateRequestedLabel }}</p>
                                </div>
                    </div>
                    <div class="ss-icon-row">
                        <Clock class="ss-row-icon" />
                                <div>
                            <p class="ss-label">Booking Status</p>
                            <span
                                class="mt-1 inline-flex rounded-full px-3 py-0.5 text-xs font-semibold"
                                :class="statusStyle(booking.status_label)"
                            >
                                {{ booking.status_label }}
                            </span>
                        </div>
                    </div>
                </div>
            </article>
        </section>

        <section v-if="booking.passengers?.length" class="ss-card">
            <h2 class="ss-heading">
                <Users class="size-5 text-[#1479E8]" />
                Passengers ({{ booking.passenger_count }})
            </h2>
            <div class="mt-4 overflow-x-auto rounded-xl border border-[#d7e5f4] dark:border-white/10">
                <table class="w-full min-w-[520px] text-left text-sm">
                    <thead class="bg-[#eaf3fc] text-ink-muted dark:bg-sky-500/10">
                        <tr>
                            <th class="ss-th">Name</th>
                            <th class="ss-th">Age</th>
                            <th class="ss-th">Gender</th>
                            <th class="ss-th">Contact</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="passenger in booking.passengers"
                            :key="passenger.id"
                            class="border-t border-slate-100 dark:border-white/10"
                        >
                            <td class="ss-td font-semibold">{{ passenger.full_name }}</td>
                            <td class="ss-td">{{ passenger.age }}</td>
                            <td class="ss-td">{{ passenger.gender }}</td>
                            <td class="ss-td">{{ passenger.contact_number || '—' }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <section class="grid gap-4 xl:grid-cols-[1.7fr_1fr]">
            <article class="ss-card">
                <h2 class="ss-heading">
                    <Wallet class="size-5 text-[#1479E8]" />
                    Payment Summary
                </h2>

                <div class="mt-4 grid gap-3 sm:grid-cols-3">
                    <div class="flex items-start justify-between gap-2 rounded-xl border border-blue-200 bg-blue-50 p-4 dark:border-sky-500/30 dark:bg-sky-500/10">
                        <div>
                            <p class="text-xs font-semibold text-[#0F4C8A] dark:text-sky-300">Total Rental Price</p>
                            <p class="mt-2 text-xl font-extrabold text-[#0F4C8A] dark:text-white">
                                {{ payment?.rental_price_label || '—' }}
                                    </p>
                                </div>
                        <Wallet class="size-6 shrink-0 text-[#1479E8]" />
                    </div>

                    <div class="flex items-start justify-between gap-2 rounded-xl border border-amber-200 bg-amber-50 p-4 dark:border-amber-500/30 dark:bg-amber-500/10">
                                <div>
                            <p class="text-xs font-semibold text-amber-800 dark:text-amber-300">
                                Downpayment ({{ downPaymentBadge }})
                            </p>
                            <p class="mt-2 text-xl font-extrabold text-[#0F4C8A] dark:text-white">
                                {{ payment?.down_payment_amount_label || '—' }}
                                    </p>
                                </div>
                        <CheckCircle2 v-if="downPaymentVerified" class="size-6 shrink-0 text-emerald-600" />
                        <Clock v-else class="size-6 shrink-0 text-amber-600" />
                    </div>

                    <div class="flex items-start justify-between gap-2 rounded-xl border border-emerald-200 bg-emerald-50 p-4 dark:border-emerald-500/30 dark:bg-emerald-500/10">
                                <div>
                            <p class="text-xs font-semibold text-emerald-800 dark:text-emerald-300">Remaining Balance</p>
                            <p class="mt-2 text-xl font-extrabold text-[#0F4C8A] dark:text-white">
                                {{ balancePaid ? peso(0) : payment?.remaining_balance_label || peso(remainingAmount) }}
                                    </p>
                                </div>
                        <CheckCircle2 v-if="balancePaid" class="size-6 shrink-0 text-emerald-600" />
                        <Hourglass v-else class="size-6 shrink-0 text-emerald-600" />
                    </div>
                </div>

                <div class="mt-4 flex flex-wrap items-center gap-3 border-t border-slate-100 pt-4 dark:border-white/10">
                    <span class="text-sm font-medium">Payment Status</span>
                    <span
                        class="inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-xs font-bold"
                        :class="statusStyle(paymentStateLabel)"
                    >
                        <Coins class="size-3.5" />
                        {{ paymentStateLabel }}
                    </span>
                    <span class="hidden h-5 w-px bg-slate-200 sm:block dark:bg-white/10" />
                    <p class="text-sm text-ink-muted dark:text-slate-400">
                        {{ peso(paidAmount) }} paid
                        <span v-if="remainingAmount !== null"> • {{ peso(remainingAmount) }} remaining</span>
                    </p>
                </div>

                <p v-if="payment?.balance_method_label || !balancePaid" class="mt-3 text-xs text-ink-muted">
                    <template v-if="balancePaid">
                        Balance paid via {{ payment?.balance_method_label || '—' }}
                        <span v-if="payment?.balance_paid_at_label">· {{ payment.balance_paid_at_label }}</span>
                    </template>
                    <template v-else>
                        Remaining balance is paid to the boat operator (GCash or cash) and confirmed in the SafeSakay mobile app.
                    </template>
                </p>

                <details
                    v-if="payment?.commission_enabled"
                    class="group mt-4 rounded-xl border border-[#d7e5f4] p-3 dark:border-white/10"
                >
                    <summary class="flex cursor-pointer list-none items-center justify-between gap-2 text-sm font-semibold text-[#0F4C8A] dark:text-sky-100 [&::-webkit-details-marker]:hidden">
                        Commission Breakdown
                        <ChevronDown class="size-4 transition group-open:rotate-180" />
                    </summary>
                    <div class="mt-3 grid gap-3 sm:grid-cols-3">
                        <div class="rounded-lg bg-[#f4f9fd] px-3 py-2 text-sm dark:bg-white/5">
                            <p class="text-xs text-ink-muted">Barangay commission</p>
                            <p class="font-semibold">
                                {{ payment.commission_amount_label || '—' }}
                                <span v-if="payment.commission_percentage_label">({{ payment.commission_percentage_label }})</span>
                            </p>
                        </div>
                        <div class="rounded-lg bg-[#f4f9fd] px-3 py-2 text-sm dark:bg-white/5">
                            <p class="text-xs text-ink-muted">Operator net earnings</p>
                            <p class="font-semibold">{{ payment.operator_net_label || '—' }}</p>
                        </div>
                        <div v-if="payment.commission_status_label" class="rounded-lg bg-[#f4f9fd] px-3 py-2 text-sm dark:bg-white/5">
                            <p class="text-xs text-ink-muted">Commission status</p>
                            <p class="font-semibold">{{ payment.commission_status_label }}</p>
                        </div>
                    </div>
                    <button
                        v-if="payment.commission_can_verify"
                        type="button"
                        class="mt-3 inline-flex h-9 items-center rounded-lg bg-[#16A34A] px-3 text-sm font-semibold text-white"
                        @click="confirmCommission"
                    >
                        Confirm commission received
                    </button>
                </details>
            </article>

            <article class="ss-card">
                <h2 class="ss-heading">
                    <CreditCard class="size-5 text-[#1479E8]" />
                    Payment Details
                            </h2>

                <div class="mt-3">
                    <div class="ss-detail-row">
                        <span>Payment Method</span>
                        <strong class="inline-flex items-center gap-1.5">
                            <span
                                v-if="isGcashMethod"
                                class="inline-flex size-5 items-center justify-center rounded-md bg-[#1479E8] text-[11px] font-black text-white"
                            >G</span>
                            {{ paymentMethodLabel }}
                        </strong>
                        </div>
                    <div class="ss-detail-row">
                        <span>Amount Paid (Downpayment)</span>
                        <strong>{{ amountPaidLabel }}</strong>
                    </div>
                    <div v-if="!isWalkInCash" class="ss-detail-row">
                        <span>Sent To</span>
                        <strong>{{ payment?.gcash_payee_label || 'SafeSakay' }}</strong>
                    </div>
                    <div v-if="payment?.gcash_reference || payment?.provider_payment_id" class="ss-detail-row">
                        <span>Reference</span>
                        <strong class="break-all">{{ payment?.gcash_reference || payment?.provider_payment_id }}</strong>
                    </div>
                    <template v-if="isWalkInCash">
                        <div class="ss-detail-row">
                            <span>Cash Received</span>
                            <strong>{{ booking.walk_in_cash_received_label || '—' }}</strong>
                        </div>
                        <div class="ss-detail-row">
                            <span>Change Given</span>
                            <strong>{{ booking.walk_in_change_label || '₱0.00' }}</strong>
                        </div>
                    </template>
                    <div v-if="!downPaymentVerified && payment?.payment_submitted_at_label" class="ss-detail-row">
                        <span>Submitted At</span>
                        <strong>{{ payment.payment_submitted_at_label }}</strong>
                    </div>
                    <div class="ss-detail-row">
                        <span>Verified At</span>
                        <strong>{{ payment?.payment_verified_at_label || (downPaymentVerified ? '—' : 'Not yet verified') }}</strong>
                    </div>
                </div>

                <form
                    v-if="payment?.can_verify"
                    class="mt-4 space-y-3 rounded-xl border border-[#d7e5f4] bg-[#f8fbfe] p-3 dark:border-white/10 dark:bg-white/5"
                    @submit.prevent="confirmVerify"
                >
                    <label class="flex items-start gap-2 text-xs">
                        <input
                            v-model="verifyForm.confirmed_gcash_transaction"
                            type="checkbox"
                            class="mt-0.5 size-4 rounded border-[#c5def5]"
                        />
                        <span>I checked the GCash transaction and it matches this payment.</span>
                    </label>
                    <button
                        type="submit"
                        class="inline-flex h-9 w-full items-center justify-center rounded-xl bg-[#16A34A] text-sm font-semibold text-white disabled:opacity-50"
                        :disabled="verifyForm.processing || !verifyForm.confirmed_gcash_transaction"
                    >
                        <Spinner v-if="verifyForm.processing" class="mr-2 size-4" />
                        Verify Down Payment
                    </button>
                    <button
                        v-if="payment?.can_reject"
                        type="button"
                        class="inline-flex h-9 w-full items-center justify-center rounded-xl border border-[#d45b5b] text-sm font-semibold text-[#d45b5b]"
                        @click="openReject"
                    >
                        Reject Proof
                    </button>
                </form>
                <p
                    v-else-if="!downPaymentVerified && payment?.payment_status === 'unpaid'"
                    class="mt-4 rounded-xl bg-slate-50 px-3 py-3 text-center text-xs text-ink-muted dark:bg-white/5"
                >
                    Waiting for the passenger to submit the down payment.
                </p>
            </article>
        </section>

        <section v-if="booking.feedback" class="ss-card">
            <h2 class="ss-heading">
                <Star class="size-5 text-[#1479E8]" />
                Passenger Feedback
            </h2>
            <div class="mt-3 flex flex-wrap items-center gap-3">
                <div class="flex items-center gap-0.5">
                    <Star
                        v-for="n in 5"
                        :key="n"
                        class="size-5"
                        :class="n <= booking.feedback.rating ? 'fill-amber-400 text-amber-400' : 'text-slate-300'"
                    />
                </div>
                <span class="text-sm font-semibold">{{ booking.feedback.rating }} / 5</span>
                <span v-if="booking.feedback.submitted_at_label" class="text-xs text-ink-muted">
                    · {{ booking.feedback.submitted_at_label }}
                </span>
            </div>
            <div v-if="booking.feedback.tags.length" class="mt-3 flex flex-wrap gap-2">
                <span
                    v-for="tag in booking.feedback.tags"
                    :key="tag"
                    class="rounded-full bg-blue-50 px-3 py-1 text-xs font-semibold text-[#1479E8] dark:bg-sky-500/10 dark:text-sky-300"
                >
                    {{ tag }}
                </span>
            </div>
            <p v-if="booking.feedback.comment" class="mt-3 rounded-xl bg-[#f4f9fd] px-4 py-3 text-sm dark:bg-white/5">
                “{{ booking.feedback.comment }}”
            </p>
        </section>

        <div
            v-if="paymentWarning"
            class="flex items-start gap-3 rounded-xl border border-amber-300 bg-amber-50 p-4 text-sm text-amber-900"
        >
            <AlertTriangle class="mt-0.5 size-5 shrink-0" />
            <p>{{ paymentWarning }}</p>
        </div>

        <section class="ss-card">
            <h2 class="ss-heading">
                <History class="size-5 text-[#1479E8]" />
                Activity History
            </h2>
            <p class="mt-1 text-xs text-ink-muted">Booking, payment, and status updates for this rental request.</p>

            <div class="mt-4 overflow-x-auto rounded-xl border border-[#d7e5f4] dark:border-white/10">
                <table class="w-full min-w-[720px] border-collapse text-left text-sm">
                    <thead class="bg-[#eaf3fc] text-ink-muted dark:bg-sky-500/10">
                        <tr>
                            <th class="ss-th">Date &amp; Time</th>
                            <th class="ss-th">Action</th>
                            <th class="ss-th">Details</th>
                            <th class="ss-th">Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr
                            v-for="item in booking.activities ?? []"
                            :key="item.id"
                            class="border-t border-slate-100 dark:border-white/10"
                        >
                            <td class="ss-td whitespace-nowrap">{{ item.occurred_at_label || '—' }}</td>
                            <td class="ss-td font-semibold text-[#0F4C8A] dark:text-sky-100">{{ activityActionLabel(item) }}</td>
                            <td class="ss-td text-ink-muted dark:text-slate-400">{{ activityDetails(item) }}</td>
                            <td class="ss-td">
                                <span
                                    class="inline-flex rounded-full px-3 py-0.5 text-xs font-semibold"
                                    :class="statusStyle(activityStatus(item))"
                                >
                                    {{ activityStatus(item) }}
                                </span>
                                        </td>
                                    </tr>
                        <tr v-if="!booking.activities?.length">
                            <td colspan="4" class="px-4 py-10 text-center text-ink-muted">No activity history available.</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </section>
                </div>

    <Dialog :open="completeOpen" @update:open="(open) => (completeOpen = open)">
        <DialogContent class="max-w-md rounded-2xl border-[#d7e8f6] p-0 sm:max-w-md">
            <DialogHeader class="border-b border-[#e8f1f8] px-5 py-4">
                <DialogTitle class="text-[#0f4c8a]">Complete Trip</DialogTitle>
                <DialogDescription>
                    Mark rental #{{ booking.code }} as completed on behalf of the operator.
                </DialogDescription>
            </DialogHeader>
            <div class="space-y-3 px-5 py-4">
                <div
                    v-if="booking.complete_settles_balance"
                    class="rounded-xl border border-amber-200 bg-amber-50 px-3 py-2.5 text-sm text-amber-900"
                >
                    Remaining balance <strong>{{ booking.payment?.remaining_balance_label }}</strong> will be recorded as paid in cash during the trip.
                    </div>
                <p class="text-sm text-ink">
                    The operator share becomes ready for payout right after.
                </p>
                <p v-if="completeError" class="text-sm text-rose-600">{{ completeError }}</p>
                <DialogFooter class="gap-2 border-0 p-0">
                    <button
                        type="button"
                        class="inline-flex h-9 items-center rounded-lg border border-[#c5def5] px-3 text-sm font-medium"
                        @click="completeOpen = false"
                    >
                        Close
                    </button>
                    <button
                        type="button"
                        class="inline-flex h-9 items-center gap-2 rounded-lg bg-[#1479E8] px-3 text-sm font-semibold text-white disabled:opacity-60"
                        :disabled="completeForm.processing"
                        @click="confirmComplete"
                    >
                        <Spinner v-if="completeForm.processing" class="size-4" />
                        Confirm Complete
                    </button>
                </DialogFooter>
                    </div>
        </DialogContent>
    </Dialog>

    <Dialog :open="approveOpen" @update:open="(open) => (approveOpen = open)">
        <DialogContent class="max-w-md rounded-2xl border-[#d7e8f6] p-0 sm:max-w-md">
            <DialogHeader class="border-b border-[#e8f1f8] px-5 py-4">
                <DialogTitle class="text-[#0f4c8a]">Approve Rental Request</DialogTitle>
                <DialogDescription>
                    Approve rental #{{ booking.code }} on behalf of the boat operator.
                </DialogDescription>
            </DialogHeader>
            <div class="space-y-3 px-5 py-4">
                <p class="text-sm text-ink">
                    Use this when the operator is not on the app and barangay is instructed to approve the request.
                </p>
                <DialogFooter class="gap-2 border-0 p-0">
                    <button
                        type="button"
                        class="inline-flex h-9 items-center rounded-lg border border-[#c5def5] px-3 text-sm font-medium"
                        @click="approveOpen = false"
                    >
                        Close
                    </button>
                    <button
                        type="button"
                        class="inline-flex h-9 items-center rounded-lg bg-[#16A34A] px-3 text-sm font-semibold text-white disabled:opacity-60"
                        :disabled="approveForm.processing"
                        @click="confirmApprove"
                    >
                        <Spinner v-if="approveForm.processing" class="size-4" />
                        Confirm Approve
                    </button>
                </DialogFooter>
                    </div>
        </DialogContent>
    </Dialog>

    <Dialog :open="cancelOpen" @update:open="(open) => (cancelOpen = open)">
        <DialogContent class="max-w-md rounded-2xl border-[#d7e8f6] p-0 sm:max-w-md">
            <DialogHeader class="border-b border-[#e8f1f8] px-5 py-4">
                <DialogTitle class="text-[#0f4c8a]">
                    {{ cancelForm.action === 'reject' ? 'Reject Request' : 'Cancel Rental' }}
                </DialogTitle>
                <DialogDescription>
                    {{
                        cancelForm.action === 'reject'
                            ? `Reject rental #${booking.code}. A reason is required.`
                            : `Cancel rental #${booking.code}. A reason is required.`
                    }}
                </DialogDescription>
            </DialogHeader>
            <form class="space-y-3 px-5 py-4" @submit.prevent="confirmCancel">
                <p class="text-sm text-ink">
                    Rental ID: <span class="font-semibold text-[#1479E8]">#{{ booking.code }}</span>
                </p>
                <label class="block text-sm font-medium text-[#0f4c8a]">
                    Cancellation reason
                    <textarea
                        v-model="cancelForm.cancellation_reason"
                        required
                        minlength="5"
                        rows="4"
                        class="mt-1 w-full rounded-xl border border-[#c5def5] px-3 py-2 text-sm text-ink outline-none focus:border-[#1479E8]"
                    />
                </label>
                <p v-if="cancelForm.errors.cancellation_reason" class="text-xs text-[#d45b5b]">
                    {{ cancelForm.errors.cancellation_reason }}
                </p>
                <DialogFooter class="gap-2 border-0 p-0">
                        <button
                            type="button"
                        class="inline-flex h-9 items-center rounded-lg border border-[#c5def5] px-3 text-sm font-medium"
                        @click="cancelOpen = false"
                        >
                        Close
                        </button>
                    <button
                        type="submit"
                        class="inline-flex h-9 items-center rounded-lg bg-[#d45b5b] px-3 text-sm font-semibold text-white disabled:opacity-60"
                        :disabled="cancelForm.processing"
                    >
                        <Spinner v-if="cancelForm.processing" class="size-4" />
                        {{ cancelForm.action === 'reject' ? 'Confirm Reject' : 'Confirm Cancel' }}
                    </button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>

    <Dialog :open="rejectOpen" @update:open="(open) => { rejectOpen = open; if (!open) resetReject(); }">
        <DialogContent class="max-w-md rounded-2xl border-[#d7e8f6] p-0 sm:max-w-md">
            <DialogHeader class="border-b border-[#e8f1f8] px-5 py-4">
                <DialogTitle class="text-[#0f4c8a]">Reject Payment Proof</DialogTitle>
                <DialogDescription>
                    Are you sure you want to reject this payment proof? The passenger can resubmit. This does not mark the rental paid.
                </DialogDescription>
            </DialogHeader>
            <form class="space-y-3 px-5 py-4" @submit.prevent="confirmReject">
                <label class="block text-sm font-medium text-[#0f4c8a]">
                    Reason for Rejection
                    <select
                        v-model="rejectChoice"
                        required
                        class="mt-1 h-10 w-full rounded-xl border border-[#c5def5] px-3 text-sm text-ink outline-none focus:border-[#1479E8]"
                    >
                        <option value="" disabled>Select a reason</option>
                        <option v-for="reason in PAYMENT_REJECT_REASONS" :key="reason" :value="reason">
                            {{ reason }}
                        </option>
                    </select>
                </label>
                <label class="block text-sm font-medium text-[#0f4c8a]">
                    Additional notes
                    <span class="font-normal text-ink-muted">
                        {{ rejectChoice === 'Other' ? '(required)' : '(optional)' }}
                    </span>
                    <textarea
                        v-model="rejectNotes"
                        maxlength="255"
                        :required="rejectChoice === 'Other'"
                        :minlength="rejectChoice === 'Other' ? 4 : undefined"
                        rows="3"
                        class="mt-1 w-full rounded-xl border border-[#c5def5] px-3 py-2 text-sm text-ink outline-none focus:border-[#1479E8]"
                        placeholder="Add details if needed"
                    />
                    <span class="mt-1 block text-right text-xs text-ink-muted">{{ rejectNotes.length }}/255</span>
                </label>
                <p v-if="rejectLocalError || rejectForm.errors.reason" class="text-xs text-[#d45b5b]">
                    {{ rejectLocalError || rejectForm.errors.reason }}
                </p>
                <DialogFooter class="gap-2 border-0 p-0">
                    <button
                        type="button"
                        class="inline-flex h-9 items-center rounded-lg border border-[#c5def5] px-3 text-sm font-medium"
                        @click="rejectOpen = false; resetReject()"
                    >
                        Close
                    </button>
                    <button
                        type="submit"
                        class="inline-flex h-9 items-center rounded-lg bg-[#d45b5b] px-3 text-sm font-semibold text-white disabled:opacity-60"
                        :disabled="rejectForm.processing"
                    >
                        <Spinner v-if="rejectForm.processing" class="size-4" />
                        Reject proof
                    </button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>

<style scoped>
.ss-card {
    border: 1px solid #d7e5f4;
    border-radius: 18px;
    background: #ffffff;
    padding: 20px;
    box-shadow: 0 2px 8px rgba(21, 78, 132, 0.035);
}

.ss-heading {
    display: flex;
    align-items: center;
    gap: 10px;
    font-size: 16px;
    font-weight: 700;
    color: #0f4c8a;
}

.ss-label {
    font-size: 12px;
    color: #7d93a8;
    line-height: 18px;
}

.ss-value {
    font-size: 14px;
    font-weight: 600;
    color: #163a5f;
    overflow-wrap: anywhere;
}

.ss-icon-row {
    display: flex;
    gap: 10px;
    align-items: flex-start;
}

.ss-row-icon {
    width: 18px;
    height: 18px;
    margin-top: 2px;
    flex-shrink: 0;
    color: #1479e8;
}

.ss-detail-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 16px;
    font-size: 13px;
    padding: 9px 0;
    border-bottom: 1px solid #edf2f8;
}

.ss-detail-row:last-child {
    border-bottom: none;
}

.ss-detail-row > span {
    color: #7d93a8;
    flex-shrink: 0;
}

.ss-detail-row strong {
    color: #163a5f;
    text-align: right;
    overflow-wrap: anywhere;
}

.ss-th {
    padding: 12px 16px;
    font-size: 11px;
    font-weight: 750;
    text-transform: uppercase;
    letter-spacing: 0.03em;
}

.ss-td {
    padding: 12px 16px;
    font-size: 13px;
}

@media (max-width: 768px) {
    .ss-card {
        padding: 16px;
        border-radius: 15px;
    }
}

:global(.dark) .ss-card {
    background: #102036;
    border-color: rgba(255, 255, 255, 0.1);
}

:global(.dark) .ss-heading {
    color: #e0f2fe;
}

:global(.dark) .ss-value,
:global(.dark) .ss-detail-row strong {
    color: #e2e8f0;
}

:global(.dark) .ss-label,
:global(.dark) .ss-detail-row > span {
    color: #94a3b8;
}

:global(.dark) .ss-detail-row {
    border-color: rgba(255, 255, 255, 0.08);
}
</style>
