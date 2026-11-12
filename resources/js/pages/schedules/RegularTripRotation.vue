
<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import {
    ArrowRight,
    Ban,
    CircleCheck,
    CloudRain,
    EllipsisVertical,
    Eye,
    Info,
    Plus,
    RotateCcw,
    MapPin,
    Search,
    Ship,
    UserRound,
    Users,
    Wallet,
} from '@lucide/vue';
import { computed, ref } from 'vue';

import AddRegularTripModal from '@/components/AddRegularTripModal.vue';
import AddTripPassengerModal from '@/components/AddTripPassengerModal.vue';
import StatCards from '@/components/admin/StatCards.vue';
import type { StatCardItem } from '@/components/admin/StatCards.vue';
import WeatherAlertBanner from '@/components/admin/WeatherAlertBanner.vue';
import { Button } from '@/components/ui/button';

import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogTitle,
} from '@/components/ui/dialog';


import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';

import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';

import { cn } from '@/lib/utils';

import type {
    OrganizationRouteOption,
    RegularTripRecord,
    RegularTripStatus,
    RegularTripStatusOption,
    ScheduleBoatOption,
} from '@/types/schedule';

// ==========================================
// PAGE PROPS
// ==========================================

const props = defineProps<{
    trips: RegularTripRecord[];
    boats: ScheduleBoatOption[];
    tripBoats: ScheduleBoatOption[];
    organizationRoutes: OrganizationRouteOption[];
    routeOptions: string[];
    ports?: {
        origin: string;
        destination: string;
    };
    weatherAlert?: {
        headline: string;
        reason_label: string;
        message: string;
        affected_date_label: string;
        affected_routes_label: string;
        duration_label: string;
    } | null;
    statusOptions: RegularTripStatusOption[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Regular Trip Rotation',
                href: '/schedules/regular-trip-rotation',
            },
        ],
    },
});

// ==========================================
// SHARED STYLES
// ==========================================

const fieldClass =
    'h-10 w-full rounded-xl border border-[#c5def5] bg-[#f7fbfe] text-ink shadow-none focus-visible:border-[#1b7ad8] focus-visible:ring-[#1b7ad8]/20 dark:border-white/10 dark:bg-white/5 dark:text-sky-50';

// ==========================================
// DATE
// ==========================================

function localDate(value = new Date()) {
    const month = String(value.getMonth() + 1).padStart(2, '0');
    const day = String(value.getDate()).padStart(2, '0');

    return `${value.getFullYear()}-${month}-${day}`;
}

// ==========================================
// MODAL AND FILTER STATE
// ==========================================

const addOpen = ref(false);
const addDefaultRoute = ref('');

const editingTrip = ref<RegularTripRecord | null>(null);
const deletingTrip = ref<RegularTripRecord | null>(null);

const deleting = ref(false);

const dateFilter = ref(localDate());
const routeFilter = ref('all');
const searchQuery = ref('');

const statusFilter = ref<
    'all' | RegularTripStatus
>('all');

const statusUpdatingId = ref<number | null>(null);

const completingTrip = ref<RegularTripRecord | null>(null);
const departingTrip = ref<RegularTripRecord | null>(null);
const encodingTripId = ref<number | null>(null);
const encodingTrip = computed(() =>
    encodingTripId.value == null
        ? null
        : (props.trips.find((trip) => trip.id === encodingTripId.value) ?? null),
);
const endingDutyTrip = ref<RegularTripRecord | null>(null);
const arrivingTrip = ref<RegularTripRecord | null>(null);

// ==========================================
// ROUTE INFORMATION
// ==========================================

const hasRegularTripRoutes = computed(
    () => props.organizationRoutes.length > 0,
);

const origin = computed(() => {
    const value = props.ports?.origin?.trim();

    return value || '—';
});

const destination = computed(() => {
    const value = props.ports?.destination?.trim();

    return value || '—';
});

// ==========================================
// FILTERED TRIPS
// ==========================================

const scopedTrips = computed(() =>
    [...props.trips]
        .filter((trip) => {
            if (
                dateFilter.value &&
                trip.trip_date !== dateFilter.value
            ) {
            return false;
        }

            if (
                routeFilter.value !== 'all' &&
                trip.route !== routeFilter.value
            ) {
            return false;
        }

            const query = searchQuery.value
                .trim()
                .toLowerCase();

            if (query) {
                const haystack = `
                    ${trip.boat.name}
                    ${trip.boat.code}
                    ${trip.boat.operator_name ?? ''}
                `.toLowerCase();

                if (!haystack.includes(query)) {
            return false;
                }
            }

            return true;
        })
        .sort((a, b) => {
            const orderA =
                a.queue_position ??
                (a.status === 'on_trip' ? 500 : 900);

            const orderB =
                b.queue_position ??
                (b.status === 'on_trip' ? 500 : 900);

            if (orderA !== orderB) {
                return orderA - orderB;
            }

            return a.id - b.id;
    }),
);

// ==========================================
// BOAT ROTATION ELIGIBILITY
// ==========================================

function boatStillInRotation(trip: RegularTripRecord) {
    if (trip.boat.is_active !== false) {
        return true;
    }

    return (
        trip.status === 'on_trip' ||
        Boolean(trip.can_complete_return)
    );
}

// ==========================================
// OUTGOING TRIPS
// ==========================================

const goingTrips = computed(() =>
    scopedTrips.value.filter(
        (trip) =>
            trip.is_outbound &&
            trip.is_lineup !== false &&
            boatStillInRotation(trip),
    ),
);

function rowPhase(trip: RegularTripRecord) {
    if (trip.status === 'on_trip') {
        return 3;
    }

    if (trip.status === 'completed' || trip.status === 'off_duty') {
        return 2;
    }

    if (trip.status === 'scheduled' || trip.status === 'delayed') {
        return 0;
    }

    return 1;
}

function orderedRows(trips: RegularTripRecord[]) {
    return [...trips].sort((a, b) => {
        const phase = rowPhase(a) - rowPhase(b);

        if (phase !== 0) {
            return phase;
        }

        const orderA = rowSortKey(a);
        const orderB = rowSortKey(b);

        if (orderA !== orderB) {
            return orderA - orderB;
        }

        return a.id - b.id;
    });
}

function rowSortKey(trip: RegularTripRecord) {
    if (rowPhase(trip) === 0) {
        return Number(trip.queue_order || trip.rotation_order || 999);
    }

    if (rowPhase(trip) === 1) {
        return trip.departed_at ? Date.parse(trip.departed_at) : trip.id;
    }

    return trip.status_changed_at
        ? Date.parse(trip.status_changed_at)
        : Number(trip.rotation_order || 999);
}

function boatDayKey(trip: RegularTripRecord) {
    return `${trip.boat.id}|${trip.trip_date}|${trip.route}`;
}

function pickBoatRow(trips: RegularTripRecord[]) {
    const active = trips.filter((trip) => trip.status !== 'cancelled');
    const outbound = active.filter((trip) => trip.is_outbound);
    const pool = outbound.length > 0 ? outbound : active;

    const onTrip = pool.find((trip) => trip.status === 'on_trip');

    if (onTrip) {
        return onTrip;
    }

    const encodingReturn = active.find((trip) => trip.can_complete_return);

    if (encodingReturn) {
        return encodingReturn;
    }

    const waiting = pool
        .filter((trip) => trip.status === 'scheduled' || trip.status === 'delayed')
        .sort(
            (a, b) =>
                Number(a.queue_order || a.rotation_order || 999) -
                Number(b.queue_order || b.rotation_order || 999),
        );

    if (waiting.length > 0) {
        return waiting[0];
    }

    const offDuty = pool.find((trip) => trip.status === 'off_duty');

    if (offDuty) {
        return offDuty;
    }

    return [...pool].sort((a, b) => b.id - a.id)[0] ?? null;
}

function matchesStatus(trip: RegularTripRecord) {
    if (statusFilter.value === 'all') {
        return true;
    }

    if (statusFilter.value === 'scheduled') {
        return trip.status === 'scheduled' || trip.status === 'delayed';
    }

    if (statusFilter.value === 'completed') {
        return trip.status === 'completed' || trip.remarks === 'completed_today';
    }

    if (statusFilter.value === 'off_duty') {
        return trip.status === 'off_duty' && trip.remarks !== 'completed_today';
    }

    return trip.status === statusFilter.value;
}

const scheduleRows = computed(() => {
    const groups = new Map<string, RegularTripRecord[]>();

    scopedTrips.value.forEach((trip) => {
        if (!boatStillInRotation(trip) && trip.status !== 'off_duty') {
            return;
        }

        const key = boatDayKey(trip);
        const bucket = groups.get(key) ?? [];
        bucket.push(trip);
        groups.set(key, bucket);
    });

    return [...groups.values()]
        .map((trips) => pickBoatRow(trips))
        .filter((trip): trip is RegularTripRecord => trip !== null)
        .sort((a, b) => {
            const phase = rowPhase(a) - rowPhase(b);

            if (phase !== 0) {
                return phase;
            }

            const orderA = rowSortKey(a);
            const orderB = rowSortKey(b);

            if (orderA !== orderB) {
                return orderA - orderB;
            }

            return a.id - b.id;
        });
});

// ==========================================
// RETURN TRIP FILTERS
// ==========================================

function matchesReturnFilters(trip: RegularTripRecord) {
    if (
        dateFilter.value &&
        trip.trip_date !== dateFilter.value
    ) {
        return false;
    }

    if (
        routeFilter.value !== 'all' &&
        trip.route !== routeFilter.value
    ) {
        return false;
    }

    const query = searchQuery.value.trim().toLowerCase();

    if (query) {
        const haystack = `
            ${trip.boat.name}
            ${trip.boat.code}
            ${trip.boat.operator_name ?? ''}
        `.toLowerCase();

        if (!haystack.includes(query)) {
            return false;
        }
    }

    return true;
}

// ==========================================
// NEXT ROTATION CYCLE
// ==========================================

function hasStartedNextGoing(trip: RegularTripRecord) {
    const cycle = trip.cycle_number || 1;

    return props.trips.some(
        (item) =>
            item.is_outbound &&
            item.boat.id === trip.boat.id &&
            item.trip_date === trip.trip_date &&
            (item.cycle_number || 1) > cycle &&
            item.status !== 'cancelled' &&
            item.status !== 'off_duty',
    );
}

// ==========================================
// RETURN QUEUE VISIBILITY
//
// Some return records exist in advance.
// Hide them until the matching outgoing
// trip has actually departed.
//
// This only changes frontend presentation.
// ==========================================

function hasDepartedForReturn(trip: RegularTripRecord) {
    const sameCycle = props.trips.find(
        (outbound) =>
            outbound.is_outbound &&
            outbound.boat.id === trip.boat.id &&
            outbound.trip_date === trip.trip_date &&
            outbound.route === trip.route &&
            (outbound.cycle_number || 1) ===
                (trip.cycle_number || 1),
    );

    if (sameCycle) {
        const departure = sameCycle.actual_departure_label;

        return (
            sameCycle.status === 'on_trip' ||
            sameCycle.status === 'completed' ||
            Boolean(
                departure &&
                departure !== 'Not Recorded' &&
                departure !== '—',
            )
        );
    }

    // Preserve historical return records
    // when the matching outbound trip
    // is not included in the current data.
    return (
        trip.status === 'completed' ||
        Boolean(
            trip.actual_arrival_label ||
            trip.arrived_at_label,
        )
    );
}

// ==========================================
// RETURN TRIPS
// ==========================================

const returnTrips = computed(() =>
    [...props.trips]
        .filter(
            (trip) =>
                !trip.is_outbound &&
                trip.status !== 'cancelled' &&
                matchesReturnFilters(trip),
        )
        .filter(
            (trip) =>
                boatStillInRotation(trip) &&
                hasDepartedForReturn(trip),
        )
        .filter(
            (trip) =>
                trip.status !== 'completed' ||
                !hasStartedNextGoing(trip),
        )
        .sort((a, b) => {
            const orderA =
                a.queue_position ??
                a.rotation_order ??
                999;

            const orderB =
                b.queue_position ??
                b.rotation_order ??
                999;

            if (orderA !== orderB) {
                return orderA - orderB;
            }

            return a.id - b.id;
        }),
);

// ==========================================
// DAY OUTBOUND TRIP RECORDS
// ==========================================

const dayOutboundTrips = computed(() =>
    props.trips.filter((trip) => {
        if (
            !trip.is_outbound ||
            trip.status === 'cancelled'
        ) {
            return false;
        }

        if (
            dateFilter.value &&
            trip.trip_date !== dateFilter.value
        ) {
            return false;
        }

        if (
            routeFilter.value !== 'all' &&
            trip.route !== routeFilter.value
        ) {
            return false;
        }

        const query = searchQuery.value
            .trim()
            .toLowerCase();

        if (query) {
            const haystack = `
                ${trip.boat.name}
                ${trip.boat.code}
                ${trip.boat.operator_name ?? ''}
            `.toLowerCase();

            if (!haystack.includes(query)) {
                return false;
            }
        }

        return true;
    }),
);

const lineupStats = computed(() => {
    const regular = [...dayOutboundTrips.value, ...returnTrips.value];
    const revenueBoats = new Map<number, number>();

    regular.forEach((trip) => {
        if (trip.boat_revenue_today != null) {
            revenueBoats.set(trip.boat.id, Number(trip.boat_revenue_today));
        }
    });

    return {
        total: dayOutboundTrips.value.filter(
            (trip) =>
                trip.is_lineup !== false &&
                boatStillInRotation(trip) &&
                (trip.status === 'scheduled' || trip.status === 'delayed'),
        ).length,
        onTrip: dayOutboundTrips.value.filter((trip) => trip.status === 'on_trip').length,
        passengers: regular.reduce((sum, trip) => sum + Number(trip.passenger_count || 0), 0),
        revenue: [...revenueBoats.values()].reduce((sum, amount) => sum + amount, 0),
    };
});

const lineupCards = computed<StatCardItem[]>(() => [
    { key: 'lineup', title: 'Waiting in Rotation', value: lineupStats.value.total, hint: 'Boats waiting for their turn', icon: Ship, tone: 'amber' },
    { key: 'on_trip', title: 'On Trip', value: lineupStats.value.onTrip, hint: 'Boats currently traveling', icon: ArrowRight, tone: 'blue' },
    { key: 'passengers', title: 'Passengers', value: lineupStats.value.passengers, hint: 'Regular trip passengers today', icon: Users, tone: 'indigo' },
    { key: 'revenue', title: 'Total Revenue', value: moneyLabel(lineupStats.value.revenue), hint: 'Regular trip fares today', icon: Wallet, tone: 'green' },
]);

function moneyLabel(value: number): string {
    return new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP' }).format(value);
}

function destinationName(trip: RegularTripRecord): string {
    const direction = trip.direction || '';
    const parts = direction.split('→').map((part) => part.trim());

    return parts[1] || 'destination';
}

const queueCounts = computed(() => {
    const rows = scheduleRows.value;

    return {
        all: rows.length,
        waiting: rows.filter((trip) => trip.status === 'scheduled' || trip.status === 'delayed').length,
        on_trip: rows.filter((trip) => trip.status === 'on_trip').length,
        completed: rows.filter((trip) => trip.status === 'completed' || trip.remarks === 'completed_today').length,
        off_duty: rows.filter((trip) => trip.status === 'off_duty' && trip.remarks !== 'completed_today').length,
    };
});

const statusPills = computed(() => [
    { value: 'all' as const, label: 'All Boats', count: queueCounts.value.all },
    { value: 'scheduled' as const, label: 'Waiting', count: queueCounts.value.waiting },
    { value: 'on_trip' as const, label: 'On Trip', count: queueCounts.value.on_trip },
    { value: 'completed' as const, label: 'Completed', count: queueCounts.value.completed },
    { value: 'off_duty' as const, label: 'Off Duty', count: queueCounts.value.off_duty },
]);

// ==========================================
// ROUTE GROUPS
// ==========================================

const routeGroups = computed(() => {
    const selected = routeFilter.value;

    const catalog =
        selected === 'all'
            ? props.organizationRoutes
            : props.organizationRoutes.filter(
                  (item) => item.name === selected,
              );

    const known = new Set(
        catalog.map((item) => item.name),
    );

    const extras = [
        ...new Set(
            [...goingTrips.value, ...returnTrips.value]
                .map((trip) => trip.route)
                .filter(
                    (name) =>
                        Boolean(name) &&
                        !known.has(name),
                ),
        ),
    ].map((name) => {
        const [from, to] = name.split(
            /\s*(?:↔|--|→)\s*/,
        );

        return {
            name,
            origin: from?.trim() || origin.value,
            destination: to?.trim() || destination.value,
        };
    });

    const items =
        catalog.length > 0
            ? [
                  ...catalog.map((item) => ({
                      name: item.name,
                      origin: item.origin,
                      destination: item.destination,
                  })),
                  ...extras,
              ]
            : extras;

    const groups = (
        items.length > 0
            ? items
            : [
                  {
                      name: '',
                      origin: origin.value,
                      destination: destination.value,
                  },
              ]
    ).map((route) => ({
        name: route.name,
        origin: route.origin || origin.value,
        destination: route.destination || destination.value,

        going: scheduleRows.value.filter(
            (trip) =>
                (!route.name || trip.route === route.name) &&
                matchesStatus(trip),
        ),

        returning: returnTrips.value.filter(
            (trip) =>
                !route.name ||
                trip.route === route.name,
        ),
    }));

    // Avoid displaying multiple empty route cards.
    if (selected !== 'all') {
        return groups;
    }

    const populated = groups.filter(
        (group) =>
            group.going.length > 0 ||
            group.returning.length > 0,
    );

    return populated.length > 0
        ? populated
        : groups.slice(0, 1);
});

// ==========================================
// ADD / EDIT BOAT OPTIONS
// ==========================================

const modalBoats = computed(() => {
    const boats = [...props.tripBoats];
    const current = editingTrip.value?.boat;

    if (
        current &&
        !boats.some((boat) => boat.id === current.id)
    ) {
        boats.unshift({
            id: current.id,
            name: current.name,
            code: current.code,
            capacity: current.capacity,
            status: current.status,
            service_type:
                current.service_type ?? 'regular_trip',
            picture_url: current.picture_url,
            operator_name: current.operator_name,
        });
    }

    return boats;
});

// ==========================================
// FILTER HELPERS
// ==========================================

const showRouteFilter = computed(
    () => props.routeOptions.length > 1,
);

function resetFilters() {
    dateFilter.value = localDate();
    searchQuery.value = '';
    statusFilter.value = 'all';
    routeFilter.value = 'all';
}

// ==========================================
// DISPLAY HELPERS
// ==========================================

function dashTime(value?: string | null) {
    if (!value || value === 'Not Recorded') {
        return '—';
    }

    return value;
}

function ordinalLabel(order: number) {
    const value = order % 100;

    const suffix =
        value >= 11 && value <= 13
            ? 'th'
            : (
                  {
                      1: 'st',
                      2: 'nd',
                      3: 'rd',
                  } as Record<number, string>
              )[value % 10] ?? 'th';

    return `${order}${suffix}`;
}

function queueNumber(trip: RegularTripRecord) {
    let order = Number(trip.rotation_order || 0);

    if (order < 1) {
        const sibling = props.trips.find(
            (item) =>
                item.boat.id === trip.boat.id &&
                item.trip_date === trip.trip_date &&
                item.route === trip.route &&
                Number(item.rotation_order || 0) > 0,
        );
        order = Number(sibling?.rotation_order || 0);
    }

    return order > 0 ? ordinalLabel(order) : '—';
}

function passengerPercent(trip: RegularTripRecord) {
    const capacity = Number(trip.boat.capacity || 0);
    const count = Number(trip.passenger_count || 0);

    if (capacity <= 0) {
        return 0;
    }

    return Math.min(
        100,
        Math.round((count / capacity) * 100),
    );
}

// ==========================================
// OUTGOING STATUS
// ==========================================

function isNextInRotation(trip: RegularTripRecord) {
    if (!trip.is_outbound || (trip.status !== 'scheduled' && trip.status !== 'delayed')) {
        return false;
    }

    const siblings = props.trips.filter(
        (item) =>
            item.is_outbound &&
            item.trip_date === trip.trip_date &&
            item.route === trip.route &&
            (item.status === 'scheduled' || item.status === 'delayed'),
    );

    const first = [...siblings].sort((a, b) => {
        const order =
            Number(a.queue_order || a.rotation_order || 999) -
            Number(b.queue_order || b.rotation_order || 999);

        return order !== 0 ? order : a.id - b.id;
    })[0];

    return first?.id === trip.id;
}

function goingStatus(trip: RegularTripRecord) {
    if (trip.status === 'on_trip') {
        return {
            label: 'On Trip',
            class: 'bg-blue-100 text-blue-700',
        };
    }

    if (trip.status === 'completed') {
        return {
            label: 'Completed',
            class: 'bg-emerald-100 text-emerald-700',
        };
    }

    if (trip.status === 'off_duty') {
        return trip.remarks === 'completed_today'
            ? { label: 'Completed', class: 'bg-emerald-100 text-emerald-700' }
            : { label: 'Off Duty', class: 'bg-slate-100 text-slate-600' };
    }

    if (isNextInRotation(trip)) {
        return {
            label: 'Boarding',
            class: 'bg-amber-100 text-amber-800',
        };
    }

    return {
        label: 'Waiting',
        class: 'bg-amber-50 text-amber-700',
    };
}

function isBoardingTrip(trip: RegularTripRecord) {
    return isNextInRotation(trip) && Boolean(trip.boarding?.started_at);
}

function hasDeparted(trip: RegularTripRecord) {
    const departure = trip.actual_departure_label;

    return (
        trip.status === 'on_trip' ||
        trip.status === 'completed' ||
        Boolean(departure && departure !== 'Not Recorded' && departure !== '—')
    );
}

function canAddPassenger(trip: RegularTripRecord) {
    if (!trip.can_encode_passengers || trip.at_capacity) {
        return false;
    }

    if (!trip.is_outbound) {
        return true;
    }

    return isNextInRotation(trip);
}

function canDepartNow(trip: RegularTripRecord) {
    return (
        trip.is_outbound &&
        isNextInRotation(trip) &&
        Boolean(trip.can_depart) &&
        Number(trip.passenger_count || 0) > 0
    );
}

function canCompleteNow(trip: RegularTripRecord) {
    return (
        !trip.is_outbound &&
        trip.status !== 'off_duty' &&
        trip.status !== 'completed' &&
        Boolean(trip.can_complete_return) &&
        Number(trip.passenger_count || 0) > 0
    );
}

function rowStatus(trip: RegularTripRecord) {
    if (
        !trip.is_outbound &&
        trip.status !== 'completed' &&
        trip.status !== 'off_duty' &&
        trip.status !== 'on_trip' &&
        Boolean(trip.can_complete_return)
    ) {
        return {
            label: 'On Trip',
            class: 'bg-blue-100 text-blue-700',
        };
    }

    return goingStatus(trip);
}

function hasGoingMenu(trip: RegularTripRecord) {
    return (
        Boolean(trip.can_depart) ||
        Boolean(trip.can_set_off_duty) ||
        Boolean(trip.can_set_on_duty)
    );
}

function tripCount(trip: RegularTripRecord) {
    const count = Number(trip.trips_today || 0);

    return count > 0 ? String(count) : '0';
}

function remainingSeats(trip: RegularTripRecord) {
    if (trip.remaining_seats != null) {
        return trip.remaining_seats;
    }

    return Math.max(
        0,
        Number(trip.boat.capacity || 0) -
            Number(trip.passenger_count || 0),
    );
}

// ==========================================
// UPDATE TRIP STATUS
// ==========================================

function updateTripStatus(
    trip: RegularTripRecord,
    status: string,
    extra: Record<string, boolean | string> = {},
) {
    if (
        !status ||
        statusUpdatingId.value === trip.id
    ) {
        return;
    }

    statusUpdatingId.value = trip.id;

    router.patch(
        `/regular-trips/${trip.id}/status`,
        {
            status,
            ...extra,
        },
        {
            preserveScroll: true,

            onFinish: () => {
                statusUpdatingId.value = null;
                completingTrip.value = null;
                departingTrip.value = null;
                endingDutyTrip.value = null;
                arrivingTrip.value = null;
            },
        },
    );
}

function confirmDepart() {
    const trip = departingTrip.value;

    if (!trip) {
        return;
    }

    if (trip.is_outbound) {
        updateTripStatus(trip, 'on_trip', { action: 'depart' });
        return;
    }

    updateTripStatus(trip, trip.status, {
        action: 'depart',
    });
}

// ==========================================
// ADD BOAT
// ==========================================

function openAdd(routeName = '') {
    editingTrip.value = null;

    addDefaultRoute.value =
        routeName ||
        (
            routeFilter.value !== 'all'
                ? routeFilter.value
                : ''
        );

    addOpen.value = true;
}

// ==========================================
// EDIT BOAT
// ==========================================

function openEdit(trip: RegularTripRecord) {
    editingTrip.value = trip;
    addOpen.value = true;
}

function onAddOpenChange(open: boolean) {
    addOpen.value = open;

    if (!open) {
        editingTrip.value = null;
    }
}

// ==========================================
// REMOVE BOAT
// ==========================================

function confirmDelete(trip: RegularTripRecord) {
    deletingTrip.value = trip;
}

function cancelDelete() {
    if (deleting.value) {
        return;
    }

    deletingTrip.value = null;
}

function deleteTrip() {
    if (!deletingTrip.value || deleting.value) {
        return;
    }

    deleting.value = true;

    router.delete(
        `/regular-trips/${deletingTrip.value.id}`,
        {
        preserveScroll: true,

        onFinish: () => {
            deleting.value = false;
            deletingTrip.value = null;
        },
        },
    );
}
</script>

<template>
    <Head title="Regular Trip Rotation" />

    <div class="flex flex-1 flex-col gap-5 p-4 md:p-6">

        <!-- ================================= -->
        <!-- PAGE HEADER -->
        <!-- ================================= -->

        <div
            class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between"
        >
            <div>
                <h1
                    class="text-2xl font-bold tracking-tight text-[#0f4c8a] dark:text-sky-100"
                >
                    Regular Trip Rotation
                </h1>

                <p
                    class="mt-1 text-sm text-ink-muted dark:text-slate-400"
                >
                    Follow the rotation queue: board passengers,
                    depart when ready, complete the round trip,
                    then continue or end duty.
                </p>
            </div>

            <button
                type="button"
                class="inline-flex h-11 items-center justify-center gap-2 rounded-xl bg-[#1b7ad8] px-4 text-sm font-semibold text-white shadow-[0_8px_18px_rgba(27,122,216,0.28)] transition hover:bg-[#1567c4] disabled:cursor-not-allowed disabled:opacity-50"
                :disabled="
                    Boolean(weatherAlert) ||
                    !hasRegularTripRoutes
                "
                @click="openAdd"
            >
                <Plus class="size-4" />
                Add boat
            </button>
        </div>

        <!-- ================================= -->
        <!-- WEATHER SUSPENSION ALERT -->
        <!-- ================================= -->

        <WeatherAlertBanner v-if="weatherAlert" :alert="weatherAlert" variant="hold" context="trips" can-lift />

        <!-- ================================= -->
        <!-- NO REGULAR ROUTES -->
        <!-- ================================= -->

        <section
            v-if="!hasRegularTripRoutes"
            class="flex flex-col gap-3 rounded-2xl border border-[#d7e8f6] bg-[#f4f9fd] px-5 py-4 sm:flex-row sm:items-center sm:justify-between dark:border-white/10 dark:bg-white/5"
        >
            <div class="flex items-start gap-3">
                <div
                    class="flex size-10 shrink-0 items-center justify-center rounded-full bg-[#1b7ad8]/15 text-[#1b7ad8]"
                >
                    <Info class="size-5" />
                </div>

                <div>
                    <p
                        class="font-semibold text-[#0f4c8a] dark:text-sky-100"
                    >
                        No regular trip routes configured yet
                    </p>

                    <p
                        class="mt-1 text-sm text-ink-muted dark:text-slate-400"
                    >
                        Add an active regular trip route in
                        Route Management before scheduling boats.
                    </p>
                </div>
            </div>

            <Link
                href="/routes"
                class="inline-flex h-10 shrink-0 items-center justify-center rounded-xl border border-[#1b7ad8] px-4 text-sm font-semibold text-[#1b7ad8] transition hover:bg-[#e8f4fc]"
            >
                Route Management
            </Link>
        </section>

        <!-- ================================= -->
        <!-- THREE ESSENTIAL SUMMARY CARDS -->
        <!-- ================================= -->

        <StatCards :items="lineupCards" />

        <!-- ================================= -->
        <!-- COMPACT FILTERS -->
        <!-- ================================= -->

        <section
            class="rounded-2xl border border-[#d7e8f6] bg-white p-4 shadow-sm dark:border-white/10 dark:bg-white/5"
        >
            <div
                class="flex flex-col gap-3 xl:flex-row xl:items-end"
            >

                <!-- DATE -->
            <div class="grid gap-1.5">
                    <label
                        class="text-xs font-medium text-ink-soft"
                    >
                        Trip date
                    </label>

                    <Input
                        v-model="dateFilter"
                        type="date"
                        :class="fieldClass"
                    />
            </div>

                <!-- SEARCH -->
            <div class="grid gap-1.5">
                    <label
                        class="text-xs font-medium text-ink-soft"
                    >
                        Search boat or operator
                    </label>

                    <div class="relative">
                        <Search
                            class="pointer-events-none absolute left-3 top-3 size-4 text-ink-muted"
                        />

                        <Input
                            v-model="searchQuery"
                            type="search"
                            placeholder="Boat name, code, or operator"
                            :class="cn(fieldClass, 'pl-9')"
                        />
                    </div>
                </div>

                <div class="flex flex-wrap items-center gap-2 xl:mb-0.5 xl:ml-auto">
                    <button
                        v-for="pill in statusPills"
                        :key="pill.value"
                        type="button"
                        class="inline-flex h-10 items-center rounded-full px-4 text-sm font-semibold transition"
                        :class="
                            statusFilter === pill.value
                                ? 'bg-[#1b7ad8] text-white'
                                : 'border border-[#d7e3ee] bg-white text-[#334155] hover:bg-[#f4f9fd] dark:bg-[#102036] dark:text-slate-100 dark:border-white/10 dark:bg-[#0b1b30]'
                        "
                        @click="statusFilter = pill.value"
                    >
                        {{ pill.label }} ({{ pill.count }})
                    </button>
                </div>
            </div>

            <!-- ROUTE FILTER -->
            <div
                v-if="showRouteFilter"
                class="mt-3 max-w-sm"
            >
                <label
                    class="mb-1.5 block text-xs font-medium text-ink-soft"
                >
                    Route
                </label>

                <Select v-model="routeFilter">
                    <SelectTrigger
                        :class="cn(fieldClass, 'w-full')"
                    >
                        <SelectValue
                            placeholder="All routes"
                        />
                    </SelectTrigger>

                    <SelectContent>
                        <SelectItem value="all">
                            All Routes
                        </SelectItem>

                        <SelectItem
                            v-for="route in routeOptions"
                            :key="route"
                            :value="route"
                        >
                            {{ route }}
                        </SelectItem>
                    </SelectContent>
                </Select>
            </div>
        </section>

        <!-- ================================= -->
        <!-- ROUTE GROUPS -->
        <!-- ================================= -->

        <template
            v-for="group in routeGroups"
            :key="`route-${group.name || 'default'}`"
        >

            <!-- ============================= -->
            <!-- OUTGOING TRIP TABLE -->
            <!-- ============================= -->

            <section
                class="overflow-hidden rounded-[28px] border border-[#d7e8f6] bg-white shadow-[0_8px_24px_rgba(15,76,138,0.06)] dark:border-white/10 dark:bg-white/5"
            >

                <!-- HEADER -->
                <div
                    class="flex flex-col gap-3 border-b border-[#eef4f9] px-5 py-4 sm:flex-row sm:items-center sm:justify-between"
                >
                    <div class="flex items-center gap-3">
                        <span
                            class="flex size-9 items-center justify-center rounded-full bg-[#1b7ad8] text-white"
                        >
                            <Ship class="size-4" />
                        </span>

                        <div>
                            <h2
                                class="text-lg font-semibold text-[#0f4c8a] dark:text-sky-100"
                            >
                                Rotation Queue Today
                            </h2>
            </div>
                </div>

                    <span
                        class="rounded-full px-3 py-1 text-xs font-semibold"
                        :class="
                            weatherAlert
                                ? 'bg-red-100 text-red-700'
                                : 'bg-[#e8f4fc] text-[#1b7ad8]'
                        "
                    >
                        {{
                            weatherAlert
                                ? 'Departures paused'
                                : `${group.going.length} ${
                                      group.going.length === 1
                                          ? 'boat'
                                          : 'boats'
                                  }`
                        }}
                    </span>
            </div>

                <!-- WEATHER HOLD -->
                <div
                    v-if="weatherAlert"
                    class="px-5 py-12 text-center"
                >
                    <CloudRain
                        class="mx-auto size-10 text-red-400"
                    />

                    <p
                        class="mt-3 font-semibold text-red-700"
                    >
                        No trips today
                    </p>

                    <p
                        class="mt-1 text-sm text-red-600"
                    >
                        Regular trips are stopped because of
                        {{
                            weatherAlert.reason_label.toLowerCase()
                        }}.
                    </p>
        </div>

                <!-- EMPTY STATE -->
                <div
                    v-else-if="group.going.length === 0"
                    class="px-5 py-12 text-center"
                >
                    <Ship
                        class="mx-auto size-10 text-[#c5def5]"
                    />

                    <p
                        class="mt-3 font-medium text-[#0f4c8a] dark:text-sky-100"
                    >
                        No boats are in the rotation queue
                    </p>
                    <p class="mt-1 text-sm text-ink-muted">
                        Add a boat to start today's rotation.
                    </p>
                </div>

                <!-- OUTGOING TABLE -->
                <div
                    v-else
                    class="overflow-x-auto"
                >
                    <table
                        class="w-full min-w-[840px] text-left text-sm"
                    >
                        <thead
                            class="bg-[#f7fbfe] text-[11px] font-semibold uppercase tracking-wide text-ink-muted dark:bg-white/5"
                        >
                            <tr>
                                <th class="px-4 py-3">
                                    Rotation No.
                                </th>

                                <th class="px-4 py-3">
                                    Boat Name
                                </th>

                                <th class="px-4 py-3">
                                    Operator
                                </th>

                                <th class="px-4 py-3">
                                    Trip Count
                                </th>

                                <th class="px-4 py-3">
                                    Passengers
                                </th>

                                <th class="px-4 py-3">
                                    Total Revenue
                                </th>

                                <th class="px-4 py-3">
                                    Status
                                </th>

                                <th
                                    class="px-4 py-3 text-right"
                                >
                                    Actions
                                </th>
                            </tr>
                        </thead>

                        <tbody>
                            <tr
                                v-for="trip in orderedRows(group.going)"
                                :key="trip.id"
                                class="border-t border-[#eef4f9] dark:border-white/10"
                            >

                                <!-- QUEUE NUMBER -->
                                <td
                                    class="px-4 py-3 font-semibold text-ink-soft"
                                >
                                    {{ queueNumber(trip) }}
                                </td>

                                <!-- BOAT AND OPERATOR -->
                                <td class="px-4 py-3">
                                    <div
                                        class="flex min-w-[165px] items-center gap-3"
                                    >
                                        <div
                                            class="flex size-10 shrink-0 items-center justify-center overflow-hidden rounded-xl bg-[#d7ebfa]"
                                        >
                                            <img
                                                v-if="trip.boat.picture_url"
                                                :src="trip.boat.picture_url"
                                                :alt="trip.boat.name"
                                                class="h-full w-full object-cover"
                                            />

                                            <Ship
                                                v-else
                                                class="size-4 text-[#1b7ad8]"
                                            />
                </div>

                                        <div class="min-w-0">
                                            <p
                                                class="font-semibold uppercase text-[#0f4c8a] dark:text-sky-100"
                                            >
                                                {{ trip.boat.name }}
                                            </p>
                                            <p class="text-xs text-ink-muted">
                                                {{ trip.boat.code }}
                                            </p>

                                        </div>
                                    </div>
                                </td>

                                <td class="px-4 py-3 text-ink">
                                    <span class="inline-flex items-center gap-1.5">
                                        <UserRound class="size-3.5 text-ink-muted" />
                                        {{ trip.boat.operator_name || 'Unassigned' }}
                                    </span>
                                </td>

                                <td class="px-4 py-3 font-semibold text-[#0f4c8a]">
                                    {{ tripCount(trip) }}
                                </td>

                                <td class="px-4 py-3 font-semibold text-[#0f4c8a]">
                                    {{ trip.passenger_count }}
                                </td>

                                <td class="px-4 py-3 font-semibold text-[#0f4c8a]">
                                    {{ trip.boat_revenue_today_label || trip.revenue_label || '₱0.00' }}
                                </td>

                                <td class="px-4 py-3">
                                    <span
                                        class="inline-flex rounded-full px-2.5 py-1 text-[11px] font-semibold"
                                        :class="rowStatus(trip).class"
                                    >
                                        {{ rowStatus(trip).label }}
                                    </span>
                                </td>

                                <!-- ACTIONS -->
                                <td class="px-4 py-3">
                                    <div
                                        class="flex items-center justify-end gap-2 whitespace-nowrap"
                                    >

                                        <button
                                            v-if="canAddPassenger(trip)"
                                            type="button"
                                            class="inline-flex h-8 items-center gap-1 rounded-full bg-[#1b7ad8] px-3 text-xs font-semibold text-white hover:bg-[#1567c4]"
                                            @click="encodingTripId = trip.id"
                                        >
                                            <Plus class="size-3.5" />
                                            Add Passengers
                                        </button>

                                        <button
                                            v-if="canDepartNow(trip)"
                                            type="button"
                                            class="inline-flex h-8 items-center rounded-full bg-emerald-600 px-3 text-xs font-semibold text-white hover:bg-emerald-700"
                                            @click="departingTrip = trip"
                                        >
                                            Depart
                                        </button>

                                        <button
                                            v-if="trip.can_arrive_origin"
                                            type="button"
                                            class="inline-flex h-8 items-center gap-1 rounded-full bg-[#1b7ad8] px-3 text-xs font-semibold text-white hover:bg-[#1567c4]"
                                            @click="arrivingTrip = trip"
                                        >
                                            <MapPin class="size-3.5" />
                                            Arrive at Origin
                                        </button>

                                        <button
                                            v-if="canCompleteNow(trip)"
                                            type="button"
                                            class="inline-flex h-8 items-center rounded-lg bg-emerald-600 px-3 text-xs font-semibold text-white hover:bg-emerald-700"
                                            @click="completingTrip = trip"
                                        >
                                            Done
                                        </button>

                                        <Link
                                            :href="`/regular-trips/${trip.id}`"
                                            class="inline-flex h-8 items-center gap-1 rounded-lg border border-[#c5def5] bg-white px-3 text-xs font-semibold text-[#1b7ad8] hover:bg-[#e8f4fc] dark:bg-[#102036] dark:text-slate-100 dark:border-white/10"
                                        >
                                            <Eye class="size-3.5" />
                                            View
                                        </Link>

                                        <DropdownMenu>
                                            <DropdownMenuTrigger as-child>
            <button
                type="button"
                                                    class="inline-flex size-8 items-center justify-center rounded-lg border border-[#c5def5] bg-white text-[#1b7ad8] hover:bg-[#e8f4fc] dark:bg-[#102036] dark:text-slate-100 dark:border-white/10"
                                                    aria-label="Actions"
                                                    :disabled="
                                                        statusUpdatingId ===
                                                        trip.id
                                                    "
                                                >
                                                    <EllipsisVertical
                                                        class="size-4"
                                                    />
            </button>
                                            </DropdownMenuTrigger>

                                            <DropdownMenuContent
                                                align="end"
                                                class="z-[80] min-w-[180px] rounded-xl border-[#d7e8f6] p-1.5"
                                            >

                                                <DropdownMenuItem
                                                    class="cursor-pointer gap-2 rounded-lg px-2.5 py-2 focus:bg-[#e8f4fc]"
                                                    @click="updateTripStatus(trip, 'scheduled')"
                                                >
                                                    <RotateCcw class="size-3.5 text-[#1b7ad8]" />
                                                    Back to Rotation
                                                </DropdownMenuItem>

                                                <DropdownMenuItem
                                                    class="cursor-pointer gap-2 rounded-lg px-2.5 py-2 focus:bg-[#e8f8ef]"
                                                    @click="updateTripStatus(trip, 'off_duty', { duty_note: 'completed_today' })"
                                                >
                                                    <CircleCheck class="size-3.5 text-[#16a34a]" />
                                                    Mark as Completed
                                                </DropdownMenuItem>

                                                <DropdownMenuItem
                                                    class="cursor-pointer gap-2 rounded-lg px-2.5 py-2 focus:bg-[#fff1f2]"
                                                    @click="endingDutyTrip = trip"
                                                >
                                                    <Ban class="size-3.5 text-[#ef4444]" />
                                                    Off Duty
                                                </DropdownMenuItem>

                                            </DropdownMenuContent>
                                        </DropdownMenu>

        </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>

        </template>
    </div>

    <!-- ================================= -->
    <!-- ADD / EDIT BOAT MODAL -->
    <!-- ================================= -->

    <AddRegularTripModal
        :open="addOpen"
        :trip="editingTrip"
        :boats="modalBoats"
        :trips="trips"
        :default-date="dateFilter"
        :default-route="addDefaultRoute"
        default-schedule-mode="rush"
        :day-has-lineup="goingTrips.length > 0"
        :organization-routes="organizationRoutes"
        @update:open="onAddOpenChange"
    />

    <!-- ================================= -->
    <!-- ADD RETURN PASSENGERS MODAL -->
    <!-- ================================= -->

    <AddTripPassengerModal
        v-if="encodingTrip"
        :open="Boolean(encodingTrip)"
        :trip-id="encodingTrip.id"
        :remaining-seats="remainingSeats(encodingTrip)"
        :capacity="encodingTrip.boat.capacity"
        :passenger-count="encodingTrip.passenger_count"
        :fares="encodingTrip.fares"
        :boarding-started-at-label="encodingTrip.boarding?.started_at_label"
        from="rotation"
        @update:open="
            (open) => !open && (encodingTripId = null)
        "
    />

    <!-- ================================= -->
    <!-- REMOVE BOAT CONFIRMATION -->
    <!-- ================================= -->

    <Dialog
        :open="Boolean(deletingTrip)"
        @update:open="
            (open) => !open && cancelDelete()
        "
    >
        <DialogContent
            v-if="deletingTrip"
            :show-close-button="false"
            class="max-w-md rounded-2xl border-none p-0 shadow-2xl"
        >
            <div
                class="bg-[#1b7ad8] px-5 py-4 text-white"
            >
                <DialogTitle
                    class="text-lg font-semibold text-white"
                >
                    Remove boat
                </DialogTitle>

                <DialogDescription
                    class="text-sm text-white/80"
                >
                    This takes the boat off today's going
                    and return lineup.
                </DialogDescription>
            </div>

            <div class="space-y-4 p-5">

                <p class="text-sm text-ink">
                    Remove

                    <strong>
                        {{ deletingTrip.boat.name }}
                    </strong>

                    from this date?

                    Passengers on this trip will also be
                    deleted.
                </p>

                <div
                    class="flex justify-end gap-3"
                >
                    <Button
                        type="button"
                        variant="outline"
                        class="h-10 rounded-xl border-[#1b7ad8] px-5 text-[#1b7ad8] hover:bg-[#e8f4fc]"
                        :disabled="deleting"
                        @click="cancelDelete"
                    >
                        Cancel
                    </Button>

                    <Button
                        type="button"
                        class="h-10 rounded-xl bg-rose-600 px-5 text-white hover:bg-rose-700"
                        :disabled="deleting"
                        @click="deleteTrip"
                    >
                        Remove
                    </Button>
                </div>
            </div>
        </DialogContent>
    </Dialog>

    <!-- ================================= -->
    <!-- DEPART CONFIRMATION -->
    <!-- ================================= -->

    <Dialog
        :open="Boolean(departingTrip)"
        @update:open="
            (open) => !open && (departingTrip = null)
        "
    >
        <DialogContent
            v-if="departingTrip"
            :show-close-button="false"
            class="max-w-md rounded-2xl border-none p-0 shadow-2xl"
        >
            <div
                class="bg-[#1b7ad8] px-5 py-4 text-white"
            >
                <DialogTitle
                    class="text-lg font-semibold text-white"
                >
                    Confirm departure
                </DialogTitle>

                <DialogDescription
                    class="text-sm text-white/80"
                >
                    Record the actual departure time for this boat.
                </DialogDescription>
            </div>

            <div class="space-y-4 p-5">
                <dl class="space-y-2 text-sm">
                    <div class="flex justify-between gap-3">
                        <dt class="text-ink-muted">Boat</dt>
                        <dd class="font-semibold text-[#0f4c8a]">
                            {{ departingTrip.boat.name }}
                        </dd>
                    </div>
                    <div class="flex justify-between gap-3">
                        <dt class="text-ink-muted">Operator</dt>
                        <dd class="text-right font-medium">
                            {{
                                departingTrip.boat.operator_name ||
                                'Unassigned operator'
                            }}
                        </dd>
                    </div>
                    <div class="flex justify-between gap-3">
                        <dt class="text-ink-muted">Passengers</dt>
                        <dd class="font-semibold">
                            {{ departingTrip.passenger_count }}
                            /
                            {{ departingTrip.boat.capacity }}
                        </dd>
                    </div>
                </dl>

                <p class="text-xs text-ink-muted">
                    The passenger list will be locked after departure.
                    Vacant seats are allowed.
                </p>

                <div
                    class="flex justify-end gap-3"
                >
                    <Button
                        type="button"
                        variant="outline"
                        class="h-10 rounded-xl"
                        @click="
                            departingTrip = null
                        "
                    >
                        Cancel
                    </Button>

                    <Button
                        type="button"
                        class="h-10 rounded-xl bg-emerald-600 text-white hover:bg-emerald-700"
                        :disabled="
                            statusUpdatingId ===
                            departingTrip.id
                        "
                        @click="confirmDepart"
                    >
                        Depart
                    </Button>
                </div>
            </div>
        </DialogContent>
    </Dialog>

    <!-- ================================= -->
    <!-- NEXT TRIP CONFIRMATION -->
    <!-- ================================= -->

    <Dialog
        :open="Boolean(completingTrip)"
        @update:open="
            (open) => !open && (completingTrip = null)
        "
    >
        <DialogContent
            v-if="completingTrip"
            :show-close-button="false"
            class="max-w-md rounded-2xl border-none p-0 shadow-2xl"
        >
            <div
                class="bg-[#1b7ad8] px-5 py-4 text-white"
            >
                <DialogTitle
                    class="text-lg font-semibold text-white"
                >
                    Another trip today?
                </DialogTitle>

                <DialogDescription
                    class="text-sm text-white/80"
                >
                    Would you like to continue your duty?
                </DialogDescription>
            </div>

            <div class="space-y-4 p-5">
                <p class="text-sm text-ink">
                    <strong>{{ completingTrip.boat.name }}</strong>
                    finished one round trip
                    ({{ completingTrip.passenger_count }} passengers).
                    Continue rotation sends the boat to the last
                    position. End duty keeps the boat on the list as Completed.
                </p>

                <div
                    class="flex flex-wrap justify-end gap-3"
                >

                    <Button
                        type="button"
                        variant="outline"
                        class="h-10 rounded-xl"
                        @click="
                            completingTrip = null
                        "
                    >
                        Cancel
                    </Button>

                    <Button
                        type="button"
                        variant="outline"
                        class="h-10 rounded-xl border-slate-300"
                        :disabled="
                            statusUpdatingId ===
                            completingTrip.id
                        "
                        @click="
                            updateTripStatus(
                                completingTrip,
                                'completed',
                                {
                                    action: 'complete',
                                    return_to_queue: false,
                                },
                            )
                        "
                    >
                        End Duty
                    </Button>

                    <Button
                        type="button"
                        class="h-10 rounded-xl bg-emerald-600 text-white hover:bg-emerald-700"
                        :disabled="
                            statusUpdatingId ===
                            completingTrip.id
                        "
                        @click="
                            updateTripStatus(
                                completingTrip,
                                'completed',
                                {
                                    action: 'complete',
                                    return_to_queue: true,
                                },
                            )
                        "
                    >
                        Next Trip
                    </Button>

                </div>
            </div>
        </DialogContent>
    </Dialog>

    <!-- ================================= -->
    <!-- START RETURN BOARDING -->
    <!-- ================================= -->

    <Dialog
        :open="Boolean(arrivingTrip)"
        @update:open="
            (open) => !open && (arrivingTrip = null)
        "
    >
        <DialogContent
            v-if="arrivingTrip"
            :show-close-button="false"
            class="max-w-md rounded-2xl border-none p-0 shadow-2xl"
        >
            <div class="bg-[#1b7ad8] px-5 py-4 text-white">
                <DialogTitle class="text-lg font-semibold text-white">
                    Arrive at the origin
                </DialogTitle>
                <DialogDescription class="text-sm text-white/80">
                    Record that {{ arrivingTrip.boat.name }} has arrived at the origin.
                </DialogDescription>
            </div>
            <div class="space-y-4 p-5">
                <p class="text-sm text-ink">
                    Add the passengers who rode home, then complete the trip.
                </p>
                <div class="flex justify-end gap-3">
                    <Button
                        type="button"
                        variant="outline"
                        class="h-10 rounded-xl"
                        @click="arrivingTrip = null"
                    >
                        Cancel
                    </Button>
                    <Button
                        type="button"
                        class="h-10 rounded-xl bg-violet-600 text-white hover:bg-violet-700"
                        :disabled="statusUpdatingId === arrivingTrip.id"
                        @click="
                            updateTripStatus(arrivingTrip, 'on_trip', {
                                action: 'arrive',
                            })
                        "
                    >
                        Arrive at the origin
                    </Button>
                </div>
            </div>
        </DialogContent>
    </Dialog>

    <!-- ================================= -->
    <!-- END DUTY -->
    <!-- ================================= -->

    <Dialog
        :open="Boolean(endingDutyTrip)"
        @update:open="
            (open) => !open && (endingDutyTrip = null)
        "
    >
        <DialogContent
            v-if="endingDutyTrip"
            :show-close-button="false"
            class="max-w-md rounded-2xl border-none p-0 shadow-2xl"
        >
            <div class="bg-[#1b7ad8] px-5 py-4 text-white">
                <DialogTitle class="text-lg font-semibold text-white">
                    Off Duty
                </DialogTitle>
                <DialogDescription class="text-sm text-white/80">
                    Remove this boat from today's rotation queue.
                </DialogDescription>
            </div>
            <div class="space-y-4 p-5">
                <p class="text-sm text-ink">
                    Set
                    <strong>{{ endingDutyTrip.boat.name }}</strong>
                    to Off Duty?
                    The operator will be taken out of the active rotation.
                </p>
                <div class="flex justify-end gap-3">
                    <Button
                        type="button"
                        variant="outline"
                        class="h-10 rounded-xl"
                        @click="endingDutyTrip = null"
                    >
                        Cancel
                    </Button>
                    <Button
                        type="button"
                        class="h-10 rounded-xl bg-rose-600 text-white hover:bg-rose-700"
                        :disabled="statusUpdatingId === endingDutyTrip.id"
                        @click="
                            updateTripStatus(endingDutyTrip, 'off_duty', { duty_note: 'off_duty' })
                        "
                    >
                        Off Duty
                    </Button>
                </div>
            </div>
        </DialogContent>
    </Dialog>

</template>
