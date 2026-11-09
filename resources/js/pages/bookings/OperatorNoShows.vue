<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { AlertTriangle, CheckCircle2, Clock, Search, Wallet, XCircle } from '@lucide/vue';
import { computed, ref } from 'vue';
import OperatorNoShowCase from '@/components/admin/OperatorNoShowCase.vue';
import type { NoShowCase } from '@/components/admin/OperatorNoShowCase.vue';

const props = defineProps<{
    cases: NoShowCase[];
    counts: { pending: number; refund: number; refunded: number; rejected: number };
    can_manage: boolean;
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Operator No-Show', href: '/bookings/no-show-reports' }],
    },
});

type Tab = NoShowCase['stage'] | 'all';

const tabs = computed(() => [
    { key: 'pending' as Tab, label: 'Pending Verification', count: props.counts.pending, icon: Clock, tone: 'text-amber-600' },
    { key: 'refund' as Tab, label: 'Refund Due', count: props.counts.refund, icon: Wallet, tone: 'text-sky-600' },
    { key: 'refunded' as Tab, label: 'Refunded', count: props.counts.refunded, icon: CheckCircle2, tone: 'text-emerald-600' },
    { key: 'rejected' as Tab, label: 'Rejected', count: props.counts.rejected, icon: XCircle, tone: 'text-rose-600' },
    { key: 'all' as Tab, label: 'All', count: props.cases.length, icon: AlertTriangle, tone: 'text-[#1479E8]' },
]);

const tab = ref<Tab>(props.counts.pending > 0 ? 'pending' : props.counts.refund > 0 ? 'refund' : 'all');
const query = ref('');

const visible = computed(() => {
    const needle = query.value.trim().toLowerCase();

    return props.cases.filter((item) => {
        if (tab.value !== 'all' && item.stage !== tab.value) {
            return false;
        }

        if (!needle) {
            return true;
        }

        return [item.booking?.code, item.booking?.customer_name, item.booking?.boat_name, item.booking?.operator_name]
            .some((value) => (value ?? '').toLowerCase().includes(needle));
    });
});
</script>

<template>
    <Head title="Operator No-Show" />

    <div class="flex flex-1 flex-col gap-5 p-6">
        <div>
            <h1 class="text-2xl font-bold italic tracking-tight text-[#0f4c8a] dark:text-sky-100">Operator No-Show</h1>
            <p class="mt-1 text-sm text-ink-muted dark:text-slate-400">
                Passengers report when the operator does not arrive. Verify each report, then send the full refund. Every verified no-show adds one violation to the operator.
            </p>
        </div>

        <!-- Steps -->
        <ol class="grid gap-2 text-xs sm:grid-cols-3">
            <li class="rounded-xl border border-[#d7e5f4] bg-white px-3 py-2 dark:border-white/10 dark:bg-white/5"><span class="font-bold text-[#1479E8]">1.</span> Passenger reports the no-show</li>
            <li class="rounded-xl border border-[#d7e5f4] bg-white px-3 py-2 dark:border-white/10 dark:bg-white/5"><span class="font-bold text-[#1479E8]">2.</span> You verify or reject the report</li>
            <li class="rounded-xl border border-[#d7e5f4] bg-white px-3 py-2 dark:border-white/10 dark:bg-white/5"><span class="font-bold text-[#1479E8]">3.</span> Verified: refund the full amount paid</li>
        </ol>

        <!-- Tabs -->
        <div class="grid grid-cols-2 gap-3 sm:grid-cols-5">
            <button
                v-for="item in tabs"
                :key="item.key"
                type="button"
                class="rounded-2xl border p-3 text-left transition"
                :class="tab === item.key ? 'border-[#1479E8] bg-[#eef6ff] shadow-sm dark:bg-sky-500/10' : 'border-[#d7e5f4] bg-white hover:border-[#1479E8]/50 dark:border-white/10 dark:bg-white/5'"
                @click="tab = item.key"
            >
                <component :is="item.icon" class="size-4" :class="item.tone" />
                <p class="mt-1 text-xl font-bold text-[#0F4C8A] dark:text-sky-100">{{ item.count }}</p>
                <p class="text-xs font-semibold text-ink-muted">{{ item.label }}</p>
            </button>
        </div>

        <label class="relative block max-w-md">
            <Search class="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-ink-muted" />
            <input
                v-model="query"
                class="h-10 w-full rounded-xl border border-[#c5def5] bg-white pl-9 pr-3 text-sm outline-none focus:border-[#1479E8] dark:border-white/10 dark:bg-white/5"
                placeholder="Search booking, passenger, boat, or operator"
            />
        </label>

        <div v-if="visible.length" class="space-y-4">
            <OperatorNoShowCase v-for="item in visible" :key="item.id" :report="item" :can-manage="can_manage" show-booking-link />
        </div>
        <div v-else class="rounded-[18px] border border-dashed border-[#c5def5] bg-white p-10 text-center text-sm text-ink-muted dark:border-white/10 dark:bg-white/5">
            No operator no-show reports here.
        </div>
    </div>
</template>
