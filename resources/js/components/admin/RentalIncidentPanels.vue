<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { Ban, CheckCircle2, RotateCcw, UserX } from '@lucide/vue';
import { computed, ref } from 'vue';
import OperatorNoShowCase from '@/components/admin/OperatorNoShowCase.vue';
import type { NoShowCase } from '@/components/admin/OperatorNoShowCase.vue';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Spinner } from '@/components/ui/spinner';
import type { BoatBookingRecord } from '@/types/booking';

export type NoShowReport = NoShowCase;

export type PassengerNoShowRecord = {
    id: number;
    status: string;
    status_label: string;
    reported_by_name: string | null;
    operator_name: string | null;
    boat_name: string | null;
    scheduled_departure_label: string | null;
    reported_at_label: string | null;
    operator_remarks: string | null;
    evidence_photo_url?: string | null;
    declaration_confirmed: boolean;
    amount_paid_label: string;
    retained_amount_label: string;
    refund_status_label: string;
    dispute_status: string;
    dispute_status_label: string;
    dispute_reason: string | null;
    disputed_at_label: string | null;
    resolution_notes: string | null;
    resolved_by_name: string | null;
    resolved_at_label: string | null;
    can_resolve: boolean;
};

const props = withDefaults(
    defineProps<{
        booking: BoatBookingRecord;
        noShowReport: NoShowReport | null;
        canManage: boolean;
        passengerNoShow?: PassengerNoShowRecord | null;
    }>(),
    { passengerNoShow: null },
);

const cancelled = computed(() => ['cancelled', 'rejected', 'passenger_no_show'].includes(props.booking.status));

/* ---------- passenger no-show ---------- */
const passengerDialog = ref<'uphold' | 'reverse' | null>(null);
const passengerForm = useForm({ decision: '' as 'uphold' | 'reverse' | '', admin_notes: '' });

function openPassengerDecision(decision: 'uphold' | 'reverse') {
    passengerForm.reset();
    passengerForm.clearErrors();
    passengerForm.decision = decision;
    passengerDialog.value = decision;
}

function submitPassengerDecision() {
    if (!props.passengerNoShow || passengerForm.admin_notes.trim().length < 5) {
        return;
    }

    passengerForm.patch(`/bookings/passenger-no-show-reports/${props.passengerNoShow.id}`, {
        preserveScroll: true,
        onSuccess: () => (passengerDialog.value = null),
    });
}

const passengerBadge = computed(() => {
    switch (props.passengerNoShow?.status) {
        case 'disputed':
            return 'bg-amber-100 text-amber-800 dark:bg-amber-500/15 dark:text-amber-200';
        case 'reversed':
            return 'bg-emerald-100 text-emerald-800 dark:bg-emerald-500/15 dark:text-emerald-200';
        default:
            return 'bg-rose-100 text-rose-800 dark:bg-rose-500/15 dark:text-rose-200';
    }
});
</script>

<template>
    <!-- Cancellation -->
    <section v-if="cancelled && (booking.cancellation_reason || booking.cancelled_by_name)" class="rounded-[18px] border border-rose-200 bg-rose-50/70 p-5 dark:border-rose-500/30 dark:bg-rose-500/10">
        <h2 class="flex items-center gap-2 text-base font-bold text-rose-800 dark:text-rose-200">
            <Ban class="size-5" />
            {{ booking.status === 'rejected' ? 'Request Rejected' : booking.cancellation_reason === 'Operator No-Show' ? 'Operator No-Show' : 'Rental Cancelled' }}
        </h2>
        <dl class="mt-3 grid gap-3 text-sm sm:grid-cols-3">
            <div class="sm:col-span-3">
                <dt class="text-xs text-rose-700/80 dark:text-rose-300">Reason</dt>
                <dd class="font-semibold text-rose-900 dark:text-rose-100">{{ booking.cancellation_reason || '—' }}</dd>
            </div>
            <div>
                <dt class="text-xs text-rose-700/80 dark:text-rose-300">Cancelled by</dt>
                <dd class="font-semibold text-rose-900 dark:text-rose-100">{{ booking.cancelled_by_name || '—' }}</dd>
            </div>
            <div>
                <dt class="text-xs text-rose-700/80 dark:text-rose-300">Date & time</dt>
                <dd class="font-semibold text-rose-900 dark:text-rose-100">{{ booking.cancelled_at_label || '—' }}</dd>
            </div>
        </dl>
    </section>

    <!-- Operator no-show: verify / reject, then full refund -->
    <OperatorNoShowCase v-if="noShowReport" :report="noShowReport" :can-manage="canManage" />

    <!-- Passenger no-show -->
    <section v-if="passengerNoShow" class="rounded-[18px] border border-rose-200 bg-white p-5 shadow-sm dark:border-rose-500/30 dark:bg-[#102036]">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <h2 class="flex items-center gap-2 text-base font-bold text-[#0F4C8A] dark:text-sky-100">
                    <UserX class="size-5 text-rose-500" />
                    Passenger No-Show
                </h2>
                <p class="mt-1 text-xs text-ink-muted">Reported {{ passengerNoShow.reported_at_label || '—' }} by the boat operator<span v-if="passengerNoShow.reported_by_name"> ({{ passengerNoShow.reported_by_name }})</span></p>
            </div>
            <span class="rounded-full px-3 py-1 text-xs font-semibold" :class="passengerBadge">{{ passengerNoShow.status_label }}</span>
        </div>

        <dl class="mt-4 grid gap-3 text-sm sm:grid-cols-2 lg:grid-cols-3">
            <div><dt class="text-xs text-ink-muted">Scheduled departure</dt><dd class="font-semibold text-ink dark:text-slate-200">{{ passengerNoShow.scheduled_departure_label || '—' }}</dd></div>
            <div><dt class="text-xs text-ink-muted">Boat · Operator</dt><dd class="font-semibold text-ink dark:text-slate-200">{{ passengerNoShow.boat_name || '—' }}<span v-if="passengerNoShow.operator_name" class="font-normal text-ink-muted"> · {{ passengerNoShow.operator_name }}</span></dd></div>
            <div><dt class="text-xs text-ink-muted">Operator declaration</dt><dd class="font-semibold text-ink dark:text-slate-200">{{ passengerNoShow.declaration_confirmed ? 'Confirmed' : 'Not confirmed' }}</dd></div>
            <div><dt class="text-xs text-ink-muted">Amount paid</dt><dd class="font-semibold text-ink dark:text-slate-200">{{ passengerNoShow.amount_paid_label }}</dd></div>
            <div><dt class="text-xs text-ink-muted">Refund</dt><dd class="font-semibold text-ink dark:text-slate-200">{{ passengerNoShow.refund_status_label }}</dd></div>
            <div><dt class="text-xs text-ink-muted">Dispute</dt><dd class="font-semibold text-ink dark:text-slate-200">{{ passengerNoShow.dispute_status_label }}<span v-if="passengerNoShow.disputed_at_label" class="font-normal text-ink-muted"> · {{ passengerNoShow.disputed_at_label }}</span></dd></div>
            <div v-if="passengerNoShow.operator_remarks" class="sm:col-span-2 lg:col-span-3"><dt class="text-xs text-ink-muted">Operator remarks</dt><dd class="text-ink dark:text-slate-200">{{ passengerNoShow.operator_remarks }}</dd></div>
            <div v-if="passengerNoShow.evidence_photo_url" class="sm:col-span-2 lg:col-span-3">
                <dt class="text-xs text-ink-muted">Proof photo</dt>
                <dd class="mt-1.5">
                    <a :href="passengerNoShow.evidence_photo_url" target="_blank" rel="noopener noreferrer" class="inline-block overflow-hidden rounded-xl border border-[#d7e5f4] bg-white dark:border-white/10 dark:bg-white/5">
                        <img :src="passengerNoShow.evidence_photo_url" alt="Operator no-show proof" class="max-h-56 max-w-full object-cover" />
                    </a>
                    <div>
                        <a :href="passengerNoShow.evidence_photo_url" target="_blank" rel="noopener noreferrer" class="mt-1.5 inline-block text-xs font-semibold text-[#1479E8] hover:underline">Open full image</a>
                    </div>
                </dd>
            </div>
            <div v-if="passengerNoShow.dispute_reason" class="sm:col-span-2 lg:col-span-3"><dt class="text-xs text-ink-muted">Passenger dispute</dt><dd class="text-ink dark:text-slate-200">{{ passengerNoShow.dispute_reason }}</dd></div>
            <div v-if="passengerNoShow.resolution_notes" class="sm:col-span-2 lg:col-span-3"><dt class="text-xs text-ink-muted">Admin decision</dt><dd class="text-ink dark:text-slate-200">{{ passengerNoShow.resolution_notes }}<span v-if="passengerNoShow.resolved_by_name" class="text-ink-muted"> · {{ passengerNoShow.resolved_by_name }}, {{ passengerNoShow.resolved_at_label }}</span></dd></div>
        </dl>

        <p v-if="!passengerNoShow.can_resolve && passengerNoShow.status === 'confirmed_passenger_no_show'" class="mt-4 rounded-xl bg-slate-50 px-4 py-3 text-sm text-ink-soft dark:bg-white/5 dark:text-slate-300">
            No action needed. The rental is cancelled because the passenger did not show up.
        </p>

        <div v-if="canManage && passengerNoShow.can_resolve" class="mt-4 flex flex-wrap gap-2">
            <Button type="button" class="rounded-xl bg-[#1479E8]" @click="openPassengerDecision('uphold')"><CheckCircle2 class="size-4" /> Uphold No-Show</Button>
            <Button type="button" variant="outline" class="rounded-xl border-emerald-300 text-emerald-700" @click="openPassengerDecision('reverse')"><RotateCcw class="size-4" /> Reverse No-Show</Button>
        </div>
    </section>

    <Dialog :open="passengerDialog !== null" @update:open="(v) => !v && (passengerDialog = null)">
        <DialogContent v-if="passengerDialog" class="max-w-md rounded-2xl">
            <DialogHeader>
                <DialogTitle class="text-[#0F4C8A]">{{ passengerDialog === 'uphold' ? 'Uphold passenger no-show' : 'Reverse passenger no-show' }}</DialogTitle>
                <DialogDescription>
                    {{
                        passengerDialog === 'uphold'
                            ? 'The cancellation stays and no refund is issued. The passenger and operator are notified.'
                            : 'The no-show is removed and the passenger can choose a refund or a new date (exception refund).'
                    }}
                </DialogDescription>
            </DialogHeader>
            <textarea
                v-model="passengerForm.admin_notes"
                rows="3"
                class="w-full rounded-xl border border-[#c5def5] px-3 py-2 text-sm outline-none focus:border-[#1479E8] dark:border-white/10 dark:bg-white/5"
                placeholder="Reason for your decision (required)"
            />
            <p v-for="(message, key) in passengerForm.errors" :key="key" class="text-xs text-rose-600">{{ message }}</p>
            <DialogFooter>
                <Button type="button" variant="outline" @click="passengerDialog = null">Cancel</Button>
                <Button
                    type="button"
                    :class="passengerDialog === 'uphold' ? 'bg-[#1479E8] hover:bg-[#0F64C4]' : 'bg-[#16A34A] hover:bg-[#15803d]'"
                    :disabled="passengerForm.processing || passengerForm.admin_notes.trim().length < 5"
                    @click="submitPassengerDecision"
                >
                    <Spinner v-if="passengerForm.processing" class="size-4" />
                    {{ passengerDialog === 'uphold' ? 'Uphold' : 'Reverse' }}
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
