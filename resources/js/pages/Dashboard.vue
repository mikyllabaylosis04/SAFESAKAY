
<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import {
    Activity,
    AlertTriangle,
    ArrowRight,
    Bell,
    CalendarDays,
    CheckCircle2,
    Clock3,
    RefreshCw,
    Route,
    Ship,
    Users,
    Wallet,
    X,
} from '@lucide/vue';
import { computed, onMounted, onUnmounted, ref } from 'vue';
import StatCards from '@/components/admin/StatCards.vue';
import type { StatCardItem } from '@/components/admin/StatCards.vue';
import WeatherAlertBanner from '@/components/admin/WeatherAlertBanner.vue';

import DashboardTripAnalytics from '@/components/DashboardTripAnalytics.vue';
import type { TripAnalyticsDay } from '@/components/DashboardTripAnalytics.vue';

import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogTitle,
} from '@/components/ui/dialog';

import { dashboard } from '@/routes';

type LiveStatus =
    | 'on_trip'
    | 'waiting'
    | 'completed'
    | 'scheduled'
    | 'cancelled'
    | 'passenger_no_show'
    | 'approved'
    | 'pending';

type LiveOperation = {
    id: string;
    trip_id: number;
    source: 'regular' | 'rental';
    number: number;
    boat_name: string;
    boat_code: string;
    operator_name: string;
    trip_type: 'Regular' | 'Rental';
    route: string;
    status: LiveStatus;
    status_label: string;
    departure: string;
    passenger_count: number;
    href: string;
};

type RotationCard = {
    id: number;
    boat_name: string;
    boat_code: string;
    route: string;
    departure_time: string;
    status: LiveStatus;
    status_label: string;
    href: string;
};

type PrivateBookingRow = {
    id: number;
    number: number;
    customer: string;
    contact_number: string | null;
    destination: string;
    time: string;
    date_label: string;
    status: LiveStatus;
    status_label: string;
    boat_name: string;
    boat_code: string;
    operator_name: string;
    passengers: number;
    payment_status: string;
    payment_status_label: string;
    href: string;
};

type RecentActivity = {
    id: string;
    text: string;
    at: string;
    tone: 'success' | 'pending' | 'info' | 'alert';
};

type WeatherAlert = {
    id?: number;
    code?: string;
    headline: string;
    reason_label: string;
    message: string;
    affected_date_label: string;
    affected_routes_label: string;
    duration_label: string;
};

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Dashboard',
                href: dashboard(),
            },
        ],
    },
});

const props = defineProps<{
    stats?: {
        total_boats: number;
        available_boats: number;
        todays_regular_trips: number;
        completed_regular_trips: number;
        todays_passengers: number;
        todays_private_rentals: number;
        completed_private_rentals: number;
        pending_rental_requests: number;
        payments_for_verification: number;
    };

    liveOperations: LiveOperation[];

    actionRequired: {
        pending_rentals: number;
        payments_for_verification: number;
        reported_issues: number;
    };

    rotation: {
        current: RotationCard | null;
        next: RotationCard | null;
        upcoming: RotationCard | null;
    };

    privateBookings: PrivateBookingRow[];
    tripAnalytics: TripAnalyticsDay[];
    recentActivities: RecentActivity[];
    notifications?: unknown[];

    weatherAlert?: (WeatherAlert & {
        id: number;
        code: string;
        reason: string;
        title: string;
    }) | null;

    weatherWatchAlert?: WeatherAlert | null;
}>();

// ==========================================
// STATE
// ==========================================

const now = ref(new Date());

const viewingLive = ref<LiveOperation | null>(null);
const viewingRental = ref<PrivateBookingRow | null>(null);

let timer: ReturnType<typeof setInterval> | undefined;

onMounted(() => {
    timer = setInterval(() => {
        now.value = new Date();
    }, 30000);
});

onUnmounted(() => {
    if (timer) {
        clearInterval(timer);
    }
});

// ==========================================
// SHARED STYLES
// ==========================================

const panelClass =
    'overflow-hidden rounded-xl border border-[#dce8f3] bg-white shadow-[0_2px_8px_rgba(15,76,138,0.04)] dark:border-white/10 dark:bg-slate-900';

const headingClass =
    'text-sm font-bold text-ink dark:text-sky-100';

const mutedClass =
    'text-xs text-ink-muted dark:text-slate-400';

const linkClass =
    'inline-flex items-center gap-1 text-xs font-semibold text-[#1b7ad8] transition hover:text-[#0f5db4] hover:underline';

const tableHeadClass =
    'bg-[#f5f9fd] text-[11px] font-semibold uppercase tracking-wide text-ink-muted dark:bg-white/5 dark:text-slate-400';

const tableCellClass =
    'px-4 py-3 text-[13px] text-ink dark:text-slate-200';

// ==========================================
// SUMMARY CARDS
// ==========================================

const statsLoading = computed(
    () => props.stats === undefined,
);

const summaryCards = computed<StatCardItem[]>(() => {
    const stats = props.stats;

    return [
        { key: 'boats', title: 'Registered Boats', value: stats?.total_boats ?? 0, hint: `${stats?.available_boats ?? 0} available`, icon: Ship, href: '/boats', tone: 'blue' },
        { key: 'regular', title: "Today's Regular Trips", value: stats?.todays_regular_trips ?? 0, hint: `${stats?.completed_regular_trips ?? 0} completed`, icon: Route, href: '/schedules/regular-trip-rotation', tone: 'green' },
        { key: 'passengers', title: 'Passengers Today', value: stats?.todays_passengers ?? 0, hint: 'Across today’s trips', icon: Users, href: '/reports', tone: 'violet' },
        { key: 'rentals', title: "Today's Private Rentals", value: stats?.todays_private_rentals ?? 0, hint: `${stats?.completed_private_rentals ?? 0} completed`, icon: CalendarDays, href: '/schedules/boat-booking-schedule', tone: 'indigo' },
    ];
});

// ==========================================
// ACTION REQUIRED
// ==========================================

const pendingActions = computed(() => [
    {
        title: 'Rental Requests',
        description: 'Awaiting approval',
        count: props.actionRequired.pending_rentals,
        href: '/bookings/requests',
        icon: CalendarDays,
        tone: 'text-orange-600',
        background: 'bg-orange-50',
    },
    {
        title: 'Payments for Verification',
        description: 'Payments to review',
        count: props.actionRequired.payments_for_verification,
        href: '/payments',
        icon: Wallet,
        tone: 'text-amber-600',
        background: 'bg-amber-50',
    },
    {
        title: 'Reported Trip Issues',
        description: 'Issues to review',
        count: props.actionRequired.reported_issues,
        href: '/boats',
        icon: AlertTriangle,
        tone: 'text-red-600',
        background: 'bg-red-50',
    },
]);

const totalPendingActions = computed(() =>
    pendingActions.value.reduce(
        (total, action) => total + action.count,
        0,
    ),
);

// ==========================================
// STATUS HELPERS
// ==========================================

function statusTone(status: LiveStatus): string {
    const tones: Record<LiveStatus, string> = {
        on_trip: 'bg-emerald-50 text-emerald-700',
        waiting: 'bg-amber-50 text-amber-700',
        completed: 'bg-blue-50 text-blue-700',
        scheduled: 'bg-slate-100 text-slate-600',
        cancelled: 'bg-red-50 text-red-700',
        passenger_no_show: 'bg-red-50 text-red-700',
        approved: 'bg-blue-50 text-blue-700',
        pending: 'bg-amber-50 text-amber-700',
    };

    return tones[status] ?? 'bg-slate-100 text-slate-600';
}

const activityTone = {
    success: 'bg-emerald-500',
    pending: 'bg-amber-500',
    info: 'bg-blue-500',
    alert: 'bg-red-500',
};

function timeAgo(iso: string): string {
    const timestamp = new Date(iso).getTime();

    if (Number.isNaN(timestamp)) {
        return '—';
    }

    const seconds = Math.max(
        0,
        Math.floor(
            (now.value.getTime() - timestamp) / 1000,
        ),
    );

    if (seconds < 60) {
        return 'Just now';
    }

    if (seconds < 3600) {
        const minutes = Math.floor(seconds / 60);

        return `${minutes} ${minutes === 1 ? 'min' : 'mins'} ago`;
    }

    if (seconds < 86400) {
        const hours = Math.floor(seconds / 3600);

        return `${hours} ${hours === 1 ? 'hour' : 'hours'} ago`;
    }

    const days = Math.floor(seconds / 86400);

    return `${days} ${days === 1 ? 'day' : 'days'} ago`;
}

// ==========================================
// ROTATION
// ==========================================

const rotationSlots = computed(() => [
    {
        key: 'current',
        title: 'Current Trip',
        card: props.rotation.current,
        empty: 'No ongoing trip',
        badge: 'bg-emerald-50 text-emerald-700',
    },
    {
        key: 'next',
        title: 'Next in Line',
        card: props.rotation.next,
        empty: 'No boat waiting',
        badge: 'bg-blue-50 text-blue-700',
    },
    {
        key: 'upcoming',
        title: 'Upcoming',
        card: props.rotation.upcoming,
        empty: 'No upcoming trip',
        badge: 'bg-slate-100 text-slate-600',
    },
]);

// ==========================================
// DISPLAY LIMITS
// ==========================================

const displayedOperations = computed(() =>
    props.liveOperations
        .filter((row) => row.source === 'regular')
        .slice(0, 6),
);

const regularOperationCount = computed(
    () => props.liveOperations.filter((row) => row.source === 'regular').length,
);

const openActions = computed(() =>
    pendingActions.value.filter((action) => action.count > 0),
);

const displayedRentals = computed(() =>
    props.privateBookings.slice(0, 5),
);

const displayedActivities = computed(() =>
    props.recentActivities.slice(0, 5),
);

// ==========================================
// MODAL HELPERS
// ==========================================

function closeLiveModal() {
    viewingLive.value = null;
}

function closeRentalModal() {
    viewingRental.value = null;
}
</script>

<template>
    <Head title="Admin Dashboard" />

    <div
        class="flex min-w-0 flex-1 flex-col gap-5 bg-[#f4f7fb] p-4 lg:p-6 dark:bg-transparent"
    >
        <!-- PAGE HEADER -->
        <header
            class="flex flex-wrap items-center justify-between gap-3"
        >
            <div>
                <h1
                    class="text-2xl font-bold tracking-tight text-[#0f4c8a] dark:text-sky-100"
                >
                    Dashboard
                </h1>

                <p
                    class="mt-1 text-sm text-ink-muted dark:text-slate-400"
                >
                    What is happening today, and what needs you.
                </p>
            </div>

            <div
                class="inline-flex items-center gap-2 rounded-lg border border-[#dce8f3] bg-white px-3 py-2 text-xs font-semibold text-ink-soft dark:border-white/10 dark:bg-slate-900 dark:text-slate-200"
            >
                <CalendarDays class="size-4 text-[#1b7ad8]" />
                Today
            </div>
        </header>

        <!-- WEATHER SUSPENSION ALERT -->
        <WeatherAlertBanner v-if="weatherAlert" :alert="weatherAlert" variant="hold" context="dashboard" can-lift />

        <!-- WEATHER WATCH -->
        <WeatherAlertBanner v-if="weatherWatchAlert" :alert="weatherWatchAlert" variant="watch" context="dashboard" />

        <!-- SUMMARY CARDS -->
        <section aria-label="Today's summary">
            <StatCards :items="summaryCards" :loading="statsLoading" />
        </section>

        <!-- WHAT NEEDS YOU -->
        <section :class="panelClass">
            <div class="flex items-center justify-between gap-3 border-b border-[#eaf0f6] px-4 py-3.5 dark:border-white/10">
                <div class="flex items-center gap-2.5">
                    <div class="flex size-9 items-center justify-center rounded-lg bg-red-50 text-red-600">
                        <Bell class="size-4" />
                    </div>
                    <div>
                        <h2 :class="headingClass">Needs you</h2>
                        <p :class="mutedClass">Open these first. Everything else can wait.</p>
                    </div>
                </div>
                <span
                    class="rounded-full px-2.5 py-1 text-xs font-bold"
                    :class="totalPendingActions > 0 ? 'bg-red-50 text-red-600' : 'bg-emerald-50 text-emerald-700'"
                >
                    {{ totalPendingActions }}
                </span>
            </div>
            <div v-if="openActions.length === 0" class="flex items-center gap-2 px-4 py-4 text-sm text-emerald-700">
                <CheckCircle2 class="size-4" />
                Nothing is waiting. Rentals, payments, and reported issues are clear.
            </div>
            <div v-else class="grid gap-3 p-3 sm:grid-cols-3">
                <Link
                    v-for="action in openActions"
                    :key="action.title"
                    :href="action.href"
                    class="flex items-center gap-3 rounded-xl border border-[#e9eff5] px-3 py-3 transition hover:bg-[#f7fbff] dark:border-white/10 dark:hover:bg-white/5"
                >
                    <div class="flex size-9 shrink-0 items-center justify-center rounded-lg" :class="[action.background, action.tone]">
                        <component :is="action.icon" class="size-4" />
                    </div>
                    <div class="min-w-0 flex-1">
                        <p class="text-[13px] font-semibold text-ink dark:text-slate-100">{{ action.title }}</p>
                        <p :class="mutedClass">{{ action.description }}</p>
                    </div>
                    <span class="min-w-7 rounded-full px-2 py-1 text-center text-xs font-bold" :class="[action.background, action.tone]">
                        {{ action.count }}
                    </span>
                </Link>
            </div>
        </section>

        <!-- TODAY -->
        <div class="grid min-w-0 items-start gap-4 xl:grid-cols-1">
            <section :class="[panelClass, 'min-w-0']">
                <div
                    class="flex flex-wrap items-center justify-between gap-3 border-b border-[#eaf0f6] px-4 py-3.5 dark:border-white/10"
                >
                    <div class="flex items-center gap-2.5">
                        <div
                            class="flex size-9 items-center justify-center rounded-lg bg-[#e8f1fd] text-[#1b7ad8]"
                        >
                            <Ship class="size-4" />
                </div>

                        <div>
                            <h2 :class="headingClass">
                                Regular trips today
                            </h2>

                            <p :class="mutedClass">
                                Going and return trips only. Rentals are in the next section.
                            </p>
            </div>
        </div>

                    <span
                        class="rounded-full bg-[#edf4fc] px-2.5 py-1 text-xs font-semibold text-ink-soft"
                    >
                        {{ regularOperationCount }}
                        trips
                    </span>
                </div>

                <div
                    v-if="regularOperationCount === 0"
                    class="px-5 py-12 text-center"
                >
                    <Ship
                        class="mx-auto size-9 text-[#b5cce2]"
                    />

                    <p
                        class="mt-3 text-sm font-semibold text-ink-soft dark:text-sky-100"
                    >
                        No regular trips today
                    </p>

                    <p
                        class="mt-1 text-xs text-ink-muted"
                    >
                        Boats in the rotation will show up here.
                    </p>
                </div>

                <div v-else class="overflow-x-auto">
                    <table
                        class="w-full min-w-[570px] text-left"
                    >
                        <thead :class="tableHeadClass">
                            <tr>
                                <th class="px-4 py-3">
                                    Boat / Trip
                                </th>
                                <th class="px-4 py-3">
                                    Route
                                </th>
                                <th class="px-4 py-3">
                                    Status
                                </th>
                                <th class="px-4 py-3">
                                    Departure
                                </th>
                                <th class="px-4 py-3 text-right">
                                    Action
                                </th>
                            </tr>
                        </thead>

                        <tbody>
                            <tr
                                v-for="row in displayedOperations"
                                :key="row.id"
                                class="border-t border-[#edf2f7] transition hover:bg-[#f8fbff] dark:border-white/10 dark:hover:bg-white/5"
                            >
                                <td :class="tableCellClass">
                                    <p class="font-semibold">
                                        {{ row.boat_name }}
                                    </p>

                                    <p
                                        class="mt-0.5 text-[11px] text-ink-muted"
                                    >
                                        {{ row.trip_type }}
                                        · {{ row.boat_code }}
                                    </p>
                                </td>

                                <td :class="tableCellClass">
                                    {{ row.route }}
                                </td>

                                <td :class="tableCellClass">
                                    <span
                                        class="inline-flex rounded-full px-2.5 py-1 text-[11px] font-semibold"
                                        :class="statusTone(row.status)"
                                    >
                                        {{ row.status_label }}
                                    </span>
                                </td>

                                <td :class="tableCellClass">
                                    {{ row.departure }}
                                </td>

                                <td
                                    class="px-4 py-3 text-right"
                                >
                                    <button
                                        type="button"
                                        class="rounded-lg border border-[#bcd9f5] px-3 py-1.5 text-xs font-semibold text-[#1b7ad8] transition hover:bg-[#e8f1fd]"
                                        @click="viewingLive = row"
                                    >
                                        View
                                    </button>
                                </td>
                            </tr>
                        </tbody>
                    </table>

                    <div
                        v-if="regularOperationCount > displayedOperations.length"
                        class="border-t border-[#edf2f7] px-4 py-3 text-center text-xs text-ink-muted dark:border-white/10"
                    >
                        Showing
                        {{ displayedOperations.length }}
                        of
                        {{ regularOperationCount }}
                        trips
        </div>
                </div>
            </section>
            </div>

        <!-- ROTATION -->
        <section :class="panelClass">
                <div
                    class="flex flex-wrap items-center justify-between gap-3 border-b border-[#eaf0f6] px-4 py-3.5 dark:border-white/10"
                >
                    <div class="flex items-center gap-2.5">
                        <div
                            class="flex size-9 items-center justify-center rounded-lg bg-[#e8f1fd] text-[#1b7ad8]"
                        >
                            <RefreshCw class="size-4" />
                        </div>

                        <div>
                            <h2 :class="headingClass">
                                Regular Trip Rotation
                            </h2>

                            <p :class="mutedClass">
                                Who is out now, who is next, and who is coming up
                            </p>
                        </div>
            </div>

                    <Link
                        href="/schedules/regular-trip-rotation"
                        :class="linkClass"
                    >
                        View Schedule
                        <ArrowRight class="size-3.5" />
                    </Link>
                </div>

                <div
                    class="grid gap-3 p-4 sm:grid-cols-3"
                >
                    <div
                        v-for="slot in rotationSlots"
                        :key="slot.key"
                        class="min-w-0 rounded-lg border border-[#e7eef5] bg-[#fbfdff] p-3 dark:border-white/10 dark:bg-white/5"
                    >
                                    <span
                            class="inline-flex rounded-full px-2 py-1 text-[11px] font-bold uppercase"
                            :class="slot.badge"
                                    >
                            {{ slot.title }}
                                    </span>

                        <template v-if="slot.card">
                            <p
                                class="mt-3 truncate text-sm font-bold text-ink dark:text-sky-100"
                                :title="slot.card.boat_name"
                            >
                                {{ slot.card.boat_name }}
                            </p>

                            <p
                                class="mt-1 text-xs text-ink-muted"
                            >
                                {{ slot.card.route }}
                            </p>

                            <p
                                class="mt-1 text-xs text-ink-muted"
                            >
                                {{ slot.card.departure_time }}
                            </p>

                                    <span
                                class="mt-3 inline-flex rounded-full px-2 py-1 text-[11px] font-semibold"
                                :class="statusTone(slot.card.status)"
                                    >
                                {{ slot.card.status_label }}
                                    </span>
                        </template>

                        <p v-else class="mt-3 text-xs text-ink-muted">
                            {{ slot.empty }}
                        </p>
                    </div>
            </div>
        </section>

        <div class="grid min-w-0 items-start gap-4 xl:grid-cols-5">
            <!-- PRIVATE RENTAL SCHEDULE -->
            <section :class="[panelClass, 'xl:col-span-3']">
            <div
                    class="flex flex-wrap items-center justify-between gap-3 border-b border-[#eaf0f6] px-4 py-3.5 dark:border-white/10"
            >
                    <div class="flex items-center gap-2.5">
                    <div
                            class="flex size-9 items-center justify-center rounded-lg bg-[#e8f1fd] text-[#1b7ad8]"
                    >
                            <CalendarDays class="size-4" />
                    </div>

                    <div>
                            <h2 :class="headingClass">
                                Private Rental Schedule
                        </h2>

                            <p :class="mutedClass">
                                Exclusive boat rentals for today, separate from the regular queue
                            </p>
                    </div>
                </div>

                <div class="flex items-center gap-3">
                    <span class="rounded-full bg-[#edf4fc] px-2.5 py-1 text-xs font-semibold text-ink-soft">
                        {{ privateBookings.length }}
                    </span>
                    <Link
                        href="/schedules/boat-booking-schedule"
                        :class="linkClass"
                    >
                        View All
                        <ArrowRight class="size-3.5" />
                    </Link>
                </div>
            </div>

                <div
                    v-if="privateBookings.length === 0"
                    class="px-4 py-12 text-center"
                >
                    <CalendarDays
                        class="mx-auto size-9 text-[#b5cce2]"
                    />

                    <p
                        class="mt-3 text-sm font-semibold text-ink-soft dark:text-sky-100"
                    >
                        No private rentals today
                    </p>

                    <p
                        class="mt-1 text-xs text-ink-muted"
                    >
                        Scheduled rentals will appear here.
                </p>
            </div>

            <div v-else class="overflow-x-auto">
                    <table
                        class="w-full min-w-[490px] text-left"
                    >
                        <thead :class="tableHeadClass">
                            <tr>
                                <th class="px-4 py-3">
                                    Passenger
                                </th>
                                <th class="px-4 py-3">
                                    Destination
                                </th>
                                <th class="px-4 py-3">
                                    Time
                                </th>
                                <th class="px-4 py-3">
                                    Status
                                </th>
                                <th class="px-4 py-3 text-right">
                                    Action
                                </th>
                        </tr>
                    </thead>

                    <tbody>
                        <tr
                                v-for="booking in displayedRentals"
                            :key="booking.id"
                                class="border-t border-[#edf2f7] hover:bg-[#f8fbff] dark:border-white/10 dark:hover:bg-white/5"
                            >
                                <td :class="tableCellClass">
                                    <p class="font-semibold">
                                        {{ booking.customer }}
                                    </p>

                                    <p
                                        class="mt-0.5 text-[11px] text-ink-muted"
                                    >
                                    {{ booking.boat_name }}
                                </p>
                            </td>

                                <td :class="tableCellClass">
                                {{ booking.destination }}
                            </td>

                                <td :class="tableCellClass">
                                    {{ booking.time }}
                            </td>

                                <td :class="tableCellClass">
                                <span
                                        class="inline-flex rounded-full px-2 py-1 text-[11px] font-semibold"
                                        :class="statusTone(booking.status)"
                                >
                                    {{ booking.status_label }}
                                </span>
                            </td>

                                <td class="px-4 py-3 text-right">
                                    <button
                                        type="button"
                                        class="rounded-lg border border-[#bcd9f5] px-3 py-1.5 text-xs font-semibold text-[#1b7ad8] hover:bg-[#e8f1fd]"
                                        @click="viewingRental = booking"
                                    >
                                        View
                                    </button>
                                </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

            <!-- RECENT ACTIVITIES -->
            <section :class="[panelClass, 'xl:col-span-2']">
                <div
                    class="flex flex-wrap items-center justify-between gap-3 border-b border-[#eaf0f6] px-4 py-3.5 dark:border-white/10"
                >
                    <div class="flex items-center gap-2.5">
                        <div
                            class="flex size-9 items-center justify-center rounded-lg bg-[#e8f1fd] text-[#1b7ad8]"
                        >
                            <Activity class="size-4" />
                        </div>

                        <div>
                            <h2 :class="headingClass">
                                Recent Activities
                            </h2>

                            <p :class="mutedClass">
                                Latest trip and booking updates
                            </p>
                        </div>
                    </div>

                    <Link
                        href="/notifications"
                        :class="linkClass"
                    >
                        View All
                        <ArrowRight class="size-3.5" />
                    </Link>
                </div>

                <div
                    v-if="recentActivities.length === 0"
                    class="px-4 py-12 text-center"
                >
                    <Clock3
                        class="mx-auto size-9 text-[#b5cce2]"
                    />

                    <p
                        class="mt-3 text-sm font-semibold text-ink-soft dark:text-sky-100"
                    >
                        No recent activity
                    </p>

                    <p
                        class="mt-1 text-xs text-ink-muted"
                    >
                        New updates will appear here.
                    </p>
                </div>

                <ul
                    v-else
                    class="divide-y divide-[#edf2f7] dark:divide-white/10"
                >
                    <li
                        v-for="item in displayedActivities"
                        :key="item.id"
                        class="flex items-start gap-3 px-4 py-3.5"
                    >
                        <span
                            class="mt-1.5 size-2.5 shrink-0 rounded-full"
                            :class="activityTone[item.tone]"
                        />

                        <p
                            class="min-w-0 flex-1 text-[13px] leading-relaxed text-ink dark:text-slate-200"
                        >
                            {{ item.text }}
                        </p>

                        <span
                            class="shrink-0 text-[11px] text-ink-muted"
                        >
                                {{ timeAgo(item.at) }}
                            </span>
                    </li>
                </ul>
            </section>
        </div>

        <section :class="panelClass">
            <DashboardTripAnalytics :days="tripAnalytics" />
        </section>
    </div>

    <!-- ===================================== -->
    <!-- LIVE OPERATION DETAILS MODAL -->
    <!-- ===================================== -->

    <Dialog
        :open="!!viewingLive"
        @update:open="
            (open) => {
                if (!open) closeLiveModal();
            }
        "
    >
        <DialogContent
            v-if="viewingLive"
            :show-close-button="false"
            class="max-h-[90vh] w-full max-w-[calc(100%-1.5rem)] gap-0 overflow-hidden rounded-2xl border-none p-0 sm:max-w-lg"
        >
            <div
                class="flex items-center justify-between bg-[#1b7ad8] px-5 py-4 text-white"
            >
                <div>
                    <DialogTitle
                        class="text-lg font-bold text-white"
                    >
                        Trip Details
                    </DialogTitle>

                    <DialogDescription
                        class="mt-0.5 text-xs text-white/80"
                    >
                        Today's boat operation
                    </DialogDescription>
                    </div>

                <button
                    type="button"
                    class="rounded-lg p-1.5 text-white hover:bg-white/15"
                    aria-label="Close"
                    @click="closeLiveModal"
                >
                    <X class="size-5" />
                </button>
                </div>

            <div
                class="max-h-[65vh] space-y-4 overflow-y-auto bg-white p-5 dark:bg-slate-900"
                    >
                        <div
                    class="rounded-xl bg-[#f0f7ff] p-4"
                >
                    <p
                        class="text-base font-bold text-ink"
                    >
                        {{ viewingLive.boat_name }}
                    </p>

                    <p
                        class="mt-1 text-xs text-ink-muted"
                    >
                        {{ viewingLive.boat_code }}
                        · {{ viewingLive.trip_type }} Trip
                    </p>
                        </div>

                <dl class="grid grid-cols-2 gap-4">
                    <div>
                        <dt :class="mutedClass">
                            Operator
                        </dt>
                        <dd
                            class="mt-1 text-sm font-semibold text-ink dark:text-slate-100"
                        >
                            {{ viewingLive.operator_name }}
                        </dd>
                    </div>

                    <div>
                        <dt :class="mutedClass">
                            Route
                        </dt>
                        <dd
                            class="mt-1 text-sm font-semibold text-ink dark:text-slate-100"
                        >
                            {{ viewingLive.route }}
                        </dd>
                    </div>

                    <div>
                        <dt :class="mutedClass">
                            Departure
                        </dt>
                        <dd
                            class="mt-1 text-sm font-semibold text-ink dark:text-slate-100"
                        >
                            {{ viewingLive.departure }}
                        </dd>
                    </div>

                    <div>
                        <dt :class="mutedClass">
                            Passengers
                        </dt>
                        <dd
                            class="mt-1 text-sm font-semibold text-ink dark:text-slate-100"
                        >
                            {{ viewingLive.passenger_count }}
                        </dd>
                    </div>

                    <div class="col-span-2">
                        <dt :class="mutedClass">
                            Status
                        </dt>

                        <dd class="mt-1">
                            <span
                                class="inline-flex rounded-full px-2.5 py-1 text-xs font-semibold"
                                :class="statusTone(viewingLive.status)"
                            >
                                {{ viewingLive.status_label }}
                        </span>
                        </dd>
                    </div>
                </dl>
            </div>

            <div
                class="flex justify-end gap-2 border-t border-[#e7eef5] bg-white px-5 py-4 dark:border-white/10 dark:bg-slate-900"
            >
                <Button
                    type="button"
                    variant="outline"
                    class="rounded-lg border-[#bcd9f5] text-[#1b7ad8]"
                    @click="closeLiveModal"
                >
                    Close
                </Button>

                <Button
                    as-child
                    class="rounded-lg bg-[#1b7ad8] text-white hover:bg-[#1567c4]"
                >
                    <Link :href="viewingLive.href">
                        Open Full Record
                    </Link>
                </Button>
                </div>
        </DialogContent>
    </Dialog>

    <!-- ===================================== -->
    <!-- PRIVATE RENTAL DETAILS MODAL -->
    <!-- ===================================== -->

    <Dialog
        :open="!!viewingRental"
        @update:open="
            (open) => {
                if (!open) closeRentalModal();
            }
        "
    >
        <DialogContent
            v-if="viewingRental"
            :show-close-button="false"
            class="max-h-[90vh] w-full max-w-[calc(100%-1.5rem)] gap-0 overflow-hidden rounded-2xl border-none p-0 sm:max-w-lg"
        >
            <div
                class="flex items-center justify-between bg-[#1b7ad8] px-5 py-4 text-white"
            >
                <div>
                    <DialogTitle
                        class="text-lg font-bold text-white"
                    >
                        Private Rental Details
                    </DialogTitle>

                    <DialogDescription
                        class="mt-0.5 text-xs text-white/80"
                    >
                        Scheduled rental information
                    </DialogDescription>
            </div>

                <button
                    type="button"
                    class="rounded-lg p-1.5 text-white hover:bg-white/15"
                    aria-label="Close"
                    @click="closeRentalModal"
                >
                    <X class="size-5" />
                </button>
        </div>

            <div
                class="max-h-[65vh] space-y-4 overflow-y-auto bg-white p-5 dark:bg-slate-900"
            >
                <div
                    class="rounded-xl bg-[#f0f7ff] p-4"
                >
                    <p
                        class="text-base font-bold text-ink"
                    >
                        {{ viewingRental.customer }}
                    </p>

                    <p
                        class="mt-1 text-xs text-ink-muted"
                    >
                        {{ viewingRental.date_label }}
                        · {{ viewingRental.time }}
                    </p>
    </div>

                <dl class="grid grid-cols-2 gap-4">
                    <div>
                        <dt :class="mutedClass">
                            Contact Number
                        </dt>
                        <dd
                            class="mt-1 text-sm font-semibold text-ink dark:text-slate-100"
                        >
                            {{
                                viewingRental.contact_number ||
                                '—'
                            }}
                        </dd>
                    </div>

                    <div>
                        <dt :class="mutedClass">
                            Destination
                        </dt>
                        <dd
                            class="mt-1 text-sm font-semibold text-ink dark:text-slate-100"
                        >
                            {{ viewingRental.destination }}
                        </dd>
                    </div>

                    <div>
                        <dt :class="mutedClass">
                            Assigned Boat
                        </dt>
                        <dd
                            class="mt-1 text-sm font-semibold text-ink dark:text-slate-100"
                        >
                            {{ viewingRental.boat_name }}
                        </dd>

                        <p :class="mutedClass">
                            {{ viewingRental.boat_code }}
                        </p>
                    </div>

                    <div>
                        <dt :class="mutedClass">
                            Operator
                        </dt>
                        <dd
                            class="mt-1 text-sm font-semibold text-ink dark:text-slate-100"
                        >
                            {{ viewingRental.operator_name }}
                        </dd>
                    </div>

                    <div>
                        <dt :class="mutedClass">
                            Passengers
                        </dt>
                        <dd
                            class="mt-1 text-sm font-semibold text-ink dark:text-slate-100"
                        >
                            {{ viewingRental.passengers }}
                        </dd>
                    </div>

                    <div>
                        <dt :class="mutedClass">
                            Payment
                        </dt>
                        <dd
                            class="mt-1 text-sm font-semibold text-ink dark:text-slate-100"
                        >
                            {{ viewingRental.payment_status_label }}
                        </dd>
                    </div>

                    <div class="col-span-2">
                        <dt :class="mutedClass">
                            Rental Status
                        </dt>

                        <dd class="mt-1">
                            <span
                                class="inline-flex rounded-full px-2.5 py-1 text-xs font-semibold"
                                :class="statusTone(viewingRental.status)"
                            >
                                {{ viewingRental.status_label }}
                            </span>
                        </dd>
                    </div>
                </dl>
            </div>

            <div
                class="flex justify-end gap-2 border-t border-[#e7eef5] bg-white px-5 py-4 dark:border-white/10 dark:bg-slate-900"
            >
                <Button
                    type="button"
                    variant="outline"
                    class="rounded-lg border-[#bcd9f5] text-[#1b7ad8]"
                    @click="closeRentalModal"
                >
                    Close
                </Button>

                <Button
                    as-child
                    class="rounded-lg bg-[#1b7ad8] text-white hover:bg-[#1567c4]"
                >
                    <Link :href="viewingRental.href">
                        Open Full Record
                    </Link>
                </Button>
            </div>
        </DialogContent>
    </Dialog>
</template>
