<script setup lang="ts">
import { Head, Link, useForm, usePoll } from '@inertiajs/vue3';
import {
    BadgeCheck,
    Banknote,
    CheckCircle2,
    Clock3,
    ExternalLink,
    Eye,
    FileDown,
    Hourglass,
    Percent,
    Search,
    Smartphone,
    Undo2,
    Wallet,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import StatCards from '@/components/admin/StatCards.vue';
import type { StatCardItem } from '@/components/admin/StatCards.vue';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';

type PayoutStatus = 'on_hold' | 'ready_for_payout' | 'paid_out' | 'cancelled' | 'refunded';

type HistoryRow = { title: string; detail: string; at_label: string | null; actor: string | null };

type CommissionRow = {
    id: number;
    code: string | null;
    boat_id: number | null;
    boat_name: string | null;
    operator_name: string | null;
    operator_contact: string | null;
    rental_date: string | null;
    rental_date_label: string | null;
    trip_status_label: string;
    trip_status_badge_class: string;
    rental_amount: string;
    commission_percentage: string;
    verified_downpayment: string;
    barangay_collected: string;
    remaining_balance: string;
    remaining_balance_raw?: number;
    commission_amount: string;
    operator_net: string;
    payout_status: PayoutStatus;
    payout_status_label: string;
    payout_status_badge_class: string;
    payout_hold_message: string | null;
    payout_hold_reason: string | null;
    payment_method_label: string | null;
    payout_at_label: string | null;
    payout_recorded_by: string | null;
    payout_remarks: string | null;
    payout_reference: string | null;
    commission_reversed: boolean;
    can_record_operator_payout: boolean;
    requires_operator_remittance: boolean;
    booking_href: string | null;
    history: HistoryRow[];
};


const props = defineProps<{
    commissions: CommissionRow[];
    boats: { id: number; name: string }[];
    stats: { total_commission: string; pending_payouts: string; payouts_released: string; refunded: string };
    can_manage: boolean;
}>();

defineOptions({
    layout: { breadcrumbs: [{ title: 'Operator Payouts', href: '/operator-payouts' }] },
});

usePoll(30000, { only: ['commissions', 'stats'] });


function localDate(value = new Date()) {
    const m = String(value.getMonth() + 1).padStart(2, '0');
    const d = String(value.getDate()).padStart(2, '0');

    return `${value.getFullYear()}-${m}-${d}`;
}

const firstOfMonth = () => localDate(new Date(new Date().getFullYear(), new Date().getMonth(), 1));
const dateFrom = ref(firstOfMonth());
const dateTo = ref('');
const search = ref('');
const boatFilter = ref('all');
const statusFilter = ref('all');

const readyCount = computed(() => props.commissions.filter((row) => row.payout_status === 'ready_for_payout').length);

const cards = computed<StatCardItem[]>(() => [
    { key: 'total', title: 'Total Commission', value: props.stats.total_commission, hint: 'Barangay share from verified rentals', icon: Percent, tone: 'green' },
    { key: 'ready_for_payout', title: 'Ready for Payout', value: props.stats.pending_payouts, hint: `${readyCount.value} operator share(s) to release`, icon: Hourglass, tone: 'amber' },
    { key: 'paid_out', title: 'Paid Out', value: props.stats.payouts_released, hint: 'Already given to operators', icon: BadgeCheck, tone: 'blue' },
    { key: 'refunded', title: 'Refunded', value: props.stats.refunded, hint: 'Commission reversed on refunds', icon: Undo2, tone: 'violet' },
]);

const filtered = computed(() => {
    const q = search.value.trim().toLowerCase();

    return props.commissions.filter((row) => {
        if (row.rental_date && ((dateFrom.value && row.rental_date < dateFrom.value) || (dateTo.value && row.rental_date > dateTo.value))) {
            return false;
        }

        if (boatFilter.value !== 'all' && String(row.boat_id) !== boatFilter.value) {
            return false;
        }

        if (statusFilter.value !== 'all' && row.payout_status !== statusFilter.value) {
            return false;
        }

        return !q || `${row.code} ${row.boat_name} ${row.operator_name}`.toLowerCase().includes(q);
    });
});

function selectCard(key: string) {
    if (key === 'total') {
        statusFilter.value = 'all';

        return;
    }

    statusFilter.value = statusFilter.value === key ? 'all' : key;
}

function resetFilters() {
    dateFrom.value = firstOfMonth();
    dateTo.value = '';
    search.value = '';
    boatFilter.value = 'all';
    statusFilter.value = 'all';
}

function exportCsv() {
    const params = new URLSearchParams({ from: dateFrom.value, to: dateTo.value, status: statusFilter.value, search: search.value });
    window.location.href = `/commissions/export?${params.toString()}`;
}

/* ---------- payout flow ---------- */
const viewing = ref<CommissionRow | null>(null);
const payoutOpen = ref(false);
const successOpen = ref(false);
const payoutForm = useForm({
    payment_method: 'cash' as 'cash' | 'gcash',
    gcash_reference: '',
    remarks: '',
    confirm_payout: false,
});

const payoutError = computed(() => (payoutForm.errors as Record<string, string | undefined>).commission);

function openPayout(row: CommissionRow) {
    viewing.value = row;
    payoutForm.reset();
    payoutForm.clearErrors();
    payoutForm.payment_method = 'gcash';
    payoutOpen.value = true;
}

function submitPayout() {
    if (!viewing.value) {
        return;
    }

    if (payoutForm.payment_method === 'cash') {
        payoutForm.gcash_reference = '';
    }

    payoutForm.post(`/commissions/${viewing.value.id}/record-payout`, {
        preserveScroll: true,
        onSuccess: () => {
            payoutOpen.value = false;
            successOpen.value = true;
        },
    });
}

function hasBalance(row: CommissionRow) {
    return Number(row.remaining_balance_raw ?? 0) > 0;
}

function payoutBanner(row: CommissionRow) {
    switch (row.payout_status) {
        case 'paid_out':
            return {
                icon: CheckCircle2,
                iconClass: 'text-emerald-600',
                box: 'border-emerald-200 bg-emerald-50 dark:border-emerald-500/30 dark:bg-emerald-500/10',
                title: 'Released to operator',
                detail: [row.payment_method_label, row.payout_at_label].filter(Boolean).join(' · '),
            };
        case 'ready_for_payout':
            return {
                icon: Wallet,
                iconClass: 'text-amber-600',
                box: 'border-amber-200 bg-amber-50 dark:border-amber-500/30 dark:bg-amber-500/10',
                title: 'Ready to release',
                detail: 'Trip completed. Pay the operator by GCash or cash.',
            };
        case 'refunded':
            return {
                icon: Undo2,
                iconClass: 'text-violet-600',
                box: 'border-violet-200 bg-violet-50 dark:border-violet-500/30 dark:bg-violet-500/10',
                title: 'Commission refunded',
                detail: 'No operator payout for this rental.',
            };
        case 'cancelled':
            return {
                icon: Undo2,
                iconClass: 'text-rose-600',
                box: 'border-rose-200 bg-rose-50 dark:border-rose-500/30 dark:bg-rose-500/10',
                title: 'Payout cancelled',
                detail: 'No operator payout for this rental.',
            };
        default:
            return {
                icon: Clock3,
                iconClass: 'text-slate-500',
                box: 'border-slate-200 bg-slate-50 dark:border-white/10 dark:bg-white/5',
                title: row.payout_hold_reason || 'On hold',
                detail: row.payout_hold_message,
            };
    }
}

</script>

<template>
    <Head title="Operator Payouts" />

    <div class="flex flex-1 flex-col gap-5 p-6">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <h1 class="text-2xl font-bold tracking-tight text-[#0F4C8A] dark:text-sky-100">Operator Payouts</h1>
                <p class="mt-1 max-w-3xl text-sm text-ink-muted dark:text-slate-400">
                    Passengers pay SafeSakay through Xendit. After a trip is completed, SafeSakay releases each operator's share (the amount paid minus the barangay commission) by GCash or cash.
                </p>
            </div>
        </div>

        <!-- ===================== PAYOUTS ===================== -->
        <template v-if="commissions">
            <StatCards :items="cards" :active="statusFilter === 'all' ? null : statusFilter" selectable @select="selectCard" />

            <div class="flex flex-col gap-3 rounded-xl border border-[#d7e8f6] bg-white p-4 xl:flex-row xl:flex-wrap xl:items-end dark:border-white/10 dark:bg-white/5">
                <label class="flex min-w-[140px] flex-col gap-1 text-xs font-medium text-ink-soft">
                    Date from
                    <input v-model="dateFrom" type="date" class="rounded-lg border border-[#d7e8f6] px-3 py-2 text-sm dark:border-white/10 dark:bg-transparent" />
                </label>
                <label class="flex min-w-[140px] flex-col gap-1 text-xs font-medium text-ink-soft">
                    Date to
                    <input v-model="dateTo" type="date" class="rounded-lg border border-[#d7e8f6] px-3 py-2 text-sm dark:border-white/10 dark:bg-transparent" />
                </label>
                <label class="flex min-w-[160px] flex-col gap-1 text-xs font-medium text-ink-soft">
                    Boat
                    <select v-model="boatFilter" class="rounded-lg border border-[#d7e8f6] px-3 py-2 text-sm dark:border-white/10 dark:bg-transparent">
                        <option value="all">All boats</option>
                        <option v-for="boat in boats" :key="boat.id" :value="String(boat.id)">{{ boat.name }}</option>
                    </select>
                </label>
                <label class="flex min-w-[160px] flex-col gap-1 text-xs font-medium text-ink-soft">
                    Payout status
                    <select v-model="statusFilter" class="rounded-lg border border-[#d7e8f6] px-3 py-2 text-sm dark:border-white/10 dark:bg-transparent">
                        <option value="all">All statuses</option>
                        <option value="on_hold">On Hold</option>
                        <option value="ready_for_payout">Ready for Payout</option>
                        <option value="paid_out">Paid Out</option>
                        <option value="refunded">Refunded</option>
                        <option value="cancelled">Cancelled</option>
                    </select>
                </label>
                <label class="flex min-w-[200px] flex-1 flex-col gap-1 text-xs font-medium text-ink-soft">
                    Search
                    <span class="relative">
                        <Search class="pointer-events-none absolute top-2.5 left-2.5 size-4 text-[#9bb0c2]" />
                        <input
                            v-model="search"
                            type="search"
                            placeholder="Booking ref, boat, operator…"
                            class="w-full rounded-lg border border-[#d7e8f6] py-2 pr-3 pl-9 text-sm dark:border-white/10 dark:bg-transparent"
                        />
                    </span>
                </label>
                <div class="flex gap-2">
                    <Button type="button" variant="outline" class="rounded-lg" @click="resetFilters">Reset</Button>
                    <Button type="button" class="rounded-lg bg-[#1479E8]" @click="exportCsv">
                        <FileDown class="size-4" />
                        Export
                    </Button>
                </div>
            </div>

            <div class="overflow-x-auto rounded-xl border border-[#d7e8f6] bg-white shadow-sm dark:border-white/10 dark:bg-white/5">
                <table class="w-full min-w-[960px] text-left text-sm">
                    <thead class="border-b border-[#eef4f9] bg-[#f8fbfe] text-xs font-bold tracking-wide text-ink-soft uppercase dark:border-white/10 dark:bg-white/5">
                        <tr>
                            <th class="px-4 py-3">Booking Ref</th>
                            <th class="px-4 py-3">Trip Date</th>
                            <th class="px-4 py-3">Boat / Operator</th>
                            <th class="px-4 py-3">Collected</th>
                            <th class="px-4 py-3">Commission</th>
                            <th class="px-4 py-3">Operator Share</th>
                            <th class="px-4 py-3">Payout Status</th>
                            <th class="px-4 py-3 text-right">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-if="!filtered.length">
                            <td colspan="8" class="px-4 py-10 text-center text-ink-muted">No payout records match your filters.</td>
                        </tr>
                        <tr v-for="row in filtered" :key="row.id" class="border-b border-[#f4f9fd] last:border-0 dark:border-white/5">
                            <td class="px-4 py-3 font-semibold text-[#1479E8]">{{ row.code }}</td>
                            <td class="px-4 py-3 text-ink dark:text-slate-200">{{ row.rental_date_label || '—' }}</td>
                            <td class="px-4 py-3">
                                <p class="font-semibold text-ink dark:text-slate-200">{{ row.boat_name || '—' }}</p>
                                <p class="text-xs text-ink-muted">{{ row.operator_name || '—' }}</p>
                            </td>
                            <td class="px-4 py-3 text-ink dark:text-slate-200">{{ row.barangay_collected }}</td>
                            <td class="px-4 py-3 text-ink dark:text-slate-200">{{ row.commission_amount }}</td>
                            <td class="px-4 py-3 font-bold text-[#0F4C8A] dark:text-sky-100">{{ row.operator_net }}</td>
                            <td class="px-4 py-3">
                                <span class="rounded-full px-2.5 py-0.5 text-xs font-semibold" :class="row.payout_status_badge_class">
                                    {{ row.payout_status_label }}
                                </span>
                                <p v-if="row.payout_hold_reason" class="mt-1 text-[11px] text-ink-muted">
                                    {{ row.payout_hold_reason }}
                                </p>
                                <Link
                                    v-if="row.payout_hold_reason === 'Trip not marked completed' && row.booking_href && can_manage"
                                    :href="row.booking_href"
                                    class="mt-0.5 inline-block text-[11px] font-semibold text-[#1479E8] hover:underline"
                                >
                                    Complete trip →
                                </Link>
                            </td>
                            <td class="px-4 py-3">
                                <div class="flex justify-end gap-2">
                                    <Button
                                        v-if="row.can_record_operator_payout && can_manage"
                                        type="button"
                                        size="sm"
                                        class="rounded-lg bg-[#16A34A] hover:bg-[#15803d]"
                                        @click="openPayout(row)"
                                    >
                                        <Wallet class="size-4" />
                                        Pay Out
                                    </Button>
                                    <Button type="button" size="sm" variant="outline" class="rounded-lg border-[#1479E8] text-[#1479E8]" @click="viewing = row">
                                        <Eye class="size-4" />
                                        View
                                    </Button>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </template>

        <!-- Details -->
        <Dialog :open="viewing !== null && !payoutOpen && !successOpen" @update:open="(v) => !v && (viewing = null)">
            <DialogContent v-if="viewing" class="flex max-h-[90vh] max-w-3xl flex-col gap-0 overflow-hidden rounded-2xl p-0">
                <DialogHeader class="border-b border-[#e8f1f8] px-6 py-4 pr-12 dark:border-white/10">
                    <div class="flex flex-wrap items-center gap-3">
                        <DialogTitle class="text-lg text-[#0F4C8A] dark:text-sky-100">Payout Details</DialogTitle>
                        <span class="rounded-full px-2.5 py-0.5 text-xs font-semibold whitespace-nowrap" :class="viewing.payout_status_badge_class">
                            {{ viewing.payout_status_label }}
                        </span>
                    </div>
                    <DialogDescription class="text-xs text-ink-muted">
                        {{ viewing.code }} · {{ viewing.rental_date_label }}
                    </DialogDescription>
                </DialogHeader>

                <div class="flex-1 space-y-4 overflow-y-auto px-6 py-5">
                    <!-- 1. Status: what is happening and what comes next -->
                    <div class="flex items-start gap-3 rounded-xl border px-4 py-3" :class="payoutBanner(viewing).box">
                        <component :is="payoutBanner(viewing).icon" class="mt-0.5 size-5 shrink-0" :class="payoutBanner(viewing).iconClass" />
                        <div class="min-w-0 flex-1">
                            <p class="text-sm font-semibold text-ink dark:text-slate-100">{{ payoutBanner(viewing).title }}</p>
                            <p v-if="payoutBanner(viewing).detail" class="text-xs text-ink-soft dark:text-slate-400">{{ payoutBanner(viewing).detail }}</p>
                        </div>
                    </div>

                    <!-- 2. Money flow -->
                    <section class="rounded-xl border border-[#eef4f9] p-4 dark:border-white/10">
                        <h3 class="text-xs font-bold tracking-wide text-ink-muted uppercase">Where the money goes</h3>
                        <ol class="mt-3 grid gap-2 sm:grid-cols-3">
                            <li class="rounded-xl bg-[#f4f9fd] px-3 py-3 dark:bg-white/5">
                                <p class="text-[11px] font-semibold text-ink-muted">1 · Passenger paid SafeSakay</p>
                                <p class="mt-1 text-lg font-bold text-ink dark:text-slate-100">{{ viewing.barangay_collected }}</p>
                                <p class="text-[11px] text-ink-muted">of {{ viewing.rental_amount }} rental</p>
                            </li>
                            <li class="rounded-xl bg-rose-50 px-3 py-3 dark:bg-rose-500/10">
                                <p class="text-[11px] font-semibold text-rose-700 dark:text-rose-300">2 · Barangay commission</p>
                                <p class="mt-1 text-lg font-bold text-rose-700 dark:text-rose-300">{{ viewing.commission_amount }}</p>
                                <p class="text-[11px] text-rose-600/80 dark:text-rose-300/80">{{ viewing.commission_percentage }} commission</p>
                            </li>
                            <li class="rounded-xl bg-emerald-50 px-3 py-3 ring-1 ring-emerald-200 dark:bg-emerald-500/10 dark:ring-emerald-500/30">
                                <p class="text-[11px] font-semibold text-emerald-700 dark:text-emerald-300">3 · SafeSakay sends to operator</p>
                                <p class="mt-1 text-lg font-bold text-emerald-700 dark:text-emerald-300">{{ viewing.operator_net }}</p>
                                <p class="text-[11px] text-emerald-700/80 dark:text-emerald-300/80">
                                    {{ viewing.payout_status === 'paid_out' ? 'Already released' : 'This payout' }}
                                </p>
                            </li>
                        </ol>
                        <p v-if="hasBalance(viewing)" class="mt-3 flex items-start gap-2 rounded-lg bg-[#f4f9fd] px-3 py-2 text-xs text-ink-soft dark:bg-white/5 dark:text-slate-400">
                            <Banknote class="mt-0.5 size-3.5 shrink-0" />
                            <span>The remaining balance of <strong class="text-ink dark:text-slate-200">{{ viewing.remaining_balance }}</strong> goes straight to the operator during the trip. It is not part of this payout.</span>
                        </p>
                    </section>

                    <!-- 3. Rental details -->
                    <section class="rounded-xl border border-[#eef4f9] p-4 dark:border-white/10">
                        <h3 class="text-xs font-bold tracking-wide text-ink-muted uppercase">Rental details</h3>
                        <dl class="mt-3 grid gap-x-6 gap-y-3 text-sm sm:grid-cols-2">
                            <div>
                                <dt class="text-xs text-ink-muted">Boat</dt>
                                <dd class="font-semibold text-ink dark:text-slate-100">{{ viewing.boat_name || '—' }}</dd>
                            </div>
                            <div>
                                <dt class="text-xs text-ink-muted">Operator</dt>
                                <dd class="font-semibold text-ink dark:text-slate-100">
                                    {{ viewing.operator_name || '—' }}
                                    <span v-if="viewing.operator_contact" class="font-normal text-ink-muted"> · {{ viewing.operator_contact }}</span>
                                </dd>
                            </div>
                            <div>
                                <dt class="text-xs text-ink-muted">Trip date</dt>
                                <dd class="font-semibold text-ink dark:text-slate-100">{{ viewing.rental_date_label || '—' }}</dd>
                            </div>
                            <div>
                                <dt class="text-xs text-ink-muted">Trip status</dt>
                                <dd>
                                    <span class="rounded-full px-2 py-0.5 text-xs font-semibold whitespace-nowrap" :class="viewing.trip_status_badge_class">
                                        {{ viewing.trip_status_label }}
                                    </span>
                                </dd>
                            </div>
                        </dl>
                    </section>

                    <section v-if="viewing.payout_status === 'paid_out'" class="rounded-xl border border-[#eef4f9] p-4 dark:border-white/10">
                        <h3 class="text-xs font-bold tracking-wide text-ink-muted uppercase">Payout Record</h3>
                        <dl class="mt-3 grid gap-3 text-sm sm:grid-cols-2">
                            <div>
                                <dt class="text-xs text-ink-muted">Method</dt>
                                <dd class="font-medium text-ink dark:text-slate-100">{{ viewing.payment_method_label || '—' }}</dd>
                            </div>
                            <div>
                                <dt class="text-xs text-ink-muted">Reference</dt>
                                <dd class="font-medium break-all text-ink dark:text-slate-100">{{ viewing.payout_reference || '—' }}</dd>
                            </div>
                            <div>
                                <dt class="text-xs text-ink-muted">Released on</dt>
                                <dd class="font-medium text-ink dark:text-slate-100">{{ viewing.payout_at_label || '—' }}</dd>
                            </div>
                            <div>
                                <dt class="text-xs text-ink-muted">Recorded by</dt>
                                <dd class="font-medium text-ink dark:text-slate-100">{{ viewing.payout_recorded_by || '—' }}</dd>
                            </div>
                            <div v-if="viewing.payout_remarks" class="sm:col-span-2">
                                <dt class="text-xs text-ink-muted">Remarks</dt>
                                <dd class="text-ink dark:text-slate-100">{{ viewing.payout_remarks }}</dd>
                            </div>
                        </dl>
                    </section>

                    <section class="rounded-xl border border-[#eef4f9] p-4 dark:border-white/10">
                        <h3 class="text-xs font-bold tracking-wide text-ink-muted uppercase">History</h3>
                        <ol v-if="viewing.history.length" class="mt-3 space-y-4 border-l-2 border-[#e8f1f8] pl-4 dark:border-white/10">
                            <li v-for="(item, idx) in viewing.history" :key="idx" class="relative text-sm">
                                <span class="absolute top-1 -left-[21px] size-2.5 rounded-full bg-[#1479E8] ring-4 ring-white dark:ring-slate-900" />
                                <div class="flex flex-wrap items-baseline justify-between gap-x-3">
                                    <p class="font-semibold text-ink dark:text-slate-200">{{ item.title }}</p>
                                    <p v-if="item.at_label" class="text-xs text-ink-muted">{{ item.at_label }}</p>
                                </div>
                                <p class="text-ink-soft">
                                    {{ item.detail }}<span v-if="item.actor" class="text-xs text-ink-muted"> · {{ item.actor }}</span>
                                </p>
                            </li>
                        </ol>
                        <p v-else class="mt-2 text-sm text-ink-muted">No history yet.</p>
                    </section>
                </div>

                <div class="flex flex-wrap items-center justify-between gap-2 border-t border-[#e8f1f8] bg-[#f8fbfe] px-6 py-3 dark:border-white/10 dark:bg-white/5">
                    <Link
                        v-if="viewing.booking_href"
                        :href="viewing.booking_href"
                        class="inline-flex h-9 items-center gap-1.5 rounded-xl px-2 text-sm font-semibold text-[#1479E8] hover:bg-[#e8f4fc] focus-visible:ring-2 focus-visible:ring-[#1479E8]/30 focus-visible:outline-none"
                    >
                        <ExternalLink class="size-4" />
                        Open rental
                    </Link>
                    <span v-else />
                    <div class="flex flex-wrap gap-2">
                        <Button type="button" variant="outline" class="rounded-xl" @click="viewing = null">Close</Button>
                        <Button
                            v-if="viewing.can_record_operator_payout && can_manage"
                            type="button"
                            class="rounded-xl bg-[#16A34A] hover:bg-[#15803d]"
                            @click="openPayout(viewing)"
                        >
                            <Wallet class="size-4" />
                            Pay Out {{ viewing.operator_net }}
                        </Button>
                    </div>
                </div>
            </DialogContent>
        </Dialog>

        <!-- Record payout -->
        <Dialog :open="payoutOpen" @update:open="(v) => !v && (payoutOpen = false)">
            <DialogContent v-if="viewing" class="flex max-h-[90vh] max-w-lg flex-col gap-0 overflow-hidden rounded-2xl p-0">
                <DialogHeader class="border-b border-[#e8f1f8] px-6 py-4 pr-12 dark:border-white/10">
                    <DialogTitle class="text-lg text-[#0F4C8A] dark:text-sky-100">Pay Out Operator</DialogTitle>
                    <DialogDescription class="text-xs text-ink-muted">{{ viewing.code }} · {{ viewing.boat_name }}</DialogDescription>
                </DialogHeader>

                <div class="flex-1 space-y-5 overflow-y-auto px-6 py-5">
                    <div class="flex items-center justify-between gap-3 rounded-xl bg-[#0F4C8A] px-4 py-3 text-white">
                        <div class="min-w-0">
                            <p class="text-xs text-sky-100/80">Release to</p>
                            <p class="truncate font-semibold">{{ viewing.operator_name || 'Operator' }}</p>
                        </div>
                        <p class="text-2xl font-bold">{{ viewing.operator_net }}</p>
                    </div>

                    <fieldset class="space-y-2">
                        <legend class="mb-2 text-sm font-semibold text-ink dark:text-slate-100">Payment method</legend>
                        <label
                            class="flex items-center gap-3 rounded-xl border-2 p-3 transition"
                            :class="[
                                payoutForm.payment_method === 'gcash' ? 'border-[#1479E8] bg-[#e8f4fc] dark:bg-sky-500/10' : 'border-[#d7e8f6] dark:border-white/10',
                                'cursor-pointer',
                            ]"
                        >
                            <input
                                v-model="payoutForm.payment_method"
                                type="radio"
                                value="gcash"
                                class="size-4 accent-[#1479E8]"
                            />
                            <Smartphone class="size-5 shrink-0 text-[#1479E8]" />
                            <span class="min-w-0 flex-1">
                                <span class="block text-sm font-semibold text-ink dark:text-slate-100">GCash</span>
                                <span class="block text-xs text-ink-soft">Send to the operator's GCash, then enter the reference</span>
                            </span>
                        </label>
                        <label
                            class="flex cursor-pointer items-center gap-3 rounded-xl border-2 p-3 transition"
                            :class="payoutForm.payment_method === 'cash' ? 'border-[#1479E8] bg-[#e8f4fc] dark:bg-sky-500/10' : 'border-[#d7e8f6] dark:border-white/10'"
                        >
                            <input v-model="payoutForm.payment_method" type="radio" value="cash" class="size-4 accent-[#1479E8]" />
                            <Banknote class="size-5 shrink-0 text-[#1479E8]" />
                            <span class="min-w-0 flex-1">
                                <span class="block text-sm font-semibold text-ink dark:text-slate-100">Cash</span>
                                <span class="block text-xs text-ink-soft">Claimed at the barangay office</span>
                            </span>
                        </label>
                    </fieldset>

                    <div v-if="payoutForm.payment_method === 'gcash'" class="space-y-1.5">
                        <Label for="gcash_reference">GCash reference number <span class="text-rose-500">*</span></Label>
                        <Input id="gcash_reference" v-model="payoutForm.gcash_reference" inputmode="numeric" placeholder="e.g. 1012345678901" />
                        <p class="text-xs text-ink-muted">From the GCash receipt after sending.</p>
                        <p v-if="payoutForm.errors.gcash_reference" class="text-xs text-rose-600">{{ payoutForm.errors.gcash_reference }}</p>
                    </div>

                    <div class="space-y-1.5">
                        <Label for="remarks">Remarks <span class="font-normal text-ink-muted">(optional)</span></Label>
                        <Textarea id="remarks" v-model="payoutForm.remarks" rows="2" placeholder="e.g. Received by operator at barangay hall" />
                    </div>

                    <label class="flex cursor-pointer items-start gap-2.5 rounded-xl border border-[#d7e8f6] bg-[#f8fbfe] px-3 py-2.5 text-sm text-ink dark:border-white/10 dark:bg-white/5 dark:text-slate-100">
                        <input v-model="payoutForm.confirm_payout" type="checkbox" class="mt-0.5 size-4 accent-[#1479E8]" />
                        <span v-if="payoutForm.payment_method === 'gcash'">
                            I sent <strong>{{ viewing.operator_net }}</strong> to {{ viewing.operator_name || 'the operator' }} via GCash.
                        </span>
                        <span v-else>
                            The operator received <strong>{{ viewing.operator_net }}</strong> in cash.
                        </span>
                    </label>
                    <p v-if="payoutError" class="text-xs text-rose-600">{{ payoutError }}</p>
                </div>

                <div class="flex justify-end gap-2 border-t border-[#e8f1f8] bg-[#f8fbfe] px-6 py-3 dark:border-white/10 dark:bg-white/5">
                    <Button type="button" variant="outline" class="rounded-xl" @click="payoutOpen = false">Cancel</Button>
                    <Button
                        type="button"
                        class="rounded-xl bg-[#16A34A] hover:bg-[#15803d]"
                        :disabled="!payoutForm.confirm_payout || payoutForm.processing || (payoutForm.payment_method === 'gcash' && !payoutForm.gcash_reference.trim())"
                        @click="submitPayout"
                    >
                        <Spinner v-if="payoutForm.processing" class="size-4" />
                        Confirm Payout
                    </Button>
                </div>
            </DialogContent>
        </Dialog>

        <!-- Success -->
        <Dialog :open="successOpen" @update:open="(v) => !v && (successOpen = false)">
            <DialogContent class="max-w-md rounded-2xl text-center">
                <CheckCircle2 class="mx-auto size-12 text-emerald-500" />
                <DialogHeader>
                    <DialogTitle class="text-center">Payout recorded</DialogTitle>
                </DialogHeader>
                <p class="text-sm text-ink-soft">The operator share is now marked Paid Out. The operator can see it in Earnings & Payouts.</p>
                <Button type="button" class="mx-auto bg-[#1479E8]" @click="successOpen = false; viewing = null">Done</Button>
            </DialogContent>
        </Dialog>

    </div>
</template>
