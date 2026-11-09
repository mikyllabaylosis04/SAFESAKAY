<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import {
    Check,
    CheckCircle2,
    Clock3,
    Coins,
    ExternalLink,
    Eye,
    FileDown,
    Search,
    Undo2,
    Wallet,
    X,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import StatCards, { type StatCardItem } from '@/components/admin/StatCards.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Spinner } from '@/components/ui/spinner';

type Row = {
    id: number;
    commission_id: string;
    transaction_id: string | null;
    rental_reference: string;
    customer_name: string;
    boat_name: string | null;
    total_rental_payment_label: string;
    commission_rate_label: string;
    commission_amount_label: string;
    operator_share_label: string;
    earned_at_label: string | null;
    status: 'paid' | 'pending' | 'reversed';
    status_label: string;
    collection_label: string;
    can_mark_paid: boolean;
    booking_href: string | null;
};

const props = defineProps<{
    commissions: Row[];
    stats: {
        total_commission: string;
        pending_commission: string;
        paid_commission: string;
        reversed_commission: string;
        transaction_count: number;
    };
    commission_rate: number;
    can_manage: boolean;
}>();

defineOptions({
    layout: { breadcrumbs: [{ title: 'My Commission', href: '/my-commission' }] },
});

const search = ref('');
const status = ref('all');
const selectedId = ref<number | null>(null);
const savingId = ref<number | null>(null);
const confirmMarkRow = ref<Row | null>(null);
const markPaidAcknowledged = ref(false);

const selected = computed(() =>
    selectedId.value === null
        ? null
        : props.commissions.find((row) => row.id === selectedId.value) ?? null,
);

const filtered = computed(() => {
    const query = search.value.trim().toLowerCase();
    return props.commissions.filter((row) => {
        if (status.value !== 'all' && row.status !== status.value) return false;
        if (!query) return true;
        return [row.commission_id, row.transaction_id, row.rental_reference, row.customer_name]
            .some((value) => String(value ?? '').toLowerCase().includes(query));
    });
});

const filtersActive = computed(() => Boolean(search.value.trim()) || status.value !== 'all');

const cards = computed<StatCardItem[]>(() => [
    { key: 'all', title: 'Total Commission', value: props.stats.total_commission, hint: `${props.commission_rate}% of each private rental price`, icon: Wallet, tone: 'blue' },
    { key: 'paid', title: 'Collected', value: props.stats.paid_commission, hint: 'Already with the barangay', icon: CheckCircle2, tone: 'green' },
    { key: 'pending', title: 'Awaiting Operator', value: props.stats.pending_commission, hint: 'Older rentals paid to the operator', icon: Clock3, tone: 'amber' },
    { key: 'reversed', title: 'Reversed', value: props.stats.reversed_commission, hint: 'Returned on refunds', icon: Undo2, tone: 'red' },
]);

function badgeClass(value: string) {
    if (value === 'paid') {
        return 'bg-emerald-50 text-emerald-700 ring-1 ring-emerald-100 dark:bg-emerald-500/15 dark:text-emerald-300 dark:ring-emerald-500/20';
    }
    if (value === 'pending') {
        return 'bg-amber-50 text-amber-800 ring-1 ring-amber-100 dark:bg-amber-500/15 dark:text-amber-300 dark:ring-amber-500/20';
    }
    return 'bg-rose-50 text-rose-700 ring-1 ring-rose-100 dark:bg-rose-500/15 dark:text-rose-300 dark:ring-rose-500/20';
}

function clearFilters() {
    search.value = '';
    status.value = 'all';
}

function openMarkPaidConfirm(row: Row) {
    if (!row.can_mark_paid || savingId.value !== null) {
        return;
    }
    markPaidAcknowledged.value = false;
    confirmMarkRow.value = row;
}

function closeMarkPaidConfirm() {
    confirmMarkRow.value = null;
    markPaidAcknowledged.value = false;
}

function submitMarkPaid() {
    const row = confirmMarkRow.value;
    if (!row || !markPaidAcknowledged.value || savingId.value !== null) {
        return;
    }

    savingId.value = row.id;
    router.post(`/my-commission/${row.id}/mark-paid`, {}, {
        preserveScroll: true,
        onSuccess: () => {
            selectedId.value = null;
            closeMarkPaidConfirm();
        },
        onError: () => {
            markPaidAcknowledged.value = false;
        },
        onFinish: () => {
            savingId.value = null;
        },
    });
}

function exportCommissionCsv() {
    window.location.href = '/my-commission/export';
}
</script>

<template>
    <Head title="My Commission" />

    <main class="flex min-w-0 flex-1 flex-col gap-5 p-6 text-slate-800 dark:text-slate-100">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <h1 class="text-2xl font-bold tracking-tight text-[#0F4C8A] dark:text-sky-100">My Commission</h1>
                <p class="mt-1 max-w-3xl text-sm text-ink-muted dark:text-slate-400">
                    The barangay earns {{ commission_rate }}% of each private rental price. It is deducted automatically from the passenger's payment to the barangay.
                </p>
            </div>
            <button
                type="button"
                class="inline-flex h-10 items-center gap-2 rounded-xl border border-[#d9e5f2] bg-white px-4 text-sm font-semibold text-[#245f9e] shadow-sm hover:bg-blue-50 dark:border-white/10 dark:bg-white/5 dark:text-sky-200"
                @click="exportCommissionCsv"
            >
                <FileDown class="size-4" /> Export CSV
            </button>
        </div>

        <StatCards :items="cards" :active="status === 'all' ? null : status" selectable @select="(key) => (status = status === key || key === 'all' ? 'all' : key)" />

        <!-- RECORDS + FILTERS -->
        <section class="overflow-hidden rounded-2xl border border-[#dce7f3] bg-white shadow-sm dark:border-white/10 dark:bg-[#0d2036]">
            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-[#edf1f7] px-5 py-4 dark:border-white/10">
                <div>
                    <h2 class="text-base font-bold text-ink dark:text-white">Commission Records</h2>
                    <p class="mt-1 text-xs text-slate-500">{{ filtered.length }} of {{ commissions.length }} records</p>
                </div>
                <span class="rounded-full bg-blue-50 px-3 py-1 text-xs font-medium text-blue-700 dark:bg-blue-500/15 dark:text-blue-200">
                    {{ stats.transaction_count }} transactions recorded
                </span>
            </div>

            <div class="flex flex-col gap-3 px-5 py-4 sm:flex-row sm:items-center">
                <label class="relative min-w-0 flex-1">
                    <Search class="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-ink-muted" />
                    <input
                        v-model="search"
                        type="search"
                        aria-label="Search commissions"
                        placeholder="Search booking or customer..."
                        class="h-10 w-full rounded-xl border border-[#dce7f3] bg-white pl-10 pr-3 text-sm text-slate-800 outline-none focus:border-blue-400 focus:ring-2 focus:ring-blue-100 dark:border-white/10 dark:bg-white/5 dark:text-white"
                    />
                </label>
                <select
                    v-model="status"
                    aria-label="Filter by commission status"
                    class="h-10 rounded-xl border border-[#dce7f3] bg-white px-3 text-sm text-slate-700 dark:border-white/10 dark:bg-[#162b43] dark:text-slate-100 sm:w-44"
                >
                    <option value="all">All statuses</option>
                    <option value="paid">Collected</option>
                    <option value="pending">Awaiting Operator</option>
                    <option value="reversed">Reversed</option>
                </select>
                <button
                    v-if="filtersActive"
                    type="button"
                    class="h-10 rounded-xl px-3 text-sm font-medium text-blue-600 hover:bg-blue-50 dark:hover:bg-white/10"
                    @click="clearFilters"
                >
                    Clear filters
                </button>
            </div>

            <!-- DESKTOP TABLE -->
            <div class="hidden overflow-x-auto md:block">
                <table class="w-full min-w-[900px] text-left text-sm">
                    <thead class="border-y border-[#edf1f7] bg-[#f8faff] text-[11px] uppercase tracking-wide text-ink-muted dark:border-white/10 dark:bg-white/5">
                        <tr>
                            <th class="px-4 py-3">Booking</th>
                            <th class="px-4 py-3">Customer</th>
                            <th class="px-4 py-3 text-right">Rental Total</th>
                            <th class="px-4 py-3 text-right">Rate</th>
                            <th class="px-4 py-3 text-right">Commission</th>
                            <th class="px-4 py-3">Earned</th>
                            <th class="px-4 py-3">Status</th>
                            <th class="px-4 py-3 text-left">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="row in filtered"
                            :key="row.id"
                            class="border-b border-[#edf1f7] last:border-0 hover:bg-blue-50/40 dark:border-white/10 dark:hover:bg-white/5"
                        >
                            <td class="px-4 py-4 font-semibold text-[#2273c8] dark:text-sky-300">{{ row.rental_reference }}</td>
                            <td class="px-4 py-4">{{ row.customer_name }}</td>
                            <td class="px-4 py-4 text-right tabular-nums">{{ row.total_rental_payment_label }}</td>
                            <td class="px-4 py-4 text-right tabular-nums">{{ row.commission_rate_label }}</td>
                            <td class="px-4 py-4 text-right font-bold tabular-nums text-[#174e84] dark:text-sky-200">{{ row.commission_amount_label }}</td>
                            <td class="whitespace-nowrap px-4 py-4 text-slate-500 dark:text-slate-300">{{ row.earned_at_label || '—' }}</td>
                            <td class="px-4 py-4">
                                <span
                                    class="inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-xs font-semibold"
                                    :class="badgeClass(row.status)"
                                >
                                    <CheckCircle2 v-if="row.status === 'paid'" class="size-3.5 shrink-0" />
                                    <Clock3 v-else-if="row.status === 'pending'" class="size-3.5 shrink-0" />
                                    {{ row.status_label }}
                                </span>
                            </td>
                            <td class="px-4 py-4">
                                <div class="grid min-w-[252px] grid-cols-[1fr_auto] items-center gap-4">
                                    <div class="flex justify-start">
                                        <button
                                            v-if="row.can_mark_paid && can_manage"
                                            type="button"
                                            :disabled="savingId !== null"
                                            class="inline-flex h-9 shrink-0 items-center gap-1.5 rounded-lg bg-[#2b7fd4] px-3 text-sm font-semibold text-white shadow-sm hover:bg-[#256bb5] disabled:cursor-not-allowed disabled:opacity-50"
                                            @click="openMarkPaidConfirm(row)"
                                        >
                                            <Check class="size-4 shrink-0" />
                                            {{ savingId === row.id ? 'Saving…' : 'Mark Received' }}
                                        </button>
                                    </div>
                                    <button
                                        type="button"
                                        class="inline-flex h-9 shrink-0 items-center gap-1.5 rounded-lg border border-[#dce7f3] bg-white px-3 text-sm font-semibold text-[#0f2d52] shadow-sm hover:bg-slate-50 dark:border-white/15 dark:bg-white/5 dark:text-white dark:hover:bg-white/10"
                                        @click="selectedId = row.id"
                                    >
                                        <Eye class="size-4 shrink-0 text-[#2476cf] dark:text-sky-300" />
                                        View
                                    </button>
                                </div>
                            </td>
                        </tr>
                        <tr v-if="filtered.length === 0">
                            <td colspan="8" class="px-4 py-12 text-center text-slate-500">No commission records match your filters.</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- MOBILE CARDS -->
            <div class="space-y-3 p-4 md:hidden">
                <article v-for="row in filtered" :key="row.id" class="rounded-xl border border-[#e1eaf4] p-4 dark:border-white/10">
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <p class="font-semibold text-blue-600 dark:text-sky-300">{{ row.rental_reference }}</p>
                            <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">{{ row.customer_name }}</p>
                        </div>
                        <span
                            class="inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-xs font-semibold"
                            :class="badgeClass(row.status)"
                        >
                            <CheckCircle2 v-if="row.status === 'paid'" class="size-3.5 shrink-0" />
                            <Clock3 v-else-if="row.status === 'pending'" class="size-3.5 shrink-0" />
                            {{ row.status_label }}
                        </span>
                    </div>
                    <div class="mt-4 flex justify-between text-sm"><span class="text-slate-500">Rental Total</span><span>{{ row.total_rental_payment_label }}</span></div>
                    <div class="mt-2 flex justify-between text-sm"><span class="text-slate-500">Commission</span><strong class="text-blue-700 dark:text-sky-200">{{ row.commission_amount_label }}</strong></div>
                    <div class="mt-3 grid grid-cols-[1fr_auto] items-center gap-4 border-t border-[#edf1f7] pt-3 dark:border-white/10">
                        <div class="flex justify-start">
                            <button
                                v-if="row.can_mark_paid && can_manage"
                                type="button"
                                :disabled="savingId !== null"
                                class="inline-flex h-9 items-center gap-1.5 rounded-lg bg-[#2b7fd4] px-3 text-sm font-semibold text-white disabled:opacity-50"
                                @click="openMarkPaidConfirm(row)"
                            >
                                <Check class="size-4" />
                                {{ savingId === row.id ? 'Saving…' : 'Mark Received' }}
                            </button>
                        </div>
                        <button
                            type="button"
                            class="inline-flex h-9 items-center gap-1.5 rounded-lg border border-[#dce7f3] bg-white px-3 text-sm font-semibold text-[#0f2d52] dark:border-white/15 dark:bg-white/5 dark:text-white"
                            @click="selectedId = row.id"
                        >
                            <Eye class="size-4 text-[#2476cf]" />
                            View
                        </button>
                    </div>
                </article>
                <p v-if="filtered.length === 0" class="py-8 text-center text-sm text-slate-500">No commission records match your filters.</p>
            </div>
        </section>

        <!-- VIEW DETAILS (mock layout) -->
        <Teleport to="body">
            <div
                v-if="selected"
                class="fixed inset-0 z-[100] flex items-center justify-center bg-slate-900/50 p-4 backdrop-blur-[1px]"
                @click.self="selectedId = null"
            >
                <section
                    role="dialog"
                    aria-modal="true"
                    aria-labelledby="commission-details-title"
                    class="flex max-h-[92vh] w-full max-w-[440px] flex-col overflow-hidden rounded-[20px] bg-white text-slate-800 shadow-2xl dark:bg-[#13263e] dark:text-white"
                >
                    <header class="flex items-start justify-between gap-3 px-6 pb-2 pt-5">
                        <div>
                            <h2 id="commission-details-title" class="text-xl font-bold tracking-tight text-[#0f2d52] dark:text-white">
                                Commission Details
                            </h2>
                            <p class="mt-0.5 text-sm text-slate-500 dark:text-slate-400">{{ selected.rental_reference }}</p>
                        </div>
                        <button
                            type="button"
                            aria-label="Close details"
                            class="rounded-lg p-1.5 text-ink-muted hover:bg-slate-100 hover:text-slate-600 dark:hover:bg-white/10"
                            @click="selectedId = null"
                        >
                            <X class="size-5" />
                        </button>
                    </header>

                    <div class="min-h-0 flex-1 overflow-y-auto px-6 pb-4">
                        <div class="flex items-center gap-3 rounded-2xl bg-[#eaf4ff] px-4 py-4 dark:bg-blue-500/15">
                            <span class="flex size-12 shrink-0 items-center justify-center rounded-full bg-[#2b7fd4] text-white shadow-sm">
                                <Coins class="size-6" />
                            </span>
                            <div class="min-w-0 flex-1">
                                <p class="text-xs font-medium text-slate-600 dark:text-slate-300">Commission Amount</p>
                                <p class="text-2xl font-bold tabular-nums text-[#0f4a8a] dark:text-sky-100">
                                    {{ selected.commission_amount_label }}
                                </p>
                            </div>
                            <span
                                class="inline-flex shrink-0 items-center gap-1 rounded-full px-3 py-1.5 text-xs font-semibold"
                                :class="badgeClass(selected.status)"
                            >
                                <Clock3 v-if="selected.status === 'pending'" class="size-3.5" />
                                <CheckCircle2 v-else-if="selected.status === 'paid'" class="size-3.5" />
                                {{ selected.status_label }}
                            </span>
                        </div>

                        <dl class="mt-2 divide-y divide-slate-100 text-sm dark:divide-white/10">
                            <div class="flex justify-between gap-4 py-3.5">
                                <dt class="text-slate-500">Commission ID</dt>
                                <dd class="text-right font-semibold text-[#0f2d52] dark:text-white">{{ selected.commission_id }}</dd>
                            </div>
                            <div class="flex justify-between gap-4 py-3.5">
                                <dt class="text-slate-500">Rental Reference</dt>
                                <dd class="text-right font-semibold text-[#0f2d52] dark:text-white">{{ selected.rental_reference }}</dd>
                            </div>
                            <div class="flex justify-between gap-4 py-3.5">
                                <dt class="text-slate-500">Customer</dt>
                                <dd class="text-right font-semibold text-[#0f2d52] dark:text-white">{{ selected.customer_name }}</dd>
                            </div>
                            <div class="flex justify-between gap-4 py-3.5">
                                <dt class="text-slate-500">Rental Total</dt>
                                <dd class="text-right font-semibold tabular-nums text-[#0f2d52] dark:text-white">{{ selected.total_rental_payment_label }}</dd>
                            </div>
                            <div class="flex justify-between gap-4 py-3.5">
                                <dt class="text-slate-500">Commission Rate</dt>
                                <dd class="text-right font-semibold text-[#0f2d52] dark:text-white">{{ selected.commission_rate_label }}</dd>
                            </div>
                            <div class="flex justify-between gap-4 py-3.5">
                                <dt class="text-slate-500">Operator Share</dt>
                                <dd class="text-right font-semibold tabular-nums text-[#0f2d52] dark:text-white">{{ selected.operator_share_label }}</dd>
                            </div>
                            <div class="flex justify-between gap-4 py-3.5">
                                <dt class="text-slate-500">How collected</dt>
                                <dd class="text-right font-semibold text-[#0f2d52] dark:text-white">{{ selected.collection_label }}</dd>
                            </div>
                            <div class="flex justify-between gap-4 py-3.5">
                                <dt class="text-slate-500">Earned Date</dt>
                                <dd class="text-right font-semibold text-[#0f2d52] dark:text-white">{{ selected.earned_at_label || '—' }}</dd>
                            </div>
                        </dl>
                    </div>

                    <footer class="flex flex-wrap items-center justify-between gap-3 border-t border-slate-100 px-6 py-4 dark:border-white/10">
                        <button
                            v-if="selected.can_mark_paid && can_manage"
                            type="button"
                            :disabled="savingId !== null"
                            class="inline-flex h-11 items-center gap-2 rounded-xl bg-[#2b7fd4] px-5 text-sm font-semibold text-white shadow-sm hover:bg-[#256bb5] disabled:cursor-not-allowed disabled:opacity-50"
                            @click="openMarkPaidConfirm(selected)"
                        >
                            <Check class="size-4" />
                            Mark Received
                        </button>
                        <span v-else />
                        <div class="flex flex-wrap justify-end gap-2">
                            <Link
                                v-if="selected.booking_href"
                                :href="selected.booking_href"
                                class="inline-flex h-11 items-center gap-2 rounded-xl border border-slate-200 bg-white px-4 text-sm font-semibold text-[#0f2d52] hover:bg-slate-50 dark:border-white/15 dark:bg-transparent dark:text-white dark:hover:bg-white/10"
                            >
                                <ExternalLink class="size-4" />
                                View Rental
                            </Link>
                            <button
                                type="button"
                                class="inline-flex h-11 items-center rounded-xl border border-slate-200 bg-white px-5 text-sm font-semibold text-[#0f2d52] hover:bg-slate-50 dark:border-white/15 dark:text-white dark:hover:bg-white/10"
                                @click="selectedId = null"
                            >
                                Close
                            </button>
                        </div>
                    </footer>
                </section>
            </div>
        </Teleport>

        <Dialog :open="confirmMarkRow !== null" @update:open="(open) => !open && closeMarkPaidConfirm()">
            <DialogContent v-if="confirmMarkRow" class="max-w-md rounded-2xl border-[#dce7f3] p-0 sm:max-w-md">
                <DialogHeader class="border-b border-[#edf1f7] px-5 py-4 text-left">
                    <DialogTitle class="text-lg font-bold text-[#0f2d52] dark:text-white">
                        Mark commission as received?
                    </DialogTitle>
                </DialogHeader>
                <div class="space-y-3 px-5 py-4 text-sm leading-relaxed text-ink dark:text-sky-50">
                    <p>
                        The passenger of
                        <strong>{{ confirmMarkRow.rental_reference }}</strong>
                        paid the operator directly. Confirm that the operator already gave the barangay share
                        (<strong>{{ confirmMarkRow.commission_id }}</strong>).
                    </p>
                    <p class="rounded-xl bg-[#eaf4ff] px-4 py-3 font-semibold tabular-nums text-[#0f4a8a] dark:bg-blue-500/15 dark:text-sky-100">
                        Amount: {{ confirmMarkRow.commission_amount_label }}
                    </p>
                    <p class="text-xs leading-5 text-slate-500 dark:text-slate-400">
                        This records the payment in SafeSakay. It does not move any money.
                    </p>
                    <label class="flex cursor-pointer items-start gap-2.5 pt-1">
                        <input
                            v-model="markPaidAcknowledged"
                            type="checkbox"
                            class="mt-0.5 size-4 shrink-0 rounded border-slate-300 text-[#2b7fd4] focus:ring-[#2b7fd4]"
                        />
                        <span>I confirm the barangay received this commission from the operator.</span>
                    </label>
                </div>
                <DialogFooter class="gap-2 border-t border-[#edf1f7] px-5 py-4 sm:justify-end">
                    <Button type="button" variant="outline" class="rounded-xl" @click="closeMarkPaidConfirm">
                        Cancel
                    </Button>
                    <Button
                        type="button"
                        class="rounded-xl bg-[#2b7fd4] hover:bg-[#256bb5] disabled:opacity-50"
                        :disabled="!markPaidAcknowledged || savingId === confirmMarkRow.id"
                        @click="submitMarkPaid"
                    >
                        <Spinner v-if="savingId === confirmMarkRow.id" class="size-4" />
                        Mark Received
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </main>
</template>
