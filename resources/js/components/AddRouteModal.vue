<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { Save, X } from '@lucide/vue';
import { computed, watch } from 'vue';
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
import type { OrganizationRouteRecord } from '@/types/route';

const props = defineProps<{
    open: boolean;
    route?: OrganizationRouteRecord | null;
    homePort?: string;
}>();

const emit = defineEmits<{
    'update:open': [value: boolean];
}>();

const fieldClass =
    'h-10 w-full rounded-xl border border-[#c5def5] bg-[#f7fbfe] text-ink shadow-none focus-visible:border-[#1b7ad8] focus-visible:ring-[#1b7ad8]/20 dark:border-white/10 dark:bg-white/5 dark:text-sky-50';

const form = useForm({
    origin: '',
    destination: '',
    trip_type: 'regular_public',
    rental_price: '',
    regular_fare: '',
    student_discount_percent: '20',
    senior_discount_percent: '20',
    down_payment_percent: '',
    is_active: '1',
});

const isEditing = computed(() => Boolean(props.route));
const isExclusiveRental = computed(
    () => form.trip_type === 'private_booking',
);

function discountedFare(percentValue: string) {
    const regular = Number(form.regular_fare);
    const percent = Number(percentValue);

    if (!form.regular_fare || Number.isNaN(regular) || regular < 0) {
        return 'Set regular fare first';
    }

    const rate = Number.isNaN(percent) ? 0 : Math.min(100, Math.max(0, percent));
    const amount = regular * ((100 - rate) / 100);

    return `₱${amount.toLocaleString('en-PH', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    })}`;
}

const studentFareLabel = computed(() =>
    discountedFare(form.student_discount_percent),
);

const seniorFareLabel = computed(() =>
    discountedFare(form.senior_discount_percent),
);

const routeName = computed(() => {
    const origin = form.origin.trim();
    const destination = form.destination.trim();

    if (!origin || !destination) {
        return '';
    }

    return `${origin} ↔ ${destination}`;
});

watch(
    () => [props.open, props.route] as const,
    ([open, route]) => {
        if (!open) {
            form.reset();
            form.clearErrors();
            return;
        }

        if (route) {
            form.origin = route.origin;
            form.destination = route.destination;
            form.trip_type = route.trip_type;
            form.rental_price =
                route.trip_type === 'private_booking' && route.rental_price != null
                    ? Number(route.rental_price).toFixed(2)
                    : '';
            form.down_payment_percent =
                route.trip_type === 'private_booking' &&
                route.down_payment_percent != null
                    ? Number(route.down_payment_percent).toFixed(2)
                    : '';
            form.regular_fare =
                route.trip_type === 'regular_public' && route.regular_fare != null
                    ? Number(route.regular_fare).toFixed(2)
                    : '';
            form.student_discount_percent =
                route.trip_type === 'regular_public'
                    ? String(route.student_discount_percent ?? 20)
                    : '20';
            form.senior_discount_percent =
                route.trip_type === 'regular_public'
                    ? String(route.senior_discount_percent ?? 20)
                    : '20';
            form.is_active = route.is_active ? '1' : '0';
            form.clearErrors();
            return;
        }

        form.origin = props.homePort?.trim() || '';
        form.destination = '';
        form.trip_type = 'regular_public';
        form.rental_price = '';
        form.regular_fare = '';
        form.student_discount_percent = '20';
        form.senior_discount_percent = '20';
        form.down_payment_percent = '';
        form.is_active = '1';
        form.clearErrors();
    },
);

watch(
    () => form.trip_type,
    () => {
        if (!props.open) {
            return;
        }

        if (!isExclusiveRental.value) {
            form.rental_price = '';
            form.down_payment_percent = '';
        } else {
            form.regular_fare = '';
            form.student_discount_percent = '20';
            form.senior_discount_percent = '20';
        }
    },
);

function close() {
    emit('update:open', false);
}

function submit() {
    const options = {
        preserveScroll: true,
        onSuccess: () => close(),
    };

    if (props.route) {
        form.post(`/routes/${props.route.id}`, options);
        return;
    }

    form.post('/routes', options);
}
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
                            {{ isEditing ? 'Edit Route' : 'Add Route' }}
                        </DialogTitle>
                        <DialogDescription class="text-sm text-white/80">
                            Configure a route for this organization only.
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
                <div class="grid gap-3 overflow-y-auto p-5">
                    <div class="grid gap-1.5">
                        <Label>Route Name</Label>
                        <Input
                            :model-value="routeName || 'Enter destination'"
                            readonly
                            tabindex="-1"
                            :class="cn(fieldClass, 'bg-[#eef6fc] text-ink-soft dark:bg-[#0b1b30] dark:text-slate-100')"
                        />
                    </div>

                    <div class="grid gap-1.5">
                        <Label>Route Type</Label>
                        <Select v-model="form.trip_type">
                            <SelectTrigger :class="cn(fieldClass, 'w-full')">
                                <SelectValue placeholder="Select route type" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="regular_public">
                                    Regular Trip
                                </SelectItem>
                                <SelectItem value="private_booking">
                                    Exclusive Boat Rental
                                </SelectItem>
                            </SelectContent>
                        </Select>
                        <InputError :message="form.errors.trip_type" />
                    </div>

                    <div class="grid gap-3 sm:grid-cols-2">
                        <div class="grid gap-1.5">
                            <Label for="route-origin">Origin</Label>
                            <Input
                                id="route-origin"
                                :model-value="form.origin"
                                type="text"
                                required
                                readonly
                                tabindex="-1"
                                placeholder="Home port"
                                :class="cn(fieldClass, 'bg-[#eef6fc] text-ink-soft dark:bg-[#0b1b30] dark:text-slate-100')"
                            />
                            <InputError :message="form.errors.origin" />
                        </div>
                        <div class="grid gap-1.5">
                            <Label for="route-destination">Destination</Label>
                            <Input
                                id="route-destination"
                                v-model="form.destination"
                                type="text"
                                required
                                placeholder="Enter destination"
                                :class="fieldClass"
                            />
                            <InputError :message="form.errors.destination" />
                        </div>
                    </div>

                    <div v-if="!isExclusiveRental" class="grid gap-3">
                        <div class="grid gap-1.5">
                            <Label for="route-regular-fare">Regular Fare (₱)</Label>
                            <Input
                                id="route-regular-fare"
                                v-model="form.regular_fare"
                                type="number"
                                inputmode="decimal"
                                min="0"
                                step="0.01"
                                placeholder="50.00"
                                :class="fieldClass"
                            />
                            <p class="text-xs text-ink-muted">
                                Full public fare. Student and Senior amounts follow the discounts you set below.
                            </p>
                            <InputError :message="form.errors.regular_fare" />
                        </div>
                        <div class="grid gap-3 sm:grid-cols-2">
                            <div class="grid gap-1.5">
                                <Label for="route-student-discount">Student Discount (%)</Label>
                                <Input
                                    id="route-student-discount"
                                    v-model="form.student_discount_percent"
                                    type="number"
                                    inputmode="decimal"
                                    min="0"
                                    max="100"
                                    step="0.01"
                                    :class="fieldClass"
                                />
                                <p class="text-xs font-semibold text-[#0d9b8a]">
                                    Pays {{ studentFareLabel }}
                                </p>
                                <InputError :message="form.errors.student_discount_percent" />
                            </div>
                            <div class="grid gap-1.5">
                                <Label for="route-senior-discount">Senior Discount (%)</Label>
                                <Input
                                    id="route-senior-discount"
                                    v-model="form.senior_discount_percent"
                                    type="number"
                                    inputmode="decimal"
                                    min="0"
                                    max="100"
                                    step="0.01"
                                    :class="fieldClass"
                                />
                                <p class="text-xs font-semibold text-[#d4922a]">
                                    Pays {{ seniorFareLabel }}
                                </p>
                                <InputError :message="form.errors.senior_discount_percent" />
                            </div>
                        </div>
                    </div>

                    <div v-if="isExclusiveRental" class="grid gap-1.5">
                        <Label for="route-rental-price">Rental Price (₱)</Label>
                        <Input
                            id="route-rental-price"
                            v-model="form.rental_price"
                            type="number"
                            inputmode="decimal"
                            min="0"
                            step="0.01"
                            required
                            placeholder="1500.00"
                            :class="fieldClass"
                        />
                        <p class="text-xs text-ink-muted">
                            Approved fare for this destination. All assigned boats use this price.
                        </p>
                        <InputError :message="form.errors.rental_price" />
                    </div>

                    <div v-if="isExclusiveRental" class="grid gap-1.5">
                        <Label for="route-down-payment">Required Down Payment (%)</Label>
                        <Input
                            id="route-down-payment"
                            v-model="form.down_payment_percent"
                            type="number"
                            inputmode="decimal"
                            min="0"
                            max="100"
                            step="0.01"
                            required
                            placeholder="30"
                            :class="fieldClass"
                        />
                        <p class="text-xs text-ink-muted">
                            Percent of the approved rental price collected as down payment.
                        </p>
                        <InputError :message="form.errors.down_payment_percent" />
                    </div>

                    <div class="grid gap-1.5">
                        <Label>Status</Label>
                        <Select v-model="form.is_active">
                            <SelectTrigger :class="cn(fieldClass, 'w-full')">
                                <SelectValue placeholder="Select status" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="1">Active</SelectItem>
                                <SelectItem value="0">Inactive</SelectItem>
                            </SelectContent>
                        </Select>
                        <InputError :message="form.errors.is_active" />
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
                        :disabled="form.processing"
                    >
                        <Spinner v-if="form.processing" class="size-4" />
                        <Save v-else class="size-4" />
                        Save Route
                    </Button>
                </div>
            </form>
        </DialogContent>
    </Dialog>
</template>
