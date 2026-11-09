<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import {
    ArrowRight,
    CalendarDays,
    ChevronLeft,
    ChevronRight,
    Eye,
    MapPin,
    Ship,
    Ticket,
    UserRound,
    Users,
} from '@lucide/vue';
import { computed, nextTick, ref } from 'vue';
import StatCards from '@/components/admin/StatCards.vue';
import type { StatCardItem } from '@/components/admin/StatCards.vue';
import WeatherAlertBanner from '@/components/admin/WeatherAlertBanner.vue';
import ScheduledTripConfirmationModal from '@/components/ScheduledTripConfirmationModal.vue';
import ViewBoatBookingModal from '@/components/ViewBoatBookingModal.vue';
import ViewBookingBoatModal from '@/components/ViewBookingBoatModal.vue';
import type {
    BoatBookingRecord,
    BoatBookingStatus,
    BookingBoatRecord,
} from '@/types/booking';

const props = defineProps<{
    availableBoats: BookingBoatRecord[];
    upcomingBookings: BoatBookingRecord[];
    calendarBookings?: BoatBookingRecord[];
    stats: {
        assigned: number;
        open: number;
        upcoming: number;
        passengers: number;
        upcoming_total?: number;
    };
    weatherAlert?: {
        id: number;
        code: string;
        reason: string;
        reason_label: string;
        headline: string;
        title: string;
        message: string;
        affected_date: string;
        affected_date_label: string;
        affected_routes_label: string;
        duration_label: string;
    } | null;
    weatherWatchAlert?: {
        headline: string;
        reason_label: string;
        message: string;
        affected_date_label: string;
        duration_label: string;
        affected_routes_label: string;
    } | null;
    weatherHoldDates?: {
        affected_date: string;
        headline: string;
        reason_label: string;
        message: string;
        affected_date_label: string;
        duration_label: string;
        affected_routes_label: string;
        tone?: 'red' | 'yellow';
        can_book?: boolean;
    }[];
}>();

type WeatherHoldDate = NonNullable<typeof props.weatherHoldDates>[number];

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Boat Rental Schedule',
                href: '/schedules/boat-booking-schedule',
            },
        ],
    },
});

type CardFilter = 'assigned' | 'open' | 'upcoming';

const boatViewOpen = ref(false);
const bookingViewOpen = ref(false);
const tripConfirmationOpen = ref(false);
const selectedBoat = ref<BookingBoatRecord | null>(null);
const selectedBooking = ref<BoatBookingRecord | null>(null);
const tripConfirmationBooking = ref<BoatBookingRecord | null>(null);
const filter = ref<CardFilter>('assigned');
const boatsSection = ref<HTMLElement | null>(null);
const bookingsSection = ref<HTMLElement | null>(null);

const cards = computed<StatCardItem[]>(() => [
    { key: 'assigned', title: 'Assigned Boats', value: props.stats.assigned, hint: 'Set for private rental', icon: Ship, tone: 'blue' },
    { key: 'open', title: 'Open for Guests', value: props.stats.open, hint: 'Shown for boat selection', icon: Ticket, tone: 'green' },
    { key: 'upcoming', title: "Today's Rentals", value: props.stats.upcoming, hint: 'On today’s schedule', icon: CalendarDays, tone: 'indigo' },
]);

const filteredBoats = computed(() => {
    if (filter.value === 'open') {
        return props.availableBoats.filter((boat) => boat.is_open_for_booking);
    }

    return props.availableBoats;
});

const boatsTitle = computed(() =>
    filter.value === 'open'
        ? 'Open for guests'
        : 'Available for private rental',
);

const boatsHint = computed(() =>
    filter.value === 'open'
        ? `${filteredBoats.value.length} ${
              filteredBoats.value.length === 1 ? 'boat' : 'boats'
          } guests can choose today`
        : 'Boats assigned for private rental. Open boats are the ones guests can choose.',
);

function openTripConfirmation(booking: BoatBookingRecord) {
    tripConfirmationBooking.value = booking;
    tripConfirmationOpen.value = true;
}

async function selectCard(key: CardFilter) {
    filter.value = key;

    await nextTick();

    const target =
        key === 'upcoming' ? bookingsSection.value : boatsSection.value;

    target?.scrollIntoView({ behavior: 'smooth', block: 'start' });
}

const WEEKDAYS = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];

function pad2(value: number) {
    return String(value).padStart(2, '0');
}

function ymd(year: number, month: number, day: number) {
    return `${year}-${pad2(month)}-${pad2(day)}`;
}

function todayDate() {
    return new Date().toLocaleDateString('en-CA', {
        timeZone: 'Asia/Manila',
    });
}

function daysInMonth(year: number, month: number) {
    return new Date(Date.UTC(year, month, 0)).getUTCDate();
}

function weekdaySun0(date: string) {
    return new Date(`${date}T12:00:00+08:00`).getUTCDay();
}

function monthFromDate(date: string) {
    const [year, month] = date.split('-').map(Number);

    return { y: year, m: month };
}

function firstUpcomingDate() {
    const today = todayDate();

    return (props.calendarBookings ?? []).find((booking) => booking.booking_date > today)?.booking_date ?? null;
}

const initialSelected = firstUpcomingDate();
const calendarMonth = ref(monthFromDate(initialSelected ?? todayDate()));
const selectedDate = ref<string | null>(initialSelected);

const bookingsByDate = computed(() => {
    const map = new Map<string, BoatBookingRecord[]>();

    for (const booking of props.calendarBookings ?? []) {
        const rows = map.get(booking.booking_date) ?? [];
        rows.push(booking);
        map.set(booking.booking_date, rows);
    }

    return map;
});

const weatherByDate = computed(() => {
    const map = new Map<string, WeatherHoldDate>();

    for (const item of props.weatherHoldDates ?? []) {
        map.set(item.affected_date, item);
    }

    return map;
});

const calendarLabel = computed(() =>
    new Date(calendarMonth.value.y, calendarMonth.value.m - 1, 1).toLocaleDateString('en-US', {
        month: 'long',
        year: 'numeric',
    }),
);

const calendarCells = computed(() => {
    const { y, m } = calendarMonth.value;
    const today = todayDate();
    const days = daysInMonth(y, m);
    const pad = weekdaySun0(ymd(y, m, 1));
    const prevMonth = m === 1 ? 12 : m - 1;
    const prevYear = m === 1 ? y - 1 : y;
    const prevDays = daysInMonth(prevYear, prevMonth);
    const nextMonth = m === 12 ? 1 : m + 1;
    const nextYear = m === 12 ? y + 1 : y;
    const cells: {
        date: string;
        day: number;
        inMonth: boolean;
        isToday: boolean;
        count: number;
        weather: boolean;
        weatherTone: 'red' | 'yellow' | null;
    }[] = [];

    for (let i = pad - 1; i >= 0; i -= 1) {
        const day = prevDays - i;
        const date = ymd(prevYear, prevMonth, day);
        cells.push({
            date,
            day,
            inMonth: false,
            isToday: date === today,
            count: bookingsByDate.value.get(date)?.length ?? 0,
            weather: weatherByDate.value.has(date),
            weatherTone: weatherByDate.value.get(date)?.tone ?? (weatherByDate.value.has(date) ? 'red' : null),
        });
    }

    for (let day = 1; day <= days; day += 1) {
        const date = ymd(y, m, day);
        cells.push({
            date,
            day,
            inMonth: true,
            isToday: date === today,
            count: bookingsByDate.value.get(date)?.length ?? 0,
            weather: weatherByDate.value.has(date),
            weatherTone: weatherByDate.value.get(date)?.tone ?? (weatherByDate.value.has(date) ? 'red' : null),
        });
    }

    let nextDay = 1;

    while (cells.length % 7 !== 0) {
        const date = ymd(nextYear, nextMonth, nextDay);
        cells.push({
            date,
            day: nextDay,
            inMonth: false,
            isToday: date === today,
            count: bookingsByDate.value.get(date)?.length ?? 0,
            weather: weatherByDate.value.has(date),
            weatherTone: weatherByDate.value.get(date)?.tone ?? (weatherByDate.value.has(date) ? 'red' : null),
        });
        nextDay += 1;
    }

    return cells;
});

const selectedCalendarBookings = computed(() =>
    selectedDate.value ? (bookingsByDate.value.get(selectedDate.value) ?? []) : [],
);

const selectedWeather = computed(() =>
    selectedDate.value ? (weatherByDate.value.get(selectedDate.value) ?? null) : null,
);

const tripsPanelTitle = computed(() => {
    if (!selectedDate.value) {
        return 'Trips on selected date';
    }

    const label = new Date(`${selectedDate.value}T12:00:00+08:00`).toLocaleDateString('en-US', {
        month: 'short',
        day: '2-digit',
        year: 'numeric',
        timeZone: 'Asia/Manila',
    });

    return `Trips on ${label}`;
});

function shiftMonth(delta: number) {
    let { y, m } = calendarMonth.value;
    m += delta;

    if (m < 1) {
        m = 12;
        y -= 1;
    }

    if (m > 12) {
        m = 1;
        y += 1;
    }

    calendarMonth.value = { y, m };
}

function selectCalendarDate(date: string) {
    selectedDate.value = date;
    calendarMonth.value = monthFromDate(date);
}

function bookingWhen(booking: BoatBookingRecord) {
    if (booking.booking_date === todayDate()) {
        return `Today · ${booking.start_time_label}`;
    }

    return `${booking.booking_date_label} · ${booking.start_time_label}`;
}

function bookingStatusTone(status: BoatBookingStatus) {
    return {
        confirmed:
            'bg-sky-50 text-sky-700 dark:bg-sky-500/15 dark:text-sky-200',
        pending:
            'bg-amber-50 text-amber-700 dark:bg-amber-500/15 dark:text-amber-200',
        awaiting_payment:
            'bg-sky-50 text-sky-700 dark:bg-sky-500/15 dark:text-sky-200',
        weather_hold:
            'bg-orange-50 text-orange-700 dark:bg-orange-500/15 dark:text-orange-200',
        reschedule_pending:
            'bg-amber-50 text-amber-800 dark:bg-amber-500/15 dark:text-amber-200',
        ongoing:
            'bg-violet-50 text-violet-700 dark:bg-violet-500/15 dark:text-violet-200',
        completed:
            'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-200',
        passenger_no_show:
            'bg-red-50 text-red-700 dark:bg-red-500/15 dark:text-red-200',
        cancelled:
            'bg-slate-100 text-slate-600 dark:bg-white/10 dark:text-slate-300',
        rejected:
            'bg-red-50 text-red-700 dark:bg-red-500/15 dark:text-red-200',
    }[status];
}

function openBoatView(boat: BookingBoatRecord) {
    selectedBoat.value = boat;
    boatViewOpen.value = true;
}

function openBookingView(booking: BoatBookingRecord) {
    selectedBooking.value = booking;
    bookingViewOpen.value = true;
}

</script>

<template>
    <Head title="Boat Rental Schedule" />

    <div class="flex flex-1 flex-col gap-6 p-6">
        <div
            class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between"
        >
            <div>
                <h1
                    class="text-2xl font-bold tracking-tight text-[#0f4c8a] dark:text-sky-100"
                >
                    Boat Rental Schedule
                </h1>
                <p
                    class="mt-1 flex items-center gap-2 text-sm text-ink-muted dark:text-slate-400"
                >
                    <svg
                        class="size-4 text-[#1b7ad8]"
                        viewBox="0 0 24 24"
                        fill="none"
                        aria-hidden="true"
                    >
                        <path
                            d="M2 12c2.5-3 5-3 7.5 0s5 3 7.5 0 5-3 7 0"
                            stroke="currentColor"
                            stroke-width="1.8"
                            stroke-linecap="round"
                        />
                    </svg>
                    Boats guests can choose, today's private rentals, and a calendar for other dates
                </p>
            </div>
        </div>

        <WeatherAlertBanner v-if="weatherAlert" :alert="weatherAlert" variant="hold" context="rentals" can-lift />
        <WeatherAlertBanner v-if="weatherWatchAlert" :alert="weatherWatchAlert" variant="watch" context="rentals" />

        <StatCards :items="cards" :active="filter" selectable @select="(key) => selectCard(key as CardFilter)" />

        <section
            ref="bookingsSection"
            class="overflow-hidden rounded-2xl border bg-white shadow-sm dark:bg-white/5"
            :class="
                filter === 'upcoming'
                    ? 'border-[#1b7ad8] ring-2 ring-[#1b7ad8]/15'
                    : 'border-[#d7e8f6] dark:border-white/10'
            "
        >
            <div
                class="border-b border-[#e8f1f8] px-5 py-4 dark:border-white/10"
            >
                <h2 class="font-semibold text-[#0f4c8a] dark:text-sky-100">
                    Today's rental schedule
                </h2>
                <p class="text-xs text-ink-muted dark:text-slate-400">
                    {{ stats.upcoming }}
                    {{ stats.upcoming === 1 ? 'rental' : 'rentals' }} today
                    · {{ stats.upcoming_total ?? 0 }}
                    upcoming
                    · {{ stats.passengers }}
                    {{ stats.passengers === 1 ? 'passenger' : 'passengers' }}
                    today · Manage: Option 1 app request, Option 2 phone call
                </p>
            </div>

            <div
                v-if="upcomingBookings.length === 0"
                class="px-5 py-16 text-center"
            >
                <CalendarDays class="mx-auto size-10 text-[#c5def5]" />
                <p class="mt-3 font-medium text-[#0f4c8a] dark:text-sky-100">
                    No rentals today
                </p>
                <p class="mt-1 text-sm text-ink-muted">
                    Rentals on other dates are in the calendar below.
                </p>
            </div>

            <div v-else class="overflow-x-auto">
                <table class="w-full min-w-[980px] text-left text-sm">
                    <thead
                        class="bg-[#f4f9fd] text-xs tracking-wide text-ink-soft uppercase dark:bg-white/5"
                    >
                        <tr>
                            <th class="px-5 py-3 font-semibold">Schedule</th>
                            <th class="px-5 py-3 font-semibold">Package</th>
                            <th class="px-5 py-3 font-semibold">Boat</th>
                            <th class="px-5 py-3 font-semibold">Customer</th>
                            <th class="px-5 py-3 font-semibold">Passengers</th>
                            <th class="px-5 py-3 font-semibold">Status</th>
                            <th class="px-5 py-3 font-semibold text-right">
                                Actions
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="booking in upcomingBookings"
                            :key="booking.id"
                            class="border-t border-[#eef4f9] dark:border-white/10"
                        >
                            <td class="px-5 py-3 font-medium text-[#0f4c8a] dark:text-sky-100">
                                {{ bookingWhen(booking) }}
                            </td>
                            <td class="px-5 py-3 text-ink dark:text-slate-200">
                                {{ booking.package }}
                            </td>
                            <td class="px-5 py-3">
                                <div class="flex items-center gap-3">
                                    <div
                                        class="flex size-10 shrink-0 items-center justify-center overflow-hidden rounded-lg bg-[#d7ebfa] dark:bg-sky-500/10"
                                    >
                                        <img
                                            v-if="booking.boat.picture_url"
                                            :src="booking.boat.picture_url"
                                            :alt="booking.boat.name"
                                            class="h-full w-full object-cover"
                                        />
                                        <Ship v-else class="size-4 text-[#1b7ad8]" />
                                    </div>
                                    <div>
                                        <p class="font-semibold text-[#0f4c8a] dark:text-sky-100">
                                            {{ booking.boat.name }}
                                        </p>
                                        <p class="text-xs text-ink-muted">
                                            {{ booking.boat.operator_name ?? booking.boat.code }}
                                        </p>
                                    </div>
                                </div>
                            </td>
                            <td class="px-5 py-3 text-ink dark:text-slate-200">
                                {{ booking.customer_name }}
                            </td>
                            <td class="px-5 py-3 text-ink dark:text-slate-200">
                                {{ booking.passenger_count }} / {{ booking.boat.capacity }}
                            </td>
                            <td class="px-5 py-3">
                                <span
                                    class="inline-flex rounded-full px-2.5 py-1 text-xs font-semibold"
                                    :class="bookingStatusTone(booking.status)"
                                >
                                    {{ booking.status_label }}
                                </span>
                            </td>
                            <td class="px-5 py-3">
                                <div class="flex items-center justify-end gap-2">
                                    <button
                                        type="button"
                                        class="inline-flex h-8 items-center gap-1.5 rounded-lg border border-[#c5def5] bg-white px-2.5 text-xs font-medium text-[#1b7ad8] transition hover:bg-[#e8f4fc] dark:border-white/10 dark:bg-white/5"
                                        @click="openBookingView(booking)"
                                    >
                                        <Eye class="size-3.5" />
                                        View
                                    </button>
                                    <button
                                        v-if="booking.show_trip_manage"
                                        type="button"
                                        class="inline-flex h-8 items-center gap-1.5 rounded-lg border border-[#1479E8] bg-[#1479E8] px-2.5 text-xs font-semibold text-white transition hover:bg-[#1268c9]"
                                        @click="openTripConfirmation(booking)"
                                    >
                                        Manage
                                    </button>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <section class="overflow-hidden rounded-2xl border border-[#d7e8f6] bg-white shadow-sm dark:border-white/10 dark:bg-white/5">
            <div class="border-b border-[#e8f1f8] px-5 py-4 dark:border-white/10">
                <h2 class="font-semibold text-[#0f4c8a] dark:text-sky-100">
                    Rental Calendar
                </h2>
                <p class="mt-0.5 text-xs text-ink-muted dark:text-slate-400">
                    View rental schedules by date. Select a date to see the trips.
                </p>
            </div>

            <div class="grid gap-5 p-5 xl:grid-cols-[minmax(0,1fr)_minmax(0,1fr)]">
                <div class="rounded-2xl border border-[#d7e8f6] bg-white p-4 dark:border-white/10 dark:bg-white/5">
                    <div class="mb-4 flex items-center justify-between gap-2">
                        <button
                            type="button"
                            class="inline-flex size-9 items-center justify-center rounded-lg border border-[#c5def5] text-[#1b7ad8] transition hover:bg-[#e8f4fc] dark:border-white/10 dark:hover:bg-white/10"
                            @click="shiftMonth(-1)"
                        >
                            <ChevronLeft class="size-4" />
                        </button>
                        <p class="text-center text-sm font-semibold text-[#0f4c8a] dark:text-sky-100">
                            {{ calendarLabel }}
                        </p>
                        <button
                            type="button"
                            class="inline-flex size-9 items-center justify-center rounded-lg border border-[#c5def5] text-[#1b7ad8] transition hover:bg-[#e8f4fc] dark:border-white/10 dark:hover:bg-white/10"
                            @click="shiftMonth(1)"
                        >
                            <ChevronRight class="size-4" />
                        </button>
                    </div>
                    <div class="grid grid-cols-7 gap-1 text-center text-[11px] font-semibold tracking-wide text-ink-soft uppercase">
                        <span v-for="weekday in WEEKDAYS" :key="weekday" class="py-1">{{ weekday }}</span>
                    </div>
                    <div class="mt-1 grid grid-cols-7 gap-1">
                        <button
                            v-for="cell in calendarCells"
                            :key="cell.date"
                            type="button"
                            class="relative flex min-h-[44px] flex-col items-center justify-center rounded-xl border text-sm transition"
                            :class="[
                                cell.weatherTone === 'red'
                                    ? selectedDate === cell.date
                                        ? 'border-red-500 bg-red-100 text-red-800 ring-2 ring-red-400/30'
                                        : 'border-red-200 bg-red-50 text-red-700 hover:border-red-400'
                                    : cell.weatherTone === 'yellow'
                                      ? selectedDate === cell.date
                                        ? 'border-amber-500 bg-amber-100 text-amber-900 ring-2 ring-amber-400/30'
                                        : 'border-amber-200 bg-amber-50 text-amber-800 hover:border-amber-400'
                                      : cell.inMonth
                                        ? 'bg-white dark:bg-white/5'
                                        : 'bg-[#f7fbfe] dark:bg-white/[0.03]',
                                !cell.weather && selectedDate === cell.date
                                    ? 'border-[#1b7ad8] bg-[#e8f4fc] ring-2 ring-[#1b7ad8]/20'
                                    : !cell.weather && cell.count > 0
                                      ? 'border-[#c5def5] hover:border-[#1b7ad8]'
                                      : !cell.weather
                                        ? 'border-transparent hover:border-[#d7e8f6]'
                                        : '',
                                !cell.weather && cell.isToday && selectedDate !== cell.date
                                    ? 'text-[#1b7ad8]'
                                    : !cell.weather
                                      ? 'text-[#0f4c8a] dark:text-sky-100'
                                      : '',
                                !cell.weather && !cell.inMonth ? 'text-[#9bb0c4]' : '',
                            ]"
                            @click="selectCalendarDate(cell.date)"
                        >
                            <span
                                class="font-semibold"
                                :class="cell.weatherTone === 'red' ? 'text-red-700' : cell.weatherTone === 'yellow' ? 'text-amber-800' : cell.isToday ? 'text-[#1b7ad8]' : ''"
                            >{{ cell.day }}</span>
                            <span
                                v-if="cell.count > 0"
                                class="mt-1 size-1.5 rounded-full"
                                :class="cell.weatherTone === 'red' ? 'bg-red-600' : cell.weatherTone === 'yellow' ? 'bg-amber-500' : 'bg-[#1b7ad8]'"
                            />
                        </button>
                    </div>
                </div>

                <div
                    class="rounded-2xl border p-4 dark:bg-white/5"
                    :class="selectedWeather?.tone === 'yellow'
                        ? 'border-amber-200 bg-amber-50/70 dark:border-amber-500/20'
                        : selectedWeather
                            ? 'border-red-200 bg-red-50/70 dark:border-red-500/20'
                            : 'border-[#d7e8f6] bg-[#f7fbfe] dark:border-white/10'"
                >
                    <div class="flex items-start gap-2">
                        <CalendarDays class="mt-0.5 size-5 shrink-0 text-[#1b7ad8]" />
                        <div>
                            <p
                                class="text-sm font-semibold"
                                :class="selectedWeather?.tone === 'yellow' ? 'text-amber-900' : selectedWeather ? 'text-red-800' : 'text-[#0f4c8a] dark:text-sky-100'"
                            >
                                {{ tripsPanelTitle }}
                </p>
                <p
                                class="mt-0.5 text-xs"
                                :class="selectedWeather?.tone === 'yellow' ? 'text-amber-800' : selectedWeather ? 'text-red-700' : 'text-ink-muted dark:text-slate-400'"
                            >
                                {{ selectedCalendarBookings.length }}
                                {{
                                    selectedCalendarBookings.length === 1
                                        ? 'rental schedule'
                                        : 'rental schedules'
                                }}
                            </p>
                        </div>
                    </div>

                    <div
                        v-if="selectedWeather"
                        class="mt-3 rounded-xl border bg-white px-3 py-2.5 dark:bg-red-950/20"
                        :class="selectedWeather.tone === 'yellow'
                            ? 'border-amber-200 text-amber-900 dark:border-amber-500/20'
                            : 'border-red-200 text-red-800 dark:border-red-500/20'"
                    >
                        <p class="text-xs font-bold uppercase tracking-wide">{{ selectedWeather.headline }}</p>
                        <p class="mt-1 text-xs">
                            {{ selectedWeather.reason_label }} · {{ selectedWeather.duration_label }}
                        </p>
                    </div>

                    <div v-if="selectedCalendarBookings.length === 0" class="mt-8 text-center">
                        <CalendarDays class="mx-auto size-8 text-[#c5def5]" />
                        <p class="mt-2 text-sm text-ink-muted">No rental scheduled on this date.</p>
                    </div>

                    <div v-else class="mt-4 space-y-3">
                        <article
                            v-for="booking in selectedCalendarBookings"
                            :key="booking.id"
                            class="flex flex-wrap items-center gap-3 rounded-xl border border-[#d7e8f6] bg-white p-3 dark:border-white/10 dark:bg-white/5"
                        >
                            <div
                                class="flex size-14 shrink-0 items-center justify-center overflow-hidden rounded-lg bg-[#d7ebfa] dark:bg-sky-500/10"
                            >
                                <img
                                    v-if="booking.boat.picture_url"
                                    :src="booking.boat.picture_url"
                                    :alt="booking.boat.name"
                                    class="h-full w-full object-cover"
                                />
                                <Ship v-else class="size-5 text-[#1b7ad8]" />
                            </div>
                            <div class="min-w-0 flex-1">
                                <p class="truncate font-bold text-[#0f4c8a] dark:text-sky-100">
                                    {{ booking.boat.name }}
                                </p>
                                <p class="truncate text-xs text-ink-muted">
                                    {{ booking.destination }}
                                </p>
                            </div>
                            <div class="shrink-0 text-right">
                                <p class="text-sm font-bold text-[#0f4c8a] dark:text-sky-100">
                                    {{ booking.start_time_label }}
                                </p>
                                <p class="mt-0.5 inline-flex items-center justify-end gap-1 text-xs text-ink-muted">
                                    <Users class="size-3.5 text-[#1b7ad8]" />
                                    {{ booking.passenger_count }} / {{ booking.boat.capacity }}
                                </p>
                            </div>
                            <span
                                class="shrink-0 rounded-full px-2.5 py-1 text-[11px] font-semibold"
                                :class="bookingStatusTone(booking.status)"
                            >
                                {{ booking.status_label }}
                            </span>
                            <button
                                type="button"
                                class="inline-flex h-8 shrink-0 items-center gap-1.5 rounded-lg border border-[#c5def5] bg-white px-2.5 text-xs font-medium text-[#1b7ad8] transition hover:bg-[#e8f4fc] dark:border-white/10 dark:bg-white/5"
                                @click="openBookingView(booking)"
                            >
                                <Eye class="size-3.5" />
                                View
                            </button>
            </article>
        </div>
                </div>
            </div>
        </section>
        <section
            ref="boatsSection"
            class="overflow-hidden rounded-2xl border border-[#d7e8f6] bg-white shadow-sm dark:border-white/10 dark:bg-white/5"
        >
            <div
                class="border-b border-[#e8f1f8] px-5 py-4 dark:border-white/10"
            >
                <h2 class="font-semibold text-[#0f4c8a] dark:text-sky-100">
                    {{ boatsTitle }}
                </h2>
                <p class="text-xs text-ink-muted dark:text-slate-400">
                    {{ boatsHint }}
                </p>
            </div>

            <div
                v-if="filteredBoats.length === 0"
                class="px-5 py-16 text-center"
            >
                <Ship class="mx-auto size-10 text-[#c5def5]" />
                <p class="mt-3 font-medium text-[#0f4c8a] dark:text-sky-100">
                    {{
                        filter === 'open'
                            ? 'No boats open for guests'
                            : 'No boats assigned for private rental'
                    }}
                </p>
                <p class="mt-1 text-sm text-ink-muted">
                    {{
                        filter === 'open'
                            ? 'Assigned boats that are free today will show here.'
                            : "Set a boat's service availability to Private Booking in Boat Management."
                    }}
                </p>
            </div>

            <div v-else class="grid gap-3 p-4 sm:grid-cols-2 xl:grid-cols-3">
                <article
                    v-for="boat in filteredBoats"
                    :key="boat.id"
                    class="flex flex-col overflow-hidden rounded-2xl border border-[#d7e8f6] bg-white shadow-[0_8px_20px_rgba(15,76,138,0.07)] dark:border-white/10 dark:bg-white/5"
                >
                    <div
                        class="relative aspect-[16/10] overflow-hidden bg-[#d7ebfa] dark:bg-sky-500/10"
                    >
                        <img
                            v-if="boat.pictures?.[0]?.url || boat.picture_url"
                            :src="boat.pictures?.[0]?.url || boat.picture_url"
                            :alt="boat.name"
                            class="h-full w-full object-cover"
                        />
                        <Ship
                            v-else
                            class="absolute inset-0 m-auto size-9 text-[#1b7ad8]"
                        />
                        <div
                            class="absolute inset-x-3 top-3 flex items-start justify-between gap-3"
                        >
                            <span
                                class="inline-flex items-center gap-1 rounded-full px-2.5 py-1 text-[11px] font-semibold text-white shadow-sm backdrop-blur-sm"
                                :class="
                                    boat.is_open_for_booking
                                        ? 'bg-[#0f4c8a]/80'
                                        : 'bg-black/55'
                                "
                            >
                                <Ship class="size-3" />
                                {{
                                    boat.is_open_for_booking
                                        ? 'Available for Rental'
                                        : boat.availability_label
                                }}
                            </span>
                        </div>
                    </div>

                    <div class="flex flex-1 flex-col px-4 pt-3 pb-4">
                        <div class="flex items-start justify-between gap-2">
                            <h3
                                class="text-lg font-bold tracking-wide text-[#0f4c8a] uppercase dark:text-sky-100"
                            >
                                {{ boat.name }}
                            </h3>
                            <span
                                class="inline-flex shrink-0 items-center gap-1 rounded-full bg-[#e8f4fc] px-2 py-1 text-[10px] font-semibold text-[#1b7ad8] dark:bg-sky-500/15 dark:text-sky-200"
                            >
                                <Ship class="size-3" />
                                Private Rental
                            </span>
                        </div>

                        <p
                            class="mt-1.5 inline-flex items-center gap-1.5 text-xs text-ink-muted"
                        >
                            <Ship class="size-3.5 text-[#1b7ad8]" />
                            {{ boat.code }} · {{ boat.capacity }} pax
                        </p>
                        <p
                            class="mt-1 inline-flex items-center gap-1.5 text-xs text-ink-muted"
                        >
                            <UserRound class="size-3.5 text-[#1b7ad8]" />
                            {{
                                boat.operator?.full_name ??
                                'No operator assigned'
                            }}
                        </p>

                        <div
                            class="mt-3 grid grid-cols-3 divide-x divide-[#d7e8f6] dark:divide-white/10"
                        >
                            <div class="flex flex-col items-center px-1.5 text-center">
                                <Users class="mb-0.5 size-4 text-[#1b7ad8]" />
                                <p class="text-sm font-semibold text-[#0f4c8a] dark:text-sky-100">
                                    {{ boat.capacity }}
                                </p>
                                <p class="text-[10px] text-ink-muted">Capacity</p>
                            </div>
                            <div class="flex flex-col items-center px-1.5 text-center">
                                <Ship class="mb-0.5 size-4 text-[#1b7ad8]" />
                                <p class="text-sm font-semibold text-[#0f4c8a] dark:text-sky-100">
                                    {{ boat.code }}
                                </p>
                                <p class="text-[10px] text-ink-muted">Boat Code</p>
                            </div>
                            <div class="flex flex-col items-center px-1.5 text-center">
                                <MapPin class="mb-0.5 size-4 text-[#1b7ad8]" />
                                <p class="text-sm font-semibold text-[#0f4c8a] dark:text-sky-100">
                                    {{ boat.home_port }}
                                </p>
                                <p class="text-[10px] text-ink-muted">Home Port</p>
                            </div>
                        </div>

                        <button
                            type="button"
                            class="relative mt-3 inline-flex h-9 w-full items-center justify-center rounded-full bg-[#1b7ad8] text-xs font-semibold text-white shadow-[0_6px_14px_rgba(27,122,216,0.24)] transition hover:bg-[#1567c4]"
                            @click="openBoatView(boat)"
                        >
                            <span class="inline-flex items-center gap-1.5">
                                <Eye class="size-3.5" />
                                View Details
                            </span>
                            <ArrowRight class="absolute right-4 size-3.5" />
                        </button>
                    </div>
                </article>
            </div>
        </section>

    </div>

    <ViewBookingBoatModal v-model:open="boatViewOpen" :boat="selectedBoat" />
    <ViewBoatBookingModal
        v-model:open="bookingViewOpen"
        :booking="selectedBooking"
        :show-edit="false"
    />
    <ScheduledTripConfirmationModal
        v-model:open="tripConfirmationOpen"
        :booking="tripConfirmationBooking"
    />
</template>
