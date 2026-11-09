<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import { CheckCircle2, RefreshCw, RotateCcw, Undo2, Wallet } from '@lucide/vue';
import { computed, ref } from 'vue';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Spinner } from '@/components/ui/spinner';

export type RefundRequest = {
    id: number;
    type: string;
    type_label: string;
    status: string;
    status_label: string;
    reason: string | null;
    refund_amount_label: string | null;
    requested_by: string | null;
    requested_at_label: string | null;
    reviewed_by: string | null;
    reviewed_at_label: string | null;
    refunded_at_label: string | null;
    failure_message: string | null;
    manual_reference: string | null;
    can_retry: boolean;
    can_record_manual: boolean;
    can_sync: boolean;
};

const props = defineProps<{
    refundRequest: RefundRequest;
    canManage: boolean;
}>();

const manualOpen = ref(false);
const form = useForm({ action: 'record_manual', admin_notes: '', manual_reference: '' });
const busy = ref(false);

const statusClass = computed(() => {
    switch (props.refundRequest.status) {
        case 'refunded':
            return 'bg-emerald-100 text-emerald-800 dark:bg-emerald-500/20 dark:text-emerald-200';
        case 'failed':
            return 'bg-rose-100 text-rose-800 dark:bg-rose-500/20 dark:text-rose-200';
        case 'refunding':
        case 'rescheduled':
            return 'bg-sky-100 text-sky-800 dark:bg-sky-500/20 dark:text-sky-200';
        default:
            return 'bg-amber-100 text-amber-800 dark:bg-amber-500/20 dark:text-amber-200';
    }
});

function run(action: 'retry' | 'sync') {
    busy.value = true;
    router.patch(`/bookings/refund-requests/${props.refundRequest.id}`, { action }, {
        preserveScroll: true,
        onFinish: () => (busy.value = false),
    });
}

function openManual() {
    form.reset();
    form.clearErrors();
    manualOpen.value = true;
}

function submitManual() {
    if (form.manual_reference.trim().length < 6) {
        return;
    }

    form.patch(`/bookings/refund-requests/${props.refundRequest.id}`, {
        preserveScroll: true,
        onSuccess: () => (manualOpen.value = false),
    });
}
</script>

<template>
    <section class="rounded-[18px] border border-[#d7e5f4] bg-white p-5 shadow-sm dark:border-white/10 dark:bg-[#102036]">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <h2 class="flex items-center gap-2 text-base font-bold text-[#0F4C8A] dark:text-sky-100">
                    <Undo2 class="size-5 text-[#1479E8]" />
                    Refund
                </h2>
                <p class="mt-1 text-xs text-ink-muted">
                    {{ refundRequest.type_label }} · {{ refundRequest.requested_at_label || '—' }}<span v-if="refundRequest.requested_by"> by {{ refundRequest.requested_by }}</span>
                </p>
            </div>
            <span class="rounded-full px-3 py-1 text-xs font-semibold" :class="statusClass">{{ refundRequest.status_label }}</span>
        </div>

        <dl class="mt-4 grid gap-3 text-sm sm:grid-cols-3">
            <div>
                <dt class="text-xs text-ink-muted">Amount to refund</dt>
                <dd class="font-bold text-[#0F4C8A] dark:text-sky-100">{{ refundRequest.refund_amount_label || '—' }}</dd>
            </div>
            <div>
                <dt class="text-xs text-ink-muted">Refunded</dt>
                <dd class="font-semibold text-ink dark:text-slate-200">{{ refundRequest.refunded_at_label || '—' }}</dd>
            </div>
            <div v-if="refundRequest.manual_reference">
                <dt class="text-xs text-ink-muted">Manual GCash reference</dt>
                <dd class="font-semibold text-ink dark:text-slate-200">{{ refundRequest.manual_reference }}</dd>
            </div>
            <div v-if="refundRequest.reason" class="sm:col-span-3">
                <dt class="text-xs text-ink-muted">Cancellation reason</dt>
                <dd class="text-ink dark:text-slate-200">{{ refundRequest.reason }}</dd>
            </div>
        </dl>

        <p v-if="refundRequest.status === 'awaiting_choice'" class="mt-4 rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-900 dark:bg-amber-500/10 dark:text-amber-100">
            The passenger chooses in the app: a full refund (sent automatically through Xendit) or a new date (you review it like any reschedule).
        </p>
        <p v-if="refundRequest.status === 'pending_review'" class="mt-4 rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-900 dark:bg-amber-500/10 dark:text-amber-100">
            The passenger requested a refund. Review and approve or reject it in
            <a href="/payments?tab=requests" class="font-semibold underline">Payments → Refund Requests</a>.
        </p>
        <p v-if="refundRequest.failure_message" class="mt-4 rounded-lg bg-rose-50 px-3 py-2 text-sm text-rose-800 dark:bg-rose-500/10 dark:text-rose-200">
            {{ refundRequest.failure_message }}
        </p>
        <p v-if="refundRequest.status === 'refunded'" class="mt-4 flex items-center gap-2 rounded-lg bg-emerald-50 px-3 py-2 text-sm text-emerald-800 dark:bg-emerald-500/10 dark:text-emerald-200">
            <CheckCircle2 class="size-4" /> Refund completed.
        </p>

        <div v-if="canManage && (refundRequest.can_retry || refundRequest.can_record_manual || refundRequest.can_sync)" class="mt-4 flex flex-wrap gap-2">
            <Button v-if="refundRequest.can_retry" type="button" class="rounded-xl bg-[#16A34A] hover:bg-[#15803d]" :disabled="busy" @click="run('retry')">
                <RotateCcw class="size-4" /> Retry Xendit Refund
            </Button>
            <Button v-if="refundRequest.can_sync" type="button" variant="outline" class="rounded-xl" :disabled="busy" @click="run('sync')">
                <Spinner v-if="busy" class="size-4" />
                <RefreshCw v-else class="size-4" />
                Check Xendit Status
            </Button>
            <Button v-if="refundRequest.can_record_manual" type="button" variant="outline" class="rounded-xl" @click="openManual">
                <Wallet class="size-4" /> Record Manual Refund
            </Button>
        </div>
    </section>

    <Dialog :open="manualOpen" @update:open="(v) => !v && (manualOpen = false)">
        <DialogContent class="max-w-md rounded-2xl">
            <DialogHeader>
                <DialogTitle class="text-[#0F4C8A]">Record manual refund</DialogTitle>
                <DialogDescription>Use this only after you sent {{ refundRequest.refund_amount_label || 'the refund' }} back to the passenger by GCash yourself.</DialogDescription>
            </DialogHeader>
            <label class="block text-sm font-medium text-ink dark:text-slate-200">
                GCash reference number <span class="text-rose-500">*</span>
                <input v-model="form.manual_reference" class="mt-1 h-10 w-full rounded-xl border border-[#c5def5] px-3 text-sm dark:border-white/10 dark:bg-white/5" placeholder="e.g. 1012345678901" />
            </label>
            <textarea
                v-model="form.admin_notes"
                rows="2"
                class="w-full rounded-xl border border-[#c5def5] px-3 py-2 text-sm outline-none focus:border-[#1479E8] dark:border-white/10 dark:bg-white/5"
                placeholder="Notes (optional)"
            />
            <p v-for="(message, key) in form.errors" :key="key" class="text-xs text-rose-600">{{ message }}</p>
            <DialogFooter>
                <Button type="button" variant="outline" @click="manualOpen = false">Back</Button>
                <Button type="button" class="bg-[#16A34A] hover:bg-[#15803d]" :disabled="form.processing || form.manual_reference.trim().length < 6" @click="submitManual">
                    <Spinner v-if="form.processing" class="size-4" />
                    Record Refund
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
