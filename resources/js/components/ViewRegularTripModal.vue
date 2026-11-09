<script setup lang="ts">
import { Clock, Ship, UserRound, X } from '@lucide/vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogTitle,
} from '@/components/ui/dialog';
import type { RegularTripRecord } from '@/types/schedule';

defineProps<{
    open: boolean;
    trip: RegularTripRecord | null;
}>();

const emit = defineEmits<{
    'update:open': [value: boolean];
    edit: [trip: RegularTripRecord];
}>();

function close() {
    emit('update:open', false);
}

function twoWayRouteTitle(trip: RegularTripRecord): string {
    return toTwoWayLabel(trip.route) ?? toTwoWayLabel(trip.direction) ?? trip.route;
}

function toTwoWayLabel(value: string): string | null {
    const trimmed = value.trim();

    if (!trimmed) {
        return null;
    }

    if (trimmed.includes('↔')) {
        return trimmed;
    }

    if (trimmed.includes('→')) {
        const [origin, destination] = trimmed.split('→').map((part) => part.trim());

        if (origin && destination) {
            return `${origin} ↔ ${destination}`;
        }
    }

    return null;
}

function rotationOrderLabel(order: number): string {
    const n = Math.max(1, Math.trunc(Number(order) || 1));
    const mod100 = n % 100;
    const mod10 = n % 10;
    const suffix =
        mod100 !== 11 && mod10 === 1
            ? 'st'
            : mod100 !== 12 && mod10 === 2
              ? 'nd'
              : mod100 !== 13 && mod10 === 3
                ? 'rd'
                : 'th';

    return `${n}${suffix} to Depart`;
}

function statusTone(status: RegularTripRecord['status']) {
    return {
        scheduled:
            'bg-sky-50 text-sky-700 dark:bg-sky-500/15 dark:text-sky-200',
        on_trip:
            'bg-violet-50 text-violet-700 dark:bg-violet-500/15 dark:text-violet-200',
        delayed:
            'bg-amber-50 text-amber-700 dark:bg-amber-500/15 dark:text-amber-200',
        completed:
            'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-200',
        cancelled:
            'bg-rose-50 text-rose-700 dark:bg-rose-500/15 dark:text-rose-200',
    }[status];
}
</script>

<template>
    <Dialog :open="open" @update:open="emit('update:open', $event)">
        <DialogContent
            v-if="trip"
            :show-close-button="false"
            class="flex max-h-[92vh] w-full max-w-[calc(100%-1.5rem)] flex-col gap-0 overflow-hidden rounded-2xl border-none p-0 shadow-2xl sm:max-w-lg"
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
                            {{ twoWayRouteTitle(trip) }}
                        </DialogTitle>
                        <DialogDescription class="text-sm text-white/80">
                            Regular trip rotation details
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

            <div class="space-y-4 overflow-y-auto p-5">
                <div class="flex items-center gap-3">
                    <div
                        class="flex size-14 items-center justify-center overflow-hidden rounded-xl bg-[#d7ebfa] dark:bg-sky-500/10"
                    >
                        <img
                            v-if="trip.boat.picture_url"
                            :src="trip.boat.picture_url"
                            :alt="trip.boat.name"
                            class="h-full w-full object-cover"
                        />
                        <Ship v-else class="size-6 text-[#1b7ad8]" />
                    </div>
                    <div>
                        <p class="font-semibold text-[#0f4c8a] dark:text-sky-100">
                            {{ trip.boat.name }}
                        </p>
                        <p class="text-xs text-ink-muted">
                            {{ trip.boat.code }}
                            <span v-if="trip.boat.operator_name">
                                · {{ trip.boat.operator_name }}
                            </span>
                        </p>
                    </div>
                </div>

                <dl class="grid gap-3 sm:grid-cols-2">
                    <div>
                        <dt class="text-xs text-ink-muted">Date</dt>
                        <dd class="font-medium text-[#0f4c8a] dark:text-sky-100">
                            {{ trip.trip_date_label }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs text-ink-muted">Departure</dt>
                        <dd class="inline-flex items-center gap-1.5 font-medium text-[#0f4c8a] dark:text-sky-100">
                            <Clock class="size-3.5 text-[#1b7ad8]" />
                            {{ trip.departure_time_label }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs text-ink-muted">Direction</dt>
                        <dd class="font-medium text-[#0f4c8a] dark:text-sky-100">
                            {{ trip.direction }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs text-ink-muted">Rotation Order</dt>
                        <dd class="font-medium text-[#0f4c8a] dark:text-sky-100">
                            {{ rotationOrderLabel(trip.rotation_order) }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs text-ink-muted">Status</dt>
                        <dd>
                            <span
                                class="inline-flex rounded-full px-2.5 py-1 text-xs font-semibold"
                                :class="statusTone(trip.status)"
                            >
                                {{ trip.status_label }}
                            </span>
                        </dd>
                    </div>
                    <div class="sm:col-span-2">
                        <dt class="mb-1 flex items-center gap-1.5 text-xs text-ink-muted">
                            <UserRound class="size-3.5" />
                            Operator
                        </dt>
                        <dd class="font-medium text-[#0f4c8a] dark:text-sky-100">
                            {{ trip.boat.operator_name ?? 'Unassigned' }}
                        </dd>
                    </div>
                    <div class="sm:col-span-2">
                        <dt class="text-xs text-ink-muted">Remarks</dt>
                        <dd class="font-medium text-[#0f4c8a] dark:text-sky-100">
                            {{ trip.remarks || 'No remarks' }}
                        </dd>
                    </div>
                </dl>
            </div>

            <div
                class="flex items-center justify-end gap-3 border-t border-[#d7e8f6] bg-white px-5 py-4 dark:border-white/10 dark:bg-background"
            >
                <Button
                    type="button"
                    variant="outline"
                    class="h-10 rounded-xl border-[#1b7ad8] px-5 text-[#1b7ad8] hover:bg-[#e8f4fc]"
                    @click="close"
                >
                    Close
                </Button>
                <Button
                    type="button"
                    class="h-10 rounded-xl bg-[#1b7ad8] px-5 text-white hover:bg-[#1567c4]"
                    @click="emit('edit', trip)"
                >
                    Edit Trip
                </Button>
            </div>
        </DialogContent>
    </Dialog>
</template>
