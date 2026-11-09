<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import {
    BadgePercent,
    CalendarDays,
    CheckCircle2,
    ChevronLeft,
    ChevronRight,
    Clock3,
    Download,
    Eye,
    Printer,
    Search,
    Wallet,
    X,
} from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import RefundRecordsTable from '@/components/admin/RefundRecordsTable.vue';
import RefundReviewDialog from '@/components/admin/RefundReviewDialog.vue';
import type { RefundRecord } from '@/components/admin/RefundReviewDialog.vue';
import StatCards from '@/components/admin/StatCards.vue';
import type {StatCardItem} from '@/components/admin/StatCards.vue';

type Amount = number | string | null | undefined;

type PaymentRow = {
    id: number | string;
    booking_reference: string;
    passenger_name: string;
    boat_name: string | null;
    payment_type: string;
    amount_paid: Amount;
    rental_total: Amount;
    commission_amount: Amount;
    status: string;
    is_verified?: boolean;
    paid_at: string | null;
    verified_at?: string | null;
    payment_method?: string | null;
    received_by?: 'Barangay' | 'Operator' | null;
    received_by_label?: string | null;
    transaction_id?: string | null;
    booking_href?: string | null;
    remaining_balance?: Amount;
    balance_amount?: Amount;
    balance_status?: 'Paid on trip' | 'Paid online' | 'Due on trip' | null;
    balance_paid_at?: string | null;
    operator_share?: Amount;
    commission_rate_label?: string | null;
    booking_id?: number;
    refund_reference?: string | null;
    refund_status?: string | null;
    refund_status_label?: string | null;
    partially_refunded?: boolean;
};

type PagedRows =
    | PaymentRow[]
    | { data: PaymentRow[] };

type Summary = {
    total_payments?: Amount;
    full_payments?: Amount;
    downpayments?: Amount;
    total_commission?: Amount;
};

const props = withDefaults(
    defineProps<{
        payments?: PagedRows;
        records?: PagedRows;
        stats?: Summary;
        commission_rate?: number | null;
        refunds?: RefundRecord[];
        can_manage_refunds?: boolean;
    }>(),
    {
        payments: undefined,
        records: undefined,
        stats: undefined,
        commission_rate: null,
        refunds: () => [],
        can_manage_refunds: false,
    },
);

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Payments',
                href: '/payments',
            },
        ],
    },
});

const initialTab = new URLSearchParams(typeof window === 'undefined' ? '' : window.location.search).get('tab');
const tab = ref<'payments' | 'requests' | 'history'>(initialTab === 'requests' || initialTab === 'history' ? initialTab : 'payments');
const reviewingId = ref<number | null>(null);
const reviewing = computed(() => props.refunds.find((refund) => refund.id === reviewingId.value) ?? null);
const pendingRefunds = computed(() => props.refunds.filter((refund) => ['pending_review', 'failed'].includes(refund.status)).length);

const search = ref('');
const typeFilter = ref('all');
const statusFilter = ref('all');
const dateFrom = ref('');
const dateTo = ref('');

const page = ref(1);
const pageSize = 6;

const selected = ref<PaymentRow | null>(null);

const rows = computed<PaymentRow[]>(() => {
    const source = props.payments ?? props.records;

    if (!source) {
        return [];
    }

    return Array.isArray(source)
        ? source
        : source.data ?? [];
});

function amountNumber(value: Amount): number {
    if (typeof value === 'number') {
        return Number.isFinite(value) ? value : 0;
    }

    const clean = String(value ?? '')
        .replace(/,/g, '')
        .replace(/[^\d.-]/g, '');

    const result = Number(clean);

    return clean && Number.isFinite(result)
        ? result
        : 0;
}

function peso(value: Amount): string {
    if (
        value === null ||
        value === undefined ||
        value === ''
    ) {
        return '—';
    }

    return new Intl.NumberFormat('en-PH', {
        style: 'currency',
        currency: 'PHP',
        minimumFractionDigits: 2,
    }).format(amountNumber(value));
}

function typeKey(value: string): string {
    const v = String(value || '').toLowerCase();

    if (
        [
            'full',
            'full_rental',
            'full_payment',
            'full-payment',
        ].includes(v)
    ) {
        return 'full';
    }

    if (
        [
            'downpayment',
            'down_payment',
            'deposit',
        ].includes(v)
    ) {
        return 'downpayment';
    }

    if (
        [
            'remaining_balance',
            'balance',
            'balance_payment',
        ].includes(v)
    ) {
        return 'balance';
    }

    return 'other';
}

function typeLabel(value: string): string {
    const key = typeKey(value);

    if (key === 'full') {
        return 'Full Payment';
    }

    if (key === 'downpayment') {
        return 'Downpayment';
    }

    if (key === 'balance') {
        return 'Balance Payment';
    }

    return value || 'Other';
}

function downpaymentShare(row: PaymentRow): string | null {
    if (typeKey(row.payment_type) !== 'downpayment') {
        return null;
    }

    const total = amountNumber(row.rental_total);
    const paid = amountNumber(row.amount_paid);

    if (total <= 0 || paid <= 0) {
        return null;
    }

    return `${Math.round((paid / total) * 100)}%`;
}

function paymentTypeLabel(row: PaymentRow): string {
    const label = typeLabel(row.payment_type);
    const share = downpaymentShare(row);

    return share ? `${label} (${share})` : label;
}

function remainingOf(row: PaymentRow): number {
    if (row.remaining_balance !== null && row.remaining_balance !== undefined && row.remaining_balance !== '') {
        return amountNumber(row.remaining_balance);
    }

    if (typeKey(row.payment_type) === 'full') {
        return 0;
    }

    if (row.balance_status === 'Due on trip') {
        return amountNumber(row.balance_amount);
    }

    return 0;
}

function rentalStatusKey(row: PaymentRow): string {
    const state = stateKey(row);

    if (state === 'refunded' || state === 'failed' || state === 'pending') {
        return state;
    }

    return remainingOf(row) > 0 ? 'partially_paid' : 'fully_paid';
}

function rentalStatusLabel(row: PaymentRow): string {
    if (row.partially_refunded && stateKey(row) === 'verified') {
        return 'Partially Refunded';
    }

    const labels: Record<string, string> = {
        fully_paid: 'Fully Paid',
        partially_paid: 'Partially Paid',
        pending: 'Pending',
        failed: 'Failed',
        refunded: 'Refunded',
    };

    return labels[rentalStatusKey(row)] || 'Unknown';
}

function rentalStatusClass(row: PaymentRow): string {
    const key = rentalStatusKey(row);

    if (key === 'fully_paid') {
        return 'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-300';
    }

    if (key === 'partially_paid' || key === 'pending') {
        return 'bg-amber-50 text-amber-700 dark:bg-amber-500/15 dark:text-amber-200';
    }

    if (key === 'refunded') {
        return 'bg-violet-50 text-violet-700 dark:bg-violet-500/15 dark:text-violet-300';
    }

    return 'bg-rose-50 text-rose-700 dark:bg-rose-500/15 dark:text-rose-300';
}

function isVerified(row: PaymentRow): boolean {
    if (row.is_verified === true) {
        return true;
    }

    if (row.is_verified === false) {
        return false;
    }

    // Status must come from the verified backend record.
    return [
        'verified',
        'paid',
        'succeeded',
        'completed',
    ].includes(
        String(row.status || '').toLowerCase(),
    );
}

function stateKey(row: PaymentRow): string {
    const v = String(row.status || '').toLowerCase();

    // Refund/reversal takes precedence over the old paid state.
    if (['refunded', 'reversed'].includes(v)) {
        return 'refunded';
    }

    if (
        [
            'failed',
            'cancelled',
            'canceled',
            'expired',
        ].includes(v)
    ) {
        return 'failed';
    }

    if (isVerified(row)) {
        return 'verified';
    }

    return 'pending';
}

function openRefundFor(row: PaymentRow): void {
    const refund = props.refunds.find((item) => item.reference === row.refund_reference || item.booking.id === row.booking_id);

    if (refund) {
        reviewingId.value = refund.id;
    }
}

function stateLabel(row: PaymentRow): string {
    if (row.partially_refunded && stateKey(row) === 'verified') {
        return 'Partially Refunded';
    }

    const labels: Record<string, string> = {
        verified: 'Paid',
        pending: 'Pending',
        failed: 'Failed',
        refunded: 'Refunded',
    };

    return labels[stateKey(row)] || 'Unknown';
}

function dateValue(row: PaymentRow): string {
    const str = row.verified_at || row.paid_at || '';
    const iso = str.match(/^\d{4}-\d{2}-\d{2}/);

    return iso ? iso[0] : '';
}

function displayDate(value?: string | null): string {
    if (!value) {
        return '—';
    }

    const parsed = new Date(
        value.replace(' ', 'T'),
    );

    if (Number.isNaN(parsed.getTime())) {
        return value;
    }

    return new Intl.DateTimeFormat('en-PH', {
        timeZone: 'Asia/Manila',
        month: 'short',
        day: '2-digit',
        year: 'numeric',
        hour: 'numeric',
        minute: '2-digit',
    }).format(parsed);
}

function chipClass(row: PaymentRow): string {
    const key = stateKey(row);

    if (key === 'verified') {
        return 'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-300';
    }

    if (key === 'pending') {
        return 'bg-amber-50 text-amber-800 dark:bg-amber-500/15 dark:text-amber-300';
    }

    if (key === 'refunded') {
        return 'bg-violet-50 text-violet-700 dark:bg-violet-500/15 dark:text-violet-300';
    }

    return 'bg-rose-50 text-rose-700 dark:bg-rose-500/15 dark:text-rose-300';
}

function typeChipClass(value: string): string {
    const key = typeKey(value);

    if (key === 'full') {
        return 'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-300';
    }

    if (key === 'downpayment') {
        return 'bg-blue-50 text-blue-700 dark:bg-blue-500/15 dark:text-blue-300';
    }

    return 'bg-slate-100 text-slate-700 dark:bg-white/10 dark:text-slate-300';
}

// Verified payments only for fallback summary calculations.
const verifiedRows = computed(() =>
    rows.value.filter(
        (row) => stateKey(row) === 'verified',
    ),
);

function totalFor(kind: string): number {
    return verifiedRows.value
        .filter(
            (row) =>
                kind === 'all' ||
                typeKey(row.payment_type) === kind,
        )
        .reduce(
            (sum, row) =>
                sum + amountNumber(row.amount_paid),
            0,
        );
}

const summary = computed(() => ({
    all:
        props.stats?.total_payments ??
        totalFor('all'),

    full:
        props.stats?.full_payments ??
        totalFor('full'),

    down:
        props.stats?.downpayments ??
        totalFor('downpayment'),

    commission:
        props.stats?.total_commission ??
        verifiedRows.value.reduce(
            (sum, row) =>
                sum +
                amountNumber(row.commission_amount),
            0,
        ),
}));

const statItems = computed<StatCardItem[]>(() => [
    { key: 'all', title: 'Total Received', value: peso(summary.value.all), hint: 'Verified payments received by SafeSakay', icon: Wallet, tone: 'blue' },
    { key: 'full', title: 'Full Payments', value: peso(summary.value.full), hint: 'Paid in full at checkout', icon: CheckCircle2, tone: 'green' },
    { key: 'downpayment', title: 'Downpayments', value: peso(summary.value.down), hint: 'Rest is paid to the operator', icon: Clock3, tone: 'amber' },
    { key: 'commission', title: 'Total Commission', value: peso(summary.value.commission), hint: 'Same total as My Commission', icon: BadgePercent, tone: 'violet' },
]);

const filtered = computed(() =>
    rows.value.filter((row) => {
        const query =
            search.value.trim().toLowerCase();

        const hay = [
            row.booking_reference,
            row.passenger_name,
            row.boat_name,
            row.transaction_id,
        ]
            .filter(Boolean)
            .join(' ')
            .toLowerCase();

        if (query && !hay.includes(query)) {
            return false;
        }

        if (
            typeFilter.value !== 'all' &&
            typeKey(row.payment_type) !==
                typeFilter.value
        ) {
            return false;
        }

        if (
            statusFilter.value !== 'all' &&
            rentalStatusKey(row) !== statusFilter.value
        ) {
            return false;
        }

        const date = dateValue(row);

        if (
            dateFrom.value &&
            (!date || date < dateFrom.value)
        ) {
            return false;
        }

        if (
            dateTo.value &&
            (!date || date > dateTo.value)
        ) {
            return false;
        }

        return true;
    }),
);

const pageCount = computed(() =>
    Math.max(
        1,
        Math.ceil(
            filtered.value.length / pageSize,
        ),
    ),
);

const shown = computed(() =>
    filtered.value.slice(
        (page.value - 1) * pageSize,
        page.value * pageSize,
    ),
);

const firstItem = computed(() =>
    filtered.value.length
        ? (page.value - 1) * pageSize + 1
        : 0,
);

const lastItem = computed(() =>
    Math.min(
        page.value * pageSize,
        filtered.value.length,
    ),
);

watch(
    [
        search,
        typeFilter,
        statusFilter,
        dateFrom,
        dateTo,
    ],
    () => {
        page.value = 1;
    },
);

function csvCell(value: unknown): string {
    let result = String(value ?? '');

    // Prevent spreadsheet formula injection.
    if (/^\s*[=+\-@]/.test(result)) {
        result = "'" + result;
    }

    return `"${result.replace(/"/g, '""')}"`;
}

function exportCsv(): void {
    const columns = [
        'Booking Reference',
        'Passenger',
        'Boat',
        'Payment Type',
        'Amount Paid',
        'Rental Total',
        'Commission',
        'Status',
        'Payment Date',
        'Payment Method',
        'Transaction ID',
    ];

    const data = filtered.value.map((row) => [
        row.booking_reference,
        row.passenger_name,
        row.boat_name ?? '',
        typeLabel(row.payment_type),
        amountNumber(row.amount_paid),
        amountNumber(row.rental_total),
        amountNumber(row.commission_amount),
        stateLabel(row),
        row.verified_at || row.paid_at || '',
        row.payment_method || '',
        row.transaction_id || '',
    ]);

    const csv = [columns, ...data]
        .map((line) =>
            line.map(csvCell).join(','),
        )
        .join('\r\n');

    const link = document.createElement('a');

    const url = URL.createObjectURL(
        new Blob(['\uFEFF' + csv], {
            type: 'text/csv;charset=utf-8',
        }),
    );

    link.href = url;
    link.download = 'safesakay-payments.csv';

    document.body.appendChild(link);
    link.click();
    link.remove();

    setTimeout(() => {
        URL.revokeObjectURL(url);
    }, 500);
}

function printReceipt(): void {
    if (
        !selected.value ||
        stateKey(selected.value) !== 'verified'
    ) {
        return;
    }

    window.print();
}
</script>

<template>
    <Head title="Payments" />

    <main
        class="flex min-h-full flex-1 flex-col gap-5 p-6"
    >
        <!-- HEADER -->
        <header
            class="flex flex-wrap items-start justify-between gap-3"
        >
            <div>
                <h1
                    class="text-2xl font-bold tracking-tight text-[#0F4C8A] dark:text-sky-100"
                >
                    Payments
                </h1>

                <p
                    class="mt-1 text-sm text-ink-muted dark:text-slate-400"
                >
                    Passenger payments for private rentals, received by SafeSakay through Xendit. Balances paid on the trip are shown under each downpayment.
                </p>
            </div>

            <button
                v-if="tab === 'payments'"
                type="button"
                :disabled="!filtered.length"
                class="inline-flex h-10 items-center gap-2 rounded-xl border border-[#d7e5f4] bg-white px-4 text-sm font-semibold text-[#1d5c9f] hover:bg-blue-50 disabled:opacity-50 dark:border-white/10 dark:bg-white/5 dark:text-sky-200"
                @click="exportCsv"
            >
                <Download class="size-4" />
                Export CSV
            </button>
        </header>

        <nav class="flex flex-wrap gap-2" aria-label="Payment sections">
            <button
                v-for="item in [
                    { key: 'payments', label: 'Payment Transactions' },
                    { key: 'requests', label: 'Refund Requests' },
                    { key: 'history', label: 'Refund History' },
                ] as const"
                :key="item.key"
                type="button"
                class="inline-flex items-center gap-2 rounded-full px-4 py-2 text-sm font-semibold transition"
                :class="tab === item.key ? 'bg-[#1479E8] text-white shadow-sm' : 'bg-[#e8f4fc] text-[#1479E8] hover:bg-[#dbeefb] dark:bg-white/10 dark:text-sky-200'"
                @click="tab = item.key"
            >
                {{ item.label }}
                <span
                    v-if="item.key === 'requests' && pendingRefunds"
                    class="rounded-full px-1.5 text-[11px] font-bold"
                    :class="tab === item.key ? 'bg-white text-[#1479E8] dark:bg-[#102036] dark:text-slate-100' : 'bg-rose-500 text-white'"
                >
                    {{ pendingRefunds }}
                </span>
            </button>
        </nav>

        <RefundRecordsTable v-if="tab !== 'payments'" :refunds="refunds" :mode="tab" @open="(refund) => (reviewingId = refund.id)" />

        <template v-if="tab === 'payments'">
        <StatCards :items="statItems" />

        <!-- PAYMENT RECORDS -->
        <section
            class="overflow-hidden rounded-2xl border border-[#d9e7f5] bg-white shadow-sm dark:border-white/10 dark:bg-[#102036]"
        >
            <div
                class="flex flex-wrap items-center justify-between gap-2 border-b border-[#e8eff7] px-4 py-4 dark:border-white/10"
            >
                <div>
                    <h2
                        class="font-bold text-ink dark:text-sky-100"
                    >
                        Passenger Payment Records
                    </h2>

                    <p
                        class="mt-1 text-xs text-ink-muted"
                    >
                        {{ filtered.length }}
                        matching payment records
                    </p>
                </div>

                <span
                    class="text-xs text-ink-muted"
                >
                    One row per payment transaction
                </span>
            </div>

            <!-- FILTERS -->
            <div
            class="flex flex-wrap items-center gap-3 px-4 py-4"
        >
                <label class="relative min-w-[220px] flex-1">
                    <Search
                        class="pointer-events-none absolute top-3 left-3 size-4 text-[#94a8bd]"
                    />

                    <input
                        v-model="search"
                        type="search"
                        placeholder="Search passenger, booking, boat..."
                        aria-label="Search payments"
                        class="h-10 w-full rounded-xl border border-[#d8e5f3] bg-white pr-3 pl-9 text-sm outline-none focus:border-blue-400 dark:border-white/10 dark:bg-white/5 dark:text-white"
                    />
                </label>

                <select
                    v-model="typeFilter"
                    aria-label="Payment type"
                    class="h-10 w-[190px] shrink-0 rounded-xl border border-[#d8e5f3] bg-white px-3 text-sm dark:border-white/10 dark:bg-[#102036] dark:text-white"
                >
                    <option value="all">
                        All Payment Types
                    </option>

                    <option value="full">
                        Full Payment
                    </option>

                    <option value="downpayment">
                        Downpayment
                    </option>

                    <option value="balance">
                        Balance Payment
                    </option>

                    <option value="other">
                        Other
                    </option>
                </select>

                <select
                    v-model="statusFilter"
                    aria-label="Rental payment status"
                    class="h-10 w-[180px] shrink-0 rounded-xl border border-[#d8e5f3] bg-white px-3 text-sm dark:border-white/10 dark:bg-[#102036] dark:text-white"
                >
                    <option value="all">
                        All Rental Status
                    </option>

                    <option value="fully_paid">
                        Fully Paid
                    </option>

                    <option value="partially_paid">
                        Partially Paid
                    </option>

                    <option value="pending">
                        Pending
                    </option>

                    <option value="failed">
                        Failed
                    </option>

                    <option value="refunded">
                        Refunded
                    </option>
                </select>

                <label
                    class="flex h-10 w-[170px] shrink-0 items-center gap-2 rounded-xl border border-[#d8e5f3] bg-white px-3 dark:border-white/10 dark:bg-[#102036]"
                >
                    <CalendarDays class="size-4 shrink-0 text-[#94a8bd]" />
                    <input
                        v-model="dateFrom"
                        type="date"
                        aria-label="From date"
                        class="w-full bg-transparent text-sm font-normal text-ink outline-none dark:text-white"
                    />
                </label>

                <label
                    class="flex h-10 w-[170px] shrink-0 items-center gap-2 rounded-xl border border-[#d8e5f3] bg-white px-3 dark:border-white/10 dark:bg-[#102036]"
                >
                    <CalendarDays class="size-4 shrink-0 text-[#94a8bd]" />
                    <input
                        v-model="dateTo"
                        type="date"
                        aria-label="To date"
                        class="w-full bg-transparent text-sm font-normal text-ink outline-none dark:text-white"
                    />
                </label>
            </div>

            <!-- DESKTOP TABLE -->
            <div class="hidden overflow-x-auto md:block">
                <table
                    class="w-full min-w-[1180px] border-collapse text-left text-sm"
                >
                    <thead
                        class="bg-[#f5f8fc] text-[11px] font-bold uppercase text-ink-muted dark:bg-white/5"
                    >
                        <tr>
                            <th class="px-4 py-3">
                                Booking
                            </th>

                            <th class="px-4 py-3">
                                Passenger
                            </th>

                            <th class="px-4 py-3">
                                Boat
                            </th>

                            <th class="px-4 py-3">
                                Payment Type
                            </th>

                            <th class="px-4 py-3 text-right">
                                Total Rental
                            </th>

                            <th class="px-4 py-3 text-right">
                                Amount Paid
                            </th>

                            <th class="px-4 py-3 text-right">
                                Remaining Balance
                            </th>

                            <th class="px-4 py-3 whitespace-nowrap">
                                Rental Payment Status
                            </th>

                            <th class="px-4 py-3 whitespace-nowrap">
                                Date
                            </th>

                            <th class="px-4 py-3 text-right">
                                Action
                            </th>
                        </tr>
                    </thead>

                    <tbody>
                        <tr
                            v-for="row in shown"
                            :key="row.id"
                            class="border-t border-[#edf2f8] text-ink-soft hover:bg-[#f9fbff] dark:border-white/10 dark:text-slate-200 dark:hover:bg-white/5"
                        >
                            <td
                                class="px-4 py-3 font-semibold whitespace-nowrap text-[#1766c3] dark:text-sky-300"
                            >
                                {{ row.booking_reference }}
                            </td>

                            <td class="px-4 py-3">
                                {{ row.passenger_name }}
                            </td>

                            <td class="px-4 py-3">
                                {{ row.boat_name || '—' }}
                            </td>

                            <td class="px-4 py-3">
                                <span
                                    class="rounded-full px-2.5 py-1 text-xs font-semibold whitespace-nowrap"
                                    :class="typeChipClass(row.payment_type)"
                                >
                                    {{ paymentTypeLabel(row) }}
                                </span>
                                <p
                                    v-if="row.balance_status"
                                    class="mt-1 text-[11px] whitespace-nowrap text-amber-700"
                                >
                                    Balance: {{ peso(row.balance_amount ?? 0) }} · {{ row.balance_status }}
                                </p>
                            </td>

                            <td
                                class="px-4 py-3 text-right font-semibold whitespace-nowrap tabular-nums text-ink dark:text-slate-100"
                            >
                                {{ peso(row.rental_total) }}
                            </td>

                            <td
                                class="px-4 py-3 text-right font-semibold whitespace-nowrap tabular-nums text-ink dark:text-slate-100"
                            >
                                {{ peso(row.amount_paid) }}
                            </td>

                            <td
                                class="px-4 py-3 text-right font-semibold whitespace-nowrap tabular-nums text-ink dark:text-slate-100"
                            >
                                {{ peso(remainingOf(row)) }}
                            </td>

                            <td class="px-4 py-3 align-middle">
                                <div class="flex items-center gap-2 whitespace-nowrap">
                                    <span
                                        class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-semibold"
                                        :class="rentalStatusClass(row)"
                                    >
                                        <CheckCircle2 v-if="rentalStatusKey(row) === 'fully_paid'" class="size-3.5 shrink-0" />
                                        <Clock3 v-else-if="rentalStatusKey(row) === 'partially_paid' || rentalStatusKey(row) === 'pending'" class="size-3.5 shrink-0" />
                                        {{ rentalStatusLabel(row) }}
                                    </span>
                                </div>
                                <button
                                    v-if="row.refund_status_label"
                                    type="button"
                                    class="mt-1 block text-[11px] font-semibold whitespace-nowrap text-violet-700 hover:underline dark:text-violet-300"
                                    @click="openRefundFor(row)"
                                >
                                    Refund {{ row.refund_reference }} · {{ row.refund_status_label }}
                                </button>
                            </td>

                            <td class="px-4 py-3 align-middle">
                                <span class="inline-flex items-center gap-1.5 text-xs whitespace-nowrap text-ink-soft dark:text-slate-300">
                                    <CalendarDays class="size-3.5 shrink-0 text-[#94a8bd]" />
                                    {{
                                        displayDate(
                                            row.verified_at ||
                                                row.paid_at,
                                        )
                                    }}
                                </span>
                            </td>

                            <td class="px-4 py-3 text-right">
                                <button
                                    type="button"
                                    class="inline-flex items-center gap-1.5 rounded-lg border border-[#dbe7f5] px-3 py-1.5 text-xs font-semibold text-[#286eb9] hover:bg-blue-50 dark:border-white/10 dark:text-sky-300"
                                    @click="selected = row"
                                >
                                    <Eye class="size-3.5" />
                                    View
                                </button>
                            </td>
                        </tr>

                        <tr v-if="!shown.length">
                            <td
                                colspan="10"
                                class="px-5 py-12 text-center text-sm text-ink-muted"
                            >
                                No payments match your filters.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- MOBILE CARDS -->
            <div
                class="space-y-3 px-4 pb-4 md:hidden"
            >
                <article
                    v-for="row in shown"
                    :key="row.id"
                    class="rounded-xl border border-[#e2ebf6] p-3 dark:border-white/10"
                >
                    <div
                        class="flex items-start justify-between gap-2"
                    >
                        <div>
                            <p
                                class="font-semibold text-[#1766c3] dark:text-sky-300"
                            >
                                {{ row.booking_reference }}
                            </p>

                            <p
                                class="text-xs text-ink-muted"
                            >
                                {{ row.passenger_name }}
                                ·
                                {{ row.boat_name || '—' }}
                            </p>
                        </div>

                        <span
                            class="rounded-full px-2 py-1 text-xs"
                            :class="chipClass(row)"
                        >
                            {{ stateLabel(row) }}
                        </span>
                    </div>

                    <div
                        class="mt-3 flex items-center justify-between"
                    >
                        <span
                            class="text-xs text-ink-muted"
                        >
                            {{ typeLabel(row.payment_type) }}
                        </span>

                        <strong
                            class="text-ink dark:text-sky-100"
                        >
                            {{ peso(row.amount_paid) }}
                        </strong>
                    </div>

                    <button
                        type="button"
                        class="mt-3 inline-flex items-center gap-1 text-sm font-semibold text-blue-600"
                        @click="selected = row"
                    >
                        <Eye class="size-4" />
                        View Details
                    </button>
                </article>

                <p
                    v-if="!shown.length"
                    class="py-6 text-center text-sm text-ink-muted"
                >
                    No payments match your filters.
                </p>
            </div>

            <!-- PAGINATION -->
            <footer
                class="flex flex-wrap items-center justify-between gap-3 border-t border-[#e8eff7] px-4 py-3 text-xs text-ink-muted dark:border-white/10"
            >
                <span>
                    Showing {{ firstItem }}–{{ lastItem }}
                    of {{ filtered.length }} loaded records
                </span>

                <div class="flex items-center gap-2">
                    <button
                        type="button"
                        :disabled="page === 1"
                        aria-label="Previous page"
                        class="rounded-lg border border-[#d9e7f5] p-2 disabled:opacity-40 dark:border-white/10"
                        @click="page--"
                    >
                        <ChevronLeft class="size-4" />
                    </button>

                    <span
                        class="font-semibold text-[#2869b7] dark:text-sky-300"
                    >
                        {{ page }} / {{ pageCount }}
                    </span>

                    <button
                        type="button"
                        :disabled="page >= pageCount"
                        aria-label="Next page"
                        class="rounded-lg border border-[#d9e7f5] p-2 disabled:opacity-40 dark:border-white/10"
                        @click="page++"
                    >
                        <ChevronRight class="size-4" />
                    </button>
                </div>
            </footer>
        </section>

        <p
            class="text-xs text-ink-muted dark:text-slate-500"
        >
            Only server-verified payments count toward totals.
            Commission is displayed from saved payment
            records, not recalculated from rental price.
            Operator payouts are tracked separately.
        </p>
        </template>
    </main>

    <RefundReviewDialog :refund="reviewing" :can-manage="can_manage_refunds" @close="reviewingId = null" />

    <!-- PAYMENT DETAILS MODAL -->
    <Teleport to="body">
        <div
            v-if="selected"
            class="fixed inset-0 z-[100] flex items-center justify-center bg-[#0d2240]/60 p-4"
            @click.self="selected = null"
        >
            <section
                role="dialog"
                aria-modal="true"
                aria-labelledby="ss-payment-detail-title"
                class="flex max-h-[90vh] w-full max-w-[480px] flex-col overflow-hidden rounded-2xl bg-white shadow-2xl dark:bg-[#102036]"
            >
                <!-- MODAL HEADER -->
                <div
                    class="flex items-start justify-between border-b border-[#e6eef7] p-5 dark:border-white/10"
                >
                    <div>
                        <h2
                            id="ss-payment-detail-title"
                            class="text-lg font-bold text-ink dark:text-white"
                        >
                            Payment Details
                        </h2>

                        <p
                            class="mt-1 text-xs text-ink-muted"
                        >
                            {{ selected.booking_reference }}
                        </p>
                    </div>

                    <button
                        type="button"
                        aria-label="Close details"
                        class="rounded-lg p-1.5 text-ink-muted hover:bg-slate-100 dark:hover:bg-white/10"
                        @click="selected = null"
                    >
                        <X class="size-5" />
                    </button>
                </div>

                <!-- MODAL CONTENT -->
                <div
                    class="space-y-4 overflow-y-auto p-5"
                >
                    <div
                        class="rounded-xl bg-[#eef5ff] p-4 dark:bg-white/5"
                    >
                        <div
                            class="flex items-center justify-between"
                        >
                            <span
                                class="text-xs font-semibold text-ink-muted"
                            >
                                {{
                                    typeLabel(
                                        selected.payment_type,
                                    )
                                }}
                            </span>

                            <span
                                class="rounded-full px-2.5 py-1 text-xs font-semibold"
                                :class="chipClass(selected)"
                            >
                                {{ stateLabel(selected) }}
                            </span>
                        </div>

                        <p
                            class="mt-2 text-2xl font-extrabold text-[#1267c5] dark:text-sky-200"
                        >
                            {{ peso(selected.amount_paid) }}
                        </p>

                        <p
                            class="mt-1 text-xs text-ink-muted"
                        >
                            {{
                                stateKey(selected) ===
                                'verified'
                                    ? 'Server-verified payment'
                                    : 'Not currently verified as received'
                            }}
                        </p>
                    </div>

                    <!-- PAYMENT BREAKDOWN -->
                    <dl
                        class="divide-y divide-[#edf2f8] text-sm dark:divide-white/10"
                    >
                        <div
                            class="flex justify-between gap-4 py-2.5"
                        >
                            <dt class="text-ink-muted">
                                Passenger
                            </dt>

                            <dd
                                class="text-right font-semibold dark:text-slate-200"
                            >
                                {{ selected.passenger_name }}
                            </dd>
                        </div>

                        <div
                            class="flex justify-between gap-4 py-2.5"
                        >
                            <dt class="text-ink-muted">
                                Boat
                            </dt>

                            <dd
                                class="text-right font-semibold dark:text-slate-200"
                            >
                                {{ selected.boat_name || '—' }}
                            </dd>
                        </div>

                        <div
                            class="flex justify-between gap-4 py-2.5"
                        >
                            <dt class="text-ink-muted">
                                Rental Total
                            </dt>

                            <dd
                                class="text-right font-semibold dark:text-slate-200"
                            >
                                {{ peso(selected.rental_total) }}
                            </dd>
                        </div>

                        <div
                            class="flex justify-between gap-4 py-2.5"
                        >
                            <dt class="text-ink-muted">
                                This Payment
                            </dt>

                            <dd
                                class="text-right font-semibold dark:text-slate-200"
                            >
                                {{ peso(selected.amount_paid) }}
                            </dd>
                        </div>

                        <div
                            class="flex justify-between gap-4 py-2.5"
                        >
                            <dt class="text-ink-muted">
                                Remaining Balance
                            </dt>

                            <dd
                                class="text-right font-semibold dark:text-slate-200"
                            >
                                <template v-if="selected.balance_status">
                                    {{ peso(selected.balance_amount ?? 0) }}
                                    <span
                                        class="block text-xs font-medium"
                                        :class="selected.balance_status === 'Due on trip' ? 'text-amber-700' : 'text-emerald-700'"
                                    >
                                        {{ selected.balance_status }}<template v-if="selected.balance_paid_at"> · {{ selected.balance_paid_at }}</template>
                                    </span>
                                </template>
                                <template v-else>
                                    {{ peso(selected.remaining_balance) }}
                                </template>
                            </dd>
                        </div>

                        <div
                            class="flex justify-between gap-4 py-2.5"
                        >
                            <dt class="text-ink-muted">
                                Commission
                                <span
                                    v-if="selected.commission_rate_label"
                                >
                                    ({{
                                        selected.commission_rate_label
                                    }})
                                </span>
                            </dt>

                            <dd
                                class="text-right font-bold text-[#1267c5] dark:text-sky-200"
                            >
                                {{
                                    peso(
                                        selected.commission_amount,
                                    )
                                }}
                            </dd>
                        </div>

                        <div
                            v-if="
                                selected.operator_share !== null &&
                                selected.operator_share !== undefined
                            "
                            class="flex justify-between gap-4 py-2.5"
                        >
                            <dt class="text-ink-muted">
                                Operator Share
                            </dt>

                            <dd
                                class="text-right font-semibold dark:text-slate-200"
                            >
                                {{ peso(selected.operator_share) }}
                            </dd>
                        </div>

                        <div
                            class="flex justify-between gap-4 py-2.5"
                        >
                            <dt class="text-ink-muted">
                                Payment Method
                            </dt>

                            <dd
                                class="text-right font-semibold dark:text-slate-200"
                            >
                                {{
                                    selected.payment_method || '—'
                                }}
                            </dd>
                        </div>

                        <div
                            class="flex justify-between gap-4 py-2.5"
                        >
                            <dt class="text-ink-muted">
                                Received By
                            </dt>

                            <dd
                                class="text-right font-semibold dark:text-slate-200"
                            >
                                {{ selected.received_by_label || 'SafeSakay' }}
                            </dd>
                        </div>

                        <div
                            class="flex justify-between gap-4 py-2.5"
                        >
                            <dt class="text-ink-muted">
                                Payment Date
                            </dt>

                            <dd
                                class="text-right font-semibold dark:text-slate-200"
                            >
                                {{
                                    displayDate(
                                        selected.verified_at ||
                                            selected.paid_at,
                                    )
                                }}
                            </dd>
                        </div>

                        <div
                            class="flex justify-between gap-4 py-2.5"
                        >
                            <dt class="text-ink-muted">
                                Transaction ID
                            </dt>

                            <dd
                                class="max-w-[60%] break-all text-right font-mono text-xs dark:text-slate-200"
                            >
                                {{
                                    selected.transaction_id || '—'
                                }}
                            </dd>
                        </div>
                    </dl>

                    <p
                        class="rounded-lg bg-slate-50 p-3 text-xs leading-5 text-slate-600 dark:bg-white/5 dark:text-slate-400"
                    >
                        A recorded commission does not prove
                        that an operator payout was transferred.
                        Check Operator Payouts for actual
                        settlement status.
                    </p>
                </div>

                <!-- MODAL BUTTONS -->
                <div
                    class="flex flex-wrap justify-end gap-2 border-t border-[#e6eef7] p-4 dark:border-white/10"
                >
                    <Link
                        v-if="selected.booking_href"
                        :href="selected.booking_href"
                        class="inline-flex h-10 items-center rounded-xl border border-[#dbe7f5] px-3 text-sm font-semibold text-[#2869b5] dark:border-white/10 dark:text-sky-300"
                    >
                        View Booking
                    </Link>

                    <button
                        type="button"
                        class="h-10 rounded-xl border border-[#dbe7f5] px-3 text-sm font-semibold text-ink-soft dark:border-white/10 dark:text-slate-300"
                        @click="selected = null"
                    >
                        Close
                    </button>

                    <button
                        type="button"
                        :disabled="
                            stateKey(selected) !== 'verified'
                        "
                        class="inline-flex h-10 items-center gap-2 rounded-xl bg-[#1875d7] px-3 text-sm font-semibold text-white disabled:opacity-40"
                        @click="printReceipt"
                    >
                        <Printer class="size-4" />
                        Print Receipt
                    </button>
                </div>
            </section>
        </div>

        <!-- PRINT RECEIPT -->
        <div
            v-if="
                selected &&
                stateKey(selected) === 'verified'
            "
            id="ss-print-receipt"
            class="ss-print-only"
        >
            <h1>
                SafeSakay — Payment Receipt
            </h1>

            <p>
                Booking:
                {{ selected.booking_reference }}
            </p>

            <p>
                Transaction ID:
                {{ selected.transaction_id || '—' }}
            </p>

            <hr />

            <table>
                <tbody>
                    <tr>
                        <th>Passenger</th>
                        <td>
                            {{ selected.passenger_name }}
                        </td>
                    </tr>

                    <tr>
                        <th>Boat</th>
                        <td>
                            {{ selected.boat_name || '—' }}
                        </td>
                    </tr>

                    <tr>
                        <th>Payment Type</th>
                        <td>
                            {{
                                typeLabel(
                                    selected.payment_type,
                                )
                            }}
                        </td>
                    </tr>

                    <tr>
                        <th>Verified Amount Paid</th>
                        <td>
                            {{ peso(selected.amount_paid) }}
                        </td>
                    </tr>

                    <tr>
                        <th>Rental Total</th>
                        <td>
                            {{ peso(selected.rental_total) }}
                        </td>
                    </tr>

                    <tr>
                        <th>Commission (Recorded)</th>
                        <td>
                            {{
                                peso(
                                    selected.commission_amount,
                                )
                            }}
                        </td>
                    </tr>

                    <tr>
                        <th>Payment Method</th>
                        <td>
                            {{
                                selected.payment_method || '—'
                            }}
                        </td>
                    </tr>

                    <tr>
                        <th>Verified Date</th>
                        <td>
                            {{
                                displayDate(
                                    selected.verified_at ||
                                        selected.paid_at,
                                )
                            }}
                        </td>
                    </tr>

                    <tr>
                        <th>Status</th>
                        <td>Verified</td>
                    </tr>
                </tbody>
            </table>

            <p class="ss-print-note">
                This confirms the verified payment record
                only, not an operator payout.
            </p>
        </div>
    </Teleport>
</template>

<style>
.ss-print-only {
    display: none;
}

@media print {
    body * {
        visibility: hidden !important;
    }

    #ss-print-receipt,
    #ss-print-receipt * {
        visibility: visible !important;
    }

    #ss-print-receipt {
        display: block !important;
        position: fixed;
        inset: 0;
        background: white;
        padding: 24px;
        color: #142c4c;
        font-family: Arial, sans-serif;
        font-size: 13px;
    }

    #ss-print-receipt h1 {
        font-size: 22px;
        margin-bottom: 20px;
    }

    #ss-print-receipt table {
        border-collapse: collapse;
        width: 100%;
        margin-top: 20px;
    }

    #ss-print-receipt th,
    #ss-print-receipt td {
        border-bottom: 1px solid #ddd;
        padding: 10px 5px;
        text-align: left;
    }

    #ss-print-receipt th {
        width: 45%;
    }

    .ss-print-note {
        margin-top: 25px;
        font-size: 11px;
    }
}
</style>

