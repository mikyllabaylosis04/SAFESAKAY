<script setup lang="ts">
import { ChevronLeft, ChevronRight, Search } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import type { RefundRecord } from '@/components/admin/RefundReviewDialog.vue';

const props = defineProps<{ refunds: RefundRecord[]; mode: 'requests' | 'history' }>();
const emit = defineEmits<{ open: [refund: RefundRecord] }>();

const OPEN = ['pending_review', 'refunding', 'failed'];
const statusOptions = computed(() =>
    props.mode === 'requests'
        ? [
              { value: 'all', label: 'All open' },
              { value: 'pending_review', label: 'Pending Review' },
              { value: 'refunding', label: 'Processing' },
              { value: 'failed', label: 'Failed' },
          ]
        : [
              { value: 'all', label: 'All' },
              { value: 'refunded', label: 'Refunded' },
              { value: 'rejected', label: 'Rejected' },
          ],
);

const search = ref('');
const status = ref('all');
const page = ref(1);
const pageSize = 10;

const source = computed(() => props.refunds.filter((refund) => OPEN.includes(refund.status) === (props.mode === 'requests')));

const filtered = computed(() => {
    const q = search.value.trim().toLowerCase();

    return source.value.filter((refund) => {
        if (status.value !== 'all' && refund.status !== status.value) {
            return false;
        }

        return !q || [refund.reference, refund.booking.code, refund.booking.passenger_name].join(' ').toLowerCase().includes(q);
    });
});

const pageCount = computed(() => Math.max(1, Math.ceil(filtered.value.length / pageSize)));
const shown = computed(() => filtered.value.slice((page.value - 1) * pageSize, page.value * pageSize));
watch([search, status], () => (page.value = 1));
watch(
    () => props.mode,
    () => {
        status.value = 'all';
        page.value = 1;
    },
);

function actionLabel(refund: RefundRecord): string {
    if (refund.can_approve) {
        return 'Review';
    }

    return refund.status === 'failed' ? 'Fix' : 'View';
}
</script>

<template>
    <section class="overflow-hidden rounded-2xl border border-[#d9e7f5] bg-white shadow-sm dark:border-white/10 dark:bg-[#102036]">
        <div class="flex flex-wrap gap-3 border-b border-[#e8eff7] px-4 py-3 dark:border-white/10">
            <label class="relative min-w-[220px] flex-1">
                <Search class="pointer-events-none absolute top-2.5 left-3 size-4 text-[#94a8bd]" />
                <input
                    v-model="search"
                    type="search"
                    placeholder="Search refund, rental or passenger"
                    class="h-9 w-full rounded-xl border border-[#d8e5f3] bg-white pr-3 pl-9 text-sm outline-none focus:border-blue-400 dark:border-white/10 dark:bg-white/5 dark:text-white"
                />
            </label>
            <select v-model="status" class="h-9 rounded-xl border border-[#d8e5f3] bg-white px-3 text-sm dark:border-white/10 dark:bg-[#102036] dark:text-white">
                <option v-for="option in statusOptions" :key="option.value" :value="option.value">{{ option.label }}</option>
            </select>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full min-w-[720px] text-left text-sm">
                <thead class="bg-[#f5f8fc] text-[11px] font-bold text-ink-muted uppercase dark:bg-white/5">
                    <tr>
                        <th class="px-4 py-3">Refund</th>
                        <th class="px-4 py-3">Passenger</th>
                        <th class="px-4 py-3">Reason</th>
                        <th class="px-4 py-3 text-right">Paid</th>
                        <th class="px-4 py-3 text-right">{{ mode === 'requests' ? 'Requested' : 'Refunded' }}</th>
                        <th class="px-4 py-3">Status</th>
                        <th class="px-4 py-3">{{ mode === 'requests' ? 'Submitted' : 'Completed' }}</th>
                        <th class="px-4 py-3" />
                    </tr>
                </thead>
                <tbody class="divide-y divide-[#eef3f8] dark:divide-white/10">
                    <tr v-for="refund in shown" :key="refund.id" class="cursor-pointer hover:bg-[#f8fbfe] dark:hover:bg-white/5" @click="emit('open', refund)">
                        <td class="px-4 py-3">
                            <p class="font-semibold text-[#1479E8]">{{ refund.reference }}</p>
                            <p class="text-xs text-ink-muted">{{ refund.booking.code }}</p>
                        </td>
                        <td class="px-4 py-3 text-ink dark:text-slate-200">{{ refund.booking.passenger_name || '—' }}</td>
                        <td class="max-w-[200px] truncate px-4 py-3 text-ink-soft" :title="refund.reason_label">{{ refund.reason_label }}</td>
                        <td class="px-4 py-3 text-right whitespace-nowrap text-ink tabular-nums dark:text-slate-200">{{ refund.payment.amount_paid_label }}</td>
                        <td class="px-4 py-3 text-right font-semibold whitespace-nowrap text-ink tabular-nums dark:text-slate-200">
                            {{ mode === 'requests' ? refund.requested_amount_label : refund.status === 'refunded' ? refund.refund_amount_label : '—' }}
                        </td>
                        <td class="px-4 py-3">
                            <span class="rounded-full px-2.5 py-0.5 text-xs font-semibold whitespace-nowrap" :class="refund.status_badge_class">{{ refund.status_label }}</span>
                        </td>
                        <td class="px-4 py-3 text-xs whitespace-nowrap text-ink-soft">
                            {{ mode === 'requests' ? refund.requested_at_label : refund.processed_at_label || '—' }}
                        </td>
                        <td class="px-4 py-3 text-right">
                            <button
                                type="button"
                                class="rounded-xl px-3 py-1.5 text-xs font-semibold whitespace-nowrap"
                                :class="refund.can_approve || refund.status === 'failed' ? 'bg-[#1479E8] text-white hover:bg-[#0f68cc]' : 'border border-[#c5def5] text-[#1479E8] hover:bg-[#eef6ff]'"
                                @click.stop="emit('open', refund)"
                            >
                                {{ actionLabel(refund) }}
                            </button>
                        </td>
                    </tr>
                    <tr v-if="!shown.length">
                        <td colspan="8" class="px-4 py-10 text-center text-sm text-ink-muted">
                            {{ mode === 'requests' ? 'No refunds need your action right now.' : 'No completed refunds yet.' }}
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div v-if="pageCount > 1 || filtered.length" class="flex items-center justify-between border-t border-[#e8eff7] px-4 py-2.5 text-xs text-ink-muted dark:border-white/10">
            <span>{{ filtered.length }} {{ filtered.length === 1 ? 'refund' : 'refunds' }}</span>
            <div v-if="pageCount > 1" class="flex items-center gap-2">
                <button type="button" class="rounded-lg border border-[#d8e5f3] p-1.5 disabled:opacity-40 dark:border-white/10" :disabled="page <= 1" aria-label="Previous page" @click="page--">
                    <ChevronLeft class="size-4" />
                </button>
                <span>Page {{ page }} of {{ pageCount }}</span>
                <button type="button" class="rounded-lg border border-[#d8e5f3] p-1.5 disabled:opacity-40 dark:border-white/10" :disabled="page >= pageCount" aria-label="Next page" @click="page++">
                    <ChevronRight class="size-4" />
                </button>
            </div>
        </div>
    </section>
</template>
