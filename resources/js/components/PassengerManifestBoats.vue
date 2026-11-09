<script setup lang="ts">
import {
    ArrowLeft,
    ArrowRight,
    ChevronDown,
    ChevronLeft,
    ChevronRight,
    FileDown,
    Ship,
    Users,
    Wallet,
    X,
} from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { downloadReport, type ReportDownloadFormat, type ReportExportSpec } from '@/lib/reportExport';
import type { ReportRegularTrip } from '@/types/report';

type RentalPassenger = {
    id: number;
    full_name: string;
    age?: number | null;
    gender?: string | null;
    contact_number?: string | null;
};

type RentalRecord = {
    id: number;
    code: string;
    customer_name?: string | null;
    contact_number?: string | null;
    destination?: string | null;
    booking_date: string;
    booking_date_label: string;
    start_time?: string;
    start_time_label?: string | null;
    passenger_count: number;
    status: string;
    status_label: string;
    report_status?: string;
    report_status_label?: string;
    remarks?: string | null;
    amount?: number;
    amount_label?: string;
    recorded_passengers?: RentalPassenger[];
    boat: ReportRegularTrip['boat'];
};

type ManifestTrip = ReportRegularTrip & {
    recordKey: string;
    kind: 'regular' | 'rental';
};

const props = defineProps<{
    trips: ReportRegularTrip[];
    rentals?: RentalRecord[];
    canViewPassengerDetails: boolean;
}>();

type DatePreset = 'today' | 'week' | 'month' | 'custom';

function localDate(value = new Date()) {
    const month = String(value.getMonth() + 1).padStart(2, '0');
    const day = String(value.getDate()).padStart(2, '0');

    return `${value.getFullYear()}-${month}-${day}`;
}

function startOfWeek(date: Date) {
    const copy = new Date(date);
    const day = copy.getDay();
    const diff = day === 0 ? -6 : 1 - day;
    copy.setDate(copy.getDate() + diff);

    return copy;
}

function formatDisplayDate(value: string) {
    const date = new Date(`${value}T00:00:00`);

    return date.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
}

function formatPeso(value: number) {
    return `₱${value.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
}

const dateFrom = ref(localDate());
const dateTo = ref(localDate());
const datePreset = ref<DatePreset>('today');
const boatFilter = ref('all');
const tripType = ref<'all' | 'regular' | 'rental'>('all');
const pageSize = ref(10);
const page = ref(1);
const selectedBoatId = ref<number | null>(null);

const sourceTrips = computed<ManifestTrip[]>(() => {
    const regular = props.trips.map((trip) => ({
        ...trip,
        recordKey: `regular-${trip.id}`,
        kind: 'regular' as const,
        leg_label: trip.leg_label || 'Regular',
        departure_time: trip.departure_time || '',
    }));

    const rentals = (props.rentals ?? []).map((booking) => {
        const named = booking.recorded_passengers ?? [];
        const destination = booking.destination || 'Exclusive rental';

        return {
            id: booking.id,
            recordKey: `rental-${booking.id}`,
            kind: 'rental' as const,
            code: booking.code,
            route: destination,
            direction: booking.customer_name ? `${destination} · ${booking.customer_name}` : destination,
            trip_date: booking.booking_date,
            trip_date_label: booking.booking_date_label,
            departure_time: booking.start_time || '',
            departure_time_label: booking.start_time_label || '',
            passenger_count: Math.max(Number(booking.passenger_count || 0), named.length),
            is_outbound: true,
            leg_label: 'Rental',
            status: booking.status,
            status_label: booking.status_label,
            report_status: booking.report_status,
            report_status_label: booking.report_status_label,
            remarks: booking.remarks ?? null,
            revenue: Number(booking.amount || 0),
            revenue_label: booking.amount_label,
            passengers: named.map((passenger) => ({
                id: passenger.id,
                full_name: passenger.full_name,
                age: Number(passenger.age || 0),
                gender: passenger.gender || '—',
                contact_number: passenger.contact_number || booking.contact_number || '—',
                fare_amount_label: 'Rental',
            })),
            boat: booking.boat,
        } satisfies ManifestTrip;
    });

    return [...regular, ...rentals];
});

const boatOptions = computed(() => {
    const seen = new Map<number, string>();

    sourceTrips.value.forEach((trip) => {
        seen.set(trip.boat.id, trip.boat.name);
    });

    return [...seen.entries()]
        .map(([id, name]) => ({ id, name }))
        .sort((left, right) => left.name.localeCompare(right.name));
});

const rangedTrips = computed(() =>
    sourceTrips.value
        .filter((trip) => {
            if (trip.trip_date < dateFrom.value || trip.trip_date > dateTo.value) {
                return false;
            }

            if (boatFilter.value !== 'all' && String(trip.boat.id) !== boatFilter.value) {
                return false;
            }

            return tripType.value === 'all' || trip.kind === tripType.value;
        })
        .sort((left, right) => {
            const dateCompare = left.trip_date.localeCompare(right.trip_date);

            return dateCompare !== 0
                ? dateCompare
                : left.departure_time.localeCompare(right.departure_time);
        }),
);

type BoatRow = {
    id: number;
    name: string;
    operator: string;
    trips: ReportRegularTrip[];
    totalTrips: number;
    totalPassengers: number;
    totalRevenue: number;
};

const boatRows = computed<BoatRow[]>(() => {
    const grouped = new Map<number, BoatRow>();

    rangedTrips.value.forEach((trip) => {
        const current = grouped.get(trip.boat.id) ?? {
            id: trip.boat.id,
            name: trip.boat.name,
            operator: trip.boat.operator_name || 'Unassigned',
            trips: [],
            totalTrips: 0,
            totalPassengers: 0,
            totalRevenue: 0,
        };

        current.trips.push(trip);
        current.totalTrips += 1;
        current.totalPassengers += Number(trip.passenger_count || 0);
        current.totalRevenue += Number(trip.revenue || 0);
        grouped.set(trip.boat.id, current);
    });

    return [...grouped.values()].sort((left, right) => left.name.localeCompare(right.name));
});

const totalPages = computed(() => Math.max(1, Math.ceil(boatRows.value.length / pageSize.value)));
const pagedBoats = computed(() => {
    const start = (page.value - 1) * pageSize.value;

    return boatRows.value.slice(start, start + pageSize.value);
});
const showingFrom = computed(() => (boatRows.value.length === 0 ? 0 : (page.value - 1) * pageSize.value + 1));
const showingTo = computed(() => Math.min(page.value * pageSize.value, boatRows.value.length));

const selectedBoat = computed(() => boatRows.value.find((boat) => boat.id === selectedBoatId.value) ?? null);

const periodLabel = computed(() => {
    if (dateFrom.value === dateTo.value) {
        return formatDisplayDate(dateFrom.value);
    }

    return `${formatDisplayDate(dateFrom.value)} – ${formatDisplayDate(dateTo.value)}`;
});

watch([boatRows, pageSize, boatFilter, tripType, dateFrom, dateTo], () => {
    if (page.value > totalPages.value) {
        page.value = 1;
    }

    if (selectedBoatId.value && !boatRows.value.some((boat) => boat.id === selectedBoatId.value)) {
        selectedBoatId.value = null;
    }
});

function applyDatePreset(next: DatePreset) {
    datePreset.value = next;
    const today = new Date();

    if (next === 'today') {
        dateFrom.value = localDate(today);
        dateTo.value = localDate(today);
    } else if (next === 'week') {
        dateFrom.value = localDate(startOfWeek(today));
        dateTo.value = localDate(today);
    } else if (next === 'month') {
        dateFrom.value = localDate(new Date(today.getFullYear(), today.getMonth(), 1));
        dateTo.value = localDate(new Date(today.getFullYear(), today.getMonth() + 1, 0));
    }

    page.value = 1;
}

function viewBoat(id: number) {
    selectedBoatId.value = id;
}

function closeBoat() {
    selectedBoatId.value = null;
}

function tripStatusClass(status?: string) {
    if (status === 'completed') {
        return 'bg-[#dcfce7] text-[#15803d]';
    }

    if (status === 'on_trip') {
        return 'bg-[#dbeafe] text-[#1d4ed8]';
    }

    if (status === 'cancelled') {
        return 'bg-[#fee2e2] text-[#b91c1c]';
    }

    return 'bg-[#e8f1fb] text-[#1b7ad8] dark:text-sky-300';
}

function tripArrowClass(trip: ManifestTrip) {
    if (trip.kind === 'rental') {
        return 'bg-[#fef3c7] text-[#b45309]';
    }

    return trip.is_outbound === false ? 'bg-[#f3e8ff] text-[#7c3aed]' : 'bg-[#dbeafe] text-[#1d4ed8]';
}

function manifestSpec(trips: ManifestTrip[], title: string, filename: string): ReportExportSpec {
    const rows = trips.flatMap((trip) => {
        if (!props.canViewPassengerDetails) {
            return [];
        }

        if (!trip.passengers?.length) {
            return [{
                number: '',
                trip: trip.code,
                date: trip.trip_date_label,
                route: trip.direction,
                type: trip.leg_label || 'Regular',
                name: 'No passengers recorded',
                age: '',
                fare: trip.revenue_label || '₱0.00',
                gender: '',
                contact: '',
                remarks: '',
            }];
        }

        return trip.passengers.map((passenger, index) => ({
            number: index + 1,
            trip: trip.code,
            date: trip.trip_date_label,
            route: trip.direction,
            type: trip.leg_label || 'Regular',
            name: passenger.full_name,
            age: passenger.age,
            fare: passenger.fare_amount_label || '—',
            gender: passenger.gender,
            contact: passenger.contact_number,
            remarks: passenger.remarks || '—',
        }));
    });

    const totalPassengers = trips.reduce((sum, trip) => sum + Number(trip.passenger_count || 0), 0);
    const totalRevenue = trips.reduce((sum, trip) => sum + Number(trip.revenue || 0), 0);

    return {
        title,
        subtitle: `Passenger manifest for ${periodLabel.value}`,
        filename,
        sectionTitle: 'Passenger Manifest',
        columns: [
            { key: 'number', header: '#' },
            { key: 'trip', header: 'Trip ID' },
            { key: 'date', header: 'Date' },
            { key: 'route', header: 'Route' },
            { key: 'type', header: 'Trip Type' },
            { key: 'name', header: 'Full Name' },
            { key: 'age', header: 'Age' },
            { key: 'fare', header: 'Fare' },
            { key: 'gender', header: 'Gender' },
            { key: 'contact', header: 'Contact Number' },
            { key: 'remarks', header: 'Remarks' },
        ],
        rows,
        stats: [
            { label: 'Total Trips', value: String(trips.length) },
            { label: 'Total Passengers', value: String(totalPassengers) },
            { label: 'Total Revenue', value: formatPeso(totalRevenue) },
        ],
        emptyMessage: 'No passengers have been recorded for this selection.',
        note: props.canViewPassengerDetails
            ? undefined
            : 'Passenger names and contact numbers are available to authorized administrators only.',
    };
}

function downloadBoat(format: ReportDownloadFormat) {
    if (!selectedBoat.value) {
        return;
    }

    downloadReport(format, manifestSpec(
        selectedBoat.value.trips,
        `Passenger Manifest — ${selectedBoat.value.name}`,
        `passenger-manifest-${selectedBoat.value.name.toLowerCase().replaceAll(/\s+/g, '-')}-${dateFrom.value}-to-${dateTo.value}`,
    ));
}

function downloadAll(format: ReportDownloadFormat) {
    downloadReport(format, manifestSpec(
        rangedTrips.value,
        'Passenger Manifest — All Boats',
        `passenger-manifest-all-boats-${dateFrom.value}-to-${dateTo.value}`,
    ));
}
</script>

<template>
    <section
        v-if="!selectedBoat"
        class="overflow-hidden rounded-[16px] border border-[#d7e8f6] bg-white dark:border-white/10 dark:bg-white/5 shadow-[0_2px_8px_rgba(15,76,138,0.05)]"
    >
        <div class="flex flex-wrap items-start justify-between gap-3 border-b border-[#eef4f9] dark:border-white/10 px-5 py-4">
            <div>
                <h2 class="text-base font-semibold text-[#0f4c8a] dark:text-sky-100">Boats with Passenger Records</h2>
                <p class="mt-0.5 text-xs text-ink-muted dark:text-slate-400">
                    Showing {{ showingFrom }}–{{ showingTo }} of {{ boatRows.length }} boats
                </p>
            </div>
            <DropdownMenu>
                <DropdownMenuTrigger as-child>
                    <Button
                        type="button"
                        class="h-9 rounded-xl bg-[#1b7ad8] px-3 text-sm text-white hover:bg-[#1567c4]"
                        :disabled="rangedTrips.length === 0 || !canViewPassengerDetails"
                    >
                        <FileDown class="size-4" />
                        Download All
                        <ChevronDown class="size-4" />
                    </Button>
                </DropdownMenuTrigger>
                <DropdownMenuContent align="end" class="min-w-44 rounded-xl border-[#d7e8f6] dark:border-white/10 dark:bg-slate-900">
                    <DropdownMenuItem class="cursor-pointer text-ink dark:text-sky-50" @click="downloadAll('pdf')">PDF</DropdownMenuItem>
                    <DropdownMenuItem class="cursor-pointer text-ink dark:text-sky-50" @click="downloadAll('excel')">Excel</DropdownMenuItem>
                    <DropdownMenuItem class="cursor-pointer text-ink dark:text-sky-50" @click="downloadAll('csv')">CSV</DropdownMenuItem>
                    <DropdownMenuItem class="cursor-pointer text-ink dark:text-sky-50" @click="downloadAll('print')">Print</DropdownMenuItem>
                </DropdownMenuContent>
            </DropdownMenu>
        </div>

        <div class="grid gap-3 border-b border-[#eef4f9] px-5 py-4 dark:border-white/10 lg:grid-cols-[minmax(0,1.4fr)_180px_180px]">
            <label class="block">
                <span class="mb-1.5 block text-xs font-semibold text-ink-soft dark:text-slate-400">Date Range</span>
                <div class="flex h-10 items-center gap-2 rounded-xl border border-[#d7e8f6] bg-white px-3 dark:border-white/10 dark:bg-white/5">
                    <select
                        v-model="datePreset"
                        class="border-0 bg-transparent text-sm text-ink outline-none dark:text-sky-50"
                        @change="applyDatePreset(datePreset)"
                    >
                        <option value="today">Today</option>
                        <option value="week">This Week</option>
                        <option value="month">This Month</option>
                        <option value="custom">Custom</option>
                    </select>
                    <span class="text-[#9bb0c2]">|</span>
                    <input
                        v-model="dateFrom"
                        type="date"
                        class="min-w-0 flex-1 border-0 bg-transparent text-sm text-ink outline-none dark:text-sky-50 [&::-webkit-calendar-picker-indicator]:hidden"
                        @change="datePreset = 'custom'; page = 1"
                    />
                    <span class="text-[#9bb0c2]">-</span>
                    <input
                        v-model="dateTo"
                        type="date"
                        class="min-w-0 flex-1 border-0 bg-transparent text-sm text-ink outline-none dark:text-sky-50 [&::-webkit-calendar-picker-indicator]:hidden"
                        @change="datePreset = 'custom'; page = 1"
                    />
                </div>
            </label>
            <label class="block">
                <span class="mb-1.5 block text-xs font-semibold text-ink-soft dark:text-slate-400">Boat</span>
                <select
                    v-model="boatFilter"
                    class="h-10 w-full rounded-xl border border-[#d7e8f6] bg-white px-3 text-sm text-ink outline-none dark:border-white/10 dark:bg-white/5 dark:text-sky-50"
                    @change="page = 1"
                >
                    <option value="all">All boats</option>
                    <option v-for="boat in boatOptions" :key="boat.id" :value="String(boat.id)">{{ boat.name }}</option>
                </select>
            </label>
            <label class="block">
                <span class="mb-1.5 block text-xs font-semibold text-ink-soft dark:text-slate-400">Trip Type</span>
                <select
                    v-model="tripType"
                    class="h-10 w-full rounded-xl border border-[#d7e8f6] bg-white px-3 text-sm text-ink outline-none dark:border-white/10 dark:bg-white/5 dark:text-sky-50"
                    @change="page = 1"
                >
                    <option value="all">All types</option>
                    <option value="regular">Regular</option>
                    <option value="rental">Rental</option>
                </select>
            </label>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead class="bg-[#f4f9fd] text-[11px] tracking-wide text-ink-soft uppercase dark:bg-white/5 dark:text-slate-400">
                    <tr>
                        <th class="px-5 py-3 font-semibold">#</th>
                        <th class="px-5 py-3 font-semibold">Boat Name</th>
                        <th class="px-5 py-3 font-semibold">Operator</th>
                        <th class="px-5 py-3 font-semibold">Total Trips</th>
                        <th class="px-5 py-3 font-semibold">Total Passengers</th>
                        <th class="px-5 py-3 font-semibold">Total Revenue</th>
                        <th class="px-5 py-3 font-semibold">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-if="pagedBoats.length === 0">
                        <td colspan="7" class="px-5 py-10 text-center text-sm text-ink-muted dark:text-slate-400">
                            No boats with passenger records for this search.
                        </td>
                    </tr>
                    <tr
                        v-for="(boat, index) in pagedBoats"
                        :key="boat.id"
                        class="border-t border-[#eef4f9] hover:bg-[#f8fbfe] dark:border-white/10 dark:hover:bg-white/5"
                    >
                        <td class="px-5 py-3 text-ink-muted dark:text-slate-400">{{ showingFrom + index }}</td>
                        <td class="px-5 py-3 whitespace-nowrap font-medium text-[#1b7ad8] dark:text-sky-300">{{ boat.name }}</td>
                        <td class="px-5 py-3 whitespace-nowrap text-ink dark:text-sky-50">{{ boat.operator }}</td>
                        <td class="px-5 py-3 text-ink dark:text-sky-50">{{ boat.totalTrips }}</td>
                        <td class="px-5 py-3 text-ink dark:text-sky-50">{{ boat.totalPassengers }}</td>
                        <td class="px-5 py-3 font-medium text-[#0f4c8a] dark:text-sky-100">{{ formatPeso(boat.totalRevenue) }}</td>
                        <td class="px-5 py-3">
                            <button
                                type="button"
                                class="rounded-full border border-[#1b7ad8] px-3 py-1 text-xs font-semibold text-[#1b7ad8] hover:bg-[#f3f9ff] dark:border-sky-400/70 dark:text-sky-200 dark:hover:bg-white/10"
                                @click="viewBoat(boat.id)"
                            >
                                View
                            </button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div class="flex flex-wrap items-center justify-between gap-3 border-t border-[#eef4f9] dark:border-white/10 px-5 py-3">
            <label class="flex items-center gap-2 text-xs text-ink-muted dark:text-slate-400">
                Show
                <span class="relative inline-flex">
                    <select v-model.number="pageSize" class="h-8 appearance-none rounded-lg border border-[#d7e8f6] bg-white py-0 pr-7 pl-2 text-sm text-ink outline-none dark:border-white/15 dark:bg-slate-800 dark:text-sky-50">
                        <option :value="10">10</option>
                        <option :value="25">25</option>
                        <option :value="50">50</option>
                    </select>
                    <ChevronDown class="pointer-events-none absolute top-1/2 right-1.5 size-3.5 -translate-y-1/2 text-ink-muted dark:text-slate-400" />
                </span>
                entries
            </label>
            <div class="flex items-center gap-1">
                <button type="button" class="flex size-8 items-center justify-center rounded-lg border border-[#d7e8f6] text-ink-muted dark:text-slate-400 disabled:opacity-40" :disabled="page <= 1" @click="page -= 1">
                    <ChevronLeft class="size-4" />
                </button>
                <span class="flex size-8 items-center justify-center rounded-lg bg-[#1b7ad8] text-sm font-semibold text-white">{{ page }}</span>
                <button type="button" class="flex size-8 items-center justify-center rounded-lg border border-[#d7e8f6] text-ink-muted dark:text-slate-400 disabled:opacity-40" :disabled="page >= totalPages" @click="page += 1">
                    <ChevronRight class="size-4" />
                </button>
            </div>
        </div>
    </section>

    <section
        v-else
        class="overflow-hidden rounded-[16px] border border-[#d7e8f6] bg-white dark:border-white/10 dark:bg-white/5 shadow-[0_2px_8px_rgba(15,76,138,0.05)]"
    >
                <div class="flex items-start justify-between gap-3 border-b border-[#eef4f9] dark:border-white/10 px-4 py-3">
                    <div>
                        <h2 class="text-base font-semibold tracking-tight text-[#0f4c8a] dark:text-sky-100">
                            {{ selectedBoat.name }} — {{ periodLabel }}
                        </h2>
                        <p class="mt-0.5 text-xs text-ink-muted dark:text-slate-400">
                            Operator: {{ selectedBoat.operator }}
                            <span class="mx-1.5 text-[#c5d6e6]">|</span>
                            {{ selectedBoat.totalTrips }} trips
                            <span class="mx-1.5 text-[#c5d6e6]">|</span>
                            {{ selectedBoat.totalPassengers }} total passengers
                            <span class="mx-1.5 text-[#c5d6e6]">|</span>
                            {{ formatPeso(selectedBoat.totalRevenue) }} total revenue
                        </p>
                    </div>
                    <div class="flex items-center gap-2">
                        <DropdownMenu>
                            <DropdownMenuTrigger as-child>
                                <Button
                                    type="button"
                                    class="h-9 rounded-xl bg-[#1b7ad8] px-3 text-sm text-white hover:bg-[#1567c4]"
                                    :disabled="!canViewPassengerDetails"
                                >
                                    <FileDown class="size-4" />
                                    Download Report
                                    <ChevronDown class="size-4" />
                                </Button>
                            </DropdownMenuTrigger>
                            <DropdownMenuContent align="end" class="min-w-44 rounded-xl border-[#d7e8f6] dark:border-white/10 dark:bg-slate-900">
                                <DropdownMenuItem class="cursor-pointer text-ink dark:text-sky-50" @click="downloadBoat('pdf')">PDF</DropdownMenuItem>
                                <DropdownMenuItem class="cursor-pointer text-ink dark:text-sky-50" @click="downloadBoat('excel')">Excel</DropdownMenuItem>
                                <DropdownMenuItem class="cursor-pointer text-ink dark:text-sky-50" @click="downloadBoat('csv')">CSV</DropdownMenuItem>
                                <DropdownMenuItem class="cursor-pointer text-ink dark:text-sky-50" @click="downloadBoat('print')">Print</DropdownMenuItem>
                            </DropdownMenuContent>
                        </DropdownMenu>
                        <button type="button" class="rounded-full p-1.5 text-ink-muted dark:text-slate-400 hover:bg-[#f4f9fd]" aria-label="Back to boat list" @click="closeBoat">
                            <X class="size-4" />
                        </button>
                    </div>
                </div>

                <div class="space-y-4 overflow-y-auto p-5">
                    <article
                        v-for="(trip, tripIndex) in selectedBoat.trips"
                        :key="trip.recordKey"
                        class="overflow-hidden rounded-xl border border-[#e4eef6]"
                    >
                        <div class="flex flex-wrap items-start justify-between gap-3 bg-[#f8fbfe] px-4 py-3 dark:bg-[#0b1b30] dark:text-slate-100">
                            <div class="flex items-start gap-3">
                                <div
                                    class="mt-0.5 flex size-8 items-center justify-center rounded-lg"
                                    :class="tripArrowClass(trip)"
                                >
                                    <ArrowLeft v-if="trip.is_outbound === false" class="size-4" />
                                    <ArrowRight v-else class="size-4" />
                                </div>
                                <div>
                                    <p class="text-sm font-semibold text-[#0f4c8a] dark:text-sky-100">
                                        Trip {{ tripIndex + 1 }} — {{ trip.leg_label || (trip.is_outbound === false ? 'Return' : 'Going') }}
                                        <span class="ml-2 font-medium text-ink-soft">{{ trip.departure_time_label }}</span>
                                    </p>
                                    <p class="mt-0.5 text-xs text-ink-muted dark:text-slate-400">
                                        {{ trip.direction }}
                                        <span class="mx-1.5">|</span>
                                        Trip ID: {{ trip.code }}
                                    </p>
                                </div>
                            </div>
                            <div class="flex items-center gap-3">
                                <span
                                    class="rounded-full px-2.5 py-0.5 text-[11px] font-semibold"
                                    :class="tripStatusClass(trip.report_status || trip.status)"
                                >
                                    {{ trip.report_status_label || trip.status_label }}
                                </span>
                                <div class="text-right">
                                    <p class="text-[11px] text-ink-muted dark:text-slate-400">Trip Total</p>
                                    <p class="text-sm font-semibold text-[#0f4c8a] dark:text-sky-100">{{ trip.revenue_label || formatPeso(Number(trip.revenue || 0)) }}</p>
                                </div>
                            </div>
                        </div>

                        <div class="overflow-x-auto">
                            <table class="w-full min-w-[640px] text-left text-sm">
                                <thead class="bg-white text-[11px] tracking-wide text-ink-soft uppercase dark:bg-white/5 dark:text-slate-400">
                                    <tr>
                                        <th class="px-3 py-2 font-semibold">#</th>
                                        <th class="px-3 py-2 font-semibold">Full Name</th>
                                        <th class="px-3 py-2 font-semibold">Age</th>
                                        <th class="px-3 py-2 font-semibold">Fare</th>
                                        <th class="px-3 py-2 font-semibold">Gender</th>
                                        <th class="px-3 py-2 font-semibold">Contact Number</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-if="!canViewPassengerDetails">
                                        <td colspan="6" class="px-3 py-4 text-center text-sm text-[#d4922a]">
                                            Passenger names and contact numbers are available to authorized administrators only.
                                        </td>
                                    </tr>
                                    <tr v-else-if="!trip.passengers?.length">
                                        <td colspan="6" class="px-3 py-4 text-center text-sm text-ink-muted dark:text-slate-400">
                                            No passengers have been recorded for this trip.
                                        </td>
                                    </tr>
                                    <template v-else>
                                        <tr
                                            v-for="(passenger, index) in trip.passengers"
                                            :key="passenger.id"
                                            class="border-t border-[#eef4f9] dark:border-white/10"
                                        >
                                            <td class="px-3 py-2 text-ink-muted dark:text-slate-400">{{ index + 1 }}</td>
                                            <td class="px-3 py-2 font-medium text-[#0f4c8a] dark:text-sky-100">{{ passenger.full_name }}</td>
                                            <td class="px-3 py-2 text-ink dark:text-sky-50">{{ passenger.age }}</td>
                                            <td class="px-3 py-2 text-ink dark:text-sky-50">{{ passenger.fare_amount_label || '—' }}</td>
                                            <td class="px-3 py-2 text-ink dark:text-sky-50">{{ passenger.gender }}</td>
                                            <td class="px-3 py-2 text-ink dark:text-sky-50">{{ passenger.contact_number }}</td>
                                        </tr>
                                    </template>
                                </tbody>
                            </table>
                        </div>
                    </article>
                </div>

                <div class="grid grid-cols-3 gap-3 border-t border-[#eef4f9] dark:border-white/10 bg-[#f8fbfe] px-5 py-4">
                    <div class="flex items-center gap-2">
                        <span class="flex size-8 items-center justify-center rounded-lg bg-[#dbeafe] text-[#1d4ed8]">
                            <Ship class="size-4" />
                        </span>
                        <div>
                            <p class="text-[11px] text-ink-muted dark:text-slate-400">Total Trips</p>
                            <p class="text-sm font-semibold text-[#0f4c8a] dark:text-sky-100">{{ selectedBoat.totalTrips }}</p>
                        </div>
                    </div>
                    <div class="flex items-center gap-2">
                        <span class="flex size-8 items-center justify-center rounded-lg bg-[#dcfce7] text-[#15803d]">
                            <Users class="size-4" />
                        </span>
                        <div>
                            <p class="text-[11px] text-ink-muted dark:text-slate-400">Total Passengers</p>
                            <p class="text-sm font-semibold text-[#0f4c8a] dark:text-sky-100">{{ selectedBoat.totalPassengers }}</p>
                        </div>
                    </div>
                    <div class="flex items-center gap-2">
                        <span class="flex size-8 items-center justify-center rounded-lg bg-[#e0e7ff] text-[#3730a3]">
                            <Wallet class="size-4" />
                        </span>
                        <div>
                            <p class="text-[11px] text-ink-muted dark:text-slate-400">Total Revenue</p>
                            <p class="text-sm font-semibold text-[#0f4c8a] dark:text-sky-100">{{ formatPeso(selectedBoat.totalRevenue) }}</p>
                        </div>
                    </div>
                </div>
    </section>
</template>
