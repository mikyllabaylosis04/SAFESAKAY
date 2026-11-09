<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import BoatReport from '@/components/BoatReport.vue';
import PassengerManifestBoats from '@/components/PassengerManifestBoats.vue';
import type { BoatUsageRow } from '@/types/report';
import RentalPaymentRecords from '@/components/RentalPaymentRecords.vue';
import {
    CalendarDays,
    ChevronLeft,
    ChevronRight,
    Download,
    Printer,
    Ship,
    UserRound,
    Users,
    Wallet,
} from '@lucide/vue';
import { computed, ref, watch } from 'vue';

type Boat = {
    id: number;
    name: string;
    code?: string;
    capacity?: number;
    operator_name?: string | null;
};

type TripPassenger = {
    id: number;
    full_name: string;
    age?: number | null;
    fare_type_label?: string | null;
    gender?: string | null;
};

type RegularTrip = {
    id: number;
    code: string;
    direction?: string | null;
    trip_date: string;
    trip_date_label: string;
    departure_time_label?: string | null;
    actual_departure_label?: string | null;
    actual_arrival_label?: string | null;
    leg_label?: string | null;
    passenger_count: number;
    status: string;
    status_label: string;
    report_status?: string;
    departed?: boolean;
    collected_fare?: number;
    collected_fare_label?: string;
    passengers?: TripPassenger[];
    boat: Boat;
};

type RentalPassenger = {
    id: number;
    full_name: string;
    age?: number | null;
    gender?: string | null;
};

type Rental = {
    id: number;
    code: string;
    customer_name?: string | null;
    contact_number?: string | null;
    destination?: string | null;
    booking_date: string;
    booking_date_label: string;
    start_time_label?: string | null;
    passenger_count: number;
    status: string;
    status_label: string;
    report_status?: string;
    report_status_label?: string;
    payment_method?: string | null;
    payment_status?: string;
    payment_status_label?: string;
    amount?: number;
    amount_label?: string;
    quoted_amount?: number;
    quoted_amount_label?: string;
    verified_amount?: number;
    recorded_passengers?: RentalPassenger[];
    boat: Boat;
};

type RefundRow = {
    id: number;
    booking_id?: number | null;
    reference?: string;
    booking_code?: string | null;
    passenger?: string | null;
    boat?: string | null;
    status: string;
    status_label?: string;
    refund_amount?: number;
    date?: string | null;
    requested_at_label?: string | null;
};

type PayoutRow = {
    id: number;
    booking_id?: number | null;
    booking_code?: string | null;
    date?: string | null;
    date_label?: string | null;
    operator?: string | null;
    boat?: string | null;
    collected?: number;
    commission?: number;
    operator_share?: number;
    payout_status_label?: string | null;
    paid_at_label?: string | null;
};

type BoatUsage = {
    id: number;
    name: string;
    code?: string;
    operator_name?: string | null;
    capacity?: number;
    status_label?: string;
    regular_trips?: number;
    private_bookings?: number;
};

type OperatorViolation = {
    id: number;
    date?: string | null;
    date_label?: string | null;
    booking_code?: string | null;
    reason?: string | null;
    strike?: number;
};

type OperatorRow = {
    id: number;
    name: string;
    boat_id?: number | null;
    boat_name?: string | null;
    boat_code?: string | null;
    capacity?: number;
    violation_count: number;
    suspended: boolean;
    suspension_reason?: string | null;
    suspension_start?: string | null;
    suspension_end?: string | null;
    violations: OperatorViolation[];
};

type Category = 'regular' | 'manifest' | 'rentals' | 'finance' | 'fleet';
type FinanceView = 'payments' | 'payouts' | 'refunds';

const props = defineProps<{
    regularTrips: RegularTrip[];
    bookings: Rental[];
    refundRecords?: RefundRow[];
    payoutRecords?: PayoutRow[];
    boatUsage?: BoatUsage[];
    operators?: OperatorRow[];
    canViewPassengerDetails?: boolean;
    rentalPayments?: Array<{
        id: number | string;
        booking_reference: string;
        passenger_name: string;
        boat_name: string | null;
        payment_type: string;
        amount_paid: number | string | null;
        rental_total: number | string | null;
        status: string;
        is_verified?: boolean;
        paid_at: string | null;
        verified_at?: string | null;
        booking_href?: string | null;
        remaining_balance?: number | string | null;
        balance_amount?: number | string | null;
        balance_status?: string | null;
        booking_id?: number;
        refund_reference?: string | null;
        refund_status_label?: string | null;
        partially_refunded?: boolean;
        received_by?: string | null;
    }>;
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Reports', href: '/reports' }],
    },
});

const category = ref<Category>('regular');
const financeView = ref<FinanceView>('payments');
const preset = ref<'today' | 'week' | 'month' | 'custom'>('month');
const search = ref('');
const boatId = ref('all');
const status = ref('all');

function ymd(date: Date): string {
    const pad = (value: number) => String(value).padStart(2, '0');

    return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`;
}

const today = ymd(new Date());
const monthStart = ymd(new Date(new Date().getFullYear(), new Date().getMonth(), 1));
const dateFrom = ref(monthStart);
const dateTo = ref(today);

function applyPreset(next: 'today' | 'week' | 'month' | 'custom') {
    preset.value = next;
    const now = new Date();

    if (next === 'today') {
        dateFrom.value = today;
        dateTo.value = today;
    }

    if (next === 'week') {
        const start = new Date(now);
        const day = start.getDay() || 7;
        start.setDate(start.getDate() - day + 1);
        dateFrom.value = ymd(start);
        dateTo.value = today;
    }

    if (next === 'month') {
        dateFrom.value = monthStart;
        dateTo.value = today;
    }
}

function money(value: number | null | undefined): string {
    return new Intl.NumberFormat('en-PH', {
        style: 'currency',
        currency: 'PHP',
    }).format(Number(value ?? 0));
}

function inRange(date: string | null | undefined): boolean {
    if (!date) {
        return false;
    }

    if (dateFrom.value && date < dateFrom.value) {
        return false;
    }

    if (dateTo.value && date > dateTo.value) {
        return false;
    }

    return true;
}

function matchesBoat(id: number | null | undefined): boolean {
    return boatId.value === 'all' || String(id ?? '') === boatId.value;
}

function matchesBoatName(name: string | null | undefined): boolean {
    if (boatId.value === 'all') {
        return true;
    }

    const boat = boats.value.find((item) => String(item.id) === boatId.value);

    return boat ? (name || '') === boat.name : true;
}

function matchesQuery(parts: Array<string | null | undefined>): boolean {
    const query = search.value.trim().toLowerCase();

    if (query === '') {
        return true;
    }

    return parts.filter(Boolean).join(' ').toLowerCase().includes(query);
}

const boats = computed(() => {
    const seen = new Map<number, string>();

    for (const boat of props.boatUsage ?? []) {
        seen.set(boat.id, boat.name);
    }

    for (const trip of props.regularTrips) {
        seen.set(trip.boat.id, trip.boat.name);
    }

    return [...seen.entries()]
        .map(([id, name]) => ({ id, name }))
        .sort((left, right) => left.name.localeCompare(right.name));
});

const recordPage = ref(1);
const recordPageSize = 10;
const rentalPage = ref(1);
const rentalPageSize = ref(10);
const financePage = ref(1);
const financePageSize = ref(10);

const trips = computed(() =>
    props.regularTrips.filter((trip) => {
        if (trip.status === 'off_duty') {
            return false;
        }

        if (!inRange(trip.trip_date) || !matchesBoat(trip.boat.id)) {
            return false;
        }

        if (status.value !== 'all' && trip.status !== status.value && trip.report_status !== status.value) {
            return false;
        }

        return matchesQuery([
            trip.code,
            trip.boat.name,
            trip.boat.operator_name,
            trip.direction,
            trip.status_label,
        ]);
    }),
);

const rentals = computed(() =>
    props.bookings.filter((booking) => {
        if (!inRange(booking.booking_date) || !matchesBoat(booking.boat.id)) {
            return false;
        }

        const reportStatus = booking.report_status ?? booking.status;
        const wantsApproved = status.value === 'approved';
        const approved = ['approved', 'on_trip', 'weather_hold', 'confirmed', 'ongoing'].includes(reportStatus);

        if (wantsApproved && !approved) {
            return false;
        }

        if (!wantsApproved && status.value !== 'all' && reportStatus !== status.value && booking.status !== status.value) {
            return false;
        }

        return matchesQuery([
            booking.code,
            booking.customer_name,
            booking.destination,
            booking.boat.name,
            booking.boat.operator_name,
            booking.status_label,
        ]);
    }),
);

const recordPages = computed(() => Math.max(1, Math.ceil(trips.value.length / recordPageSize)));

const recordRows = computed(() => {
    const page = Math.min(recordPage.value, recordPages.value);
    const start = (page - 1) * recordPageSize;

    return trips.value.slice(start, start + recordPageSize);
});

const recordRangeLabel = computed(() => {
    const total = trips.value.length;

    if (total === 0) {
        return 'Showing 0 records';
    }

    const page = Math.min(recordPage.value, recordPages.value);
    const start = (page - 1) * recordPageSize + 1;
    const end = Math.min(page * recordPageSize, total);

    return `Showing ${start}-${end} of ${total} records`;
});

watch([dateFrom, dateTo, boatId, status, search, financeView], () => {
    recordPage.value = 1;
    rentalPage.value = 1;
    financePage.value = 1;
});

const rentalPages = computed(() => Math.max(1, Math.ceil(rentals.value.length / rentalPageSize.value)));

const rentalRows = computed(() => {
    const page = Math.min(rentalPage.value, rentalPages.value);
    const start = (page - 1) * rentalPageSize.value;

    return rentals.value.slice(start, start + rentalPageSize.value);
});

const rentalRangeLabel = computed(() => {
    const total = rentals.value.length;

    if (total === 0) {
        return 'Showing 0 records';
    }

    const page = Math.min(rentalPage.value, rentalPages.value);
    const start = (page - 1) * rentalPageSize.value + 1;
    const end = Math.min(page * rentalPageSize.value, total);

    return `Showing ${start}–${end} of ${total} records`;
});

function rentalNumber(index: number): number {
    const page = Math.min(rentalPage.value, rentalPages.value);

    return (page - 1) * rentalPageSize.value + index + 1;
}

function rentalAmount(booking: Rental): string {
    if (booking.quoted_amount_label) {
        return booking.quoted_amount_label;
    }

    return money(booking.quoted_amount ?? booking.amount);
}

function rentalStatusClass(status?: string): string {
    if (status === 'approved' || status === 'completed' || status === 'on_trip') {
        return 'bg-emerald-50 text-emerald-700';
    }

    if (status === 'cancelled' || status === 'rejected' || status === 'passenger_no_show') {
        return 'bg-rose-50 text-rose-700';
    }

    if (status === 'pending' || status === 'reschedule_pending') {
        return 'bg-amber-50 text-amber-700';
    }

    return 'bg-slate-100 text-slate-600';
}

function rentalPaymentClass(status?: string): string {
    if (status === 'verified') {
        return 'bg-emerald-50 text-emerald-700';
    }

    if (status === 'for_verification') {
        return 'bg-amber-50 text-amber-700';
    }

    if (status === 'rejected') {
        return 'bg-rose-50 text-rose-700';
    }

    return 'bg-slate-100 text-slate-500';
}

function recordNumber(index: number): number {
    const page = Math.min(recordPage.value, recordPages.value);

    return (page - 1) * recordPageSize + index + 1;
}

function statusPill(status: string): string {
    if (status === 'completed') {
        return 'bg-emerald-50 text-emerald-700';
    }

    if (status === 'cancelled') {
        return 'bg-rose-50 text-rose-700';
    }

    if (status === 'on_trip') {
        return 'bg-sky-50 text-sky-700';
    }

    return 'bg-slate-100 text-slate-500';
}

const rentalSummary = computed(() => ({
    total: rentals.value.length,
    confirmed: rentals.value.filter((booking) =>
        ['approved', 'on_trip', 'weather_hold', 'confirmed', 'ongoing'].includes(booking.report_status ?? booking.status),
    ).length,
    completed: rentals.value.filter((booking) => booking.status === 'completed').length,
    cancelled: rentals.value.filter((booking) =>
        ['cancelled', 'rejected', 'passenger_no_show'].includes(booking.status) || booking.report_status === 'cancelled',
    ).length,
    collected: rentals.value.reduce((sum, booking) => sum + Number(booking.amount ?? 0), 0),
}));

const regularTripRevenue = computed(() =>
    trips.value.reduce((sum, trip) => sum + Number(trip.collected_fare ?? 0), 0),
);

const verifiedRentalPayments = computed(() =>
    (props.rentalPayments ?? []).reduce((sum, row) => {
        if (!row.is_verified || row.received_by !== 'Barangay') {
            return sum;
        }

        return sum + Number(row.amount_paid ?? 0);
    }, 0),
);

const financePayouts = computed(() =>
    (props.payoutRecords ?? []).filter((row) => inRange(row.date) && matchesBoatName(row.boat) && matchesQuery([row.booking_code, row.operator, row.boat])),
);

const financeRefunds = computed(() =>
    (props.refundRecords ?? []).filter((row) => inRange(row.date) && matchesBoatName(row.boat) && matchesQuery([row.reference, row.booking_code, row.passenger, row.boat])),
);

const financeSource = computed(() => {
    if (financeView.value === 'payouts') {
        return financePayouts.value;
    }

    if (financeView.value === 'refunds') {
        return financeRefunds.value;
    }

    return [];
});

const financePages = computed(() => Math.max(1, Math.ceil(financeSource.value.length / financePageSize.value)));

const financeRangeLabel = computed(() => {
    const total = financeSource.value.length;

    if (total === 0) {
        return 'Showing 0 records';
    }

    const page = Math.min(financePage.value, financePages.value);
    const start = (page - 1) * financePageSize.value + 1;
    const end = Math.min(page * financePageSize.value, total);

    return `Showing ${start}–${end} of ${total} records`;
});

function financeSlice<T>(rows: T[]): T[] {
    const page = Math.min(financePage.value, Math.max(1, Math.ceil(rows.length / financePageSize.value)));
    const start = (page - 1) * financePageSize.value;

    return rows.slice(start, start + financePageSize.value);
}

function financeNumber(index: number): number {
    const page = Math.min(financePage.value, financePages.value);

    return (page - 1) * financePageSize.value + index + 1;
}

const financeSummary = computed(() => ({
    collected: verifiedRentalPayments.value,
    regular: regularTripRevenue.value,
    commission: financePayouts.value.reduce((sum, row) => sum + Number(row.commission ?? 0), 0),
    operator: financePayouts.value.reduce((sum, row) => sum + Number(row.operator_share ?? 0), 0),
    refunded: financeRefunds.value.reduce((sum, row) => sum + Number(row.refund_amount ?? 0), 0),
}));

const operatorRows = computed(() =>
    (props.operators ?? [])
        .filter((operator) => matchesBoat(operator.boat_id) && matchesQuery([operator.name, operator.boat_name, operator.boat_code]))
        .map((operator) => {
            const name = operator.name;
            const regularCount = trips.value.filter((trip) => (trip.boat.operator_name || '') === name).length;
            const rentalCount = rentals.value.filter((booking) => (booking.boat.operator_name || '') === name).length;
            const violations = operator.violations.filter((violation) => !violation.date || inRange(violation.date));

            return {
                ...operator,
                trips: regularCount + rentalCount,
                violations,
                violationCount: violations.length,
            };
        }),
);

const fleetBoats = computed<BoatUsageRow[]>(() =>
    (props.boatUsage ?? []).map((boat) => {
        const operator = (props.operators ?? []).find((item) => item.boat_id === boat.id);

        return {
            ...(boat as BoatUsageRow),
            code: boat.code || '',
            regular_trips: boat.regular_trips ?? 0,
            private_bookings: boat.private_bookings ?? 0,
            total_trips: (boat.regular_trips ?? 0) + (boat.private_bookings ?? 0),
            maintenance_records: (boat as BoatUsageRow).maintenance_records ?? 0,
            in_maintenance: (boat as BoatUsageRow).in_maintenance ?? false,
            usage_share: (boat as BoatUsageRow).usage_share ?? 0,
            usage_frequency: (boat as BoatUsageRow).usage_frequency ?? 'Low',
            violation_count: operator?.violation_count ?? 0,
            suspended: operator?.suspended ?? false,
            suspension_start: operator?.suspension_start ?? null,
            suspension_end: operator?.suspension_end ?? null,
            violations: operator?.violations ?? [],
        };
    }),
);

const reportCards = computed(() => {
    const regularRows = props.regularTrips.filter((trip) => trip.status !== 'cancelled' && trip.status !== 'off_duty');
    const rentalRows = props.bookings.filter((booking) => !['cancelled', 'rejected'].includes(booking.status));
    const passengers = regularRows.reduce((sum, trip) => sum + Number(trip.passenger_count ?? 0), 0)
        + rentalRows.reduce((sum, booking) => sum + Number(booking.passenger_count ?? 0), 0);
    const regularRevenue = regularRows.reduce((sum, trip) => sum + Number(trip.collected_fare ?? 0), 0);

    return [
        { id: 'regular', label: 'Regular Trips', hint: 'Total Trips', value: String(regularRows.length), icon: Ship, card: 'border-[#bfdbfe] bg-[#e8f3ff] dark:border-sky-500/30 dark:bg-sky-950/40', iconBox: 'bg-[#1b7ad8]' },
        { id: 'rentals', label: 'Exclusive Rentals', hint: 'Total Rentals', value: String(rentalRows.length), icon: CalendarDays, card: 'border-[#bbf7d0] bg-[#e8f8ef] dark:border-emerald-500/30 dark:bg-emerald-950/40', iconBox: 'bg-[#16a34a]' },
        { id: 'manifest', label: 'Passenger Manifest', hint: 'Total Passengers', value: String(passengers), icon: Users, card: 'border-[#e9d5ff] bg-[#f6edff]', iconBox: 'bg-[#7c3aed]' },
        { id: 'finance', label: 'Finance', hint: 'Total Revenue', value: money(regularRevenue + verifiedRentalPayments.value), icon: Wallet, card: 'border-[#fde68a] bg-[#fff8e1] dark:border-amber-500/30 dark:bg-amber-950/40', iconBox: 'bg-[#d97706]' },
        { id: 'fleet', label: 'Boats & Operators', hint: 'Total Boats', value: String((props.boatUsage ?? []).length), icon: UserRound, card: 'border-[#fed7aa] bg-[#fff1e6] dark:border-orange-500/30 dark:bg-orange-950/40', iconBox: 'bg-[#ea580c]' },
    ];
});

const rangeLabel = computed(() => {
    const format = (value: string) =>
        value
            ? new Date(`${value}T00:00:00`).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' })
            : '—';

    return `${format(dateFrom.value)} – ${format(dateTo.value)}`;
});

function exportRows(): { title: string; headers: string[]; rows: string[][] } {
    if (category.value === 'regular') {
        return {
            title: 'Regular Trip Report',
            headers: ['Trip ID', 'Date', 'Boat', 'Operator', 'Trip Type', 'Actual Departure', 'Actual Arrival', 'Passengers', 'Status'],
            rows: trips.value.map((trip) => [
                trip.code,
                trip.trip_date_label,
                trip.boat.name,
                trip.boat.operator_name || 'Unassigned',
                trip.leg_label || 'Regular',
                trip.actual_departure_label || 'Not Recorded',
                trip.actual_arrival_label || 'Not Recorded',
                String(trip.passenger_count),
                trip.status_label,
            ]),
        };
    }

    if (category.value === 'rentals') {
        return {
            title: 'Exclusive Rental Report',
            headers: ['Rental ID', 'Date', 'Boat', 'Renter', 'Contact', 'Destination', 'Time', 'Amount', 'Status', 'Payment'],
            rows: rentals.value.map((booking) => [
                booking.code,
                booking.booking_date_label,
                booking.boat.name,
                booking.customer_name || '—',
                booking.contact_number || '—',
                booking.destination || '—',
                booking.start_time_label || '—',
                rentalAmount(booking),
                booking.report_status_label || booking.status_label,
                booking.payment_status_label || 'Unpaid',
            ]),
        };
    }

    if (category.value === 'manifest') {
        const regularRows = trips.value.flatMap((trip) =>
            (trip.passengers ?? []).map((passenger) => [
                'Regular',
                trip.code,
                trip.trip_date_label,
                trip.boat.name,
                passenger.full_name,
                String(passenger.age ?? '—'),
                passenger.gender || '—',
            ]),
        );
        const rentalRows = rentals.value.flatMap((booking) =>
            (booking.recorded_passengers ?? []).map((passenger) => [
                'Rental',
                booking.code,
                booking.booking_date_label,
                booking.boat.name,
                passenger.full_name,
                String(passenger.age ?? '—'),
                passenger.gender || '—',
            ]),
        );

        return {
            title: 'Passenger Manifest',
            headers: ['Trip Type', 'Trip', 'Date', 'Boat', 'Passenger', 'Age', 'Gender'],
            rows: [...regularRows, ...rentalRows],
        };
    }

    if (category.value === 'finance') {
        if (financeView.value === 'payouts') {
            return {
                title: 'Operator Earnings and Commission',
                headers: ['Date', 'Booking', 'Operator', 'Boat', 'Collected', 'Commission', 'Operator Earnings', 'Payout'],
                rows: financePayouts.value.map((row) => [
                    row.date_label || row.date || '—',
                    row.booking_code || '—',
                    row.operator || '—',
                    row.boat || '—',
                    money(row.collected),
                    money(row.commission),
                    money(row.operator_share),
                    row.payout_status_label || '—',
                ]),
            };
        }

        if (financeView.value === 'refunds') {
            return {
                title: 'Refund Records',
                headers: ['Date', 'Reference', 'Booking', 'Passenger', 'Boat', 'Refunded', 'Status'],
                rows: financeRefunds.value.map((row) => [
                    row.requested_at_label || row.date || '—',
                    row.reference || '—',
                    row.booking_code || '—',
                    row.passenger || '—',
                    row.boat || '—',
                    money(row.refund_amount),
                    row.status_label || row.status,
                ]),
            };
        }

        return {
            title: 'Rental Payment Records',
            headers: ['Booking', 'Passenger', 'Boat', 'Payment Type', 'Amount Paid', 'Status'],
            rows: (props.rentalPayments ?? []).map((row) => [
                row.booking_reference,
                row.passenger_name,
                row.boat_name || '—',
                row.payment_type,
                money(Number(row.amount_paid ?? 0)),
                row.status,
            ]),
        };
    }

    return {
        title: 'Boat and Operator Report',
        headers: ['Operator', 'Boat', 'Trips in period', 'Confirmed violations', 'Suspension'],
        rows: operatorRows.value.map((operator) => [
            operator.name,
            operator.boat_name ? `${operator.boat_name}${operator.boat_code ? ` (${operator.boat_code})` : ''}` : 'Unassigned',
            String(operator.trips),
            String(operator.violationCount),
            operator.suspended ? `${operator.suspension_start} – ${operator.suspension_end}` : 'Active',
        ]),
    };
}

function downloadExcel() {
    const report = exportRows();
    const escape = (value: string) => `"${value.replace(/"/g, '""')}"`;
    const csv = [report.headers, ...report.rows].map((line) => line.map(escape).join(',')).join('\r\n');
    const blob = new Blob([`\uFEFF${csv}`], { type: 'text/csv;charset=utf-8' });
    const link = document.createElement('a');
    link.href = URL.createObjectURL(blob);
    link.download = `${report.title.replace(/\s+/g, '-').toLowerCase()}-${dateFrom.value}.csv`;
    link.click();
    URL.revokeObjectURL(link.href);
}

function printReport() {
    const report = exportRows();
    const rows = report.rows
        .map((row) => `<tr>${row.map((cell) => `<td>${cell}</td>`).join('')}</tr>`)
        .join('');
    const html = `<!doctype html><html><head><title>${report.title}</title>
        <style>
            body { font-family: Arial, sans-serif; color: #0f2f57; padding: 24px; }
            h1 { margin: 0; font-size: 22px; }
            p { color: #5b7c99; font-size: 13px; }
            table { width: 100%; border-collapse: collapse; margin-top: 16px; }
            th, td { border-bottom: 1px solid #d7e8f6; text-align: left; padding: 8px; font-size: 12px; }
            th { background: #f4f9fd; }
        </style></head><body>
        <h1>SafeSakay</h1>
        <p>${report.title}<br>${rangeLabel.value}<br>Generated ${new Date().toLocaleString('en-PH', { timeZone: 'Asia/Manila' })}</p>
        <table><thead><tr>${report.headers.map((header) => `<th>${header}</th>`).join('')}</tr></thead><tbody>${rows || '<tr><td>No records in this period.</td></tr>'}</tbody></table>
        </body></html>`;
    const frame = window.open('', '_blank', 'width=900,height=700');

    if (!frame) {
        return;
    }

    frame.document.write(html);
    frame.document.close();
    frame.focus();
    frame.print();
}

function selectCategory(id: string) {
    category.value = id as Category;
}
</script>

<template>
    <Head title="Reports" />

    <div class="flex min-w-0 flex-1 flex-col gap-5 px-4 py-5 sm:px-6 lg:px-7">
        <header class="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
            <div>
                <h1 class="text-2xl font-bold tracking-tight text-[#0f4c8a] dark:text-sky-100">Reports</h1>
                <p class="mt-1 text-sm text-ink-muted">Trip, rental, passenger, finance, and operator records from this barangay.</p>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <span class="rounded-xl border border-[#d7e8f6] bg-white px-3 py-2 text-sm font-semibold text-[#0f4c8a] dark:bg-[#102036] dark:text-slate-100 dark:border-white/10">
                    {{ rangeLabel }}
                </span>
                <button type="button" class="inline-flex h-10 items-center gap-2 rounded-xl border border-[#c5def5] bg-white px-3 text-sm font-semibold text-[#1b7ad8] dark:bg-[#102036] dark:text-slate-100 dark:border-white/10" @click="printReport">
                    <Printer class="size-4" />
                    Print / PDF
                </button>
                <button type="button" class="inline-flex h-10 items-center gap-2 rounded-xl bg-[#1b7ad8] px-3 text-sm font-semibold text-white" @click="downloadExcel">
                    <Download class="size-4" />
                    Export Excel
                </button>
            </div>
        </header>

        <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-5">
            <button
                v-for="item in reportCards"
                :key="item.id"
                type="button"
                class="rounded-2xl border p-4 text-left shadow-sm"
                :class="[item.card, category === item.id ? 'ring-2 ring-[#1b7ad8]' : '']"
                @click="selectCategory(item.id)"
            >
                <span class="flex size-10 items-center justify-center rounded-xl text-white" :class="item.iconBox">
                    <component :is="item.icon" class="size-5" />
                </span>
                <p class="mt-3 text-sm font-semibold text-[#0f4c8a]">{{ item.label }}</p>
                <p class="mt-1 text-2xl font-bold text-[#0f2f57]">{{ item.value }}</p>
                <p class="text-xs text-ink-muted">{{ item.hint }}</p>
                <p class="mt-2 text-xs font-semibold" :class="category === item.id ? 'text-[#1b7ad8]' : 'text-ink-muted'">
                    {{ category === item.id ? 'Viewing' : 'View Report' }}
                </p>
            </button>
        </div>

        <section v-if="category !== 'manifest' && category !== 'fleet'" class="rounded-2xl border border-[#d7e8f6] bg-white p-4 shadow-sm dark:border-white/10 dark:bg-white/5">
            <div class="grid gap-2 sm:grid-cols-2 lg:grid-cols-5">
                <select v-model="preset" class="h-10 rounded-xl border border-[#d7e8f6] px-3 text-sm" @change="applyPreset(preset)">
                    <option value="today">Today</option>
                    <option value="week">This week</option>
                    <option value="month">This month</option>
                    <option value="custom">Custom range</option>
                </select>
                <input v-model="dateFrom" type="date" class="h-10 rounded-xl border border-[#d7e8f6] px-3 text-sm" @change="preset = 'custom'" />
                <input v-model="dateTo" type="date" class="h-10 rounded-xl border border-[#d7e8f6] px-3 text-sm" @change="preset = 'custom'" />
                <select v-model="boatId" class="h-10 rounded-xl border border-[#d7e8f6] px-3 text-sm">
                    <option value="all">All boats</option>
                    <option v-for="boat in boats" :key="boat.id" :value="String(boat.id)">{{ boat.name }}</option>
                </select>
                <input v-model="search" type="search" placeholder="Search" class="h-10 rounded-xl border border-[#d7e8f6] px-3 text-sm" />
            </div>
            <div v-if="category === 'regular' || category === 'rentals'" class="mt-3">
                <select v-model="status" class="h-10 rounded-xl border border-[#d7e8f6] px-3 text-sm">
                    <option value="all">All statuses</option>
                    <template v-if="category === 'regular'">
                        <option value="completed">Completed</option>
                        <option value="cancelled">Cancelled</option>
                        <option value="on_trip">On trip</option>
                        <option value="scheduled">Scheduled</option>
                    </template>
                    <template v-else>
                        <option value="approved">Confirmed</option>
                        <option value="completed">Completed</option>
                        <option value="cancelled">Cancelled</option>
                    </template>
                </select>
            </div>
        </section>

        <template v-if="category === 'regular'">
            <section class="overflow-hidden rounded-2xl border border-[#d7e8f6] bg-white shadow-sm dark:bg-[#102036] dark:text-slate-100 dark:border-white/10">
                <div class="flex flex-col gap-3 px-4 py-4 sm:flex-row sm:items-center sm:justify-between">
                    <h3 class="text-base font-bold text-[#0f4c8a]">Regular Trip Records</h3>
                    <div class="flex flex-wrap items-center gap-3">
                        <p class="text-xs text-ink-muted">{{ recordRangeLabel }}</p>
                        <button type="button" class="inline-flex h-9 items-center gap-2 rounded-full bg-[#1b7ad8] px-3 text-xs font-semibold text-white" @click="downloadExcel">
                            <Download class="size-3.5" />
                            Download Report
                        </button>
                    </div>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[980px] text-left text-sm">
                        <thead class="bg-[#f4f9fd] text-[11px] uppercase tracking-wide text-[#5b7c99] dark:bg-[#0b1b30] dark:text-slate-100">
                            <tr>
                                <th class="px-4 py-3">#</th>
                                <th class="py-3">Trip ID</th>
                                <th>Date</th>
                                <th>Boat Name</th>
                                <th>Operator</th>
                                <th>Trip Type</th>
                                <th>Actual Departure</th>
                                <th>Actual Arrival</th>
                                <th>Passengers</th>
                                <th>Status</th>
                                <th class="pr-4">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="(trip, index) in recordRows" :key="trip.id" class="border-t border-[#eef4f9]">
                                <td class="px-4 py-3 text-ink-muted">{{ recordNumber(index) }}</td>
                                <td class="py-3 font-semibold text-[#1b7ad8]">{{ trip.code }}</td>
                                <td class="py-3">{{ trip.trip_date_label }}</td>
                                <td class="py-3 font-semibold uppercase">{{ trip.boat.name }}</td>
                                <td class="py-3">{{ trip.boat.operator_name || 'Unassigned' }}</td>
                                <td class="py-3">{{ trip.leg_label || 'Regular' }}</td>
                                <td class="py-3">{{ trip.actual_departure_label || 'Not Recorded' }}</td>
                                <td class="py-3">{{ trip.actual_arrival_label || 'Not Recorded' }}</td>
                                <td class="py-3">{{ trip.passenger_count }}</td>
                                <td class="py-3">
                                    <span class="rounded-full px-2.5 py-1 text-xs font-semibold" :class="statusPill(trip.status)">{{ trip.status_label }}</span>
                                </td>
                                <td class="py-3 pr-4">
                                    <a :href="`/regular-trips/${trip.id}`" class="inline-flex h-8 items-center rounded-full border border-[#c5def5] px-3 text-xs font-semibold text-[#1b7ad8]">View</a>
                                </td>
                            </tr>
                            <tr v-if="!recordRows.length">
                                <td colspan="11" class="px-4 py-10 text-center text-ink-muted">No regular trips in this period.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <div class="flex items-center justify-end gap-3 border-t border-[#eef4f9] px-4 py-3 text-sm text-ink-muted">
                    <button type="button" class="inline-flex size-8 items-center justify-center rounded-lg border border-[#d7e8f6] disabled:opacity-40" :disabled="recordPage <= 1" @click="recordPage -= 1">
                        <ChevronLeft class="size-4" />
                    </button>
                    <span>{{ Math.min(recordPage, recordPages) }} / {{ recordPages }}</span>
                    <button type="button" class="inline-flex size-8 items-center justify-center rounded-lg border border-[#d7e8f6] disabled:opacity-40" :disabled="recordPage >= recordPages" @click="recordPage += 1">
                        <ChevronRight class="size-4" />
                    </button>
                </div>
            </section>
        </template>

        <PassengerManifestBoats
            v-else-if="category === 'manifest'"
            :trips="regularTrips"
            :rentals="bookings"
            :can-view-passenger-details="Boolean(canViewPassengerDetails)"
        />

        <template v-else-if="category === 'rentals'">
            <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-5">
                <article class="rounded-2xl bg-[#e8f4fc] p-4 dark:bg-sky-950/40"><p class="text-xs font-semibold text-[#5b7c99] dark:text-slate-300">Total rentals</p><p class="mt-2 text-2xl font-bold">{{ rentalSummary.total }}</p></article>
                <article class="rounded-2xl bg-emerald-50 p-4"><p class="text-xs font-semibold text-emerald-700">Confirmed</p><p class="mt-2 text-2xl font-bold">{{ rentalSummary.confirmed }}</p></article>
                <article class="rounded-2xl bg-violet-50 p-4"><p class="text-xs font-semibold text-violet-700">Completed</p><p class="mt-2 text-2xl font-bold">{{ rentalSummary.completed }}</p></article>
                <article class="rounded-2xl bg-rose-50 p-4"><p class="text-xs font-semibold text-rose-700">Cancelled</p><p class="mt-2 text-2xl font-bold">{{ rentalSummary.cancelled }}</p></article>
                <article class="rounded-2xl bg-amber-50 p-4"><p class="text-xs font-semibold text-amber-800">Collected payments</p><p class="mt-2 text-2xl font-bold">{{ money(rentalSummary.collected) }}</p></article>
            </div>
            <p class="text-xs text-ink-muted">Collected payments are verified down payments plus balance recorded as paid. Pending GCash is not included.</p>
            <section class="overflow-hidden rounded-2xl border border-[#d7e8f6] bg-white shadow-sm dark:bg-[#102036] dark:text-slate-100 dark:border-white/10">
                <div class="flex flex-col gap-3 border-b border-[#eef4f9] px-5 py-4 sm:flex-row sm:items-center sm:justify-between">
                    <h3 class="text-base font-semibold text-[#0f4c8a]">Private Rental Records</h3>
                    <div class="flex flex-wrap items-center gap-3">
                        <p class="text-xs text-ink-muted">{{ rentalRangeLabel }}</p>
                        <button type="button" class="inline-flex h-9 items-center gap-2 rounded-xl bg-[#1b7ad8] px-3 text-sm font-semibold text-white" @click="downloadExcel">
                            <Download class="size-4" />
                            Download Report
                        </button>
                    </div>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[1100px] text-left text-sm">
                        <thead class="bg-[#f4f9fd] text-[11px] tracking-wide text-[#5b7c99] uppercase dark:bg-[#0b1b30] dark:text-slate-100">
                            <tr>
                                <th class="px-4 py-3 font-semibold">#</th>
                                <th class="px-3 py-3 font-semibold">Rental ID</th>
                                <th class="px-3 py-3 font-semibold">Date</th>
                                <th class="px-3 py-3 font-semibold">Boat Name</th>
                                <th class="px-3 py-3 font-semibold">Renter Name</th>
                                <th class="px-3 py-3 font-semibold">Contact</th>
                                <th class="px-3 py-3 font-semibold">Destination / Route</th>
                                <th class="px-3 py-3 font-semibold">Time</th>
                                <th class="px-3 py-3 font-semibold">Amount</th>
                                <th class="px-3 py-3 font-semibold">Status</th>
                                <th class="px-3 py-3 font-semibold">Payment</th>
                                <th class="px-4 py-3 font-semibold">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="(booking, index) in rentalRows" :key="booking.id" class="border-t border-[#eef4f9]">
                                <td class="px-4 py-3 text-ink-muted">{{ rentalNumber(index) }}</td>
                                <td class="px-3 py-3 font-semibold text-[#1b7ad8]">{{ booking.code }}</td>
                                <td class="px-3 py-3">{{ booking.booking_date_label }}</td>
                                <td class="px-3 py-3 font-semibold uppercase">{{ booking.boat.name }}</td>
                                <td class="px-3 py-3">{{ booking.customer_name || '—' }}</td>
                                <td class="px-3 py-3">{{ booking.contact_number || '—' }}</td>
                                <td class="px-3 py-3">{{ booking.destination || '—' }}</td>
                                <td class="px-3 py-3">{{ booking.start_time_label || '—' }}</td>
                                <td class="px-3 py-3 font-semibold">{{ rentalAmount(booking) }}</td>
                                <td class="px-3 py-3">
                                    <span class="rounded-full px-2.5 py-1 text-xs font-semibold" :class="rentalStatusClass(booking.report_status || booking.status)">{{ booking.report_status_label || booking.status_label }}</span>
                                </td>
                                <td class="px-3 py-3">
                                    <span class="rounded-full px-2.5 py-1 text-xs font-semibold" :class="rentalPaymentClass(booking.payment_status)">{{ booking.payment_status_label || 'Unpaid' }}</span>
                                </td>
                                <td class="px-4 py-3">
                                    <a :href="`/bookings/requests/${booking.id}`" class="inline-flex h-8 items-center rounded-full border border-[#c5def5] px-3 text-xs font-semibold text-[#1b7ad8]">View</a>
                                </td>
                            </tr>
                            <tr v-if="!rentalRows.length">
                                <td colspan="12" class="px-4 py-10 text-center text-ink-muted">No rentals in this period.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <div class="flex flex-wrap items-center justify-between gap-3 border-t border-[#eef4f9] px-5 py-3">
                    <label class="flex items-center gap-2 text-xs text-ink-muted">
                        Show
                        <select v-model.number="rentalPageSize" class="h-8 rounded-lg border border-[#d7e8f6] bg-white px-2 text-sm text-ink dark:bg-[#102036] dark:text-slate-100 dark:border-white/10">
                            <option :value="10">10</option>
                            <option :value="25">25</option>
                            <option :value="50">50</option>
                        </select>
                        entries
                    </label>
                    <div class="flex items-center gap-1">
                        <button type="button" class="inline-flex size-8 items-center justify-center rounded-lg border border-[#d7e8f6] text-ink-muted disabled:opacity-40" :disabled="rentalPage <= 1" @click="rentalPage -= 1">
                            <ChevronLeft class="size-4" />
                        </button>
                        <span class="flex size-8 items-center justify-center rounded-lg bg-[#1b7ad8] text-sm font-semibold text-white">{{ Math.min(rentalPage, rentalPages) }}</span>
                        <button type="button" class="inline-flex size-8 items-center justify-center rounded-lg border border-[#d7e8f6] text-ink-muted disabled:opacity-40" :disabled="rentalPage >= rentalPages" @click="rentalPage += 1">
                            <ChevronRight class="size-4" />
                        </button>
                    </div>
                </div>
            </section>
        </template>

        <template v-else-if="category === 'finance'">
            <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-5">
                <article class="rounded-2xl bg-[#e8f4fc] p-4 dark:bg-sky-950/40"><p class="text-xs font-semibold text-[#5b7c99] dark:text-slate-300">All payments</p><p class="mt-2 text-2xl font-bold">{{ money(financeSummary.collected) }}</p></article>
                <article class="rounded-2xl bg-sky-50 p-4"><p class="text-xs font-semibold text-sky-700">Regular trip revenue</p><p class="mt-2 text-2xl font-bold">{{ money(financeSummary.regular) }}</p></article>
                <article class="rounded-2xl bg-violet-50 p-4"><p class="text-xs font-semibold text-violet-700">Commission</p><p class="mt-2 text-2xl font-bold">{{ money(financeSummary.commission) }}</p></article>
                <article class="rounded-2xl bg-emerald-50 p-4"><p class="text-xs font-semibold text-emerald-700">Operator earnings</p><p class="mt-2 text-2xl font-bold">{{ money(financeSummary.operator) }}</p></article>
                <article class="rounded-2xl bg-rose-50 p-4"><p class="text-xs font-semibold text-rose-700">Refunded</p><p class="mt-2 text-2xl font-bold">{{ money(financeSummary.refunded) }}</p></article>
            </div>
            <div class="flex flex-wrap gap-2">
                <button v-for="item in [{ id: 'payments', label: 'All payments' }, { id: 'payouts', label: 'Commission & payouts' }, { id: 'refunds', label: 'Refunds' }]" :key="item.id" type="button" class="rounded-full px-4 py-2 text-sm font-semibold" :class="financeView === item.id ? 'bg-[#1b7ad8] text-white' : 'border border-[#d7e8f6] bg-white text-[#0f4c8a] dark:bg-[#102036] dark:text-slate-100 dark:border-white/10'" @click="financeView = item.id as FinanceView">{{ item.label }}</button>
            </div>
            <RentalPaymentRecords
                v-if="financeView === 'payments'"
                :rows="rentalPayments ?? []"
                :date-from="dateFrom"
                :date-to="dateTo"
                :boat-name="boatId === 'all' ? '' : boats.find((boat) => String(boat.id) === boatId)?.name || ''"
            />
            <section v-else class="overflow-hidden rounded-2xl border border-[#d7e8f6] bg-white shadow-sm dark:bg-[#102036] dark:text-slate-100 dark:border-white/10">
                <div class="flex flex-col gap-3 border-b border-[#eef4f9] px-5 py-4 sm:flex-row sm:items-center sm:justify-between">
                    <h3 class="text-base font-semibold text-[#0f4c8a]">
                        {{ financeView === 'refunds' ? 'Refunds' : 'Commission & payouts' }}
                    </h3>
                    <div class="flex flex-wrap items-center gap-3">
                        <p class="text-xs text-ink-muted">{{ financeRangeLabel }}</p>
                        <button type="button" class="inline-flex h-9 items-center gap-2 rounded-xl bg-[#1b7ad8] px-3 text-sm font-semibold text-white" @click="downloadExcel">
                            <Download class="size-4" />
                            Download Report
                        </button>
                    </div>
                </div>
                <div class="overflow-x-auto">
                    <table v-if="financeView === 'payouts'" class="w-full min-w-[980px] text-left text-sm">
                        <thead class="bg-[#f4f9fd] text-[11px] tracking-wide text-[#5b7c99] uppercase dark:bg-[#0b1b30] dark:text-slate-100">
                            <tr>
                                <th class="px-4 py-3 font-semibold">#</th>
                                <th class="px-3 py-3 font-semibold">Date</th>
                                <th class="px-3 py-3 font-semibold">Booking</th>
                                <th class="px-3 py-3 font-semibold">Operator</th>
                                <th class="px-3 py-3 font-semibold">Boat</th>
                                <th class="px-3 py-3 text-right font-semibold">Commission</th>
                                <th class="px-3 py-3 text-right font-semibold">Operator earnings</th>
                                <th class="px-3 py-3 font-semibold">Payout</th>
                                <th class="px-4 py-3 font-semibold">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="(row, index) in financeSlice(financePayouts)" :key="row.id" class="border-t border-[#eef4f9]">
                                <td class="px-4 py-3 text-ink-muted">{{ financeNumber(index) }}</td>
                                <td class="px-3 py-3">{{ row.date_label || '—' }}</td>
                                <td class="px-3 py-3 font-semibold text-[#1b7ad8]">{{ row.booking_code }}</td>
                                <td class="px-3 py-3">{{ row.operator }}</td>
                                <td class="px-3 py-3 font-semibold">{{ row.boat }}</td>
                                <td class="px-3 py-3 text-right">{{ money(row.commission) }}</td>
                                <td class="px-3 py-3 text-right font-semibold">{{ money(row.operator_share) }}</td>
                                <td class="px-3 py-3">{{ row.payout_status_label || '—' }}</td>
                                <td class="px-4 py-3">
                                    <a v-if="row.booking_id" :href="`/bookings/requests/${row.booking_id}`" class="inline-flex h-8 items-center rounded-full border border-[#c5def5] px-3 text-xs font-semibold text-[#1b7ad8]">View</a>
                                </td>
                            </tr>
                            <tr v-if="!financePayouts.length"><td colspan="9" class="px-4 py-10 text-center text-ink-muted">No commission records in this period.</td></tr>
                        </tbody>
                    </table>
                    <table v-else class="w-full min-w-[980px] text-left text-sm">
                        <thead class="bg-[#f4f9fd] text-[11px] tracking-wide text-[#5b7c99] uppercase dark:bg-[#0b1b30] dark:text-slate-100">
                            <tr>
                                <th class="px-4 py-3 font-semibold">#</th>
                                <th class="px-3 py-3 font-semibold">Date</th>
                                <th class="px-3 py-3 font-semibold">Reference</th>
                                <th class="px-3 py-3 font-semibold">Booking</th>
                                <th class="px-3 py-3 font-semibold">Passenger</th>
                                <th class="px-3 py-3 font-semibold">Boat</th>
                                <th class="px-3 py-3 text-right font-semibold">Refunded</th>
                                <th class="px-3 py-3 font-semibold">Status</th>
                                <th class="px-4 py-3 font-semibold">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="(row, index) in financeSlice(financeRefunds)" :key="row.id" class="border-t border-[#eef4f9]">
                                <td class="px-4 py-3 text-ink-muted">{{ financeNumber(index) }}</td>
                                <td class="px-3 py-3">{{ row.requested_at_label || '—' }}</td>
                                <td class="px-3 py-3">{{ row.reference }}</td>
                                <td class="px-3 py-3 font-semibold text-[#1b7ad8]">{{ row.booking_code }}</td>
                                <td class="px-3 py-3">{{ row.passenger }}</td>
                                <td class="px-3 py-3 font-semibold">{{ row.boat }}</td>
                                <td class="px-3 py-3 text-right font-semibold">{{ money(row.refund_amount) }}</td>
                                <td class="px-3 py-3">{{ row.status_label }}</td>
                                <td class="px-4 py-3">
                                    <a v-if="row.booking_id" :href="`/bookings/requests/${row.booking_id}`" class="inline-flex h-8 items-center rounded-full border border-[#c5def5] px-3 text-xs font-semibold text-[#1b7ad8]">View</a>
                                </td>
                            </tr>
                            <tr v-if="!financeRefunds.length"><td colspan="9" class="px-4 py-10 text-center text-ink-muted">No refunds in this period.</td></tr>
                        </tbody>
                    </table>
                </div>
                <div class="flex flex-wrap items-center justify-between gap-3 border-t border-[#eef4f9] px-5 py-3">
                    <label class="flex items-center gap-2 text-xs text-ink-muted">
                        Show
                        <select v-model.number="financePageSize" class="h-8 rounded-lg border border-[#d7e8f6] bg-white px-2 text-sm text-ink dark:bg-[#102036] dark:text-slate-100 dark:border-white/10">
                            <option :value="10">10</option>
                            <option :value="25">25</option>
                            <option :value="50">50</option>
                        </select>
                        entries
                    </label>
                    <div class="flex items-center gap-1">
                        <button type="button" class="inline-flex size-8 items-center justify-center rounded-lg border border-[#d7e8f6] text-ink-muted disabled:opacity-40" :disabled="financePage <= 1" @click="financePage -= 1">
                            <ChevronLeft class="size-4" />
                        </button>
                        <span class="flex size-8 items-center justify-center rounded-lg bg-[#1b7ad8] text-sm font-semibold text-white">{{ Math.min(financePage, financePages) }}</span>
                        <button type="button" class="inline-flex size-8 items-center justify-center rounded-lg border border-[#d7e8f6] text-ink-muted disabled:opacity-40" :disabled="financePage >= financePages" @click="financePage += 1">
                            <ChevronRight class="size-4" />
                        </button>
                    </div>
                </div>
            </section>
        </template>

        <BoatReport
            v-else
            :boats="fleetBoats"
            :trips="regularTrips"
            :bookings="bookings"
        />
    </div>
</template>
