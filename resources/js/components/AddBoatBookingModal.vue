<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { CalendarDays, CloudUpload, Save, X } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
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
import type {
    BoatBookingRecord,
    BoatBookingStatusOption,
    BookingBoatOption,
} from '@/types/booking';

const props = defineProps<{
    open: boolean;
    boats: BookingBoatOption[];
    packageOptions: string[];
    statusOptions: BoatBookingStatusOption[];
    booking?: BoatBookingRecord | null;
    walkInCommission?: {
        enabled: boolean;
        percentage: number;
        percentage_label: string;
    } | null;
}>();

const emit = defineEmits<{
    'update:open': [value: boolean];
}>();

const OTHER_PURPOSE = 'Other / Specify';

const TRIP_PURPOSES = [
    'Island hopping',
    'Mangaligo',
    'Leisure',
    'Family outing',
    'Transport',
    OTHER_PURPOSE,
] as const;

const fieldClass =
    'h-10 w-full rounded-xl border border-[#c5def5] bg-[#f7fbfe] text-ink shadow-none focus-visible:border-[#1b7ad8] focus-visible:ring-[#1b7ad8]/20 dark:border-white/10 dark:bg-white/5 dark:text-sky-50';

const purposeChoice = ref<string>(TRIP_PURPOSES[0]);
const purposeOther = ref('');

const form = useForm({
    boat_id: '',
    customer_name: '',
    contact_number: '',
    package: '',
    destination: '',
    pickup_point: '',
    purpose: '',
    booking_date: '',
    start_time: '',
    passenger_count: '1',
    status: 'confirmed',
    walk_in_payment_method: 'cash' as 'cash' | 'gcash',
    walk_in_cash_received: '',
    gcash_reference: '',
    payment_receipt: null as File | null,
    remarks: '',
});

const receiptInput = ref<HTMLInputElement | null>(null);
const receiptFileName = ref('');

function formatPeso(amount: number): string {
    if (!Number.isFinite(amount)) {
        return '—';
    }

    return `₱${amount.toLocaleString('en-PH', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    })}`;
}

function commissionOnDue(base: number): number {
    if (!props.walkInCommission?.enabled || base <= 0) {
        return 0;
    }

    return (
        Math.round(((base * props.walkInCommission.percentage) / 100) * 100) /
        100
    );
}

const isEditing = computed(() => Boolean(props.booking));

const selectedBoat = computed(() =>
    props.boats.find((boat) => String(boat.id) === String(form.boat_id)),
);

const rentalDestinations = computed(
    () => selectedBoat.value?.rental_destinations ?? [],
);

const selectedDestination = computed(() =>
    rentalDestinations.value.find(
        (item) => item.destination === form.destination,
    ),
);

const showPurposeOther = computed(
    () => purposeChoice.value === OTHER_PURPOSE,
);

const amountDue = computed(() =>
    Number(selectedDestination.value?.down_payment_amount ?? 0),
);

const amountDueLabel = computed(
    () =>
        selectedDestination.value?.down_payment_amount_label ??
        formatPeso(amountDue.value),
);

const amountReceived = computed(
    () => parseFloat(form.walk_in_cash_received) || 0,
);

const changeAmount = computed(() =>
    Math.max(0, amountReceived.value - amountDue.value),
);

const commissionAmountLabel = computed(() =>
    formatPeso(commissionOnDue(amountDue.value)),
);

const isCashWalkIn = computed(
    () => !isEditing.value && form.walk_in_payment_method === 'cash',
);

const isGcashWalkIn = computed(
    () => !isEditing.value && form.walk_in_payment_method === 'gcash',
);

function onReceiptSelected(event: Event) {
    const file = (event.target as HTMLInputElement).files?.[0] ?? null;
    form.payment_receipt = file;
    receiptFileName.value = file?.name ?? '';
}

function clearReceipt() {
    form.payment_receipt = null;
    receiptFileName.value = '';
    if (receiptInput.value) {
        receiptInput.value.value = '';
    }
}

watch(
    () => form.boat_id,
    () => {
        if (isEditing.value) {
            return;
        }

        const first = rentalDestinations.value[0];
        form.destination = first?.destination ?? '';
        form.package = form.destination;
    },
);

watch(
    () => form.destination,
    (value) => {
        if (value && !isEditing.value) {
            form.package = value;
        }
    },
);

watch(
    () => [props.open, props.booking] as const,
    ([open, booking]) => {
        if (!open) {
            form.reset();
            form.clearErrors();
            clearReceipt();
            purposeChoice.value = TRIP_PURPOSES[0];
            purposeOther.value = '';
            return;
        }

        if (booking) {
            form.boat_id = String(booking.boat.id);
            form.customer_name = booking.customer_name;
            form.contact_number = booking.contact_number ?? '';
            form.package = booking.package;
            form.destination = booking.destination;
            form.pickup_point = booking.pickup_point ?? '';
            form.purpose = booking.purpose ?? '';
            form.booking_date = booking.booking_date;
            form.start_time = booking.start_time;
            form.passenger_count = String(booking.passenger_count);
            form.status = booking.status;
            form.remarks = booking.remarks ?? '';
            form.walk_in_payment_method = 'cash';
            form.gcash_reference = '';
            purposeChoice.value = TRIP_PURPOSES.includes(
                booking.purpose as (typeof TRIP_PURPOSES)[number],
            )
                ? (booking.purpose as string)
                : booking.purpose
                  ? OTHER_PURPOSE
                  : TRIP_PURPOSES[0];
            purposeOther.value =
                purposeChoice.value === OTHER_PURPOSE
                    ? (booking.purpose ?? '')
                    : '';
            form.clearErrors();
            return;
        }

        const today = new Date();
        const month = String(today.getMonth() + 1).padStart(2, '0');
        const day = String(today.getDate()).padStart(2, '0');

        form.booking_date = `${today.getFullYear()}-${month}-${day}`;
        form.start_time = '08:00';
        form.boat_id = props.boats[0] ? String(props.boats[0].id) : '';
        const firstDest =
            props.boats[0]?.rental_destinations?.[0]?.destination ?? '';
        form.destination = firstDest;
        form.package = firstDest;
        form.pickup_point = '';
        form.customer_name = '';
        form.contact_number = '';
        form.passenger_count = '1';
        form.walk_in_payment_method = 'cash';
        form.walk_in_cash_received = '';
        form.gcash_reference = '';
        form.payment_receipt = null;
        clearReceipt();
        form.remarks = '';
        purposeChoice.value = TRIP_PURPOSES[0];
        purposeOther.value = '';
        form.clearErrors();
    },
);

function resolvedPurpose(): string {
    if (showPurposeOther.value) {
        return purposeOther.value.trim();
    }

    return purposeChoice.value;
}

function close() {
    emit('update:open', false);
}

function submit() {
    const options = {
        preserveScroll: true,
        onSuccess: () => close(),
    };

    if (props.booking) {
        form.transform((data) => ({
            ...data,
            purpose: resolvedPurpose() || data.purpose,
        }));
        form.post(`/boat-bookings/${props.booking.id}`, options);
        return;
    }

    form.transform((data) => {
        const { status: _status, ...rest } = data;

        return {
            ...rest,
            walk_in: true,
            purpose: resolvedPurpose(),
            package: rest.destination || rest.package,
        };
    });
    form.post('/boat-bookings', { ...options, forceFormData: true });
}
</script>

<template>
    <Dialog :open="open" @update:open="emit('update:open', $event)">
        <DialogContent
            :show-close-button="false"
            class="flex max-h-[92vh] w-full max-w-[calc(100%-1.5rem)] flex-col gap-0 overflow-hidden rounded-2xl border-none p-0 shadow-2xl sm:max-w-xl"
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
                            {{ isEditing ? 'Edit Rental' : 'Add Rental' }}
                        </DialogTitle>
                        <DialogDescription class="text-sm text-white/80">
                            {{
                                isEditing
                                    ? 'Update this private rental booking.'
                                    : 'Walk-in booking — same trip details as the app, with payment taken at the desk.'
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

            <form class="flex min-h-0 flex-1 flex-col" @submit.prevent="submit">
                <div class="grid gap-3 overflow-y-auto p-5 sm:grid-cols-2">
                    <div class="grid gap-1.5 sm:col-span-2">
                        <Label>Boat</Label>
                        <Select v-model="form.boat_id">
                            <SelectTrigger :class="cn(fieldClass, 'w-full')">
                                <SelectValue placeholder="Select boat" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem
                                    v-for="boat in boats"
                                    :key="boat.id"
                                    :value="String(boat.id)"
                                >
                                    {{ boat.name }} ({{ boat.code }})
                                </SelectItem>
                            </SelectContent>
                        </Select>
                        <InputError :message="form.errors.boat_id" />
                    </div>
                    <div class="grid gap-1.5">
                        <Label for="customer-name">Customer Name</Label>
                        <Input
                            id="customer-name"
                            v-model="form.customer_name"
                            :class="fieldClass"
                            placeholder="Full name"
                        />
                        <InputError :message="form.errors.customer_name" />
                    </div>
                    <div class="grid gap-1.5">
                        <Label for="contact-number">Contact Number</Label>
                        <Input
                            id="contact-number"
                            v-model="form.contact_number"
                            :class="fieldClass"
                            placeholder="09XXXXXXXXX"
                        />
                        <InputError :message="form.errors.contact_number" />
                    </div>
                    <div class="grid gap-1.5 sm:col-span-2">
                        <Label>Destination</Label>
                        <Select
                            v-model="form.destination"
                            :disabled="!rentalDestinations.length"
                        >
                            <SelectTrigger :class="cn(fieldClass, 'w-full')">
                                <SelectValue
                                    :placeholder="
                                        rentalDestinations.length
                                            ? 'Select destination'
                                            : 'No rental routes on this boat'
                                    "
                                />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem
                                    v-for="item in rentalDestinations"
                                    :key="item.id"
                                    :value="item.destination"
                                >
                                    {{ item.destination }}
                                </SelectItem>
                            </SelectContent>
                        </Select>
                        <InputError :message="form.errors.destination" />
                    </div>
                    <div
                        v-if="selectedDestination && !isEditing"
                        class="rounded-xl border border-[#c5def5] bg-[#eaf4fc] p-3 sm:col-span-2 dark:border-white/10 dark:bg-white/5"
                    >
                        <p class="text-xs font-medium text-ink-soft">
                            Rental price (whole boat)
                        </p>
                        <p class="text-lg font-semibold text-ink dark:text-sky-50">
                            {{
                                selectedDestination.rental_price_label ??
                                'Price on file'
                            }}
                        </p>
                        <p
                            v-if="selectedDestination.down_payment_amount_label"
                            class="mt-1 text-sm text-ink-soft"
                        >
                            Down payment due now:
                            {{ selectedDestination.down_payment_amount_label }}
                            <span
                                v-if="selectedDestination.remaining_balance_label"
                                class="text-ink-muted"
                            >
                                · Balance on trip day:
                                {{ selectedDestination.remaining_balance_label }}
                            </span>
                        </p>
                    </div>
                    <div class="grid gap-1.5 sm:col-span-2">
                        <Label for="pickup-point">Pickup / meeting point</Label>
                        <Input
                            id="pickup-point"
                            v-model="form.pickup_point"
                            :class="fieldClass"
                            placeholder="Where guests will meet the boat"
                        />
                        <InputError :message="form.errors.pickup_point" />
                    </div>
                    <div class="grid gap-1.5 sm:col-span-2">
                        <Label>Purpose of the trip</Label>
                        <Select v-model="purposeChoice">
                            <SelectTrigger :class="cn(fieldClass, 'w-full')">
                                <SelectValue placeholder="Select purpose" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem
                                    v-for="item in TRIP_PURPOSES"
                                    :key="item"
                                    :value="item"
                                >
                                    {{ item }}
                                </SelectItem>
                            </SelectContent>
                        </Select>
                        <Input
                            v-if="showPurposeOther"
                            v-model="purposeOther"
                            :class="cn(fieldClass, 'mt-2')"
                            placeholder="Describe the purpose"
                        />
                        <InputError :message="form.errors.purpose" />
                    </div>
                    <div class="grid gap-1.5">
                        <Label for="booking-date">Date</Label>
                        <div class="relative">
                            <Input
                                id="booking-date"
                                v-model="form.booking_date"
                                type="date"
                                :class="cn(fieldClass, 'pr-10')"
                            />
                            <CalendarDays
                                class="pointer-events-none absolute top-1/2 right-3 size-4 -translate-y-1/2 text-[#7da7cc]"
                            />
                        </div>
                        <InputError :message="form.errors.booking_date" />
                    </div>
                    <div class="grid gap-1.5">
                        <Label for="start-time">Start Time</Label>
                        <Input
                            id="start-time"
                            v-model="form.start_time"
                            type="time"
                            :class="fieldClass"
                        />
                        <InputError :message="form.errors.start_time" />
                    </div>
                    <div class="grid gap-1.5">
                        <Label for="booking-passengers">Passengers</Label>
                        <Input
                            id="booking-passengers"
                            v-model="form.passenger_count"
                            type="number"
                            min="1"
                            :max="selectedBoat?.capacity ?? 200"
                            :class="fieldClass"
                        />
                        <p class="text-xs text-ink-muted">
                            {{
                                selectedBoat
                                    ? `Capacity ${selectedBoat.capacity} pax`
                                    : 'Select a boat to see capacity'
                            }}
                        </p>
                        <InputError :message="form.errors.passenger_count" />
                    </div>
                    <div v-if="isEditing" class="grid gap-1.5">
                        <Label>Status</Label>
                        <Select v-model="form.status">
                            <SelectTrigger :class="cn(fieldClass, 'w-full')">
                                <SelectValue placeholder="Select status" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem
                                    v-for="option in statusOptions"
                                    :key="option.value"
                                    :value="option.value"
                                >
                                    {{ option.label }}
                                </SelectItem>
                            </SelectContent>
                        </Select>
                        <InputError :message="form.errors.status" />
                    </div>
                    <div
                        v-if="!isEditing"
                        class="grid gap-3 sm:col-span-2"
                    >
                        <Label>Payment at desk</Label>
                        <div class="grid grid-cols-2 gap-2">
                            <button
                                type="button"
                                :class="
                                    cn(
                                        fieldClass,
                                        'text-sm font-medium transition',
                                        form.walk_in_payment_method === 'cash'
                                            ? 'border-[#1b7ad8] bg-[#dceeff] text-[#1b7ad8]'
                                            : 'text-ink-soft',
                                    )
                                "
                                @click="form.walk_in_payment_method = 'cash'"
                            >
                                Cash
                            </button>
                            <button
                                type="button"
                                :class="
                                    cn(
                                        fieldClass,
                                        'text-sm font-medium transition',
                                        form.walk_in_payment_method === 'gcash'
                                            ? 'border-[#1b7ad8] bg-[#dceeff] text-[#1b7ad8]'
                                            : 'text-ink-soft',
                                    )
                                "
                                @click="form.walk_in_payment_method = 'gcash'"
                            >
                                GCash
                            </button>
                        </div>
                        <InputError
                            :message="form.errors.walk_in_payment_method"
                        />

                        <div
                            v-if="isCashWalkIn"
                            class="space-y-3 rounded-xl border border-[#c5def5] bg-[#f4f9fd] p-4 dark:border-white/10 dark:bg-white/5"
                        >
                            <p class="text-sm font-bold text-[#0f4c8a] dark:text-sky-100">
                                Walk-in Rental – Cash Payment
                            </p>
                            <dl class="space-y-2 text-sm">
                                <div class="flex items-center justify-between gap-3">
                                    <dt class="text-ink-muted">Total Amount Due</dt>
                                    <dd class="font-bold text-ink dark:text-sky-50">
                                        {{ amountDueLabel }}
                                    </dd>
                                </div>
                                <div class="grid gap-1.5">
                                    <Label for="cash-received">Amount Received</Label>
                                    <Input
                                        id="cash-received"
                                        v-model="form.walk_in_cash_received"
                                        type="number"
                                        min="0"
                                        step="0.01"
                                        :class="fieldClass"
                                        placeholder="0.00"
                                    />
                                    <InputError
                                        :message="form.errors.walk_in_cash_received"
                                    />
                                </div>
                                <div class="flex items-center justify-between gap-3 border-t border-[#d7e8f6] pt-2 dark:border-white/10">
                                    <dt class="text-ink-muted">Change</dt>
                                    <dd class="text-lg font-bold text-[#1b7ad8]">
                                        {{ formatPeso(changeAmount) }}
                                    </dd>
                                </div>
                            </dl>
                            <div
                                v-if="walkInCommission?.enabled"
                                class="rounded-lg border border-[#d7e8f6] bg-white p-3 dark:border-white/10 dark:bg-background"
                            >
                                <p class="text-xs font-semibold text-ink-soft">
                                    Commission
                                    <span class="font-normal text-ink-muted">
                                        (recorded separately — not added to what the passenger pays)
                                    </span>
                                </p>
                                <div class="mt-2 flex justify-between text-sm">
                                    <span class="text-ink-muted">Commission Rate</span>
                                    <span class="font-semibold text-ink">{{
                                        walkInCommission?.percentage_label
                                    }}</span>
                                </div>
                                <div class="mt-1 flex justify-between text-sm">
                                    <span class="text-ink-muted">Commission Amount</span>
                                    <span class="font-bold text-ink">{{
                                        commissionAmountLabel
                                    }}</span>
                                </div>
                            </div>
                            <p
                                class="rounded-lg bg-emerald-50 px-3 py-2 text-xs font-semibold text-emerald-800"
                            >
                                Payment status: Cash Payment Recorded
                            </p>
                        </div>

                        <div
                            v-if="isGcashWalkIn"
                            class="space-y-3 rounded-xl border border-[#c5def5] bg-[#f4f9fd] p-4 dark:border-white/10 dark:bg-white/5"
                        >
                            <p class="text-sm font-bold text-[#0f4c8a] dark:text-sky-100">
                                Walk-in Rental – GCash Payment
                            </p>
                            <dl class="space-y-2 text-sm">
                                <div class="flex items-center justify-between gap-3">
                                    <dt class="text-ink-muted">Total Amount Due</dt>
                                    <dd class="font-bold text-ink dark:text-sky-50">
                                        {{ amountDueLabel }}
                                    </dd>
                                </div>
                                <div class="grid gap-1.5">
                                    <Label for="gcash-ref">GCash Reference Number</Label>
                                    <Input
                                        id="gcash-ref"
                                        v-model="form.gcash_reference"
                                        :class="fieldClass"
                                        placeholder="Enter transaction reference"
                                    />
                                    <InputError :message="form.errors.gcash_reference" />
                                </div>
                                <div class="grid gap-1.5">
                                    <Label for="gcash-receipt">Payment Proof</Label>
                                    <label
                                        class="flex cursor-pointer flex-col items-center justify-center gap-2 rounded-xl border border-dashed border-[#1b7ad8] bg-white px-4 py-5 text-center dark:bg-background"
                                    >
                                        <CloudUpload class="size-8 text-[#1b7ad8]" />
                                        <span class="text-sm font-semibold text-[#1b7ad8]">
                                            Upload GCash Receipt
                                        </span>
                                        <span class="text-xs text-ink-muted">Required</span>
                                        <input
                                            id="gcash-receipt"
                                            ref="receiptInput"
                                            type="file"
                                            accept="image/jpeg,image/png,image/webp,application/pdf"
                                            class="sr-only"
                                            @change="onReceiptSelected"
                                        />
                                    </label>
                                    <p
                                        v-if="receiptFileName"
                                        class="text-xs font-medium text-ink"
                                    >
                                        {{ receiptFileName }}
                                    </p>
                                    <InputError :message="form.errors.payment_receipt" />
                                </div>
                            </dl>
                            <div
                                v-if="walkInCommission?.enabled"
                                class="flex justify-between text-sm"
                            >
                                <span class="text-ink-muted">Commission</span>
                                <span class="font-bold text-ink">{{
                                    commissionAmountLabel
                                }}</span>
                            </div>
                            <p
                                class="rounded-lg bg-amber-50 px-3 py-2 text-xs font-semibold text-amber-900"
                            >
                                Payment status: Awaiting Payment Verification
                            </p>
                            <p class="text-xs text-ink-muted">
                                Verify the reference number and receipt in Rental Requests before
                                marking the payment as verified.
                            </p>
                        </div>
                    </div>
                    <div class="grid gap-1.5 sm:col-span-2">
                        <Label for="booking-remarks">Additional notes (optional)</Label>
                        <textarea
                            id="booking-remarks"
                            v-model="form.remarks"
                            rows="3"
                            :class="cn(fieldClass, 'h-auto min-h-20 py-2')"
                            placeholder="Special requests or internal notes"
                        />
                    </div>
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
                        Cancel
                    </Button>
                    <Button
                        type="submit"
                        class="h-10 rounded-xl bg-[#1b7ad8] px-5 text-white hover:bg-[#1567c4]"
                        :disabled="
                            form.processing ||
                            (!isEditing && !rentalDestinations.length)
                        "
                    >
                        <Spinner v-if="form.processing" class="size-4" />
                        <Save v-else class="size-4" />
                        {{ isEditing ? 'Save Changes' : 'Save walk-in booking' }}
                    </Button>
                </div>
            </form>
        </DialogContent>
    </Dialog>
</template>
