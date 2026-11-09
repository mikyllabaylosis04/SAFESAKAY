<script setup lang="ts">
import {
    Briefcase,
    CalendarDays,
    CheckCircle2,
    Clock,
    FileDown,
    FileText,
    Flag,
    MapPin,
    Ship,
    StickyNote,
    UserRound,
    Users,
    X,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogTitle,
} from '@/components/ui/dialog';
import { Spinner } from '@/components/ui/spinner';
import { downloadRentalSlip } from '@/lib/rentalSlip';
import type { BoatBookingRecord } from '@/types/booking';
const props = withDefaults(
    defineProps<{
        open: boolean;
        booking: BoatBookingRecord | null;
        showEdit?: boolean;
        showReview?: boolean;
        processing?: boolean;
    }>(),
    {
        showEdit: true,
        showReview: false,
        processing: false,
    },
);

const emit = defineEmits<{
    'update:open': [value: boolean];
    edit: [booking: BoatBookingRecord];
    reject: [booking: BoatBookingRecord];
}>();

const listStatusKey = computed(
    () =>
        props.booking?.display_status ??
        props.booking?.status ??
        'pending',
);

const slipDownloading = ref(false);

function close() {
    emit('update:open', false);
}

function statusTone(
    status: BoatBookingRecord['status'] | 'weather_reschedule_confirmed',
) {
    return {
        pending: 'bg-[#fbf3e6] text-[#d4922a]',
        awaiting_payment: 'bg-sky-50 text-sky-700',
        confirmed: 'bg-emerald-50 text-emerald-700',
        weather_reschedule_confirmed: 'bg-teal-50 text-teal-800',
        ongoing: 'bg-violet-50 text-violet-700',
        reschedule_pending: 'bg-violet-100 text-violet-800',
        reschedule_confirmed: 'bg-emerald-50 text-emerald-700',
        completed: 'bg-sky-50 text-sky-700',
        passenger_no_show: 'bg-red-50 text-red-700',
        cancelled: 'bg-red-50 text-red-700',
        rejected: 'bg-red-50 text-red-700',
    }[status] ?? 'bg-[#f4f9fd] text-ink dark:bg-[#0b1b30] dark:text-slate-100';
}

async function downloadSlip() {
    if (!props.booking || slipDownloading.value) {
        return;
    }

    slipDownloading.value = true;
    try {
        await downloadRentalSlip(props.booking);
    } finally {
        slipDownloading.value = false;
    }
}
</script>

<template>
    <Dialog :open="open" @update:open="emit('update:open', $event)">
        <DialogContent
            v-if="booking"
            :show-close-button="false"
            class="flex max-h-[92vh] w-full max-w-[calc(100%-1.5rem)] flex-col gap-0 overflow-hidden rounded-2xl border-none p-0 shadow-2xl sm:max-w-2xl"
        >
            <div
                class="flex items-start justify-between bg-[#1b7ad8] px-5 py-4 text-white"
            >
                <div class="flex items-center gap-3">
                    <div
                        class="flex size-11 items-center justify-center overflow-hidden rounded-full bg-white/15"
                    >
                        <img
                            src="/images/safesakay-logo.png?v=2"
                            alt="SafeSakay"
                            class="size-9 object-contain"
                        />
                    </div>
                    <div>
                        <DialogTitle class="text-lg font-semibold text-white">
                            Rental Request Details
                        </DialogTitle>
                        <DialogDescription class="text-sm text-white/80">
                            Request information for a boat rental.
                        </DialogDescription>
                    </div>
                </div>
                <button
                    type="button"
                    class="rounded-full p-1.5 text-white/80 transition hover:bg-white/15 hover:text-white"
                    aria-label="Close"
                    @click="close"
                >
                    <X class="size-5" />
                </button>
            </div>

            <div class="space-y-4 overflow-y-auto bg-[#f8fbfe] p-5 dark:bg-background">
                <div
                    class="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-[#d7e8f6] bg-white px-4 py-3 dark:border-white/10 dark:bg-white/5"
                >
                    <span
                        class="inline-flex items-center gap-1.5 rounded-full px-3 py-1.5 text-sm font-semibold"
                        :class="statusTone(listStatusKey)"
                    >
                        <CheckCircle2
                            v-if="
                                listStatusKey === 'confirmed' ||
                                listStatusKey === 'weather_reschedule_confirmed' ||
                                listStatusKey === 'completed'
                            "
                            class="size-4"
                        />
                        <Clock v-else class="size-4" />
                        {{ booking.status_label }}
                    </span>
                    <div class="flex items-center gap-2 text-sm">
                        <FileText class="size-4 text-[#1b7ad8]" />
                        <span class="text-ink-muted">Reference No.</span>
                        <span class="font-bold text-[#1b7ad8]">#{{ booking.code }}</span>
                    </div>
                </div>

                <div
                    class="rounded-xl border border-[#d7e8f6] bg-white p-4 dark:border-white/10 dark:bg-white/5"
                >
                    <div class="flex gap-3">
                        <div
                            class="size-20 shrink-0 overflow-hidden rounded-xl border border-[#d7e8f6] bg-[#eef6ff] dark:border-white/10"
                        >
                            <img
                                v-if="booking.boat.picture_url"
                                :src="booking.boat.picture_url"
                                :alt="booking.boat.name"
                                class="size-full object-cover"
                            />
                            <div
                                v-else
                                class="flex size-full items-center justify-center"
                            >
                                <Ship class="size-8 text-[#1b7ad8] opacity-50" />
                            </div>
                        </div>
                        <div class="min-w-0 flex-1">
                            <p
                                class="text-lg font-bold text-[#0f4c8a] dark:text-sky-100"
                            >
                                {{ booking.boat.name }}
                            </p>
                            <p class="text-sm font-semibold text-[#1b7ad8]">
                                {{ booking.boat.code }}
                            </p>
                            <p
                                class="mt-2 flex items-center gap-1.5 text-sm font-semibold text-ink dark:text-slate-200"
                            >
                                <Ship class="size-4 shrink-0 text-[#1b7ad8]" />
                                {{ booking.boat.type || 'Pump Boat' }}
                            </p>
                            <p
                                class="mt-1 flex items-center gap-1.5 text-sm font-semibold text-ink dark:text-slate-200"
                            >
                                <UserRound class="size-4 shrink-0 text-[#1b7ad8]" />
                                {{ booking.boat.operator_name ?? 'Unassigned' }}
                            </p>
                        </div>
                    </div>
                </div>

                <section
                    class="overflow-hidden rounded-xl border border-[#d7e8f6] bg-white dark:border-white/10 dark:bg-white/5"
                >
                    <div
                        class="flex items-center gap-2 border-b border-[#d7e8f6] bg-[#e8f4fc] px-4 py-2.5 dark:border-white/10 dark:bg-sky-500/10"
                    >
                        <CalendarDays class="size-4 text-[#1b7ad8]" />
                        <h3 class="text-sm font-bold text-[#0f4c8a] dark:text-sky-100">
                            Trip Details
                        </h3>
                    </div>
                    <div class="grid gap-3 p-4 sm:grid-cols-2">
                        <div class="flex gap-2.5">
                            <div
                                class="flex size-9 shrink-0 items-center justify-center rounded-lg bg-[#e8f1ff] dark:bg-sky-500/15"
                            >
                                <CalendarDays class="size-4 text-[#1b7ad8]" />
                            </div>
                            <div>
                                <p class="text-xs text-ink-muted">Date of Trip</p>
                                <p
                                    class="text-sm font-bold text-ink dark:text-slate-200"
                                >
                                    {{ booking.booking_date_label }}
                                </p>
                            </div>
                        </div>
                        <div class="flex gap-2.5">
                            <div
                                class="flex size-9 shrink-0 items-center justify-center rounded-lg bg-[#e8f1ff] dark:bg-sky-500/15"
                            >
                                <Clock class="size-4 text-[#1b7ad8]" />
                            </div>
                            <div>
                                <p class="text-xs text-ink-muted">Preferred Time</p>
                                <p
                                    class="text-sm font-bold text-ink dark:text-slate-200"
                                >
                                    {{ booking.start_time_label }}
                                </p>
                            </div>
                        </div>
                        <div class="flex gap-2.5">
                            <div
                                class="flex size-9 shrink-0 items-center justify-center rounded-lg bg-[#e8f1ff] dark:bg-sky-500/15"
                            >
                                <MapPin class="size-4 text-[#1b7ad8]" />
                            </div>
                            <div>
                                <p class="text-xs text-ink-muted">Destination</p>
                                <p
                                    class="text-sm font-bold text-ink dark:text-slate-200"
                                >
                                    {{ booking.destination }}
                                </p>
                            </div>
                        </div>
                        <div class="flex gap-2.5">
                            <div
                                class="flex size-9 shrink-0 items-center justify-center rounded-lg bg-[#e8f1ff] dark:bg-sky-500/15"
                            >
                                <Flag class="size-4 text-[#1b7ad8]" />
                            </div>
                            <div>
                                <p class="text-xs text-ink-muted">
                                    Pickup/Meeting Point
                                </p>
                                <p
                                    class="text-sm font-bold text-ink dark:text-slate-200"
                                >
                                    {{ booking.pickup_point || '—' }}
                                </p>
                            </div>
                        </div>
                    </div>
                </section>

                <div class="grid gap-3 sm:grid-cols-2">
                    <div
                        class="rounded-xl border border-[#d7e8f6] bg-white p-4 dark:border-white/10 dark:bg-white/5"
                    >
                        <div class="flex items-start gap-2.5">
                            <UserRound class="mt-0.5 size-4 shrink-0 text-[#1b7ad8]" />
                            <div>
                                <p class="text-xs text-ink-muted">Requester</p>
                                <p
                                    class="text-base font-bold text-[#1b7ad8]"
                                >
                                    {{ booking.customer_name }}
                                </p>
                                <p
                                    class="mt-1 text-sm font-semibold text-ink dark:text-slate-200"
                                >
                                    {{ booking.contact_number || '—' }}
                                </p>
                            </div>
                        </div>
                    </div>
                    <div
                        class="rounded-xl border border-[#d7e8f6] bg-white p-4 dark:border-white/10 dark:bg-white/5"
                    >
                        <div class="flex items-start gap-2.5">
                            <Users class="mt-0.5 size-4 shrink-0 text-[#1b7ad8]" />
                            <div>
                                <p class="text-xs text-ink-muted">Passengers</p>
                                <p
                                    class="text-base font-bold text-[#1b7ad8]"
                                >
                                    {{ booking.passenger_count }} /
                                    {{ booking.boat.capacity }} pax
                                </p>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="grid gap-3 sm:grid-cols-2">
                    <div
                        class="rounded-xl border border-[#d7e8f6] bg-white p-4 dark:border-white/10 dark:bg-white/5"
                    >
                        <div class="flex items-start gap-2.5">
                            <Briefcase class="mt-0.5 size-4 shrink-0 text-[#1b7ad8]" />
                            <div>
                                <p class="text-xs text-ink-muted">Purpose of Trip</p>
                                <p
                                    class="text-sm font-bold text-[#1b7ad8]"
                                >
                                    {{ booking.purpose || '—' }}
                                </p>
                            </div>
                        </div>
                    </div>
                    <div
                        class="rounded-xl border border-[#d7e8f6] bg-white p-4 dark:border-white/10 dark:bg-white/5"
                    >
                        <div class="flex items-start gap-2.5">
                            <StickyNote class="mt-0.5 size-4 shrink-0 text-[#1b7ad8]" />
                            <div>
                                <p class="text-xs text-ink-muted">Additional Notes</p>
                                <p
                                    class="text-sm font-bold text-[#1b7ad8]"
                                >
                                    {{ booking.remarks_display?.trim() || booking.remarks || '—' }}
                                </p>
                            </div>
                        </div>
                    </div>
                </div>

                <div
                    v-if="booking.passengers?.length"
                    class="rounded-xl border border-[#d7e8f6] bg-white p-4 dark:border-white/10 dark:bg-white/5"
                >
                    <div class="mb-3 flex items-center gap-2">
                        <Users class="size-4 text-[#1b7ad8]" />
                        <h3 class="text-sm font-bold text-[#0f4c8a] dark:text-sky-100">
                            Safety manifest
                        </h3>
                    </div>
                    <div
                        class="overflow-hidden rounded-xl border border-[#d7e8f6] dark:border-white/10"
                    >
                        <div
                            v-for="passenger in booking.passengers"
                            :key="passenger.id"
                            class="flex flex-wrap items-center justify-between gap-2 border-t border-[#d7e8f6] px-3 py-2 text-sm first:border-t-0 dark:border-white/10"
                        >
                            <span
                                class="font-medium text-[#0f4c8a] dark:text-sky-100"
                            >
                                {{ passenger.full_name }}
                            </span>
                            <span class="text-xs text-ink-muted">
                                {{ passenger.age }} · {{ passenger.gender }}
                                <span v-if="passenger.contact_number">
                                    · {{ passenger.contact_number }}
                                </span>
                            </span>
                        </div>
                    </div>
                </div>
            </div>

            <div
                class="flex flex-wrap items-center justify-end gap-3 border-t border-[#d7e8f6] bg-white px-5 py-4 dark:border-white/10 dark:bg-background"
            >
                <Button
                    type="button"
                    variant="outline"
                    class="h-10 rounded-xl border-[#1b7ad8] px-5 text-[#1b7ad8] hover:bg-[#e8f4fc]"
                    :disabled="processing"
                    @click="close"
                >
                    Close
                </Button>
                <Button
                    type="button"
                    variant="outline"
                    class="h-10 rounded-xl border-[#1b7ad8] px-5 text-[#1b7ad8] hover:bg-[#e8f4fc]"
                    :disabled="processing || slipDownloading"
                    @click="downloadSlip"
                >
                    <Spinner v-if="slipDownloading" class="size-4" />
                    <FileDown v-else class="size-4" />
                    Download Slip
                </Button>
                <template v-if="showReview && booking.status === 'pending'">
                    <Button
                        type="button"
                        variant="outline"
                        class="h-10 rounded-xl border-[#d45b5b] px-5 text-[#d45b5b] hover:bg-[#fdeeee]"
                        :disabled="processing"
                        @click="emit('reject', booking)"
                    >
                        <Spinner v-if="processing" class="size-4" />
                        Cancel request
                    </Button>
                </template>
                <Button
                    v-else-if="showEdit"
                    type="button"
                    class="h-10 rounded-xl bg-[#1b7ad8] px-5 text-white hover:bg-[#1567c4]"
                    @click="emit('edit', booking)"
                >
                    Edit Booking
                </Button>
            </div>
        </DialogContent>
    </Dialog>
</template>
