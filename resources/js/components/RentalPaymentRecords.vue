<script setup lang="ts">
import { CalendarDays, CheckCircle2, ChevronLeft, ChevronRight, Clock3, Eye, Search } from '@lucide/vue';
import { computed, ref, watch } from 'vue';

type Amount = number | string | null | undefined;

type PaymentRow = {
    id: number | string;
    booking_reference: string;
    passenger_name: string;
    boat_name: string | null;
    payment_type: string;
    amount_paid: Amount;
    rental_total: Amount;
    status: string;
    is_verified?: boolean;
    paid_at: string | null;
    verified_at?: string | null;
    booking_href?: string | null;
    remaining_balance?: Amount;
    balance_amount?: Amount;
    balance_status?: string | null;
    booking_id?: number;
    refund_reference?: string | null;
    refund_status_label?: string | null;
    partially_refunded?: boolean;
    received_by?: string | null;
};

const props = defineProps<{
    rows: PaymentRow[];
    dateFrom?: string;
    dateTo?: string;
    boatName?: string;
}>();

const search = ref('');
const typeFilter = ref('all');
const statusFilter = ref('all');
const from = ref(props.dateFrom || '');
const to = ref(props.dateTo || '');
const page = ref(1);
const pageSize = 6;

watch(
    () => [props.dateFrom, props.dateTo],
    () => {
        from.value = props.dateFrom || '';
        to.value = props.dateTo || '';
        page.value = 1;
    },
);

function amountNumber(value: Amount): number {
    if (typeof value === 'number') {
        return Number.isFinite(value) ? value : 0;
    }

    const clean = String(value ?? '').replace(/,/g, '').replace(/[^\d.-]/g, '');
    const result = Number(clean);

    return clean && Number.isFinite(result) ? result : 0;
}

function peso(value: Amount): string {
    return new Intl.NumberFormat('en-PH', {
        style: 'currency',
        currency: 'PHP',
        minimumFractionDigits: 2,
    }).format(amountNumber(value));
}

function typeKey(value: string): string {
    const key = String(value || '').toLowerCase();

    if (['full', 'full_rental', 'full_payment'].includes(key)) {
        return 'full';
    }

    if (['downpayment', 'down_payment', 'deposit'].includes(key)) {
        return 'downpayment';
    }

    if (['remaining_balance', 'balance', 'balance_payment'].includes(key)) {
        return 'balance';
    }

    return 'other';
}

function paymentTypeLabel(row: PaymentRow): string {
    const key = typeKey(row.payment_type);
    const label = key === 'full' ? 'Full Payment' : key === 'downpayment' ? 'Downpayment' : key === 'balance' ? 'Balance Payment' : 'Other';

    if (key !== 'downpayment') {
        return label;
    }

    const total = amountNumber(row.rental_total);
    const paid = amountNumber(row.amount_paid);

    return total > 0 && paid > 0 ? `${label} (${Math.round((paid / total) * 100)}%)` : label;
}

function typeChipClass(value: string): string {
    const key = typeKey(value);

    if (key === 'full') {
        return 'bg-emerald-50 text-emerald-700';
    }

    if (key === 'downpayment') {
        return 'bg-blue-50 text-blue-700';
    }

    return 'bg-slate-100 text-slate-700';
}

function verified(row: PaymentRow): boolean {
    return row.is_verified === true || ['verified', 'paid', 'succeeded', 'completed'].includes(String(row.status || '').toLowerCase());
}

function stateKey(row: PaymentRow): string {
    const status = String(row.status || '').toLowerCase();

    if (['refunded', 'reversed'].includes(status)) {
        return 'refunded';
    }

    if (['failed', 'cancelled', 'canceled', 'expired'].includes(status)) {
        return 'failed';
    }

    return verified(row) ? 'verified' : 'pending';
}

function remainingOf(row: PaymentRow): number {
    if (row.remaining_balance !== null && row.remaining_balance !== undefined && row.remaining_balance !== '') {
        return amountNumber(row.remaining_balance);
    }

    if (typeKey(row.payment_type) === 'full') {
        return 0;
    }

    return row.balance_status === 'Due on trip' ? amountNumber(row.balance_amount) : 0;
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

    return {
        fully_paid: 'Fully Paid',
        partially_paid: 'Partially Paid',
        pending: 'Pending',
        failed: 'Failed',
        refunded: 'Refunded',
    }[rentalStatusKey(row)] || 'Unknown';
}

function rentalStatusClass(row: PaymentRow): string {
    const key = rentalStatusKey(row);

    if (key === 'fully_paid') {
        return 'bg-emerald-50 text-emerald-700';
    }

    if (key === 'refunded') {
        return 'bg-violet-50 text-violet-700';
    }

    if (key === 'failed') {
        return 'bg-rose-50 text-rose-700';
    }

    return 'bg-amber-50 text-amber-700';
}

function dateValue(row: PaymentRow): string {
    const value = row.verified_at || row.paid_at || '';
    const match = value.match(/^\d{4}-\d{2}-\d{2}/);

    return match ? match[0] : '';
}

function displayDate(value?: string | null): string {
    if (!value) {
        return '—';
    }

    const parsed = new Date(value.replace(' ', 'T'));

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

const filtered = computed(() =>
    props.rows.filter((row) => {
        if (props.boatName && row.boat_name !== props.boatName) {
            return false;
        }

        const date = dateValue(row);

        if (from.value && date && date < from.value) {
            return false;
        }

        if (to.value && date && date > to.value) {
            return false;
        }

        if (typeFilter.value !== 'all' && typeKey(row.payment_type) !== typeFilter.value) {
            return false;
        }

        if (statusFilter.value !== 'all' && rentalStatusKey(row) !== statusFilter.value) {
            return false;
        }

        const query = search.value.trim().toLowerCase();

        if (!query) {
            return true;
        }

        return [row.booking_reference, row.passenger_name, row.boat_name].filter(Boolean).join(' ').toLowerCase().includes(query);
    }),
);

const pages = computed(() => Math.max(1, Math.ceil(filtered.value.length / pageSize)));
const shown = computed(() => filtered.value.slice((page.value - 1) * pageSize, page.value * pageSize));
const firstItem = computed(() => (filtered.value.length === 0 ? 0 : (page.value - 1) * pageSize + 1));
const lastItem = computed(() => Math.min(page.value * pageSize, filtered.value.length));

watch([search, typeFilter, statusFilter, from, to, filtered], () => {
    if (page.value > pages.value) {
        page.value = 1;
    }
});
</script>

<template>
    <section class="overflow-hidden rounded-2xl border border-[#d9e7f5] bg-white shadow-sm dark:bg-[#102036] dark:text-slate-100 dark:border-white/10">
        <div class="flex flex-wrap items-center justify-between gap-2 border-b border-[#e8eff7] px-4 py-4">
            <div>
                <h2 class="font-bold text-[#0f4c8a]">Passenger Payment Records</h2>
                <p class="mt-1 text-xs text-ink-muted">{{ filtered.length }} matching payment records</p>
            </div>
            <span class="text-xs text-ink-muted">One row per payment transaction</span>
        </div>
        <div class="flex flex-wrap items-center gap-3 px-4 py-4">
            <label class="relative min-w-[220px] flex-1">
                <Search class="pointer-events-none absolute top-3 left-3 size-4 text-[#94a8bd]" />
                <input v-model="search" type="search" placeholder="Search passenger, booking, boat..." class="h-10 w-full rounded-xl border border-[#d8e5f3] bg-white pr-3 pl-9 text-sm outline-none dark:bg-[#102036] dark:text-slate-100 dark:border-white/10" />
            </label>
            <select v-model="typeFilter" class="h-10 w-[190px] rounded-xl border border-[#d8e5f3] bg-white px-3 text-sm dark:bg-[#102036] dark:text-slate-100 dark:border-white/10">
                <option value="all">All Payment Types</option>
                <option value="full">Full Payment</option>
                <option value="downpayment">Downpayment</option>
                <option value="balance">Balance Payment</option>
                <option value="other">Other</option>
            </select>
            <select v-model="statusFilter" class="h-10 w-[180px] rounded-xl border border-[#d8e5f3] bg-white px-3 text-sm dark:bg-[#102036] dark:text-slate-100 dark:border-white/10">
                <option value="all">All Rental Status</option>
                <option value="fully_paid">Fully Paid</option>
                <option value="partially_paid">Partially Paid</option>
                <option value="pending">Pending</option>
                <option value="failed">Failed</option>
                <option value="refunded">Refunded</option>
            </select>
            <label class="flex h-10 w-[170px] items-center gap-2 rounded-xl border border-[#d8e5f3] bg-white px-3 dark:bg-[#102036] dark:text-slate-100 dark:border-white/10">
                <CalendarDays class="size-4 text-[#94a8bd]" />
                <input v-model="from" type="date" class="w-full bg-transparent text-sm outline-none" />
            </label>
            <label class="flex h-10 w-[170px] items-center gap-2 rounded-xl border border-[#d8e5f3] bg-white px-3 dark:bg-[#102036] dark:text-slate-100 dark:border-white/10">
                <CalendarDays class="size-4 text-[#94a8bd]" />
                <input v-model="to" type="date" class="w-full bg-transparent text-sm outline-none" />
            </label>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full min-w-[1180px] text-left text-sm">
                <thead class="bg-[#f5f8fc] text-[11px] font-bold uppercase text-ink-muted">
                    <tr>
                        <th class="px-4 py-3">Booking</th>
                        <th class="px-4 py-3">Passenger</th>
                        <th class="px-4 py-3">Boat</th>
                        <th class="px-4 py-3">Payment Type</th>
                        <th class="px-4 py-3 text-right">Total Rental</th>
                        <th class="px-4 py-3 text-right">Amount Paid</th>
                        <th class="px-4 py-3 text-right">Remaining Balance</th>
                        <th class="px-4 py-3">Rental Payment Status</th>
                        <th class="px-4 py-3">Date</th>
                        <th class="px-4 py-3 text-right">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="row in shown" :key="row.id" class="border-t border-[#edf2f8]">
                        <td class="px-4 py-3 font-semibold whitespace-nowrap text-[#1766c3]">{{ row.booking_reference }}</td>
                        <td class="px-4 py-3">{{ row.passenger_name }}</td>
                        <td class="px-4 py-3">{{ row.boat_name || '—' }}</td>
                        <td class="px-4 py-3">
                            <span class="rounded-full px-2.5 py-1 text-xs font-semibold whitespace-nowrap" :class="typeChipClass(row.payment_type)">{{ paymentTypeLabel(row) }}</span>
                            <p v-if="row.balance_status" class="mt-1 text-[11px] whitespace-nowrap text-amber-700">Balance: {{ peso(row.balance_amount ?? 0) }} · {{ row.balance_status }}</p>
                        </td>
                        <td class="px-4 py-3 text-right font-semibold">{{ peso(row.rental_total) }}</td>
                        <td class="px-4 py-3 text-right font-semibold">{{ peso(row.amount_paid) }}</td>
                        <td class="px-4 py-3 text-right font-semibold">{{ peso(remainingOf(row)) }}</td>
                        <td class="px-4 py-3">
                            <span class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-semibold" :class="rentalStatusClass(row)">
                                <CheckCircle2 v-if="rentalStatusKey(row) === 'fully_paid'" class="size-3.5" />
                                <Clock3 v-else-if="rentalStatusKey(row) === 'partially_paid' || rentalStatusKey(row) === 'pending'" class="size-3.5" />
                                {{ rentalStatusLabel(row) }}
                            </span>
                            <p v-if="row.refund_status_label" class="mt-1 text-[11px] font-semibold text-violet-700">Refund {{ row.refund_reference }} · {{ row.refund_status_label }}</p>
                        </td>
                        <td class="px-4 py-3">
                            <span class="inline-flex items-center gap-1.5 text-xs whitespace-nowrap text-ink-muted">
                                <CalendarDays class="size-3.5" />
                                {{ displayDate(row.verified_at || row.paid_at) }}
                            </span>
                        </td>
                        <td class="px-4 py-3 text-right">
                            <a :href="row.booking_href || `/bookings/requests/${row.booking_id}`" class="inline-flex items-center gap-1.5 rounded-lg border border-[#dbe7f5] px-3 py-1.5 text-xs font-semibold text-[#286eb9]">
                                <Eye class="size-3.5" />
                                View
                            </a>
                        </td>
                    </tr>
                    <tr v-if="!shown.length">
                        <td colspan="10" class="px-5 py-12 text-center text-sm text-ink-muted">No payments match your filters.</td>
                    </tr>
                </tbody>
            </table>
        </div>
        <footer class="flex flex-wrap items-center justify-between gap-3 border-t border-[#e8eff7] px-4 py-3 text-xs text-ink-muted">
            <span>Showing {{ firstItem }}–{{ lastItem }} of {{ filtered.length }} loaded records</span>
            <div class="flex items-center gap-2">
                <button type="button" class="rounded-lg border border-[#d9e7f5] p-2 disabled:opacity-40" :disabled="page <= 1" @click="page -= 1">
                    <ChevronLeft class="size-4" />
                </button>
                <span class="font-semibold text-[#2869b7]">{{ Math.min(page, pages) }} / {{ pages }}</span>
                <button type="button" class="rounded-lg border border-[#d9e7f5] p-2 disabled:opacity-40" :disabled="page >= pages" @click="page += 1">
                    <ChevronRight class="size-4" />
                </button>
            </div>
            <p class="w-full text-[11px] text-ink-muted">Only server-verified payments count toward totals. Commission is displayed from saved payment records. Operator payouts are tracked separately.</p>
        </footer>
    </section>
</template>
