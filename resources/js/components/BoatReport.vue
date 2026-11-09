<script setup lang="ts">
import {
    CalendarDays,
    ChevronDown,
    ChevronLeft,
    ChevronRight,
    CircleCheck,
    FileDown,
    RotateCcw,
    Search,
    ShieldAlert,
    Ship,
    UserRound,
    Wallet,
    Wrench,
    X,
} from '@lucide/vue';
import { computed, ref } from 'vue';
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
import { downloadReport, type ReportDownloadFormat, type ReportExportSpec } from '@/lib/reportExport';
import type { BoatUsageRow, ReportBooking, ReportRegularTrip } from '@/types/report';

type StatusFilter = 'all' | 'active' | 'maintenance' | 'unavailable';
type HistoryStatusFilter = 'all' | 'completed' | 'approved' | 'on_trip' | 'scheduled' | 'pending' | 'cancelled';
type RegularLegFilter = 'all' | 'going' | 'return';
type DetailTab = 'trips' | 'maintenance' | 'summary';

type HistoryRow = {
    id: string;
    date: string;
    dateTimeLabel: string;
    reference: string;
    kind: 'regular' | 'rental';
    leg?: 'going' | 'return';
    typeLabel: string;
    route: string;
    passengers: number;
    amount: number;
    amountLabel: string;
    status: string;
    statusLabel: string;
};

const props = defineProps<{
    boats: BoatUsageRow[];
    trips: ReportRegularTrip[];
    bookings: ReportBooking[];
}>();

function formatPeso(value: number) {
    return `₱${value.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
}

function statusKey(status?: string) {
    if (status === 'completed') {
        return 'completed';
    }

    if (status === 'approved' || status === 'confirmed') {
        return 'approved';
    }

    if (['pending', 'for_verification', 'unpaid', 'waiting'].includes(status || '')) {
        return 'pending';
    }

    if (['on_trip', 'ongoing'].includes(status || '')) {
        return 'on_trip';
    }

    if (status === 'cancelled' || status === 'rejected') {
        return 'cancelled';
    }

    return 'scheduled';
}

function statusTone(status: string) {
    if (status === 'active' || status === 'completed' || status === 'approved') {
        return 'bg-[#dcfce7] text-[#15803d] dark:bg-emerald-500/20 dark:text-emerald-200';
    }

    if (status === 'maintenance' || status === 'pending') {
        return 'bg-[#fef3c7] text-[#d97706] dark:bg-amber-500/20 dark:text-amber-200';
    }

    if (status === 'on_trip') {
        return 'bg-[#dbeafe] text-[#1d4ed8] dark:bg-sky-500/20 dark:text-sky-200';
    }

    if (status === 'unavailable' || status === 'cancelled') {
        return 'bg-[#fee2e2] text-[#b91c1c] dark:bg-rose-500/20 dark:text-rose-200';
    }

    return 'bg-[#e8f1fb] text-[#1b7ad8] dark:bg-sky-500/20 dark:text-sky-200';
}

const draftStatus = ref<StatusFilter>('all');
const draftOperator = ref('all');
const draftSearch = ref('');
const statusFilter = ref<StatusFilter>('all');
const operatorFilter = ref('all');
const search = ref('');
const page = ref(1);
const pageSize = ref(10);
const selectedId = ref<number | null>(null);
const detailTab = ref<DetailTab>('trips');
const regularLeg = ref<RegularLegFilter>('all');
const regularStatus = ref<HistoryStatusFilter>('all');
const regularSearch = ref('');
const regularPage = ref(1);
const rentalStatus = ref<HistoryStatusFilter>('all');
const rentalSearch = ref('');
const rentalPage = ref(1);
const historyPageSize = 10;
const maintenanceSearch = ref('');
const maintenancePage = ref(1);

const operators = computed(() =>
    [...new Set(props.boats.map((boat) => boat.operator_name).filter((name): name is string => Boolean(name)))]
        .sort((left, right) => left.localeCompare(right)),
);

function reportStatus(boat: BoatUsageRow) {
    return boat.report_status || (boat.in_maintenance ? 'maintenance' : 'active');
}

function reportStatusLabel(boat: BoatUsageRow) {
    return boat.report_status_label || (boat.in_maintenance ? 'Under Maintenance' : boat.status_label || 'Active');
}

const filteredBoats = computed(() =>
    props.boats.filter((boat) => {
        if (statusFilter.value !== 'all' && reportStatus(boat) !== statusFilter.value) {
            return false;
        }

        if (operatorFilter.value !== 'all' && (boat.operator_name || '') !== operatorFilter.value) {
            return false;
        }

        if (search.value) {
            const haystack = `${boat.name} ${boat.operator_name || ''} ${boat.code}`.toLowerCase();

            if (!haystack.includes(search.value.toLowerCase())) {
                return false;
            }
        }

        return true;
    }),
);

const totalPages = computed(() => Math.max(1, Math.ceil(filteredBoats.value.length / pageSize.value)));
const pagedBoats = computed(() => {
    const start = (page.value - 1) * pageSize.value;

    return filteredBoats.value.slice(start, start + pageSize.value);
});
const showingFrom = computed(() => (filteredBoats.value.length === 0 ? 0 : (page.value - 1) * pageSize.value + 1));
const showingTo = computed(() => Math.min(page.value * pageSize.value, filteredBoats.value.length));

const selectedBoat = computed(() => props.boats.find((boat) => boat.id === selectedId.value) ?? null);

const violationCount = computed(() => selectedBoat.value?.violation_count ?? selectedBoat.value?.violations?.length ?? 0);

function violationTone(count: number): string {
    if (count >= 3) {
        return 'bg-red-100 text-red-700';
    }

    if (count === 2) {
        return 'bg-orange-100 text-orange-700';
    }

    if (count === 1) {
        return 'bg-amber-100 text-amber-800';
    }

    return 'bg-emerald-50 text-emerald-700';
}

function boatHistory(boatId: number): HistoryRow[] {
    const tripRows = props.trips
        .filter((trip) => trip.boat.id === boatId)
        .map((trip) => ({
            id: `trip-${trip.id}`,
            date: trip.trip_date,
            dateTimeLabel: `${trip.trip_date_label}\n${trip.departure_time_label}`,
            reference: trip.code,
            kind: 'regular' as const,
            leg: trip.is_outbound === false ? 'return' : 'going',
            typeLabel: trip.is_outbound === false ? 'Regular (Return)' : 'Regular (Going)',
            route: trip.direction,
            passengers: Number(trip.passenger_count || 0),
            amount: Number(trip.revenue || 0),
            amountLabel: trip.revenue_label || formatPeso(Number(trip.revenue || 0)),
            status: statusKey(trip.report_status || trip.status),
            statusLabel: trip.report_status_label || trip.status_label,
        }));

    const rentalRows = props.bookings
        .filter((booking) => booking.boat.id === boatId)
        .map((booking) => {
            const confirmed = Number(booking.amount ?? booking.verified_amount ?? 0);
            const quoted = Number(booking.quoted_amount ?? 0);
            const amount = confirmed > 0 ? confirmed : quoted;

            return {
                id: `rental-${booking.id}`,
                date: booking.booking_date,
                dateTimeLabel: `${booking.booking_date_label}\n${booking.start_time_label}`,
                reference: booking.code,
                kind: 'rental' as const,
                typeLabel: 'Private Rental',
                route: booking.destination || '—',
                passengers: Number(booking.passenger_count || 0),
                amount,
                amountLabel: confirmed > 0 ? (booking.amount_label || formatPeso(confirmed)) : (booking.quoted_amount_label || formatPeso(amount)),
                status: statusKey(booking.report_status || booking.status),
                statusLabel: booking.report_status_label || booking.status_label,
            };
        });

    return [...tripRows, ...rentalRows].sort((left, right) => right.date.localeCompare(left.date));
}

function boatStats(boatId: number) {
    const boat = props.boats.find((item) => item.id === boatId);

    if (boat && boat.total_revenue !== undefined) {
        return {
            regularTrips: boat.regular_trips,
            rentalTrips: boat.private_bookings,
            completedTrips: boat.completed_trips ?? 0,
            passengers: boat.total_passengers ?? 0,
            revenue: Number(boat.total_revenue || 0),
        };
    }

    const rows = boatHistory(boatId);
    const regular = rows.filter((row) => row.kind === 'regular');
    const rental = rows.filter((row) => row.kind === 'rental' && row.status !== 'cancelled');

    return {
        regularTrips: regular.length,
        rentalTrips: rental.length,
        completedTrips: rows.filter((row) => row.status === 'completed').length,
        passengers: rows.reduce((sum, row) => sum + row.passengers, 0),
        revenue: rows.reduce((sum, row) => {
            if (row.status === 'cancelled') {
                return sum;
            }

            if (row.kind === 'regular') {
                return sum + row.amount;
            }

            const booking = props.bookings.find((item) => `rental-${item.id}` === row.id);

            return sum + Number(booking?.amount ?? 0);
        }, 0),
    };
}

const selectedStats = computed(() =>
    selectedBoat.value ? boatStats(selectedBoat.value.id) : null,
);

const selectedHistory = computed(() =>
    selectedBoat.value ? boatHistory(selectedBoat.value.id) : [],
);

function matchesHistory(row: HistoryRow, status: HistoryStatusFilter, query: string, leg: RegularLegFilter = 'all') {
    if (leg !== 'all' && row.leg !== leg) {
        return false;
    }

    if (status !== 'all' && row.status !== status) {
        return false;
    }

    if (query) {
        const haystack = `${row.reference} ${row.route} ${row.typeLabel}`.toLowerCase();

        if (!haystack.includes(query.toLowerCase())) {
            return false;
        }
    }

    return true;
}

const regularHistory = computed(() =>
    selectedHistory.value
        .filter((row) => row.kind === 'regular')
        .filter((row) => matchesHistory(row, regularStatus.value, regularSearch.value, regularLeg.value)),
);

const rentalHistory = computed(() =>
    selectedHistory.value
        .filter((row) => row.kind === 'rental')
        .filter((row) => matchesHistory(row, rentalStatus.value, rentalSearch.value)),
);

const regularTotalPages = computed(() => Math.max(1, Math.ceil(regularHistory.value.length / historyPageSize)));
const pagedRegular = computed(() => {
    const start = (regularPage.value - 1) * historyPageSize;

    return regularHistory.value.slice(start, start + historyPageSize);
});

const rentalTotalPages = computed(() => Math.max(1, Math.ceil(rentalHistory.value.length / historyPageSize)));
const pagedRental = computed(() => {
    const start = (rentalPage.value - 1) * historyPageSize;

    return rentalHistory.value.slice(start, start + historyPageSize);
});

const maintenanceRows = computed(() => {
    const rows = selectedBoat.value?.maintenance ?? [];
    const query = maintenanceSearch.value.trim().toLowerCase();

    if (!query) {
        return rows;
    }

    return rows.filter((row) => `${row.type} ${row.description || ''}`.toLowerCase().includes(query));
});
const maintenanceTotalPages = computed(() => Math.max(1, Math.ceil(maintenanceRows.value.length / 10)));
const pagedMaintenance = computed(() => {
    const start = (maintenancePage.value - 1) * 10;

    return maintenanceRows.value.slice(start, start + 10);
});

function typeBadge(row: HistoryRow) {
    if (row.kind === 'rental') {
        return 'bg-[#f3e8ff] text-[#7c3aed]';
    }

    if (row.leg === 'return') {
        return 'bg-[#ede9fe] text-[#6d28d9]';
    }

    return 'bg-[#dbeafe] text-[#1d4ed8]';
}

const monthLabels = computed(() => {
    const labels: { key: string; label: string }[] = [];
    const cursor = new Date();
    cursor.setMonth(cursor.getMonth() - 4);
    cursor.setDate(1);

    for (let index = 0; index < 5; index += 1) {
        const key = `${cursor.getFullYear()}-${String(cursor.getMonth() + 1).padStart(2, '0')}`;
        labels.push({
            key,
            label: cursor.toLocaleDateString('en-US', { month: 'short' }),
        });
        cursor.setMonth(cursor.getMonth() + 1);
    }

    return labels;
});

const monthlyCharts = computed(() => {
    if (!selectedBoat.value) {
        return [];
    }

    const rows = selectedHistory.value;

    return monthLabels.value.map((month) => {
        const monthRows = rows.filter((row) => row.date.startsWith(month.key) && row.status !== 'cancelled');

        return {
            ...month,
            regularTrips: monthRows.filter((row) => row.kind === 'regular').length,
            rentalTrips: monthRows.filter((row) => row.kind === 'rental').length,
            regularRevenue: monthRows.filter((row) => row.kind === 'regular').reduce((sum, row) => sum + row.amount, 0),
            rentalRevenue: monthRows
                .filter((row) => row.kind === 'rental')
                .reduce((sum, row) => sum + Number(props.bookings.find((booking) => `rental-${booking.id}` === row.id)?.amount ?? 0), 0),
        };
    });
});

const tripChartMax = computed(() =>
    Math.max(1, ...monthlyCharts.value.map((month) => month.regularTrips + month.rentalTrips)),
);
const revenueChartMax = computed(() =>
    Math.max(1, ...monthlyCharts.value.map((month) => month.regularRevenue + month.rentalRevenue)),
);

function applyFilters() {
    statusFilter.value = draftStatus.value;
    operatorFilter.value = draftOperator.value;
    search.value = draftSearch.value.trim();
    page.value = 1;
}

function resetFilters() {
    draftStatus.value = 'all';
    draftOperator.value = 'all';
    draftSearch.value = '';
    applyFilters();
}

function viewBoat(id: number) {
    selectedId.value = id;
    detailTab.value = 'trips';
    regularLeg.value = 'all';
    regularStatus.value = 'all';
    regularSearch.value = '';
    regularPage.value = 1;
    rentalStatus.value = 'all';
    rentalSearch.value = '';
    rentalPage.value = 1;
    maintenanceSearch.value = '';
    maintenancePage.value = 1;
}

function closeDetails() {
    selectedId.value = null;
}

function boatExportSpec(boat: BoatUsageRow): ReportExportSpec {
    const stats = boatStats(boat.id);
    const history = boatHistory(boat.id);
    const maintenance = boat.maintenance ?? [];

    return {
        title: `Boat Report — ${boat.name}`,
        subtitle: `${reportStatusLabel(boat)} · ${boat.operator_name || 'Unassigned'}`,
        filename: `boat-report-${boat.name.toLowerCase().replaceAll(/\s+/g, '-')}`,
        sectionTitle: 'Trip and Rental History',
        columns: [
            { key: 'datetime', header: 'Date & Time' },
            { key: 'reference', header: 'Trip ID' },
            { key: 'type', header: 'Type' },
            { key: 'route', header: 'Route / Destination' },
            { key: 'passengers', header: 'Passengers' },
            { key: 'amount', header: 'Fare / Amount' },
            { key: 'status', header: 'Status' },
        ],
        rows: history.map((row) => ({
            datetime: row.dateTimeLabel.replace('\n', ' '),
            reference: row.reference,
            type: row.typeLabel,
            route: row.route,
            passengers: row.passengers,
            amount: row.amountLabel,
            status: row.statusLabel,
        })),
        stats: [
            { label: 'Regular Trips', value: String(stats.regularTrips) },
            { label: 'Private Rentals', value: String(stats.rentalTrips) },
            { label: 'Completed Trips', value: String(stats.completedTrips) },
            { label: 'Total Passengers', value: String(stats.passengers) },
            { label: 'Total Revenue', value: formatPeso(stats.revenue) },
            { label: 'Maintenance Records', value: String(maintenance.length) },
        ],
        emptyMessage: 'No trip or rental records for this boat.',
        note: maintenance.length
            ? `Maintenance records: ${maintenance.map((row) => `${row.date_label || '—'} · ${row.type}`).join('; ')}.`
            : 'No maintenance records are stored for this boat.',
    };
}

function fleetExportSpec(): ReportExportSpec {
    return {
        title: 'Boat Report',
        subtitle: 'Registered boats and usage',
        filename: 'boat-report-all',
        sectionTitle: 'Registered Boats',
        columns: [
            { key: 'boat', header: 'Boat Name' },
            { key: 'operator', header: 'Operator' },
            { key: 'capacity', header: 'Capacity' },
            { key: 'status', header: 'Status' },
            { key: 'regular', header: 'Regular Trips' },
            { key: 'rental', header: 'Rental Trips' },
            { key: 'completed', header: 'Completed Trips' },
            { key: 'revenue', header: 'Total Revenue' },
            { key: 'maintenance', header: 'Last Maintenance' },
            { key: 'violations', header: 'Violations' },
            { key: 'account', header: 'Account' },
        ],
        rows: filteredBoats.value.map((boat) => {
            const stats = boatStats(boat.id);

            return {
                boat: boat.name,
                operator: boat.operator_name || 'Unassigned',
                capacity: boat.capacity ?? '—',
                status: reportStatusLabel(boat),
                regular: stats.regularTrips,
                rental: stats.rentalTrips,
                completed: stats.completedTrips,
                revenue: formatPeso(stats.revenue),
                maintenance: boat.last_maintenance_label || '—',
                violations: boat.violation_count ?? 0,
                account: boat.suspended ? `Suspended ${boat.suspension_start || ''} – ${boat.suspension_end || ''}` : 'Active',
            };
        }),
        emptyMessage: 'No registered boats match the selected filters.',
    };
}

function exportBoatsCsvQuery(boatId?: number) {
    const params = new URLSearchParams({
        status: statusFilter.value,
        operator: operatorFilter.value,
        search: search.value,
    });

    if (boatId !== undefined) {
        params.set('boat_id', String(boatId));
    }

    return params.toString();
}

function downloadBoat(format: ReportDownloadFormat) {
    if (!selectedBoat.value) {
        return;
    }

    if (format === 'csv') {
        window.location.href = `/reports/export/boats?${exportBoatsCsvQuery(selectedBoat.value.id)}`;
        return;
    }

    downloadReport(format, boatExportSpec(selectedBoat.value));
}

function downloadAll(format: ReportDownloadFormat) {
    if (format === 'csv') {
        window.location.href = `/reports/export/boats?${exportBoatsCsvQuery()}`;
        return;
    }

    downloadReport(format, fleetExportSpec());
}

function displayRevenue(boat: BoatUsageRow) {
    if (boat.total_revenue_label) {
        return boat.total_revenue_label;
    }

    return formatPeso(boatRowStats(boat).revenue);
}

function boatRowStats(boat: BoatUsageRow) {
    return boatStats(boat.id);
}
</script>

<template>
    <div class="space-y-4">
        <section class="rounded-[16px] border border-[#d7e8f6] bg-white dark:border-white/10 dark:bg-white/5 px-4 py-3 shadow-[0_2px_8px_rgba(15,76,138,0.05)]">
            <div class="flex flex-col gap-3 xl:flex-row xl:items-end">
                <label class="w-full xl:w-44">
                    <span class="mb-1.5 block text-xs font-semibold text-ink-soft dark:text-slate-400">Boat Status</span>
                    <select v-model="draftStatus" class="h-10 w-full rounded-xl border border-[#d7e8f6] bg-white px-3 text-sm text-ink outline-none dark:border-white/10 dark:bg-white/5 dark:text-sky-50">
                        <option value="all">All Status</option>
                        <option value="active">Active</option>
                        <option value="maintenance">Under Maintenance</option>
                        <option value="unavailable">Unavailable</option>
                    </select>
                </label>
                <label class="w-full xl:w-48">
                    <span class="mb-1.5 block text-xs font-semibold text-ink-soft dark:text-slate-400">Operator</span>
                    <select v-model="draftOperator" class="h-10 w-full rounded-xl border border-[#d7e8f6] bg-white px-3 text-sm text-ink outline-none dark:border-white/10 dark:bg-white/5 dark:text-sky-50">
                        <option value="all">All Operators</option>
                        <option v-for="name in operators" :key="name" :value="name">{{ name }}</option>
                    </select>
                </label>
                <label class="min-w-0 flex-1">
                    <span class="mb-1.5 block text-xs font-semibold text-ink-soft dark:text-slate-400">Search</span>
                    <div class="flex h-10 items-center gap-2 rounded-xl border border-[#d7e8f6] bg-white dark:border-white/10 dark:bg-white/5 px-3">
                        <Search class="size-4 text-ink-muted dark:text-slate-400" />
                        <input
                            v-model="draftSearch"
                            type="search"
                            placeholder="Search boat name, operator..."
                            class="min-w-0 flex-1 border-0 bg-transparent text-sm text-ink dark:text-sky-50 outline-none"
                            @keydown.enter.prevent="applyFilters"
                        />
                    </div>
                </label>
                <div class="flex shrink-0 items-end gap-2">
                    <Button class="h-10 rounded-xl bg-[#1b7ad8] px-4 text-sm font-semibold text-white hover:bg-[#1567c4]" @click="applyFilters">
                        <Search class="size-4" />
                        Search
                    </Button>
                    <Button variant="outline" class="h-10 rounded-xl border-[#d7e8f6] bg-white dark:border-white/10 dark:bg-white/5 px-4 text-sm font-semibold text-ink dark:text-sky-50 hover:bg-[#f8fbff]" @click="resetFilters">
                        <RotateCcw class="size-4" />
                        Reset
                    </Button>
                </div>
            </div>
        </section>

        <section class="overflow-hidden rounded-[16px] border border-[#d7e8f6] bg-white dark:border-white/10 dark:bg-white/5 shadow-[0_2px_8px_rgba(15,76,138,0.05)]">
            <div class="flex flex-wrap items-center justify-between gap-2 px-4 py-3">
                <div>
                    <h2 class="text-base font-semibold text-[#0f4c8a] dark:text-sky-100">Registered Boats</h2>
                    <p class="text-xs text-ink-muted dark:text-slate-400">Showing {{ showingFrom }}–{{ showingTo }} of {{ filteredBoats.length }} boats</p>
                </div>
                <DropdownMenu>
                    <DropdownMenuTrigger as-child>
                        <Button
                            type="button"
                            class="h-9 rounded-xl bg-[#1b7ad8] px-3 text-sm text-white hover:bg-[#1567c4]"
                            :disabled="filteredBoats.length === 0"
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
            <div class="overflow-x-auto">
                <table class="w-full min-w-[980px] text-left text-sm">
                    <thead class="bg-[#f4f9fd] text-[11px] tracking-wide text-ink-soft uppercase dark:bg-white/5 dark:text-slate-400">
                        <tr>
                            <th class="px-3 py-2.5 font-semibold">#</th>
                            <th class="px-3 py-2.5 font-semibold">Boat Name</th>
                            <th class="px-3 py-2.5 font-semibold">Operator</th>
                            <th class="px-3 py-2.5 font-semibold">Capacity</th>
                            <th class="px-3 py-2.5 font-semibold">Status</th>
                            <th class="px-3 py-2.5 font-semibold">Regular Trips</th>
                            <th class="px-3 py-2.5 font-semibold">Rental Trips</th>
                            <th class="px-3 py-2.5 font-semibold">Completed Trips</th>
                            <th class="px-3 py-2.5 font-semibold">Last Maintenance</th>
                            <th class="px-3 py-2.5 font-semibold">Total Revenue</th>
                            <th class="px-3 py-2.5 font-semibold">Violations</th>
                            <th class="px-3 py-2.5 font-semibold">Account</th>
                            <th class="px-3 py-2.5 font-semibold">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-if="pagedBoats.length === 0">
                            <td colspan="13" class="px-3 py-8 text-center text-sm text-ink-muted dark:text-slate-400">No registered boats match the selected filters.</td>
                        </tr>
                        <tr
                            v-for="(boat, index) in pagedBoats"
                            :key="boat.id"
                            class="border-t border-[#eef4f9] dark:border-white/10"
                            :class="selectedId === boat.id ? 'bg-[#f3f8ff] dark:bg-white/10' : ''"
                        >
                            <td class="px-3 py-2.5 text-ink-muted dark:text-slate-400">{{ showingFrom + index }}</td>
                            <td class="px-3 py-2.5">
                                <button
                                    type="button"
                                    class="font-medium text-[#1b7ad8] hover:underline dark:text-sky-300"
                                    @click="viewBoat(boat.id)"
                                >
                                    {{ boat.name }}
                                </button>
                            </td>
                            <td class="px-3 py-2.5 text-ink dark:text-sky-50">{{ boat.operator_name || 'Unassigned' }}</td>
                            <td class="px-3 py-2.5 text-ink dark:text-sky-50">{{ boat.capacity ?? '—' }}</td>
                            <td class="px-3 py-2.5">
                                <span class="inline-flex rounded-full px-2.5 py-0.5 text-[11px] font-semibold" :class="statusTone(reportStatus(boat))">
                                    {{ reportStatusLabel(boat) }}
                                </span>
                            </td>
                            <td class="px-3 py-2.5 text-ink dark:text-sky-50">{{ boatRowStats(boat).regularTrips }}</td>
                            <td class="px-3 py-2.5 text-ink dark:text-sky-50">{{ boatRowStats(boat).rentalTrips }}</td>
                            <td class="px-3 py-2.5 text-ink dark:text-sky-50">{{ boatRowStats(boat).completedTrips }}</td>
                            <td class="px-3 py-2.5 whitespace-nowrap text-ink dark:text-sky-50">{{ boat.last_maintenance_label || '—' }}</td>
                            <td class="px-3 py-2.5 font-medium text-[#0f4c8a] dark:text-sky-100">{{ displayRevenue(boat) }}</td>
                            <td class="px-3 py-2.5 font-semibold" :class="(boat.violation_count ?? 0) > 0 ? 'text-rose-700' : 'text-ink'">{{ boat.violation_count ?? 0 }}</td>
                            <td class="px-3 py-2.5">
                                <span v-if="boat.suspended" class="font-semibold text-rose-700">Suspended</span>
                                <span v-else>Active</span>
                            </td>
                            <td class="px-3 py-2.5">
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
            <div class="flex flex-wrap items-center justify-between gap-3 border-t border-[#eef4f9] dark:border-white/10 px-4 py-3">
                <label class="flex items-center gap-2 text-xs text-ink-muted dark:text-slate-400">
                    Show
                    <span class="relative inline-flex">
                        <select v-model.number="pageSize" class="h-8 appearance-none rounded-lg border border-[#d7e8f6] bg-white py-0 pr-7 pl-2 text-sm text-ink outline-none dark:border-white/15 dark:bg-slate-800 dark:text-sky-50">
                            <option :value="10">10</option>
                            <option :value="25">25</option>
                        </select>
                        <ChevronDown class="pointer-events-none absolute top-1/2 right-1.5 size-3.5 -translate-y-1/2 text-ink-muted dark:text-slate-400" />
                    </span>
                    entries
                </label>
                <div class="flex items-center gap-1">
                    <button type="button" class="flex size-8 items-center justify-center rounded-lg text-ink-soft disabled:opacity-40" :disabled="page <= 1" @click="page -= 1">
                        <ChevronLeft class="size-4" />
                    </button>
                    <span class="flex size-8 items-center justify-center rounded-lg bg-[#1b7ad8] text-sm font-semibold text-white">{{ page }}</span>
                    <button type="button" class="flex size-8 items-center justify-center rounded-lg text-ink-soft disabled:opacity-40" :disabled="page >= totalPages" @click="page += 1">
                        <ChevronRight class="size-4" />
                    </button>
                </div>
            </div>
        </section>

        <Dialog :open="!!selectedBoat" @update:open="(open) => { if (!open) closeDetails() }">
            <DialogContent
                v-if="selectedBoat && selectedStats"
                :show-close-button="false"
                class="flex max-h-[90vh] w-full max-w-[calc(100%-1.5rem)] flex-col gap-0 overflow-hidden rounded-2xl border-[#d7e8f6] p-0 sm:max-w-5xl dark:border-white/10 dark:bg-slate-900"
            >
            <div class="flex items-center justify-between border-b border-[#eef4f9] px-5 py-4 dark:border-white/10">
                <div class="flex items-center gap-2">
                    <DialogTitle class="text-lg font-semibold text-[#0f4c8a] dark:text-sky-100">Boat Details — {{ selectedBoat.name }}</DialogTitle>
                    <span class="inline-flex rounded-full px-2.5 py-0.5 text-[11px] font-semibold" :class="statusTone(reportStatus(selectedBoat))">
                        {{ reportStatusLabel(selectedBoat) }}
                    </span>
                </div>
                <div class="flex items-center gap-2">
                    <DropdownMenu>
                        <DropdownMenuTrigger as-child>
                            <Button type="button" class="h-9 rounded-xl bg-[#1b7ad8] px-3 text-sm text-white hover:bg-[#1567c4]">
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
                    <button type="button" class="rounded-full p-1.5 text-ink-muted dark:text-slate-400 hover:bg-[#f4f9fd]" aria-label="Close details" @click="closeDetails">
                        <X class="size-5" />
                    </button>
                </div>
            </div>
            <DialogDescription class="sr-only">
                Trip, rental, and maintenance records for {{ selectedBoat.name }}.
            </DialogDescription>
            <div class="min-h-0 flex-1 overflow-y-auto">

            <div class="grid gap-5 px-5 py-4 lg:grid-cols-[280px_minmax(0,1fr)]">
                <div class="overflow-hidden rounded-2xl border border-[#d7e8f6] bg-[#f4f9fd] dark:border-white/10 dark:bg-white/5">
                    <img
                        v-if="selectedBoat.picture_url"
                        :src="selectedBoat.picture_url"
                        :alt="selectedBoat.name"
                        class="h-52 w-full object-cover"
                    />
                    <div v-else class="flex h-52 items-center justify-center text-ink-muted dark:text-slate-400">
                        <Ship class="size-12" />
                    </div>
                </div>
                <dl class="grid gap-4 sm:grid-cols-2">
                    <div class="flex items-start gap-3">
                        <UserRound class="mt-0.5 size-5 text-[#1b7ad8] dark:text-sky-300" />
                        <div>
                            <dt class="text-xs text-ink-muted dark:text-slate-400">Operator</dt>
                            <dd class="font-semibold text-[#0f4c8a] dark:text-sky-100">{{ selectedBoat.operator_name || 'Unassigned' }}</dd>
                            <dd v-if="selectedBoat.suspended" class="text-xs font-semibold text-rose-700">Suspended · {{ selectedBoat.suspension_start }} – {{ selectedBoat.suspension_end }}</dd>
                            <dd v-else class="text-xs text-ink-muted">Account active</dd>
                        </div>
                    </div>
                    <div class="flex items-start gap-3">
                        <CircleCheck class="mt-0.5 size-5 text-[#16a34a]" />
                        <div>
                            <dt class="text-xs text-ink-muted dark:text-slate-400">Status</dt>
                            <dd class="font-semibold text-[#0f4c8a] dark:text-sky-100">{{ selectedBoat.status_label || reportStatusLabel(selectedBoat) }}</dd>
                        </div>
                    </div>
                    <div class="flex items-start gap-3">
                        <Ship class="mt-0.5 size-5 text-[#1b7ad8] dark:text-sky-300" />
                        <div>
                            <dt class="text-xs text-ink-muted dark:text-slate-400">Capacity</dt>
                            <dd class="font-semibold text-[#0f4c8a] dark:text-sky-100">{{ selectedBoat.capacity ?? '—' }} passengers</dd>
                        </div>
                    </div>
                    <div class="flex items-start gap-3">
                        <Ship class="mt-0.5 size-5 text-[#1b7ad8] dark:text-sky-300" />
                        <div>
                            <dt class="text-xs text-ink-muted dark:text-slate-400">Total Regular Trips</dt>
                            <dd class="font-semibold text-[#0f4c8a] dark:text-sky-100">{{ selectedStats.regularTrips }}</dd>
                        </div>
                    </div>
                    <div class="flex items-start gap-3">
                        <Ship class="mt-0.5 size-5 text-[#1b7ad8] dark:text-sky-300" />
                        <div>
                            <dt class="text-xs text-ink-muted dark:text-slate-400">Boat Type</dt>
                            <dd class="font-semibold text-[#0f4c8a] dark:text-sky-100">{{ selectedBoat.type || 'Pump Boat' }}</dd>
                        </div>
                    </div>
                    <div class="flex items-start gap-3">
                        <CalendarDays class="mt-0.5 size-5 text-[#1b7ad8] dark:text-sky-300" />
                        <div>
                            <dt class="text-xs text-ink-muted dark:text-slate-400">Rental Trips</dt>
                            <dd class="font-semibold text-[#0f4c8a] dark:text-sky-100">{{ selectedStats.rentalTrips }}</dd>
                        </div>
                    </div>
                    <div class="flex items-start gap-3 sm:col-span-2">
                        <Wallet class="mt-0.5 size-5 text-[#1b7ad8] dark:text-sky-300" />
                        <div>
                            <dt class="text-xs text-ink-muted dark:text-slate-400">Total Revenue</dt>
                            <dd class="font-semibold text-[#0f4c8a] dark:text-sky-100">{{ formatPeso(selectedStats.revenue) }}</dd>
                        </div>
                    </div>
                </dl>
            </div>
            <div class="px-5 pb-2">
                <div class="rounded-2xl border border-[#d7e8f6] bg-[#f8fbfe] p-4 dark:border-white/10 dark:bg-white/5">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <h3 class="flex items-center gap-2 text-sm font-semibold text-[#0f4c8a] dark:text-sky-100">
                                <ShieldAlert class="size-4" :class="violationCount >= 3 ? 'text-red-600' : violationCount > 0 ? 'text-amber-600' : 'text-emerald-600'" />
                                Operator violations
                            </h3>
                            <p class="mt-1 max-w-xl text-xs leading-5 text-ink-muted">
                                A violation is counted only after an admin confirms the operator did not show. Three confirmed violations suspend the account for 7 days.
                            </p>
                        </div>
                        <span
                            class="inline-flex items-center rounded-full px-3 py-1 text-xs font-semibold"
                            :class="violationTone(violationCount)"
                        >
                            {{ violationCount }} of 3
                        </span>
                    </div>
                    <div class="mt-3 h-2 overflow-hidden rounded-full bg-white dark:bg-white/10">
                        <div
                            class="h-full rounded-full"
                            :class="violationCount >= 3 ? 'bg-red-500' : violationCount === 2 ? 'bg-orange-500' : violationCount === 1 ? 'bg-amber-400' : 'bg-emerald-500'"
                            :style="{ width: `${Math.min(100, (violationCount / 3) * 100)}%` }"
                        />
                    </div>
                    <p v-if="!selectedBoat.violations?.length" class="mt-3 text-sm text-emerald-700">
                        No confirmed violations.
                    </p>
                    <ul v-else class="mt-3 space-y-2">
                        <li
                            v-for="violation in selectedBoat.violations"
                            :key="violation.id"
                            class="grid gap-2 rounded-xl border border-white bg-white px-3 py-3 sm:grid-cols-[auto_1fr_auto] sm:items-center dark:border-white/10 dark:bg-slate-900"
                        >
                            <span
                                class="inline-flex w-fit rounded-full px-2.5 py-1 text-xs font-semibold"
                                :class="violationTone(violation.strike || 1)"
                            >
                                Strike {{ violation.strike || '—' }}
                            </span>
                            <div class="min-w-0">
                                <p class="text-sm font-semibold text-[#0f4c8a] dark:text-sky-100">
                                    {{ violation.reason || 'Confirmed operator no-show' }}
                                </p>
                                <p class="mt-0.5 text-xs text-ink-muted">
                                    {{ violation.date_label || 'Date not recorded' }}
                                </p>
                            </div>
                            <p class="text-xs font-semibold text-[#1b7ad8]">
                                {{ violation.booking_code || 'No booking' }}
                            </p>
                        </li>
                    </ul>
                </div>
            </div>

            <div class="flex flex-wrap gap-2 px-5">
                <button
                    type="button"
                    class="inline-flex items-center gap-1.5 rounded-full px-4 py-1.5 text-sm font-semibold"
                    :class="detailTab === 'trips' ? 'bg-[#1b7ad8] text-white' : 'bg-[#eef5fb] text-ink-soft dark:bg-white/10 dark:text-slate-300'"
                    @click="detailTab = 'trips'"
                >
                    <Ship class="size-3.5" /> Trip History
                </button>
                <button
                    type="button"
                    class="inline-flex items-center gap-1.5 rounded-full px-4 py-1.5 text-sm font-semibold"
                    :class="detailTab === 'maintenance' ? 'bg-[#1b7ad8] text-white' : 'bg-[#eef5fb] text-ink-soft dark:bg-white/10 dark:text-slate-300'"
                    @click="detailTab = 'maintenance'"
                >
                    <Wrench class="size-3.5" /> Maintenance History
                </button>
                <button
                    type="button"
                    class="inline-flex items-center gap-1.5 rounded-full px-4 py-1.5 text-sm font-semibold"
                    :class="detailTab === 'summary' ? 'bg-[#1b7ad8] text-white' : 'bg-[#eef5fb] text-ink-soft dark:bg-white/10 dark:text-slate-300'"
                    @click="detailTab = 'summary'"
                >
                    Summary
                </button>
            </div>

            <div v-if="detailTab === 'trips'" class="space-y-6 p-5">
                <div>
                    <div class="mb-3 flex flex-wrap items-end justify-between gap-3">
                        <div>
                            <h3 class="text-sm font-semibold text-[#0f4c8a] dark:text-sky-100">Regular Trip History</h3>
                            <p class="text-xs text-ink-muted dark:text-slate-400">List of all regular trips for this boat.</p>
                        </div>
                        <div class="flex flex-wrap items-center gap-2">
                            <select v-model="regularLeg" class="h-9 rounded-xl border border-[#d7e8f6] px-3 text-xs text-ink dark:text-sky-50 outline-none" @change="regularPage = 1">
                                <option value="all">All Trip Types</option>
                                <option value="going">Going</option>
                                <option value="return">Return</option>
                            </select>
                            <select v-model="regularStatus" class="h-9 rounded-xl border border-[#d7e8f6] px-3 text-xs text-ink dark:text-sky-50 outline-none" @change="regularPage = 1">
                                <option value="all">All Status</option>
                                <option value="completed">Completed</option>
                                <option value="on_trip">On Trip</option>
                                <option value="scheduled">Scheduled</option>
                                <option value="cancelled">Cancelled</option>
                            </select>
                            <div class="flex h-9 items-center gap-2 rounded-xl border border-[#d7e8f6] px-3">
                                <Search class="size-3.5 text-ink-muted dark:text-slate-400" />
                                <input v-model="regularSearch" type="text" placeholder="Search trip ID, destination..." class="w-44 border-0 bg-transparent text-xs text-ink dark:text-sky-50 outline-none" @input="regularPage = 1" />
                            </div>
                        </div>
                    </div>
                    <div class="overflow-x-auto rounded-xl border border-[#e4eef6]">
                        <table class="w-full min-w-[860px] text-left text-sm">
                            <thead class="bg-[#f8fbfe] text-[11px] tracking-wide text-ink-soft uppercase dark:bg-white/5 dark:text-slate-400">
                                <tr>
                                    <th class="px-3 py-2.5 font-semibold">#</th>
                                    <th class="px-3 py-2.5 font-semibold">Date & Time</th>
                                    <th class="px-3 py-2.5 font-semibold">Trip ID</th>
                                    <th class="px-3 py-2.5 font-semibold">Type</th>
                                    <th class="px-3 py-2.5 font-semibold">Route / Destination</th>
                                    <th class="px-3 py-2.5 font-semibold">Passengers</th>
                                    <th class="px-3 py-2.5 font-semibold">Fare / Amount</th>
                                    <th class="px-3 py-2.5 font-semibold">Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-if="pagedRegular.length === 0">
                                    <td colspan="8" class="px-3 py-8 text-center text-sm text-ink-muted dark:text-slate-400">No regular trip records for this boat.</td>
                                </tr>
                                <tr v-for="(row, index) in pagedRegular" :key="row.id" class="border-t border-[#eef4f9] dark:border-white/10">
                                    <td class="px-3 py-2.5 text-ink-muted dark:text-slate-400">{{ (regularPage - 1) * historyPageSize + index + 1 }}</td>
                                    <td class="px-3 py-2.5 whitespace-pre-line text-ink dark:text-sky-50">{{ row.dateTimeLabel }}</td>
                                    <td class="px-3 py-2.5 font-medium text-[#1b7ad8] dark:text-sky-300">{{ row.reference }}</td>
                                    <td class="px-3 py-2.5">
                                        <span class="inline-flex rounded-full px-2.5 py-0.5 text-[11px] font-semibold" :class="typeBadge(row)">{{ row.typeLabel }}</span>
                                    </td>
                                    <td class="px-3 py-2.5 text-ink dark:text-sky-50">{{ row.route }}</td>
                                    <td class="px-3 py-2.5 text-ink dark:text-sky-50">{{ row.passengers }}</td>
                                    <td class="px-3 py-2.5 font-medium text-[#0f4c8a] dark:text-sky-100">{{ row.amountLabel }}</td>
                                    <td class="px-3 py-2.5">
                                        <span class="inline-flex rounded-full px-2.5 py-0.5 text-[11px] font-semibold" :class="statusTone(row.status)">{{ row.statusLabel }}</span>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <div class="mt-3 flex items-center justify-between">
                        <p class="text-xs text-ink-muted dark:text-slate-400">Show {{ historyPageSize }} entries</p>
                        <div class="flex items-center gap-1">
                            <button type="button" class="flex size-8 items-center justify-center rounded-lg text-ink-soft disabled:opacity-40" :disabled="regularPage <= 1" @click="regularPage -= 1">
                                <ChevronLeft class="size-4" />
                            </button>
                            <span class="flex size-8 items-center justify-center rounded-lg bg-[#1b7ad8] text-sm font-semibold text-white">{{ regularPage }}</span>
                            <button type="button" class="flex size-8 items-center justify-center rounded-lg text-ink-soft disabled:opacity-40" :disabled="regularPage >= regularTotalPages" @click="regularPage += 1">
                                <ChevronRight class="size-4" />
                            </button>
                        </div>
                    </div>
                </div>

                <div>
                    <div class="mb-3 flex flex-wrap items-end justify-between gap-3">
                        <div>
                            <h3 class="text-sm font-semibold text-[#0f4c8a] dark:text-sky-100">Private Rental History</h3>
                            <p class="text-xs text-ink-muted dark:text-slate-400">List of all private rentals for this boat.</p>
                        </div>
                        <div class="flex flex-wrap items-center gap-2">
                            <select v-model="rentalStatus" class="h-9 rounded-xl border border-[#d7e8f6] px-3 text-xs text-ink dark:text-sky-50 outline-none" @change="rentalPage = 1">
                                <option value="all">All Status</option>
                                <option value="approved">Approved</option>
                                <option value="completed">Completed</option>
                                <option value="on_trip">On Trip</option>
                                <option value="pending">Pending</option>
                                <option value="cancelled">Cancelled</option>
                            </select>
                            <div class="flex h-9 items-center gap-2 rounded-xl border border-[#d7e8f6] px-3">
                                <Search class="size-3.5 text-ink-muted dark:text-slate-400" />
                                <input v-model="rentalSearch" type="text" placeholder="Search rental ID, destination..." class="w-44 border-0 bg-transparent text-xs text-ink dark:text-sky-50 outline-none" @input="rentalPage = 1" />
                            </div>
                        </div>
                    </div>
                    <div class="overflow-x-auto rounded-xl border border-[#e4eef6]">
                        <table class="w-full min-w-[860px] text-left text-sm">
                            <thead class="bg-[#f8fbfe] text-[11px] tracking-wide text-ink-soft uppercase dark:bg-white/5 dark:text-slate-400">
                                <tr>
                                    <th class="px-3 py-2.5 font-semibold">#</th>
                                    <th class="px-3 py-2.5 font-semibold">Date & Time</th>
                                    <th class="px-3 py-2.5 font-semibold">Trip ID</th>
                                    <th class="px-3 py-2.5 font-semibold">Type</th>
                                    <th class="px-3 py-2.5 font-semibold">Route / Destination</th>
                                    <th class="px-3 py-2.5 font-semibold">Passengers</th>
                                    <th class="px-3 py-2.5 font-semibold">Fare / Amount</th>
                                    <th class="px-3 py-2.5 font-semibold">Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-if="pagedRental.length === 0">
                                    <td colspan="8" class="px-3 py-8 text-center text-sm text-ink-muted dark:text-slate-400">No private rental records for this boat.</td>
                                </tr>
                                <tr v-for="(row, index) in pagedRental" :key="row.id" class="border-t border-[#eef4f9] dark:border-white/10">
                                    <td class="px-3 py-2.5 text-ink-muted dark:text-slate-400">{{ (rentalPage - 1) * historyPageSize + index + 1 }}</td>
                                    <td class="px-3 py-2.5 whitespace-pre-line text-ink dark:text-sky-50">{{ row.dateTimeLabel }}</td>
                                    <td class="px-3 py-2.5 font-medium text-[#1b7ad8] dark:text-sky-300">{{ row.reference }}</td>
                                    <td class="px-3 py-2.5">
                                        <span class="inline-flex rounded-full px-2.5 py-0.5 text-[11px] font-semibold" :class="typeBadge(row)">{{ row.typeLabel }}</span>
                                    </td>
                                    <td class="px-3 py-2.5 text-ink dark:text-sky-50">{{ row.route }}</td>
                                    <td class="px-3 py-2.5 text-ink dark:text-sky-50">{{ row.passengers }}</td>
                                    <td class="px-3 py-2.5 font-medium text-[#0f4c8a] dark:text-sky-100">{{ row.amountLabel }}</td>
                                    <td class="px-3 py-2.5">
                                        <span class="inline-flex rounded-full px-2.5 py-0.5 text-[11px] font-semibold" :class="statusTone(row.status)">{{ row.statusLabel }}</span>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <div class="mt-3 flex items-center justify-between">
                        <p class="text-xs text-ink-muted dark:text-slate-400">Show {{ historyPageSize }} entries</p>
                        <div class="flex items-center gap-1">
                            <button type="button" class="flex size-8 items-center justify-center rounded-lg text-ink-soft disabled:opacity-40" :disabled="rentalPage <= 1" @click="rentalPage -= 1">
                                <ChevronLeft class="size-4" />
                            </button>
                            <span class="flex size-8 items-center justify-center rounded-lg bg-[#1b7ad8] text-sm font-semibold text-white">{{ rentalPage }}</span>
                            <button type="button" class="flex size-8 items-center justify-center rounded-lg text-ink-soft disabled:opacity-40" :disabled="rentalPage >= rentalTotalPages" @click="rentalPage += 1">
                                <ChevronRight class="size-4" />
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <div v-else-if="detailTab === 'maintenance'" class="p-5">
                <div class="mb-3 flex flex-wrap items-end justify-between gap-3">
                    <div>
                        <h3 class="text-sm font-semibold text-[#0f4c8a] dark:text-sky-100">Maintenance History</h3>
                        <p class="text-xs text-ink-muted dark:text-slate-400">List of all maintenance records for this boat.</p>
                    </div>
                    <div class="flex h-9 items-center gap-2 rounded-xl border border-[#d7e8f6] px-3">
                        <Search class="size-3.5 text-ink-muted dark:text-slate-400" />
                        <input v-model="maintenanceSearch" type="text" placeholder="Search maintenance record..." class="w-52 border-0 bg-transparent text-xs text-ink dark:text-sky-50 outline-none" @input="maintenancePage = 1" />
                    </div>
                </div>
                <div class="overflow-x-auto rounded-xl border border-[#e4eef6]">
                    <table class="w-full min-w-[760px] text-left text-sm">
                        <thead class="bg-[#f8fbfe] text-[11px] tracking-wide text-ink-soft uppercase dark:bg-white/5 dark:text-slate-400">
                            <tr>
                                <th class="px-3 py-2.5 font-semibold">#</th>
                                <th class="px-3 py-2.5 font-semibold">Date</th>
                                <th class="px-3 py-2.5 font-semibold">Type</th>
                                <th class="px-3 py-2.5 font-semibold">Description</th>
                                <th class="px-3 py-2.5 font-semibold">Performed By</th>
                                <th class="px-3 py-2.5 font-semibold">Next Schedule</th>
                                <th class="px-3 py-2.5 font-semibold">Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-if="pagedMaintenance.length === 0">
                                <td colspan="7" class="px-3 py-8 text-center text-sm text-ink-muted dark:text-slate-400">No maintenance records are stored for this boat.</td>
                            </tr>
                            <tr v-for="(row, index) in pagedMaintenance" :key="row.id" class="border-t border-[#eef4f9] dark:border-white/10">
                                <td class="px-3 py-2.5 text-ink-muted dark:text-slate-400">{{ (maintenancePage - 1) * 10 + index + 1 }}</td>
                                <td class="px-3 py-2.5 whitespace-nowrap text-ink dark:text-sky-50">{{ row.date_label || '—' }}</td>
                                <td class="px-3 py-2.5 text-ink dark:text-sky-50">{{ row.type }}</td>
                                <td class="px-3 py-2.5 text-ink dark:text-sky-50">{{ row.description || '—' }}</td>
                                <td class="px-3 py-2.5 text-ink dark:text-sky-50">—</td>
                                <td class="px-3 py-2.5 text-ink dark:text-sky-50">—</td>
                                <td class="px-3 py-2.5">
                                    <span class="inline-flex rounded-full bg-[#dcfce7] px-2.5 py-0.5 text-[11px] font-semibold text-[#15803d]">Recorded</span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <div v-else class="grid gap-6 p-5 lg:grid-cols-2">
                <div>
                    <h3 class="text-sm font-semibold text-[#0f4c8a] dark:text-sky-100">Performance Summary ({{ selectedBoat.name }})</h3>
                    <dl class="mt-4 space-y-3 text-sm">
                        <div class="flex items-center justify-between gap-2">
                            <dt class="flex items-center gap-2 text-ink-muted dark:text-slate-400"><span class="size-2.5 rounded-full bg-[#3b82f6]" /> Regular Trips</dt>
                            <dd class="font-semibold text-[#0f4c8a] dark:text-sky-100">{{ selectedStats.regularTrips }}</dd>
                        </div>
                        <div class="flex items-center justify-between gap-2">
                            <dt class="flex items-center gap-2 text-ink-muted dark:text-slate-400"><span class="size-2.5 rounded-full bg-[#a855f7]" /> Private Rental Trips</dt>
                            <dd class="font-semibold text-[#0f4c8a] dark:text-sky-100">{{ selectedStats.rentalTrips }}</dd>
                        </div>
                        <div class="flex items-center justify-between gap-2">
                            <dt class="flex items-center gap-2 text-ink-muted dark:text-slate-400"><span class="size-2.5 rounded-full bg-[#22c55e]" /> Total Completed Trips</dt>
                            <dd class="font-semibold text-[#0f4c8a] dark:text-sky-100">{{ selectedStats.completedTrips }}</dd>
                        </div>
                        <div class="flex items-center justify-between gap-2">
                            <dt class="flex items-center gap-2 text-ink-muted dark:text-slate-400"><span class="size-2.5 rounded-full bg-[#f59e0b]" /> Total Passengers</dt>
                            <dd class="font-semibold text-[#0f4c8a] dark:text-sky-100">{{ selectedStats.passengers }}</dd>
                        </div>
                        <div class="flex items-center justify-between gap-2">
                            <dt class="flex items-center gap-2 text-ink-muted dark:text-slate-400"><span class="size-2.5 rounded-full bg-[#1b7ad8]" /> Total Revenue</dt>
                            <dd class="font-semibold text-[#0f4c8a] dark:text-sky-100">{{ formatPeso(selectedStats.revenue) }}</dd>
                        </div>
                    </dl>
                </div>
                <div class="space-y-5">
                    <div>
                        <h4 class="text-xs font-semibold text-[#0f4c8a] dark:text-sky-100">Monthly Trips ({{ selectedBoat.name }})</h4>
                        <div class="mt-2 flex h-28 items-end gap-2">
                            <div v-for="month in monthlyCharts" :key="`t-${month.key}`" class="flex min-w-0 flex-1 flex-col items-center justify-end gap-1">
                                <div class="flex w-full max-w-8 flex-col justify-end" :style="{ height: '88px' }">
                                    <div class="w-full bg-[#a855f7]" :style="{ height: `${(month.rentalTrips / tripChartMax) * 88}px` }" />
                                    <div class="w-full bg-[#3b82f6]" :style="{ height: `${(month.regularTrips / tripChartMax) * 88}px` }" />
                                </div>
                                <span class="text-[11px] text-ink-muted dark:text-slate-400">{{ month.label }}</span>
                            </div>
                        </div>
                    </div>
                    <div>
                        <h4 class="text-xs font-semibold text-[#0f4c8a] dark:text-sky-100">Monthly Revenue ({{ selectedBoat.name }})</h4>
                        <div class="mt-2 flex h-28 items-end gap-2">
                            <div v-for="month in monthlyCharts" :key="`r-${month.key}`" class="flex min-w-0 flex-1 flex-col items-center justify-end gap-1">
                                <div class="flex w-full max-w-8 flex-col justify-end" :style="{ height: '88px' }">
                                    <div class="w-full bg-[#f97316]" :style="{ height: `${(month.rentalRevenue / revenueChartMax) * 88}px` }" />
                                    <div class="w-full bg-[#3b82f6]" :style="{ height: `${(month.regularRevenue / revenueChartMax) * 88}px` }" />
                                </div>
                                <span class="text-[11px] text-ink-muted dark:text-slate-400">{{ month.label }}</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            </div>
            </DialogContent>
        </Dialog>
    </div>
</template>
