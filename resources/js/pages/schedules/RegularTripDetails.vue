
<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import {
    AlertTriangle,
    ArrowLeft,
    ArrowRight,
    CalendarDays,
    Clock3,
    Coins,
    Pencil,
    Plus,
    Ship,
    Trash2,
    Users,
    Wallet,
} from '@lucide/vue';
import { computed, onMounted, ref } from 'vue';

import AddTripPassengerModal from '@/components/AddTripPassengerModal.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogTitle,
} from '@/components/ui/dialog';
import { Spinner } from '@/components/ui/spinner';

import type {
    RegularTripDetailsRecord,
    RegularTripHistoryItem,
    RegularTripStatus,
    RegularTripStatusOption,
    TripPassengerRecord,
} from '@/types/schedule';

// ==========================================
// PROPS
// ==========================================

const props = defineProps<{
    trip: RegularTripDetailsRecord;
    statusOptions: RegularTripStatusOption[];
    tripHistory?: RegularTripHistoryItem[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Regular Trip Rotation',
                href: '/schedules/regular-trip-rotation',
            },
            {
                title: 'Trip Details',
                href: '/schedules/regular-trip-rotation',
            },
        ],
    },
});

// ==========================================
// STATE
// ==========================================

const passengerFormOpen = ref(false);
const editingPassenger = ref<TripPassengerRecord | null>(null);
const deletingPassenger = ref<TripPassengerRecord | null>(null);

const statusProcessing = ref(false);
const deleteProcessing = ref(false);
const completeOpen = ref(false);
const departOpen = ref(false);
const endDutyOpen = ref(false);
const returnBoardingOpen = ref(false);

const boarding = computed(() => props.trip.boarding);

onMounted(() => {
    const params = new URLSearchParams(window.location.search);

    if (params.get('encode') === '1') {
        openAddPassenger();
    }

    if (params.get('duty') === '1') {
        completeOpen.value = true;
    }
});

// ==========================================
// PASSENGER INFORMATION
// ==========================================

const capacity = computed(() =>
    Number(props.trip.boat.capacity || 0),
);

const passengerCount = computed(() =>
    Array.isArray(props.trip.passengers)
        ? props.trip.passengers.length
        : Number(props.trip.passenger_count || 0),
);

const remainingSeats = computed(() =>
    Math.max(0, capacity.value - passengerCount.value),
);

const passengerPercent = computed(() => {
    if (capacity.value <= 0) {
        return 0;
    }

    return Math.min(
        100,
        Math.round((passengerCount.value / capacity.value) * 100),
    );
});

const nearCapacity = computed(() => {
    if (
        capacity.value <= 0 ||
        passengerCount.value >= capacity.value
    ) {
        return false;
    }

    return (
        remainingSeats.value <= 2 ||
        passengerPercent.value >= 80
    );
});

const canAddPassenger = computed(
    () =>
        Boolean(props.trip.can_encode_passengers) &&
        !props.trip.at_capacity &&
        !['cancelled', 'completed', 'off_duty'].includes(
            props.trip.status,
        ),
);

const canManagePassengers = computed(
    () =>
        Boolean(props.trip.can_encode_passengers) &&
        !['cancelled', 'completed', 'off_duty'].includes(
            props.trip.status,
        ),
);

// ==========================================
// STATUS STYLES
// ==========================================

function statusTone(status: RegularTripStatus) {
    const styles: Record<string, string> = {
        scheduled:
            'border-sky-200 bg-sky-50 text-sky-700',
        on_trip:
            'border-violet-200 bg-violet-50 text-violet-700',
        delayed:
            'border-amber-200 bg-amber-50 text-amber-700',
        completed:
            'border-emerald-200 bg-emerald-50 text-emerald-700',
        cancelled:
            'border-rose-200 bg-rose-50 text-rose-700',
        off_duty:
            'border-slate-200 bg-slate-50 text-slate-600',
    };

    return styles[status] ?? styles.scheduled;
}

function statusDisplay(status: RegularTripStatus, label?: string) {
    if (status === 'on_trip') {
        return 'ON TRIP';
    }

    return label || props.trip.status_label;
}

const returnNotStarted = computed(
    () =>
        props.trip.is_outbound &&
        props.trip.status === 'on_trip' &&
        !props.trip.paired_return?.started,
);

// ==========================================
// CURRENT TRIP INFORMATION
// ==========================================

const currentDirection = computed(() =>
    props.trip.is_outbound ? 'Going Trip' : 'Return Trip',
);

const currentRoute = computed(() =>
    props.trip.direction || props.trip.route,
);

const currentTripNumber = computed(() =>
    props.trip.trip_number_label || 'Trip 1',
);

const boatTrips = computed(() => props.tripHistory ?? []);

const todayRevenueLabel = computed(() => {
    const total = boatTrips.value.reduce((sum, item) => sum + Number(item.revenue || 0), 0);

    return new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP' }).format(total);
});

function recordedTime(label?: string | null) {
    if (!label || label === 'Not Recorded' || label === '—') {
        return 'Not recorded';
    }

    return label;
}

const isEditableTrip = computed(() =>
    props.trip.status !== 'completed' &&
    props.trip.status !== 'cancelled' &&
    props.trip.status !== 'off_duty',
);

// ==========================================
// PASSENGER MODAL ACTIONS
// ==========================================

function openAddPassenger() {
    if (!canAddPassenger.value) {
        return;
    }

    editingPassenger.value = null;
    passengerFormOpen.value = true;
}

function openEditPassenger(passenger: TripPassengerRecord) {
    if (!canManagePassengers.value) {
        return;
    }

    editingPassenger.value = passenger;
    passengerFormOpen.value = true;
}

function onPassengerFormOpenChange(open: boolean) {
    passengerFormOpen.value = open;

    if (!open) {
        editingPassenger.value = null;
    }
}

// ==========================================
// TRIP STATUS ACTIONS
// ==========================================

function updateStatus(
    status: RegularTripStatus,
    extra: Record<string, boolean | string> = {},
) {
    if (statusProcessing.value) {
        return;
    }

    statusProcessing.value = true;

    router.patch(
        `/regular-trips/${props.trip.id}/status`,
        {
            status,
            ...extra,
        },
        {
            preserveScroll: true,
            onFinish: () => {
                statusProcessing.value = false;
                completeOpen.value = false;
                departOpen.value = false;
                endDutyOpen.value = false;
                returnBoardingOpen.value = false;
            },
        },
    );
}

// ==========================================
// DELETE PASSENGER
// ==========================================

function confirmDelete(passenger: TripPassengerRecord) {
    if (!canManagePassengers.value) {
        return;
    }

    deletingPassenger.value = passenger;
}

function cancelDelete() {
    if (deleteProcessing.value) {
        return;
    }

    deletingPassenger.value = null;
}

function deletePassenger() {
    if (
        !deletingPassenger.value ||
        deleteProcessing.value ||
        !canManagePassengers.value
    ) {
        return;
    }

    deleteProcessing.value = true;

    router.delete(
        `/regular-trips/${props.trip.id}/passengers/${deletingPassenger.value.id}`,
        {
            preserveScroll: true,
            onFinish: () => {
                deleteProcessing.value = false;
                deletingPassenger.value = null;
            },
        },
    );
}
</script>

<template>
    <Head :title="`Trip Details · ${trip.boat.name}`" />

    <div class="flex flex-1 flex-col gap-5 p-4 md:p-6">

        <!-- ================================= -->
        <!-- BACK NAVIGATION -->
        <!-- ================================= -->

        <Link
            href="/schedules/regular-trip-rotation"
            class="inline-flex w-fit items-center gap-2 text-sm font-medium text-[#1b7ad8] hover:underline"
        >
            <ArrowLeft class="size-4" />
            Back to Regular Trip Rotation
        </Link>

        <!-- ================================= -->
        <!-- PAGE HEADER -->
        <!-- ================================= -->

        <header
            class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between"
        >
            <div>
                <h1
                    class="text-2xl font-bold tracking-tight text-[#0f4c8a] dark:text-sky-100"
                >
                    Trip Details
                </h1>

                <p class="mt-1 text-sm text-ink-muted">
                    Trip Reference:
                    {{ trip.reference_number || currentTripNumber }}
                    · Trip Date:
                    {{ trip.trip_date_label }}
                </p>
            </div>

            <span
                class="inline-flex w-fit rounded-full border px-3 py-1.5 text-xs font-semibold uppercase tracking-wide"
                :class="statusTone(trip.status)"
            >
                {{ statusDisplay(trip.status, trip.status_label) }}
            </span>
        </header>

        <section
            class="relative overflow-hidden rounded-2xl border border-emerald-100 bg-[#E7F8EE] p-4 shadow-sm dark:border-emerald-500/20 dark:bg-emerald-500/15"
        >
            <div class="flex items-center gap-3">
                <div class="flex size-11 items-center justify-center rounded-xl bg-[#16A34A] text-white">
                    <Coins class="size-5" />
                </div>
                <div>
                    <p class="text-sm font-semibold text-ink dark:text-slate-200">
                        Total Revenue Today
                    </p>
                    <p class="mt-0.5 text-2xl font-bold text-[#0F2854] dark:text-white">
                        {{ todayRevenueLabel }}
                    </p>
                    <p class="mt-0.5 text-xs text-ink-soft dark:text-slate-400">
                        Fare collected from this boat's regular trips today
                    </p>
                </div>
            </div>
        </section>

        <!-- ================================= -->
        <!-- COMPACT TRIP INFORMATION -->
        <!-- ================================= -->

        <section
            class="overflow-hidden rounded-2xl border border-[#d7e8f6] bg-white shadow-sm dark:border-white/10 dark:bg-white/5"
        >
            <div
                class="flex items-center justify-between gap-4 border-b border-[#e8f1f8] p-4 dark:border-white/10"
            >
                <div class="flex items-center gap-3">
                    <div
                        class="flex size-12 shrink-0 items-center justify-center overflow-hidden rounded-xl bg-[#d7ebfa]"
                    >
                        <img
                            v-if="trip.boat.picture_url"
                            :src="trip.boat.picture_url"
                            :alt="trip.boat.name"
                            class="h-full w-full object-cover"
                        />

                        <Ship
                            v-else
                            class="size-5 text-[#1b7ad8]"
                        />
                    </div>

                    <div>
                        <h2
                            class="font-semibold text-[#0f4c8a] dark:text-sky-100"
                        >
                            {{ trip.boat.name }}
                        </h2>

                        <p class="text-xs text-ink-muted">
                            {{ trip.boat.code }}
                        </p>
                        <p class="mt-0.5 text-xs text-ink-muted">
                            Operator:
                            {{ trip.boat.operator_name || 'Unassigned' }}
                        </p>
                    </div>
                </div>

                <span
                    class="rounded-full bg-[#e8f4fc] px-3 py-1 text-xs font-semibold text-[#1b7ad8]"
                >
                    {{ passengerCount }} / {{ capacity }}
                    passengers
                </span>
            </div>

            <dl
                class="grid gap-4 p-4 sm:grid-cols-2 lg:grid-cols-3"
            >
                <div>
                    <dt class="text-xs text-ink-muted">
                        Route
                    </dt>
                    <dd class="mt-1 font-semibold text-[#0f4c8a] dark:text-sky-100">
                        {{ currentRoute }}
                    </dd>
                </div>
                <div>
                    <dt class="flex items-center gap-1.5 text-xs text-ink-muted">
                        <CalendarDays class="size-3.5" />
                        Trip Date
                    </dt>
                    <dd class="mt-1 font-semibold text-[#0f4c8a] dark:text-sky-100">
                        {{ trip.trip_date_label }}
                    </dd>
                </div>
                <div v-if="trip.is_outbound">
                    <dt class="flex items-center gap-1.5 text-xs text-ink-muted">
                        <Clock3 class="size-3.5" />
                        Boarding Time
                    </dt>
                    <dd class="mt-1 font-medium text-[#0f4c8a] dark:text-sky-100">
                        {{ trip.boarding?.started_at_label || 'Not started' }}
                    </dd>
                </div>
                <div v-if="trip.is_outbound">
                    <dt class="text-xs text-ink-muted">
                        Departure Time
                    </dt>
                    <dd class="mt-1 font-medium text-[#0f4c8a] dark:text-sky-100">
                        {{
                            trip.actual_departure_label &&
                            trip.actual_departure_label !== 'Not Recorded'
                                ? trip.actual_departure_label
                                : 'Not recorded'
                        }}
                    </dd>
                </div>
                <div v-else>
                    <dt class="flex items-center gap-1.5 text-xs text-ink-muted">
                        <Clock3 class="size-3.5" />
                        Arrived at origin
                    </dt>
                    <dd class="mt-1 font-medium text-[#0f4c8a] dark:text-sky-100">
                        {{
                            trip.arrived_at_label &&
                            trip.arrived_at_label !== 'Not Recorded'
                                ? trip.arrived_at_label
                                : 'Not recorded'
                        }}
                    </dd>
                </div>
                <div>
                    <dt class="flex items-center gap-1.5 text-xs text-ink-muted">
                        <Users class="size-3.5" />
                        Passenger Count
                    </dt>
                    <dd class="mt-1 font-semibold text-[#0f4c8a] dark:text-sky-100">
                        {{ passengerCount }} / {{ capacity }}
                    </dd>
                </div>
            </dl>

            <!-- ================================= -->
            <!-- TRIP ACTIONS -->
            <!-- ================================= -->

            <div
                v-if="
                    isEditableTrip &&
                    (
                        trip.can_depart ||
                        trip.can_arrive_origin ||
                        trip.can_complete_return ||
                        trip.can_set_off_duty ||
                        trip.can_set_on_duty
                    )
                "
                class="flex flex-wrap items-center gap-2 border-t border-[#e8f1f8] px-4 py-3 dark:border-white/10"
            >
                <span
                    class="mr-2 text-xs font-semibold text-ink-muted"
                >
                    Trip Actions:
                </span>

                <Button
                    v-if="trip.can_depart"
                    type="button"
                    class="rounded-lg bg-emerald-600 text-white hover:bg-emerald-700"
                    :disabled="statusProcessing"
                    @click="departOpen = true"
                >
                    Depart
                </Button>

                <Button
                    v-if="trip.can_arrive_origin"
                    type="button"
                    class="rounded-lg bg-violet-600 text-white hover:bg-violet-700"
                    :disabled="statusProcessing"
                    @click="returnBoardingOpen = true"
                >
                    Arrive at the origin
                </Button>

                <Button
                    v-if="trip.can_complete_return"
                    type="button"
                    class="rounded-lg bg-emerald-600 text-white hover:bg-emerald-700"
                    :disabled="statusProcessing"
                    @click="completeOpen = true"
                >
                    Done
                </Button>

                <Button
                    v-if="trip.can_set_off_duty"
                    type="button"
                    variant="outline"
                    class="rounded-lg"
                    :disabled="statusProcessing"
                    @click="endDutyOpen = true"
                >
                    End Duty
                </Button>

                <Button
                    v-if="trip.can_set_on_duty"
                    type="button"
                    variant="outline"
                    class="rounded-lg"
                    :disabled="statusProcessing"
                    @click="updateStatus('scheduled')"
                >
                    Return to Rotation
                </Button>
            </div>

            <!-- GOING / RETURN PASSENGER MANIFEST -->
            <div
                class="flex flex-col gap-3 border-t border-[#e8f1f8] p-4 sm:flex-row sm:items-center sm:justify-between dark:border-white/10"
            >
                <div>
                    <h2
                        class="flex items-center gap-2 font-semibold text-[#0f4c8a] dark:text-sky-100"
                    >
                        <ArrowRight
                            v-if="trip.is_outbound"
                            class="size-4 text-[#1b7ad8]"
                        />

                        <ArrowLeft
                            v-else
                            class="size-4 text-[#1b7ad8]"
                        />

                        {{
                            trip.is_outbound
                                ? 'Going Trip — Passenger Manifest'
                                : 'Return Trip — Passenger Manifest'
                        }}
                    </h2>
                </div>

                <Button
                    v-if="canAddPassenger"
                    type="button"
                    class="size-10 rounded-xl bg-[#1b7ad8] p-0 text-white hover:bg-[#1567c4]"
                    aria-label="Add Passenger"
                    @click="openAddPassenger"
                >
                    <Plus class="size-5" />
                </Button>

                <span
                    v-else
                    class="rounded-full bg-[#f1f5f9] px-3 py-1.5 text-xs font-medium text-slate-600"
                >
                    {{
                        trip.status === 'completed'
                            ? 'Completed Manifest'
                            : trip.at_capacity
                              ? 'Boat Full'
                              : 'View Only'
                    }}
                </span>
            </div>

            <!-- PASSENGER CAPACITY WARNING -->

                <div
                    v-if="canAddPassenger && trip.at_capacity"
                class="mx-4 mt-4 flex items-start gap-2 rounded-xl bg-rose-50 p-3 text-xs text-rose-700"
            >
                <AlertTriangle class="size-4 shrink-0" />
                Boat capacity reached.
                No more passengers can be added.
                </div>

                <div
                    v-else-if="canAddPassenger && nearCapacity"
                class="mx-4 mt-4 flex items-start gap-2 rounded-xl bg-amber-50 p-3 text-xs text-amber-800"
            >
                <AlertTriangle class="size-4 shrink-0" />
                Almost full.
                {{ remainingSeats }} seats remaining.
            </div>

            <!-- EMPTY MANIFEST -->

            <div
                v-if="trip.passengers.length === 0"
                class="px-5 py-12 text-center"
            >
                <Users
                    class="mx-auto size-10 text-[#c5def5]"
                />

                <p
                    class="mt-3 font-medium text-[#0f4c8a] dark:text-sky-100"
                >
                    No passengers recorded
                </p>

                <p class="mt-1 text-sm text-ink-muted">
                    {{
                        canAddPassenger
                            ? 'Use the + button to record passengers for this trip.'
                            : 'This trip has no saved passenger records.'
                    }}
                </p>
            </div>

            <!-- PASSENGER MANIFEST TABLE -->

            <div v-else class="overflow-x-auto">
                <table
                    class="w-full min-w-[680px] text-left text-sm"
                >
                    <thead
                        class="bg-[#f4f9fd] text-xs uppercase tracking-wide text-ink-soft dark:bg-white/5"
                    >
                        <tr>
                            <th class="px-4 py-3">
                                Passenger No.
                            </th>

                            <th class="px-4 py-3">
                                Passenger Name
                            </th>

                            <th class="px-4 py-3">
                                Age
                            </th>

                            <th class="px-4 py-3">
                                Gender
                            </th>

                            <th class="px-4 py-3">
                                Fare Type
                            </th>

                            <th class="px-4 py-3">
                                Fare Amount
                            </th>

                            <th
                                v-if="canManagePassengers"
                                class="px-4 py-3 text-right"
                            >
                                Actions
                            </th>
                        </tr>
                    </thead>

                    <tbody>
                        <tr
                            v-for="(passenger, index) in trip.passengers"
                            :key="passenger.id"
                            class="border-t border-[#eef4f9] dark:border-white/10"
                        >
                            <td
                                class="px-4 py-3 text-ink-muted"
                            >
                                {{ index + 1 }}
                            </td>

                            <td
                                class="px-4 py-3 font-semibold text-[#0f4c8a] dark:text-sky-100"
                            >
                                {{ passenger.full_name }}
                            </td>

                            <td
                                class="px-4 py-3 text-ink dark:text-slate-200"
                            >
                                {{ passenger.age }}
                            </td>

                            <td
                                class="px-4 py-3 text-ink dark:text-slate-200"
                            >
                                {{ passenger.gender || '—' }}
                            </td>

                            <td
                                class="px-4 py-3 text-ink dark:text-slate-200"
                            >
                                {{
                                    passenger.fare_type_label ||
                                    'Regular'
                                }}
                            </td>

                            <td
                                class="px-4 py-3 font-medium text-[#0f4c8a] dark:text-sky-100"
                            >
                                {{ passenger.fare_amount_label || '—' }}
                            </td>

                            <td
                                class="px-4 py-3 text-ink dark:text-slate-200"
                            >
                                {{
                                    passenger.contact_number || '—'
                                }}
                            </td>

                            <td
                                v-if="canManagePassengers"
                                class="px-4 py-3"
                            >
                                <div
                                    class="flex justify-end gap-2"
                                >
                                    <button
                                        type="button"
                                        class="inline-flex h-8 items-center gap-1 rounded-lg border border-[#c5def5] px-3 text-xs font-medium text-[#1b7ad8]"
                                        @click="
                                            openEditPassenger(passenger)
                                        "
                                    >
                                        <Pencil class="size-3.5" />
                                        Edit
                                    </button>

                                    <button
                                        type="button"
                                        class="inline-flex h-8 items-center gap-1 rounded-lg border border-rose-200 px-3 text-xs font-medium text-rose-600"
                                        @click="
                                            confirmDelete(passenger)
                                        "
                                    >
                                        <Trash2 class="size-3.5" />
                                        Delete
                                    </button>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div
                class="border-t border-[#e8f1f8] bg-[#f7fbfe] px-4 py-3 text-sm text-ink-soft dark:border-white/10"
            >
                {{ passengerCount }}
                {{ passengerCount === 1 ? 'Passenger' : 'Passengers' }}
                Recorded
            </div>
        </section>

        <!-- ================================= -->
        <!-- RETURN TRIP STATUS -->
        <!-- ================================= -->

        <section
            v-if="returnNotStarted"
            class="rounded-2xl border border-[#d7e8f6] bg-[#f7fbfe] px-4 py-4 dark:border-white/10 dark:bg-white/5"
        >
            <h2 class="text-sm font-semibold text-[#0f4c8a] dark:text-sky-100">
                Return Trip
            </h2>
            <p class="mt-1 text-sm font-semibold text-amber-800">
                Not Started
            </p>
            <p class="mt-1 text-xs text-ink-muted">
                The home passenger list opens when the boat arrives at the origin.
            </p>
        </section>

        <section
            v-if="boatTrips.length > 0"
            class="overflow-hidden rounded-2xl border border-[#d7e8f6] bg-white shadow-sm dark:border-white/10 dark:bg-white/5"
        >
            <div class="border-b border-[#e8f1f8] px-4 py-3 dark:border-white/10">
                <div>
                    <h2 class="font-semibold text-[#0f4c8a] dark:text-sky-100">
                        Trips today · {{ trip.boat.name }}
                    </h2>
                    <p class="mt-1 text-xs text-ink-muted">
                        Revenue is the fare from the passengers recorded on each trip.
                    </p>
                </div>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full min-w-[640px] text-left text-sm">
                    <thead class="bg-[#f4f9fd] text-xs uppercase tracking-wide text-ink-soft dark:bg-white/5">
                        <tr>
                            <th class="px-4 py-3">Trip</th>
                            <th class="px-4 py-3">Boarding</th>
                            <th class="px-4 py-3">Departed</th>
                            <th class="px-4 py-3">Passengers</th>
                            <th class="px-4 py-3">Revenue</th>
                            <th class="px-4 py-3 text-right">Details</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="item in boatTrips"
                            :key="item.id"
                            class="border-t border-[#eef4f9] dark:border-white/10"
                        >
                            <td class="px-4 py-3 font-semibold text-[#0f4c8a] dark:text-sky-100">
                                Trip {{ item.trip_number }}
                            </td>
                            <td class="px-4 py-3">
                                {{ recordedTime(item.boarding_started_at_label) }}
                            </td>
                            <td class="px-4 py-3">
                                {{ recordedTime(item.departure_time_label) }}
                            </td>
                            <td class="px-4 py-3">
                                {{ item.passenger_count }}
                            </td>
                            <td class="px-4 py-3 font-semibold text-[#0f4c8a]">
                                {{ item.revenue_label || '₱0.00' }}
                            </td>
                            <td class="px-4 py-3 text-right">
                                <span
                                    v-if="item.id === trip.id"
                                    class="text-xs font-semibold text-ink-muted"
                                >
                                    This trip
                                </span>
                                <Link
                                    v-else
                                    :href="`/regular-trips/${item.id}`"
                                    class="text-xs font-semibold text-[#1b7ad8] hover:underline"
                                >
                                    View
                                </Link>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <!-- ================================= -->
        <!-- TRIP REVENUE SUMMARY -->
        <!-- ================================= -->

        <section
            class="overflow-hidden rounded-2xl border border-[#d7e8f6] bg-white shadow-sm dark:border-white/10 dark:bg-white/5"
        >
            <div class="border-b border-[#e8f1f8] px-4 py-3 dark:border-white/10">
                <h2 class="flex items-center gap-2 font-semibold text-[#0f4c8a] dark:text-sky-100">
                    <Wallet class="size-4 text-[#1b7ad8]" />
                    Trip Revenue Summary
                </h2>
                <p class="mt-1 text-xs text-ink-muted">
                    Fares collected from this boat's departed trips today.
                </p>
            </div>
            <dl class="grid gap-3 p-4 sm:grid-cols-3">
                <div class="rounded-xl border border-white/70 bg-[#E8F2FF] p-4 dark:border-white/10 dark:bg-blue-500/15">
                    <div class="mb-3 flex size-9 items-center justify-center rounded-lg bg-[#1479E8] text-white">
                        <ArrowRight class="size-4" />
                    </div>
                    <dt class="text-[13px] font-semibold text-ink dark:text-slate-200">Outbound Revenue</dt>
                    <dd class="mt-1 text-2xl font-bold text-[#0F2854] dark:text-white">
                        {{ trip.outbound_revenue_label || '₱0.00' }}
                    </dd>
                </div>
                <div class="rounded-xl border border-white/70 bg-[#E7F8EE] p-4 dark:border-white/10 dark:bg-emerald-500/15">
                    <div class="mb-3 flex size-9 items-center justify-center rounded-lg bg-[#16A34A] text-white">
                        <ArrowLeft class="size-4" />
                    </div>
                    <dt class="text-[13px] font-semibold text-ink dark:text-slate-200">Return Revenue</dt>
                    <dd class="mt-1 text-2xl font-bold text-[#0F2854] dark:text-white">
                        {{ trip.return_revenue_label || '₱0.00' }}
                    </dd>
                </div>
                <div class="rounded-xl border border-white/70 bg-[#FFF4E2] p-4 dark:border-white/10 dark:bg-amber-500/15">
                    <div class="mb-3 flex size-9 items-center justify-center rounded-lg bg-[#F59E0B] text-white">
                        <Wallet class="size-4" />
                    </div>
                    <dt class="text-[13px] font-semibold text-ink dark:text-slate-200">Total Revenue Collected</dt>
                    <dd class="mt-1 text-2xl font-bold text-[#0F2854] dark:text-white">
                        {{ trip.total_revenue_label || '₱0.00' }}
                    </dd>
                </div>
            </dl>
        </section>

    </div>

    <!-- ================================= -->
    <!-- ADD / EDIT PASSENGER MODAL -->
    <!-- ================================= -->

    <AddTripPassengerModal
        :open="passengerFormOpen"
        :trip-id="trip.id"
        :passenger="editingPassenger"
        :remaining-seats="remainingSeats"
        :capacity="trip.boat.capacity"
        :passenger-count="passengerCount"
        :fares="trip.fares"
        :boarding-started-at-label="trip.boarding?.started_at_label"
        :allow-complete="!trip.is_outbound"
        @update:open="onPassengerFormOpenChange"
        @complete="
            passengerFormOpen = false;
            completeOpen = true;
        "
    />

    <!-- ================================= -->
    <!-- DELETE PASSENGER CONFIRMATION -->
    <!-- ================================= -->

    <Dialog
        :open="Boolean(deletingPassenger)"
        @update:open="
            (open) => !open && cancelDelete()
        "
    >
        <DialogContent
            v-if="deletingPassenger"
            :show-close-button="false"
            class="max-w-md rounded-2xl border-none p-0 shadow-2xl"
        >
            <div class="bg-[#1b7ad8] px-5 py-4 text-white">
                <DialogTitle class="text-lg font-semibold text-white">
                    Delete Passenger
                </DialogTitle>

                <DialogDescription class="text-sm text-white/80">
                    Remove this passenger from the trip manifest.
                </DialogDescription>
            </div>

            <div class="space-y-4 p-5">
                <p class="text-sm text-ink">
                    Are you sure you want to delete

                    <strong>
                        {{ deletingPassenger.full_name }}
                    </strong>

                    from this trip?
                </p>

                <div class="flex justify-end gap-3">
                    <Button
                        type="button"
                        variant="outline"
                        class="rounded-xl"
                        :disabled="deleteProcessing"
                        @click="cancelDelete"
                    >
                        Cancel
                    </Button>

                    <Button
                        type="button"
                        class="rounded-xl bg-rose-600 text-white hover:bg-rose-700"
                        :disabled="deleteProcessing"
                        @click="deletePassenger"
                    >
                        <Spinner
                            v-if="deleteProcessing"
                            class="size-4"
                        />

                        Delete
                    </Button>
                </div>
            </div>
        </DialogContent>
    </Dialog>

    <!-- ================================= -->
    <!-- COMPLETE RETURN TRIP -->
    <!-- ================================= -->

    <Dialog
        :open="completeOpen"
        @update:open="
            (open) => (completeOpen = open)
        "
    >
        <DialogContent
            :show-close-button="false"
            class="max-w-md rounded-2xl border-none p-0 shadow-2xl"
        >
            <div class="bg-[#1b7ad8] px-5 py-4 text-white">
                <DialogTitle class="text-lg font-semibold text-white">
                    Another trip today?
                </DialogTitle>

                <DialogDescription class="text-sm text-white/80">
                    Would you like to continue your duty?
                </DialogDescription>
            </div>

            <div class="space-y-4 p-5">
                <p class="text-sm text-ink">
                    Passengers have been recorded.
                    Continue rotation sends
                    <strong>{{ trip.boat.name }}</strong>
                    to the last position.
                    End duty keeps the boat on the list, outside the queue.
                </p>

                <div class="flex flex-wrap justify-end gap-3">
                <Button
                    type="button"
                    variant="outline"
                    class="rounded-xl"
                    @click="completeOpen = false"
                >
                    Cancel
                </Button>

                <Button
                    type="button"
                    variant="outline"
                    class="rounded-xl"
                    :disabled="statusProcessing"
                    @click="
                        updateStatus('completed', {
                            action: 'complete',
                            return_to_queue: false,
                        })
                    "
                >
                    End Duty
                </Button>

                <Button
                    type="button"
                    class="rounded-xl bg-emerald-600 text-white hover:bg-emerald-700"
                    :disabled="statusProcessing"
                    @click="
                        updateStatus('completed', {
                            action: 'complete',
                            return_to_queue: true,
                        })
                    "
                    >
                    Next Trip
                </Button>
                </div>
            </div>
        </DialogContent>
    </Dialog>

    <Dialog
        :open="departOpen"
        @update:open="(open) => (departOpen = open)"
    >
        <DialogContent
            :show-close-button="false"
            class="max-w-md rounded-2xl border-none p-0 shadow-2xl"
        >
            <div class="bg-[#1b7ad8] px-5 py-4 text-white">
                <DialogTitle class="text-lg font-semibold text-white">
                    Confirm departure
                </DialogTitle>
                <DialogDescription class="text-sm text-white/80">
                    Record the actual departure time.
                </DialogDescription>
            </div>
            <div class="space-y-4 p-5">
                <dl class="space-y-2 text-sm">
                    <div class="flex justify-between gap-3">
                        <dt class="text-ink-muted">Boat</dt>
                        <dd class="font-semibold">{{ trip.boat.name }}</dd>
                    </div>
                    <div class="flex justify-between gap-3">
                        <dt class="text-ink-muted">Operator</dt>
                        <dd>{{ trip.boat.operator_name || 'Unassigned' }}</dd>
                    </div>
                    <div class="flex justify-between gap-3">
                        <dt class="text-ink-muted">Passengers</dt>
                        <dd>{{ passengerCount }} / {{ capacity }}</dd>
                    </div>
                </dl>
                <div class="flex justify-end gap-3">
                    <Button type="button" variant="outline" class="rounded-xl" @click="departOpen = false">
                        Cancel
                    </Button>
                    <Button
                        type="button"
                        class="rounded-xl bg-emerald-600 text-white hover:bg-emerald-700"
                        :disabled="statusProcessing"
                        @click="
                            trip.is_outbound
                                ? updateStatus('on_trip')
                                : updateStatus(trip.status, { action: 'depart' })
                        "
                    >
                        Depart
                    </Button>
                </div>
            </div>
        </DialogContent>
    </Dialog>

    <Dialog
        :open="returnBoardingOpen"
        @update:open="(open) => (returnBoardingOpen = open)"
    >
        <DialogContent
            :show-close-button="false"
            class="max-w-md rounded-2xl border-none p-0 shadow-2xl"
        >
            <div class="bg-[#1b7ad8] px-5 py-4 text-white">
                <DialogTitle class="text-lg font-semibold text-white">
                    Arrive at the origin
                </DialogTitle>
                <DialogDescription class="text-sm text-white/80">
                    Record that the boat has arrived at the origin.
                </DialogDescription>
            </div>
            <div class="flex justify-end gap-3 p-5">
                <Button type="button" variant="outline" class="rounded-xl" @click="returnBoardingOpen = false">
                    Cancel
                </Button>
                <Button
                    type="button"
                    class="rounded-xl bg-violet-600 text-white hover:bg-violet-700"
                    :disabled="statusProcessing"
                    @click="updateStatus('on_trip', { action: 'arrive' })"
                >
                    Arrive at the origin
                </Button>
            </div>
        </DialogContent>
    </Dialog>

    <Dialog
        :open="endDutyOpen"
        @update:open="(open) => (endDutyOpen = open)"
    >
        <DialogContent
            :show-close-button="false"
            class="max-w-md rounded-2xl border-none p-0 shadow-2xl"
        >
            <div class="bg-[#1b7ad8] px-5 py-4 text-white">
                <DialogTitle class="text-lg font-semibold text-white">
                    End duty
                </DialogTitle>
                <DialogDescription class="text-sm text-white/80">
                    Remove {{ trip.boat.name }} from the active rotation queue.
                </DialogDescription>
            </div>
            <div class="flex justify-end gap-3 p-5">
                <Button type="button" variant="outline" class="rounded-xl" @click="endDutyOpen = false">
                    Cancel
                </Button>
                <Button
                    type="button"
                    class="rounded-xl bg-rose-600 text-white hover:bg-rose-700"
                    :disabled="statusProcessing"
                    @click="updateStatus('off_duty')"
                >
                    End Duty
                </Button>
            </div>
        </DialogContent>
    </Dialog>
</template>
