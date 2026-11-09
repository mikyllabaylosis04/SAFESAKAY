<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { MapPin, Phone, Send, Ship, UserRound, Users } from '@lucide/vue';
import { computed, ref } from 'vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import type { BoatBookingRecord } from '@/types/booking';

const props = defineProps<{
    booking: BoatBookingRecord | null;
    open: boolean;
}>();

const emit = defineEmits<{
    'update:open': [value: boolean];
}>();

const remarks = ref('');
const reason = ref('');
const replacementBoatId = ref<number | null>(null);
const busy = ref(false);
const flash = ref<string | null>(null);

const replacement = computed(() => props.booking?.replacement ?? null);
const record = computed(() => props.booking?.confirmation_record ?? null);

const isConfirmed = computed(
    () => props.booking?.trip_confirmation_status === 'confirmed',
);

const isCannotOperate = computed(
    () => props.booking?.trip_confirmation_status === 'cannot_operate',
);

const showActionForm = computed(() => {
    if (!props.booking) {
        return false;
    }

    if (record.value?.is_read_only) {
        return false;
    }

    return (
        props.booking.can_send_confirmation_request
        || props.booking.can_admin_confirm_trip
        || props.booking.can_admin_cannot_operate
    );
});

const operatorPhone = computed(
    () =>
        props.booking?.operator_contact_number_label
        ?? props.booking?.boat?.operator_contact_number
        ?? null,
);

const statusTone = computed(() => {
    if (isConfirmed.value) {
        return 'bg-emerald-50 text-emerald-900 border-emerald-200';
    }
    if (isCannotOperate.value) {
        return 'bg-amber-50 text-amber-950 border-amber-200';
    }

    return 'bg-sky-50 text-sky-950 border-sky-200';
});

function close() {
    emit('update:open', false);
    remarks.value = '';
    reason.value = '';
    replacementBoatId.value = null;
    flash.value = null;
}

function post(url: string, data: Record<string, unknown>, options?: { keepOpen?: boolean; successMessage?: string }) {
    if (!props.booking) {
        return;
    }

    busy.value = true;
    router.post(url, data, {
        preserveScroll: true,
        onSuccess: () => {
            if (options?.successMessage) {
                flash.value = options.successMessage;
            }
            if (!options?.keepOpen) {
                close();
            }
        },
        onFinish: () => {
            busy.value = false;
        },
    });
}

function sendRequest() {
    post(
        `/boat-bookings/${props.booking!.id}/trip-confirmation/request`,
        {},
        {
            keepOpen: true,
            successMessage: 'The operator was notified in the mobile app to confirm availability.',
        },
    );
}

function confirmTrip() {
    post(`/boat-bookings/${props.booking!.id}/trip-confirmation/confirm`, {
        remarks: remarks.value || null,
    });
}

function cannotOperate() {
    post(`/boat-bookings/${props.booking!.id}/trip-confirmation/cannot-operate`, {
        reason: reason.value || null,
    });
}

function proposeReplacement() {
    if (!replacementBoatId.value) {
        return;
    }

    post(`/boat-bookings/${props.booking!.id}/trip-confirmation/replacement`, {
        replacement_boat_id: replacementBoatId.value,
    });
}

function approveReplacement() {
    if (!replacement.value?.id) {
        return;
    }

    busy.value = true;
    router.post(`/boat-bookings/replacements/${replacement.value.id}/approve`, {}, {
        preserveScroll: true,
        onFinish: () => {
            busy.value = false;
            close();
        },
    });
}

function detailRow(label: string, value: string | null | undefined) {
    return value && value.trim() !== '' ? { label, value } : null;
}

const confirmationDetailRows = computed(() => {
    const r = record.value;
    if (!r) {
        return [];
    }

    const rows = [
        detailRow('Confirmation Request Sent', r.confirmation_request_sent_at_label ?? undefined),
        detailRow('Request Sent Via', r.confirmation_request_via_label ?? undefined),
        detailRow('Confirmed By', r.confirmed_by_label ?? undefined),
        detailRow(
            'Availability Recorded Via',
            r.confirmation_type_label ?? undefined,
        ),
        detailRow('Confirmation Date & Time', r.confirmation_at_label ?? undefined),
        detailRow('Operator Response', r.operator_response_label ?? undefined),
        detailRow('Remarks', r.remarks ?? undefined),
    ].filter(Boolean) as { label: string; value: string }[];

    if (!r.is_read_only && r.confirmation_request_sent_at_label) {
        return [
            detailRow('Confirmation Request Sent', r.confirmation_request_sent_at_label),
        ].filter(Boolean) as { label: string; value: string }[];
    }

    return rows;
});
</script>

<template>
    <Dialog :open="open" @update:open="emit('update:open', $event)">
        <DialogContent class="max-h-[92vh] max-w-md overflow-y-auto p-0">
            <DialogHeader v-if="booking" class="space-y-1 border-b border-[#e8f1f8] px-5 pb-4 pt-5 text-left">
                <DialogTitle class="text-lg font-bold text-[#0f4c8a]">
                    Trip Confirmation · {{ booking.code }}
                </DialogTitle>
                <DialogDescription class="text-sm text-ink-soft">
                    {{ booking.booking_date_label }} · {{ booking.start_time_label }} ·
                    {{ booking.destination }}
                </DialogDescription>
            </DialogHeader>

            <div v-if="booking" class="space-y-4 px-5 py-4 text-sm">
                <div class="rounded-xl border px-4 py-3 text-center" :class="statusTone">
                    <p class="text-base font-bold">
                        {{ booking.trip_confirmation_status_label ?? 'Awaiting Confirmation' }}
                    </p>
                </div>

                <div class="grid grid-cols-2 gap-2">
                    <div class="rounded-xl border border-[#d7e8f6] bg-white p-3 dark:bg-[#102036] dark:text-slate-100 dark:border-white/10">
                        <div class="flex items-center gap-2 text-ink-soft">
                            <Ship class="size-4 text-[#1479E8]" />
                            <span class="text-[11px] font-semibold uppercase">Boat</span>
                        </div>
                        <p class="mt-1.5 text-sm font-semibold text-[#0f4c8a]">
                            {{ booking.boat?.name ?? '—' }}
                        </p>
                    </div>
                    <div class="rounded-xl border border-[#d7e8f6] bg-white p-3 dark:bg-[#102036] dark:text-slate-100 dark:border-white/10">
                        <div class="flex items-center gap-2 text-ink-soft">
                            <UserRound class="size-4 text-[#1479E8]" />
                            <span class="text-[11px] font-semibold uppercase">Operator</span>
                        </div>
                        <p class="mt-1.5 text-sm font-semibold text-[#0f4c8a]">
                            {{ booking.boat?.operator_name ?? '—' }}
                        </p>
                    </div>
                    <div class="rounded-xl border border-[#d7e8f6] bg-white p-3 dark:bg-[#102036] dark:text-slate-100 dark:border-white/10">
                        <div class="flex items-center gap-2 text-ink-soft">
                            <Users class="size-4 text-[#1479E8]" />
                            <span class="text-[11px] font-semibold uppercase">Passengers</span>
                        </div>
                        <p class="mt-1.5 text-sm font-semibold text-[#0f4c8a]">
                            {{ booking.passenger_count }}
                        </p>
                    </div>
                    <div class="rounded-xl border border-[#d7e8f6] bg-white p-3 dark:bg-[#102036] dark:text-slate-100 dark:border-white/10">
                        <div class="flex items-center gap-2 text-ink-soft">
                            <MapPin class="size-4 text-[#1479E8]" />
                            <span class="text-[11px] font-semibold uppercase">Pickup</span>
                        </div>
                        <p class="mt-1.5 text-sm font-semibold text-[#0f4c8a]">
                            {{ booking.pickup_point ?? '—' }}
                        </p>
                    </div>
                </div>

                <section
                    v-if="confirmationDetailRows.length && (record?.is_read_only || record?.confirmation_request_sent_at_label)"
                    class="rounded-xl border border-[#d7e8f6] bg-white p-4 dark:bg-[#102036] dark:text-slate-100 dark:border-white/10"
                >
                    <p class="text-sm font-bold text-[#0f4c8a]">Confirmation Details</p>
                    <p
                        v-if="record?.is_read_only"
                        class="mt-0.5 text-xs text-ink-soft"
                    >
                        Read-only record. Confirmation actions are closed for this trip.
                    </p>
                    <dl class="mt-3 space-y-2.5">
                        <div
                            v-for="row in confirmationDetailRows"
                            :key="row.label"
                            class="flex flex-col gap-0.5 border-b border-[#eef4f9] pb-2 last:border-0 last:pb-0 sm:flex-row sm:gap-4"
                        >
                            <dt class="shrink-0 text-xs font-semibold text-ink-soft sm:w-40">
                                {{ row.label }}
                            </dt>
                            <dd class="text-sm font-medium text-[#0f4c8a]">
                                {{ row.value }}
                            </dd>
                        </div>
                    </dl>
                </section>

                <p
                    v-if="flash"
                    class="rounded-lg border border-emerald-200 bg-emerald-50 px-3 py-2 text-xs text-emerald-900"
                >
                    {{ flash }}
                </p>

                <template v-if="showActionForm">
                    <section
                        v-if="booking.can_send_confirmation_request"
                        class="rounded-xl border border-[#d7e8f6] bg-[#f8fbfe] p-4 dark:bg-[#0b1b30] dark:text-slate-100"
                    >
                        <p class="text-xs font-bold uppercase tracking-wide text-[#1479E8]">
                            Option 1 · Send app request
                        </p>
                        <p class="mt-1 text-xs text-ink-soft">
                            Notifies the operator in SafeSakay to confirm availability or tap Cannot Operate.
                        </p>
                        <Button
                            type="button"
                            variant="outline"
                            class="mt-3 h-10 w-full border-[#1479E8] text-[#1479E8] hover:bg-[#eef6ff]"
                            :disabled="busy"
                            @click="sendRequest"
                        >
                            <Send class="mr-2 size-4" />
                            Send Request Confirmation
                        </Button>
                    </section>

                    <section
                        v-if="booking.can_admin_confirm_trip || booking.can_admin_cannot_operate"
                        class="rounded-xl border border-[#e8dcc8] bg-[#fffbf5] p-4 dark:border-white/10 dark:bg-[#102036] dark:text-slate-100"
                    >
                        <p class="text-xs font-bold uppercase tracking-wide text-[#b45309]">
                            Option 2 · Phone call
                        </p>
                        <p class="mt-1 text-xs text-ink-soft">
                            Use when the operator cannot open the app. Call them, then record their answer.
                        </p>

                        <div
                            class="mt-3 flex items-center gap-3 rounded-lg border border-[#fcd34d] bg-white px-3 py-2.5 dark:bg-[#102036] dark:text-slate-100 dark:border-white/10"
                        >
                            <Phone class="size-5 shrink-0 text-[#b45309]" />
                            <div class="min-w-0">
                                <p class="text-[11px] font-semibold uppercase text-ink-soft">
                                    Operator contact
                                </p>
                                <a
                                    v-if="operatorPhone"
                                    :href="`tel:${operatorPhone.replace(/\s/g, '')}`"
                                    class="text-base font-bold text-[#0f4c8a] underline-offset-2 hover:underline"
                                >
                                    {{ operatorPhone }}
                                </a>
                                <p v-else class="text-sm font-medium text-ink-muted">
                                    No number on file
                                </p>
                            </div>
                        </div>

                        <div class="mt-3 grid gap-2 sm:grid-cols-2">
                            <button
                                v-if="booking.can_admin_confirm_trip"
                                type="button"
                                class="rounded-xl bg-emerald-600 px-3 py-3.5 text-left text-white transition hover:bg-emerald-700 disabled:opacity-50"
                                :disabled="busy"
                                @click="confirmTrip"
                            >
                                <span class="block text-sm font-bold">Confirm Trip</span>
                                <span class="mt-0.5 block text-[11px] text-emerald-100">
                                    Operator available
                                </span>
                            </button>
                            <button
                                v-if="booking.can_admin_cannot_operate"
                                type="button"
                                class="rounded-xl bg-red-600 px-3 py-3.5 text-left text-white transition hover:bg-red-700 disabled:opacity-50"
                                :disabled="busy"
                                @click="cannotOperate"
                            >
                                <span class="block text-sm font-bold">Cannot Operate</span>
                                <span class="mt-0.5 block text-[11px] text-red-100">
                                    Operator unavailable
                                </span>
                            </button>
                        </div>

                        <details class="mt-3 rounded-lg border border-[#fde68a] bg-white/80 px-3 py-2 dark:border-amber-500/30 dark:bg-amber-950/30">
                            <summary class="cursor-pointer text-xs font-semibold text-[#0f4c8a]">
                                Add optional note
                            </summary>
                            <textarea
                                v-model="remarks"
                                rows="2"
                                class="mt-2 w-full rounded-lg border border-[#c5def5] px-3 py-2 text-sm"
                                placeholder="Phone confirm note"
                            />
                            <textarea
                                v-if="booking.can_admin_cannot_operate"
                                v-model="reason"
                                rows="2"
                                class="mt-2 w-full rounded-lg border border-[#c5def5] px-3 py-2 text-sm"
                                placeholder="Cannot operate reason"
                            />
                        </details>
                    </section>
                </template>

                <div
                    v-if="isCannotOperate && replacement"
                    class="space-y-2 rounded-xl border border-amber-200 bg-amber-50/80 p-3 text-xs"
                >
                    <p class="font-bold text-amber-950">Replacement boat</p>
                    <p class="text-amber-900">{{ replacement.status_label }}</p>
                    <Button
                        v-if="replacement.can_approve"
                        type="button"
                        class="w-full"
                        :disabled="busy"
                        @click="approveReplacement"
                    >
                        Approve replacement
                    </Button>
                    <template v-else-if="replacement.replacement_options?.length">
                        <select
                            v-model.number="replacementBoatId"
                            class="w-full rounded-lg border bg-white px-3 py-2 dark:bg-[#102036] dark:text-slate-100 dark:border-white/10"
                        >
                            <option :value="null" disabled>Select replacement boat</option>
                            <option
                                v-for="boat in replacement.replacement_options"
                                :key="boat.id"
                                :value="boat.id"
                            >
                                {{ boat.name }} · {{ boat.operator_name }}
                            </option>
                        </select>
                        <Button
                            type="button"
                            class="mt-2 w-full"
                            :disabled="busy || !replacementBoatId"
                            @click="proposeReplacement"
                        >
                            Notify replacement operator
                        </Button>
                    </template>
                </div>
            </div>

            <DialogFooter class="border-t border-[#e8f1f8] px-5 py-3">
                <Button type="button" variant="outline" @click="close">Close</Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
