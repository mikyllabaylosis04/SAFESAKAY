<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { AlertTriangle, Save, X } from '@lucide/vue';
import { computed, nextTick, ref, watch } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Spinner } from '@/components/ui/spinner';
import { cn } from '@/lib/utils';
import type { TripPassengerRecord } from '@/types/schedule';

const props = defineProps<{
    open: boolean;
    tripId: number;
    passenger?: TripPassengerRecord | null;
    remainingSeats: number;
    capacity: number;
    passengerCount: number;
    fares?: {
        regular_fare_label?: string | null;
        student_fare_label?: string | null;
        senior_fare_label?: string | null;
        discount_percent?: number;
        student_discount_percent?: number;
        senior_discount_percent?: number;
    } | null;
    from?: string | null;
    boardingStartedAtLabel?: string | null;
    allowComplete?: boolean;
}>();

const emit = defineEmits<{
    'update:open': [value: boolean];
    complete: [];
}>();

const fieldClass =
    'h-10 w-full rounded-xl border border-[#c5def5] bg-[#f7fbfe] text-ink shadow-none focus-visible:border-[#1b7ad8] focus-visible:ring-[#1b7ad8]/20 dark:border-white/10 dark:bg-white/5 dark:text-sky-50';

const genderOptions = ['Male', 'Female', 'Prefer not to say'] as const;

const studentDiscount = computed(
    () => props.fares?.student_discount_percent ?? props.fares?.discount_percent ?? 20,
);

const seniorDiscount = computed(
    () => props.fares?.senior_discount_percent ?? props.fares?.discount_percent ?? 20,
);

const fareOptions = computed(() => [
    {
        value: 'regular',
        label: 'Regular',
        amount: props.fares?.regular_fare_label || 'Full fare',
    },
    {
        value: 'student',
        label: 'Student',
        amount: props.fares?.student_fare_label || `${studentDiscount.value}% off`,
    },
    {
        value: 'senior',
        label: 'Senior',
        amount: props.fares?.senior_fare_label || `${seniorDiscount.value}% off`,
    },
]);

const selectedFare = computed(
    () =>
        fareOptions.value.find((option) => option.value === form.fare_type) ??
        fareOptions.value[0],
);

const form = useForm({
    full_name: '',
    age: '',
    fare_type: 'regular',
    gender: '',
    contact_number: '',
    from: props.from || '',
    finish: '',
});

const isEditing = computed(() => Boolean(props.passenger));

const boardingLabel = ref(props.boardingStartedAtLabel ?? '');
let boardingStartSent = Boolean(props.boardingStartedAtLabel);

function csrfToken() {
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);

    return match ? decodeURIComponent(match[1]) : '';
}

watch(
    () => props.boardingStartedAtLabel,
    (value) => {
        boardingLabel.value = value ?? '';
        boardingStartSent = Boolean(value);
    },
);

watch(
    () => form.full_name,
    (value) => {
        if (isEditing.value || boardingStartSent || value.trim() === '') {
            return;
        }

        boardingStartSent = true;

        fetch(`/regular-trips/${props.tripId}/boarding/start`, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-XSRF-TOKEN': csrfToken(),
            },
        })
            .then((response) => (response.ok ? response.json() : null))
            .then((data: { started_at_label?: string | null } | null) => {
                if (data?.started_at_label) {
                    boardingLabel.value = data.started_at_label;
                }
            })
            .catch(() => {
                boardingStartSent = false;
            });
    },
);

const filledSeats = computed(() =>
    Math.min(props.passengerCount, Math.max(0, props.capacity)),
);

const seatsLeft = computed(() => Math.max(0, props.remainingSeats));

const fillPercent = computed(() => {
    if (props.capacity <= 0) {
        return 0;
    }

    return Math.min(100, Math.round((filledSeats.value / props.capacity) * 100));
});

const isLastSeat = computed(() => !isEditing.value && seatsLeft.value === 1);

const isAlmostFull = computed(
    () => !isEditing.value && seatsLeft.value > 1 && seatsLeft.value <= 2,
);

const fillTone = computed(() => {
    if (isLastSeat.value || fillPercent.value >= 100) {
        return 'rose';
    }

    if (isAlmostFull.value || fillPercent.value >= 80) {
        return 'amber';
    }

    return 'blue';
});

function blankForm() {
    form.reset();
    form.fare_type = 'regular';
    form.clearErrors();
}

function fillPassenger(passenger: TripPassengerRecord) {
    form.full_name = passenger.full_name;
    form.age = String(passenger.age);
    form.fare_type = passenger.fare_type || 'regular';
    form.gender = passenger.gender;
    form.contact_number = passenger.contact_number;
    form.clearErrors();
}

function close() {
    emit('update:open', false);
}

function focusName() {
    nextTick(() => {
        const input = document.getElementById(
            'passenger-name',
        ) as HTMLInputElement | null;
        input?.focus();
        input?.select();
    });
}

function submit(mode: 'next' | 'close' | 'complete') {
    if (mode === 'complete' && form.full_name.trim() === '') {
        emit('complete');
        close();

        return;
    }

    form.from = props.from || '';
    form.finish = mode === 'complete' ? '1' : '';
    const fillingLastSeat = props.remainingSeats <= 1;

    const options = {
        preserveScroll: true,
        preserveState: !isEditing.value && mode === 'next' && !fillingLastSeat,
        onSuccess: () => {
            if (!isEditing.value && mode === 'next' && !fillingLastSeat) {
                blankForm();
                focusName();
                return;
            }

            close();
        },
    };

    if (isEditing.value && props.passenger) {
        form.post(
            `/regular-trips/${props.tripId}/passengers/${props.passenger.id}`,
            options,
        );
        return;
    }

    form.post(`/regular-trips/${props.tripId}/passengers`, options);
}

watch(
    () => [props.open, props.passenger] as const,
    ([open, passenger]) => {
        if (!open) {
            blankForm();
            return;
        }

        if (passenger) {
            fillPassenger(passenger);
        } else {
            blankForm();
        }

        focusName();
    },
);
</script>

<template>
    <Dialog :open="open" @update:open="emit('update:open', $event)">
        <DialogContent
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
                            {{ isEditing ? 'Edit Passenger' : 'Add Passenger' }}
                        </DialogTitle>
                        <DialogDescription class="sr-only">
                            {{
                                isEditing
                                    ? 'Update passenger on the trip manifest.'
                                    : 'Add a passenger to the trip manifest.'
                            }}
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

            <form
                class="flex min-h-0 flex-1 flex-col"
                @submit.prevent="submit(isEditing || isLastSeat ? 'close' : 'next')"
            >
                <div class="grid gap-3 overflow-y-auto p-5 sm:grid-cols-2">
                    <div
                        class="rounded-xl px-4 py-3 sm:col-span-2"
                        :class="{
                            'bg-rose-50 text-rose-700': fillTone === 'rose',
                            'bg-[#fbf3e6] text-[#b45309]': fillTone === 'amber',
                            'bg-[#e8f4fc] text-[#0f4c8a]': fillTone === 'blue',
                        }"
                    >
                        <div class="flex items-start justify-between gap-3">
                            <div class="flex min-w-0 items-start gap-2">
                                <AlertTriangle
                                    v-if="fillTone !== 'blue'"
                                    class="mt-0.5 size-4 shrink-0"
                                />
                                <div>
                                    <p class="text-sm font-semibold">
                                        {{ filledSeats }} / {{ capacity }} seats filled
                                    </p>
                                    <p
                                        v-if="fillTone === 'rose'"
                                        class="mt-0.5 text-xs leading-5"
                                    >
                                        Last seat — boat will be full after save.
                                    </p>
                                    <p
                                        v-else-if="fillTone === 'amber'"
                                        class="mt-0.5 text-xs leading-5"
                                    >
                                        Almost full.
                                    </p>
                                </div>
                            </div>
                            <p
                                v-if="!isEditing"
                                class="shrink-0 text-xs font-semibold"
                            >
                                {{ seatsLeft }} left
                            </p>
                        </div>
                        <div
                            class="mt-2 h-2 overflow-hidden rounded-full bg-white/70"
                        >
                            <div
                                class="h-full rounded-full transition-all"
                                :class="{
                                    'bg-rose-600': fillTone === 'rose',
                                    'bg-[#d4922a]': fillTone === 'amber',
                                    'bg-[#1b7ad8]': fillTone === 'blue',
                                }"
                                :style="{ width: `${fillPercent}%` }"
                            />
                        </div>
                        <p
                            class="mt-3 border-t border-white/50 pt-2 text-xs"
                        >
                            Boarding Time:
                            <span class="font-semibold">
                                {{ boardingLabel || 'Not started' }}
                            </span>
                        </p>
                    </div>
                    <div class="grid gap-1.5 sm:col-span-2">
                        <Label for="passenger-name">Full Name *</Label>
                        <Input
                            id="passenger-name"
                            v-model="form.full_name"
                            type="text"
                            required
                            autocomplete="name"
                            :class="fieldClass"
                            placeholder="Passenger full name"
                        />
                        <InputError :message="form.errors.full_name" />
                    </div>

                    <div class="grid gap-1.5">
                        <Label for="passenger-age">Age *</Label>
                        <Input
                            id="passenger-age"
                            v-model="form.age"
                            type="number"
                            min="0"
                            max="120"
                            required
                            :class="fieldClass"
                            placeholder="Age"
                        />
                        <InputError :message="form.errors.age" />
                    </div>

                    <div class="grid gap-1.5">
                        <Label>Gender *</Label>
                        <Select v-model="form.gender" required>
                            <SelectTrigger :class="cn(fieldClass, 'w-full')">
                                <SelectValue placeholder="Select gender" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem
                                    v-for="option in genderOptions"
                                    :key="option"
                                    :value="option"
                                >
                                    {{ option }}
                                </SelectItem>
                            </SelectContent>
                        </Select>
                        <InputError :message="form.errors.gender" />
                    </div>

                    <div class="grid gap-1.5 sm:col-span-2">
                        <Label>Fare Type *</Label>
                        <Select v-model="form.fare_type">
                            <SelectTrigger :class="cn(fieldClass, 'w-full')">
                                <SelectValue placeholder="Select Regular, Student, or Senior">
                                    <span class="flex w-full items-center justify-between gap-3">
                                        <span>{{ selectedFare.label }}</span>
                                        <span class="text-xs font-semibold text-[#1b7ad8]">
                                            {{ selectedFare.amount }}
                                        </span>
                                    </span>
                                </SelectValue>
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem
                                    v-for="option in fareOptions"
                                    :key="option.value"
                                    :value="option.value"
                                >
                                    <span class="flex w-full items-center justify-between gap-6">
                                        <span>{{ option.label }}</span>
                                        <span class="text-xs text-ink-muted">
                                            {{ option.amount }}
                                        </span>
                                    </span>
                                </SelectItem>
                            </SelectContent>
                        </Select>
                        <InputError :message="form.errors.fare_type" />
                    </div>
                </div>

                <div
                    class="flex flex-wrap items-center justify-end gap-3 border-t border-[#d7e8f6] bg-white px-5 py-4 dark:border-white/10 dark:bg-background"
                >
                    <Button
                        v-if="!isEditing && !isLastSeat"
                        type="submit"
                        class="h-10 rounded-xl bg-[#1b7ad8] px-5 text-white hover:bg-[#1567c4]"
                        :disabled="form.processing"
                    >
                        <Spinner v-if="form.processing" class="size-4" />
                        <Save v-else class="size-4" />
                        Save & Next
                    </Button>
                    <Button
                        type="button"
                        class="h-10 rounded-xl px-5 text-white"
                        :class="
                            isEditing
                                ? 'bg-[#1b7ad8] hover:bg-[#1567c4]'
                                : 'bg-[#0d9b8a] hover:bg-[#0b8778]'
                        "
                        :disabled="form.processing"
                        @click="submit('close')"
                    >
                        <Spinner v-if="form.processing" class="size-4" />
                        <Save v-else class="size-4" />
                        {{ isEditing ? 'Save' : 'Save & Close' }}
                    </Button>
                    <Button
                        v-if="allowComplete && !isEditing"
                        type="button"
                        class="h-10 rounded-xl bg-emerald-600 px-5 text-white hover:bg-emerald-700"
                        :disabled="form.processing"
                        @click="submit('complete')"
                    >
                        <Spinner v-if="form.processing" class="size-4" />
                        Done
                    </Button>
                </div>
            </form>
        </DialogContent>
    </Dialog>
</template>