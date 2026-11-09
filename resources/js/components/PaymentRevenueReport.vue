<script setup lang="ts">
import {
    ChevronDown,
    ChevronLeft,
    ChevronRight,
    FileDown,
    Search,
    Ship,
    Wallet,
    X,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { downloadReport, type ReportDownloadFormat, type ReportExportSpec } from '@/lib/reportExport';
import type { ReportBooking, ReportRegularTrip, ReportRevenueRecord } from '@/types/report';

type PeriodPreset = 'today' | 'week' | 'month';
type TxType = 'all' | 'regular' | 'rental';
type MethodFilter = 'all' | 'cash' | 'gcash' | 'bank';
type StatusFilter = 'all' | 'completed' | 'pending' | 'on_trip' | 'scheduled' | 'cancelled';

type RevenueRecord = {
    id: string;
    date: string;
    dateTimeLabel: string;
    type: 'regular' | 'rental';
    typeLabel: string;
    reference: string;
    boatId: number;
    boatName: string;
    customer: string;
    amount: number;
    amountLabel: string;
    countedAmount: number;
    method: string;
    methodKey: string;
    status: string;
    statusLabel: string;
};

type BoatRevenue = {
    id: number;
    name: string;
    regular: number;
    rental: number;
    total: number;
    transactions: number;
};

const props = defineProps<{
    trips: ReportRegularTrip[];
    bookings: ReportBooking[];
    records?: ReportRevenueRecord[];
    reportBoats?: { id: number; name: string }[];
    canViewPassengerDetails: boolean;
}>();

function localDate(value = new Date()) {
    const month = String(value.getMonth() + 1).padStart(2, '0');
    const day = String(value.getDate()).padStart(2, '0');

    return `${value.getFullYear()}-${month}-${day}`;
}

function parseLocalDate(value: string) {
    const [year, month, day] = value.split('-').map(Number);

    return new Date(year, (month ?? 1) - 1, day ?? 1);
}

function startOfWeek(date: Date) {
    const copy = new Date(date);
    const day = copy.getDay();
    const diff = day === 0 ? -6 : 1 - day;
    copy.setDate(copy.getDate() + diff);

    return copy;
}

function formatPeso(value: number) {
    return `₱${value.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
}

function formatDisplayDate(value: string) {
    return parseLocalDate(value).toLocaleDateString('en-US', {
        month: 'short',
        day: 'numeric',
        year: 'numeric',
    });
}

const now = new Date();
const monthStart = localDate(new Date(now.getFullYear(), now.getMonth(), 1));
const monthEnd = localDate(new Date(now.getFullYear(), now.getMonth() + 1, 0));

const draft = ref({
    from: monthStart,
    to: monthEnd,
    boat: 'all',
    type: 'all' as TxType,
    method: 'all' as MethodFilter,
    status: 'all' as StatusFilter,
});
const applied = ref({ ...draft.value });
const preset = ref<PeriodPreset>('month');
const selectedBoatId = ref<number | null>(null);
const boatPage = ref(1);
const recordPage = ref(1);
const boatPageSize = 5;
const recordPageSize = 10;

const boatOptions = computed(() => {
    if (props.reportBoats && props.reportBoats.length > 0) {
        return [...props.reportBoats].sort((left, right) => left.name.localeCompare(right.name));
    }

    const map = new Map<number, string>();
    props.trips.forEach((trip) => map.set(trip.boat.id, trip.boat.name));
    props.bookings.forEach((booking) => map.set(booking.boat.id, booking.boat.name));

    return [...map.entries()]
        .map(([id, name]) => ({ id, name }))
        .sort((left, right) => left.name.localeCompare(right.name));
});

function methodKey(value: string) {
    const text = value.toLowerCase();

    if (text.includes('gcash')) {
        return 'gcash';
    }

    if (text.includes('bank')) {
        return 'bank';
    }

    if (text.includes('cash')) {
        return 'cash';
    }

    return 'other';
}

function recordStatus(status: string) {
    if (['completed'].includes(status)) {
        return 'completed';
    }

    if (['pending', 'for_verification', 'unpaid', 'waiting'].includes(status)) {
        return 'pending';
    }

    if (['on_trip', 'ongoing'].includes(status)) {
        return 'on_trip';
    }

    if (status === 'cancelled' || status === 'rejected') {
        return 'cancelled';
    }

    return 'scheduled';
}

function statusTone(status: string) {
    if (status === 'completed') {
        return 'bg-[#dcfce7] text-[#15803d] dark:bg-emerald-500/20 dark:text-emerald-200';
    }

    if (status === 'pending') {
        return 'bg-[#fef3c7] text-[#d97706] dark:bg-amber-500/20 dark:text-amber-200';
    }

    if (status === 'on_trip') {
        return 'bg-[#dbeafe] text-[#1d4ed8] dark:bg-sky-500/20 dark:text-sky-200';
    }

    if (status === 'cancelled') {
        return 'bg-[#fee2e2] text-[#b91c1c] dark:bg-rose-500/20 dark:text-rose-200';
    }

    return 'bg-[#e8f1fb] text-[#1b7ad8] dark:bg-sky-500/20 dark:text-sky-200';
}

function mapBackendRecord(row: ReportRevenueRecord): RevenueRecord {
    return {
        id: row.id,
        date: row.date,
        dateTimeLabel: row.date_time_label,
        type: row.type,
        typeLabel: row.type_label,
        reference: row.reference,
        boatId: row.boat_id,
        boatName: row.boat_name,
        customer: row.customer,
        amount: Number(row.amount || 0),
        amountLabel: row.amount_label,
        countedAmount: Number(row.counted_amount || 0),
        method: row.method,
        methodKey: row.method_key,
        status: row.status,
        statusLabel: row.status_label,
    };
}

const allRecords = computed<RevenueRecord[]>(() => {
    if (props.records && props.records.length > 0) {
        return [...props.records].map(mapBackendRecord).sort((left, right) => {
            const dateCompare = left.date.localeCompare(right.date);

            return dateCompare !== 0 ? dateCompare : left.reference.localeCompare(right.reference);
        });
    }

    const tripRows: RevenueRecord[] = props.trips.flatMap((trip) => {
        const counted = trip.status === 'cancelled' ? 0 : Number(trip.revenue || 0);
        const status = recordStatus(trip.report_status || trip.status);
        const base = {
            date: trip.trip_date,
            dateTimeLabel: `${trip.trip_date_label}\n${trip.departure_time_label}`,
            type: 'regular' as const,
            typeLabel: 'Regular Trip',
            boatId: trip.boat.id,
            boatName: trip.boat.name,
            method: 'Cash',
            methodKey: 'cash',
            status,
            statusLabel: trip.report_status_label || trip.status_label,
        };

        if (props.canViewPassengerDetails && trip.passengers?.length) {
            return trip.passengers.map((passenger) => {
                const fare = Number(passenger.fare_amount ?? 0);

                return {
                    ...base,
                    id: `trip-${trip.id}-${passenger.id}`,
                    reference: trip.code,
                    customer: passenger.full_name,
                    amount: fare,
                    amountLabel: passenger.fare_amount_label || formatPeso(fare),
                    countedAmount: trip.status === 'cancelled' ? 0 : fare,
                };
            });
        }

        return [{
            ...base,
            id: `trip-${trip.id}`,
            reference: trip.code,
            customer: props.canViewPassengerDetails ? 'No passengers recorded' : '—',
            amount: counted,
            amountLabel: trip.revenue_label || formatPeso(counted),
            countedAmount: counted,
        }];
    });

    const rentalRows: RevenueRecord[] = props.bookings.map((booking) => {
        const confirmed = Number(booking.amount ?? booking.verified_amount ?? 0);
        const quoted = Number(booking.quoted_amount ?? 0);
        const display = confirmed > 0 ? confirmed : quoted;
        const status = recordStatus(booking.report_status || booking.status);
        const cancelled = status === 'cancelled';

        return {
            id: `rental-${booking.id}`,
            date: booking.booking_date,
            dateTimeLabel: `${booking.booking_date_label}\n${booking.start_time_label}`,
            type: 'rental' as const,
            typeLabel: 'Private Rental',
            reference: booking.code,
            boatId: booking.boat.id,
            boatName: booking.boat.name,
            customer: booking.customer_name,
            amount: display,
            amountLabel: confirmed > 0 ? (booking.amount_label || formatPeso(confirmed)) : (booking.quoted_amount_label || formatPeso(display)),
            countedAmount: cancelled ? 0 : confirmed,
            method: booking.payment_method && booking.payment_method !== '—' ? booking.payment_method : 'Cash',
            methodKey: methodKey(booking.payment_method || 'Cash'),
            status,
            statusLabel: booking.report_status_label || booking.status_label,
        };
    });

    return [...tripRows, ...rentalRows].sort((left, right) => {
        const dateCompare = left.date.localeCompare(right.date);

        return dateCompare !== 0 ? dateCompare : left.reference.localeCompare(right.reference);
    });
});

const filteredRecords = computed(() =>
    allRecords.value.filter((row) => {
        if (row.date < applied.value.from || row.date > applied.value.to) {
            return false;
        }

        if (applied.value.boat !== 'all' && String(row.boatId) !== applied.value.boat) {
            return false;
        }

        if (applied.value.type !== 'all' && row.type !== applied.value.type) {
            return false;
        }

        if (applied.value.method !== 'all' && row.methodKey !== applied.value.method) {
            return false;
        }

        if (applied.value.status !== 'all' && row.status !== applied.value.status) {
            return false;
        }

        return true;
    }),
);

const boatRows = computed<BoatRevenue[]>(() => {
    const grouped = new Map<number, BoatRevenue>();

    filteredRecords.value.forEach((row) => {
        const current = grouped.get(row.boatId) ?? {
            id: row.boatId,
            name: row.boatName,
            regular: 0,
            rental: 0,
            total: 0,
            transactions: 0,
        };

        if (row.type === 'regular') {
            current.regular += row.countedAmount;
        } else {
            current.rental += row.countedAmount;
        }

        current.total += row.countedAmount;
        current.transactions += 1;
        grouped.set(row.boatId, current);
    });

    const rows = [...grouped.values()].sort((left, right) => left.name.localeCompare(right.name));

    if (applied.value.boat === 'all') {
        return rows;
    }

    const boatId = Number(applied.value.boat);
    const match = rows.find((boat) => boat.id === boatId);

    if (match) {
        return [match];
    }

    const option = boatOptions.value.find((boat) => boat.id === boatId);

    if (!option) {
        return [];
    }

    return [
        {
            id: option.id,
            name: option.name,
            regular: 0,
            rental: 0,
            total: 0,
            transactions: 0,
        },
    ];
});

const selectedBoat = computed(() => {
    if (selectedBoatId.value === null) {
        return null;
    }

    const found = boatRows.value.find((boat) => boat.id === selectedBoatId.value);

    if (found) {
        return found;
    }

    const option = boatOptions.value.find((boat) => boat.id === selectedBoatId.value);

    if (!option) {
        return null;
    }

    return {
        id: option.id,
        name: option.name,
        regular: 0,
        rental: 0,
        total: 0,
        transactions: 0,
    };
});

const visibleRecords = computed(() =>
    selectedBoat.value
        ? filteredRecords.value.filter((row) => row.boatId === selectedBoat.value?.id)
        : filteredRecords.value,
);

const totals = computed(() =>
    boatRows.value.reduce(
        (sum, boat) => ({
            regular: sum.regular + boat.regular,
            rental: sum.rental + boat.rental,
            total: sum.total + boat.total,
            transactions: sum.transactions + boat.transactions,
        }),
        { regular: 0, rental: 0, total: 0, transactions: 0 },
    ),
);

const donut = computed(() => {
    const regular = totals.value.regular;
    const rental = totals.value.rental;
    const total = Math.max(regular + rental, 0.01);
    const circumference = 2 * Math.PI * 52;
    const regularLength = (regular / total) * circumference;

    return {
        circumference,
        regularLength,
        rentalLength: circumference - regularLength,
        regularPercent: regular + rental === 0 ? 0 : (regular / (regular + rental)) * 100,
        rentalPercent: regular + rental === 0 ? 0 : (rental / (regular + rental)) * 100,
    };
});

const barColors = ['#3b82f6', '#22c55e', '#eab308', '#a855f7', '#ef4444', '#06b6d4', '#f97316'];
const barMax = computed(() => Math.max(1, ...boatRows.value.map((boat) => boat.total)));

const trendPoints = computed(() => {
    const from = parseLocalDate(applied.value.from);
    const to = parseLocalDate(applied.value.to);
    const span = Math.round((to.getTime() - from.getTime()) / 86400000) + 1;
    const buckets = new Map<string, number>();

    if (preset.value === 'month' || span > 21) {
        const cursor = new Date(from.getFullYear(), from.getMonth(), 1);
        const end = new Date(to.getFullYear(), to.getMonth(), 1);

        while (cursor <= end) {
            const key = `${cursor.getFullYear()}-${String(cursor.getMonth() + 1).padStart(2, '0')}`;
            buckets.set(key, 0);
            cursor.setMonth(cursor.getMonth() + 1);
        }

        filteredRecords.value.forEach((row) => {
            const key = row.date.slice(0, 7);
            buckets.set(key, (buckets.get(key) ?? 0) + row.countedAmount);
        });

        return [...buckets.entries()].map(([key, value]) => ({
            label: parseLocalDate(`${key}-01`).toLocaleDateString('en-US', { month: 'short' }),
            value,
        }));
    }

    if (preset.value === 'week' || span > 1) {
        const cursor = new Date(from);

        while (cursor <= to) {
            buckets.set(localDate(cursor), 0);
            cursor.setDate(cursor.getDate() + 1);
        }

        filteredRecords.value.forEach((row) => {
            buckets.set(row.date, (buckets.get(row.date) ?? 0) + row.countedAmount);
        });

        return [...buckets.entries()].map(([key, value]) => ({
            label: parseLocalDate(key).toLocaleDateString('en-US', { month: 'short', day: 'numeric' }),
            value,
        }));
    }

    return [{ label: formatDisplayDate(applied.value.from), value: totals.value.total }];
});

const trendMax = computed(() => Math.max(1, ...trendPoints.value.map((point) => point.value)));
const trendPath = computed(() => {
    const points = trendPoints.value;

    if (points.length === 0) {
        return '';
    }

    const width = 320;
    const height = 120;
    const max = trendMax.value;

    return points
        .map((point, index) => {
            const x = points.length === 1 ? width / 2 : (index / (points.length - 1)) * width;
            const y = height - (point.value / max) * height;

            return `${index === 0 ? 'M' : 'L'}${x},${y}`;
        })
        .join(' ');
});

const periodLabel = computed(() => {
    if (applied.value.from === applied.value.to) {
        return formatDisplayDate(applied.value.from);
    }

    return `${formatDisplayDate(applied.value.from)} – ${formatDisplayDate(applied.value.to)}`;
});

const boatTotalPages = computed(() => Math.max(1, Math.ceil(boatRows.value.length / boatPageSize)));
const pagedBoats = computed(() => {
    const start = (boatPage.value - 1) * boatPageSize;

    return boatRows.value.slice(start, start + boatPageSize);
});
const boatFrom = computed(() => (boatRows.value.length === 0 ? 0 : (boatPage.value - 1) * boatPageSize + 1));
const boatTo = computed(() => Math.min(boatPage.value * boatPageSize, boatRows.value.length));

const recordTotalPages = computed(() => Math.max(1, Math.ceil(visibleRecords.value.length / recordPageSize)));
const pagedRecords = computed(() => {
    const start = (recordPage.value - 1) * recordPageSize;

    return visibleRecords.value.slice(start, start + recordPageSize);
});
const recordFrom = computed(() => (visibleRecords.value.length === 0 ? 0 : (recordPage.value - 1) * recordPageSize + 1));
const recordTo = computed(() => Math.min(recordPage.value * recordPageSize, visibleRecords.value.length));

function applyPreset(next: PeriodPreset) {
    preset.value = next;
    const today = new Date();

    if (next === 'today') {
        draft.value.from = localDate(today);
        draft.value.to = localDate(today);
    } else if (next === 'week') {
        draft.value.from = localDate(startOfWeek(today));
        draft.value.to = localDate(today);
    } else if (next === 'month') {
        draft.value.from = localDate(new Date(today.getFullYear(), today.getMonth(), 1));
        draft.value.to = localDate(new Date(today.getFullYear(), today.getMonth() + 1, 0));
    }

    applyFilters();
}

function applyFilters() {
    applied.value = { ...draft.value };
    selectedBoatId.value = null;
    boatPage.value = 1;
    recordPage.value = 1;
}

function filterByBoat(id: number) {
    draft.value.boat = String(id);
    applyFilters();
}

function openBoatDetail(id: number) {
    draft.value.boat = String(id);
    applied.value = { ...draft.value };
    selectedBoatId.value = id;
    recordPage.value = 1;
}

function closeBoat() {
    selectedBoatId.value = null;
    recordPage.value = 1;
}

function exportSpec(records: RevenueRecord[], title: string, filename: string): ReportExportSpec {
    return {
        title,
        subtitle: `Revenue records for ${periodLabel.value}`,
        filename,
        sectionTitle: 'Detailed Revenue Records',
        columns: [
            { key: 'datetime', header: 'Date & Time' },
            { key: 'type', header: 'Transaction Type' },
            { key: 'reference', header: 'Reference ID' },
            { key: 'boat', header: 'Boat Name' },
            { key: 'customer', header: 'Customer' },
            { key: 'amount', header: 'Amount' },
            { key: 'method', header: 'Payment Method' },
            { key: 'status', header: 'Status' },
        ],
        rows: records.map((row) => ({
            datetime: row.dateTimeLabel.replace('\n', ' '),
            type: row.typeLabel,
            reference: row.reference,
            boat: row.boatName,
            customer: row.customer,
            amount: row.amountLabel,
            method: row.method,
            status: row.statusLabel,
        })),
        stats: [
            { label: 'Regular Trip Revenue', value: formatPeso(selectedBoat.value?.regular ?? totals.value.regular) },
            { label: 'Private Rental Revenue', value: formatPeso(selectedBoat.value?.rental ?? totals.value.rental) },
            { label: 'Total Revenue', value: formatPeso(selectedBoat.value?.total ?? totals.value.total) },
            { label: 'Total Transactions', value: String(selectedBoat.value?.transactions ?? totals.value.transactions) },
        ],
        emptyMessage: 'No revenue records for this selection.',
        note: 'Regular trip revenue is based on recorded passenger fares. Private rental revenue includes verified down payments and recorded remaining balances.',
    };
}

function downloadAll(format: ReportDownloadFormat) {
    downloadReport(format, exportSpec(
        filteredRecords.value,
        'Payment Report — Revenue',
        `payment-revenue-${applied.value.from}-to-${applied.value.to}`,
    ));
}

function downloadBoat(format: ReportDownloadFormat) {
    if (!selectedBoat.value) {
        return;
    }

    downloadReport(format, exportSpec(
        visibleRecords.value,
        `Payment Report — ${selectedBoat.value.name}`,
        `payment-revenue-${selectedBoat.value.name.toLowerCase().replaceAll(/\s+/g, '-')}-${applied.value.from}-to-${applied.value.to}`,
    ));
}
</script>

<template>
    <div class="space-y-4">
        <section class="rounded-[16px] border border-[#d7e8f6] bg-white dark:border-white/10 dark:bg-white/5 px-4 py-3 shadow-[0_2px_8px_rgba(15,76,138,0.05)]">
            <div class="flex flex-col gap-3 xl:flex-row xl:items-end">
                <label class="w-full xl:w-48">
                    <span class="mb-1.5 block text-xs font-semibold text-ink-soft dark:text-slate-400">Date Range</span>
                    <select
                        v-model="preset"
                        class="h-10 w-full rounded-xl border border-[#d7e8f6] bg-white px-3 text-sm text-ink outline-none dark:border-white/10 dark:bg-white/5 dark:text-sky-50"
                        @change="applyPreset(preset)"
                    >
                        <option value="today">Today</option>
                        <option value="week">This Week</option>
                        <option value="month">This Month</option>
                    </select>
                </label>
                <label class="w-full xl:w-40">
                    <span class="mb-1.5 block text-xs font-semibold text-ink-soft dark:text-slate-400">Boat</span>
                    <select
                        v-model="draft.boat"
                        class="h-10 w-full rounded-xl border border-[#d7e8f6] bg-white dark:border-white/10 dark:bg-white/5 px-3 text-sm text-ink dark:text-sky-50 outline-none"
                        @change="applyFilters"
                    >
                        <option value="all">All Boats</option>
                        <option v-for="boat in boatOptions" :key="boat.id" :value="String(boat.id)">{{ boat.name }}</option>
                    </select>
                </label>
                <label class="w-full xl:w-44">
                    <span class="mb-1.5 block text-xs font-semibold text-ink-soft dark:text-slate-400">Transaction Type</span>
                    <select v-model="draft.type" class="h-10 w-full rounded-xl border border-[#d7e8f6] bg-white dark:border-white/10 dark:bg-white/5 px-3 text-sm text-ink dark:text-sky-50 outline-none">
                        <option value="all">All Types</option>
                        <option value="regular">Regular Trip</option>
                        <option value="rental">Private Rental</option>
                    </select>
                </label>
                <label class="w-full xl:w-40">
                    <span class="mb-1.5 block text-xs font-semibold text-ink-soft dark:text-slate-400">Payment Method</span>
                    <select v-model="draft.method" class="h-10 w-full rounded-xl border border-[#d7e8f6] bg-white dark:border-white/10 dark:bg-white/5 px-3 text-sm text-ink dark:text-sky-50 outline-none">
                        <option value="all">All Methods</option>
                        <option value="cash">Cash</option>
                        <option value="gcash">GCash</option>
                        <option value="bank">Bank Transfer</option>
                    </select>
                </label>
                <label class="w-full xl:w-40">
                    <span class="mb-1.5 block text-xs font-semibold text-ink-soft dark:text-slate-400">Status</span>
                    <select v-model="draft.status" class="h-10 w-full rounded-xl border border-[#d7e8f6] bg-white dark:border-white/10 dark:bg-white/5 px-3 text-sm text-ink dark:text-sky-50 outline-none">
                        <option value="all">All Status</option>
                        <option value="completed">Completed</option>
                        <option value="on_trip">On Trip</option>
                        <option value="pending">Pending</option>
                        <option value="scheduled">Scheduled</option>
                        <option value="cancelled">Cancelled</option>
                    </select>
                </label>
                <div class="flex shrink-0 items-end gap-2">
                    <Button class="h-10 rounded-xl bg-[#1b7ad8] px-4 text-sm font-semibold text-white hover:bg-[#1567c4]" @click="applyFilters">
                        <Search class="size-4" />
                        Search
                    </Button>
                </div>
            </div>
        </section>

        <template v-if="!selectedBoat">
            <div class="grid gap-4 xl:grid-cols-3">
                <section class="rounded-[16px] border border-[#d7e8f6] bg-white dark:border-white/10 dark:bg-white/5 p-4 shadow-[0_2px_8px_rgba(15,76,138,0.05)]">
                    <h3 class="text-sm font-semibold text-[#0f4c8a] dark:text-sky-100">Revenue by Transaction Type</h3>
                    <div class="mt-4 flex items-center gap-5">
                        <svg viewBox="0 0 160 160" class="size-36 shrink-0">
                            <circle cx="80" cy="80" r="52" fill="none" class="stroke-[#e8f1fb] dark:stroke-white/10" stroke-width="22" />
                            <circle
                                cx="80"
                                cy="80"
                                r="52"
                                fill="none"
                                stroke="#3b82f6"
                                stroke-width="22"
                                stroke-linecap="butt"
                                :stroke-dasharray="`${donut.regularLength} ${donut.circumference}`"
                                transform="rotate(-90 80 80)"
                            />
                            <circle
                                cx="80"
                                cy="80"
                                r="52"
                                fill="none"
                                stroke="#f59e0b"
                                stroke-width="22"
                                stroke-linecap="butt"
                                :stroke-dasharray="`${donut.rentalLength} ${donut.circumference}`"
                                :stroke-dashoffset="-donut.regularLength"
                                transform="rotate(-90 80 80)"
                            />
                            <text x="80" y="76" text-anchor="middle" class="fill-[#0f4c8a] dark:fill-sky-100" font-size="13" font-weight="700">{{ formatPeso(totals.total) }}</text>
                            <text x="80" y="94" text-anchor="middle" class="fill-[#7d93a8] dark:fill-slate-400" font-size="8">Total Revenue</text>
                        </svg>
                        <div class="space-y-3 text-sm">
                            <div>
                                <p class="flex items-center gap-2 font-medium text-ink dark:text-sky-50">
                                    <span class="size-2.5 rounded-full bg-[#3b82f6]" /> Regular Trip
                                </p>
                                <p class="pl-5 text-[#0f4c8a] dark:text-sky-100">{{ formatPeso(totals.regular) }} <span class="text-xs text-ink-muted dark:text-slate-400">({{ donut.regularPercent.toFixed(1) }}%)</span></p>
                            </div>
                            <div>
                                <p class="flex items-center gap-2 font-medium text-ink dark:text-sky-50">
                                    <span class="size-2.5 rounded-full bg-[#f59e0b]" /> Private Rental
                                </p>
                                <p class="pl-5 text-[#0f4c8a] dark:text-sky-100">{{ formatPeso(totals.rental) }} <span class="text-xs text-ink-muted dark:text-slate-400">({{ donut.rentalPercent.toFixed(1) }}%)</span></p>
                            </div>
                        </div>
                    </div>
                </section>

                <section class="rounded-[16px] border border-[#d7e8f6] bg-white dark:border-white/10 dark:bg-white/5 p-4 shadow-[0_2px_8px_rgba(15,76,138,0.05)]">
                    <h3 class="text-sm font-semibold text-[#0f4c8a] dark:text-sky-100">Revenue by Boat</h3>
                    <div v-if="boatRows.length === 0" class="flex h-40 items-center justify-center text-sm text-ink-muted dark:text-slate-400">
                        No boat revenue for this period.
                    </div>
                    <div v-else class="mt-4 flex h-40 items-end gap-2">
                        <button
                            v-for="(boat, index) in boatRows.slice(0, 8)"
                            :key="boat.id"
                            type="button"
                            class="flex min-w-0 flex-1 flex-col items-center justify-end gap-1 rounded-lg transition hover:bg-[#f4f9fd] dark:hover:bg-white/5"
                            @click="filterByBoat(boat.id)"
                        >
                            <span class="text-[11px] font-semibold text-ink-soft">{{ formatPeso(boat.total) }}</span>
                            <div
                                class="w-full max-w-10 rounded-t-md"
                                :style="{
                                    height: `${Math.max(8, (boat.total / barMax) * 110)}px`,
                                    background: barColors[index % barColors.length],
                                }"
                            />
                            <span class="max-w-full truncate text-[11px] font-medium text-ink-muted dark:text-slate-400">{{ boat.name }}</span>
                        </button>
                    </div>
                </section>

                <section class="rounded-[16px] border border-[#d7e8f6] bg-white dark:border-white/10 dark:bg-white/5 p-4 shadow-[0_2px_8px_rgba(15,76,138,0.05)]">
                    <h3 class="text-sm font-semibold text-[#0f4c8a] dark:text-sky-100">
                        {{ preset === 'today' ? 'Daily' : preset === 'week' ? 'Weekly' : 'Monthly' }} Revenue Trend
                    </h3>
                    <div v-if="trendPoints.length === 0" class="flex h-40 items-center justify-center text-sm text-ink-muted dark:text-slate-400">
                        No trend data for this period.
                    </div>
                    <svg v-else viewBox="-10 -10 340 150" class="mt-3 h-40 w-full">
                        <line v-for="n in 4" :key="n" x1="0" :y1="(n - 1) * 40" x2="320" :y2="(n - 1) * 40" class="stroke-[#e8f1fb] dark:stroke-white/10" />
                        <path :d="trendPath" fill="none" stroke="#3b82f6" stroke-width="2.5" />
                        <circle
                            v-for="(point, index) in trendPoints"
                            :key="point.label"
                            :cx="trendPoints.length === 1 ? 160 : (index / Math.max(trendPoints.length - 1, 1)) * 320"
                            :cy="120 - (point.value / trendMax) * 120"
                            r="4"
                            fill="#3b82f6"
                        />
                        <text
                            v-for="(point, index) in trendPoints"
                            :key="`l-${point.label}`"
                            :x="trendPoints.length === 1 ? 160 : (index / Math.max(trendPoints.length - 1, 1)) * 320"
                            y="142"
                            text-anchor="middle"
                            font-size="8"
                            class="fill-[#7d93a8] dark:fill-slate-400"
                        >
                            {{ point.label }}
                        </text>
                    </svg>
                </section>
            </div>

            <div class="grid gap-4">
                <section class="overflow-hidden rounded-[16px] border border-[#d7e8f6] bg-white dark:border-white/10 dark:bg-white/5 shadow-[0_2px_8px_rgba(15,76,138,0.05)]">
                    <div class="flex items-center justify-between px-4 py-3">
                        <h3 class="text-sm font-semibold text-[#0f4c8a] dark:text-sky-100">Revenue per Boat</h3>
                        <p class="text-xs text-ink-muted dark:text-slate-400">Showing {{ boatFrom }}–{{ boatTo }} of {{ boatRows.length }} boats</p>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="w-full text-left text-sm">
                            <thead class="bg-[#f4f9fd] text-[11px] tracking-wide text-ink-soft uppercase dark:bg-white/5 dark:text-slate-400">
                                <tr>
                                    <th class="px-3 py-2.5 font-semibold">#</th>
                                    <th class="px-3 py-2.5 font-semibold">Boat Name</th>
                                    <th class="px-3 py-2.5 font-semibold">Regular Trip Revenue</th>
                                    <th class="px-3 py-2.5 font-semibold">Private Rental Revenue</th>
                                    <th class="px-3 py-2.5 font-semibold">Total Revenue</th>
                                    <th class="px-3 py-2.5 font-semibold">Total Transactions</th>
                                    <th class="px-3 py-2.5 font-semibold">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-if="pagedBoats.length === 0">
                                    <td colspan="7" class="px-3 py-8 text-center text-sm text-ink-muted dark:text-slate-400">No boat revenue for this period.</td>
                                </tr>
                                <tr v-for="(boat, index) in pagedBoats" :key="boat.id" class="border-t border-[#eef4f9] dark:border-white/10">
                                    <td class="px-3 py-2.5 text-ink-muted dark:text-slate-400">{{ boatFrom + index }}</td>
                                    <td class="px-3 py-2.5">
                                        <button
                                            type="button"
                                            class="font-medium text-[#1b7ad8] hover:underline dark:text-sky-300"
                                            @click="filterByBoat(boat.id)"
                                        >
                                            {{ boat.name }}
                                        </button>
                                    </td>
                                    <td class="px-3 py-2.5 text-ink dark:text-sky-50">{{ formatPeso(boat.regular) }}</td>
                                    <td class="px-3 py-2.5 text-ink dark:text-sky-50">{{ formatPeso(boat.rental) }}</td>
                                    <td class="px-3 py-2.5 font-semibold text-[#0f4c8a] dark:text-sky-100">{{ formatPeso(boat.total) }}</td>
                                    <td class="px-3 py-2.5 text-ink dark:text-sky-50">{{ boat.transactions }}</td>
                                    <td class="px-3 py-2.5">
                                        <button
                                            type="button"
                                            class="rounded-full border border-[#1b7ad8] px-3 py-1 text-xs font-semibold text-[#1b7ad8] hover:bg-[#f3f9ff] dark:border-sky-400/70 dark:text-sky-200 dark:hover:bg-white/10"
                                            @click="openBoatDetail(boat.id)"
                                        >
                                            View
                                        </button>
                                    </td>
                                </tr>
                            </tbody>
                            <tfoot v-if="boatRows.length">
                                <tr class="border-t border-[#d7e8f6] bg-[#f8fbfe] font-semibold text-[#0f4c8a] dark:border-white/10 dark:bg-white/5 dark:text-sky-100">
                                    <td class="px-3 py-2.5" colspan="2">TOTAL</td>
                                    <td class="px-3 py-2.5">{{ formatPeso(totals.regular) }}</td>
                                    <td class="px-3 py-2.5">{{ formatPeso(totals.rental) }}</td>
                                    <td class="px-3 py-2.5">{{ formatPeso(totals.total) }}</td>
                                    <td class="px-3 py-2.5">{{ totals.transactions }}</td>
                                    <td />
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                    <div class="flex justify-end gap-1 border-t border-[#eef4f9] dark:border-white/10 px-4 py-3">
                        <button type="button" class="flex size-8 items-center justify-center rounded-lg text-ink-soft disabled:opacity-40" :disabled="boatPage <= 1" @click="boatPage -= 1">
                            <ChevronLeft class="size-4" />
                        </button>
                        <span class="flex size-8 items-center justify-center rounded-lg bg-[#1b7ad8] text-sm font-semibold text-white">{{ boatPage }}</span>
                        <button type="button" class="flex size-8 items-center justify-center rounded-lg text-ink-soft disabled:opacity-40" :disabled="boatPage >= boatTotalPages" @click="boatPage += 1">
                            <ChevronRight class="size-4" />
                        </button>
                    </div>
                </section>

                <section class="overflow-hidden rounded-[16px] border border-[#d7e8f6] bg-white dark:border-white/10 dark:bg-white/5 shadow-[0_2px_8px_rgba(15,76,138,0.05)]">
                    <div class="flex flex-wrap items-center justify-between gap-2 px-4 py-3">
                        <h3 class="text-sm font-semibold text-[#0f4c8a] dark:text-sky-100">Detailed Revenue Records</h3>
                        <div class="flex items-center gap-3">
                            <p class="text-xs text-ink-muted dark:text-slate-400">Showing {{ recordFrom }}–{{ recordTo }} of {{ visibleRecords.length }} records</p>
                            <DropdownMenu>
                                <DropdownMenuTrigger as-child>
                                    <Button type="button" class="h-8 rounded-xl bg-[#1b7ad8] px-3 text-xs text-white hover:bg-[#1567c4]" :disabled="filteredRecords.length === 0">
                                        <FileDown class="size-3.5" />
                                        Download Report
                                        <ChevronDown class="size-3.5" />
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
                    </div>
                    <div class="overflow-x-auto">
                        <table class="w-full min-w-[860px] text-left text-sm">
                            <thead class="bg-[#f4f9fd] text-[11px] tracking-wide text-ink-soft uppercase dark:bg-white/5 dark:text-slate-400">
                                <tr>
                                    <th class="px-3 py-2.5 font-semibold">#</th>
                                    <th class="px-3 py-2.5 font-semibold">Date & Time</th>
                                    <th class="px-3 py-2.5 font-semibold">Transaction Type</th>
                                    <th class="px-3 py-2.5 font-semibold">Reference ID</th>
                                    <th class="px-3 py-2.5 font-semibold">Boat Name</th>
                                    <th class="px-3 py-2.5 font-semibold">Customer</th>
                                    <th class="px-3 py-2.5 font-semibold">Amount</th>
                                    <th class="px-3 py-2.5 font-semibold">Payment Method</th>
                                    <th class="px-3 py-2.5 font-semibold">Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-if="pagedRecords.length === 0">
                                    <td colspan="9" class="px-3 py-8 text-center text-sm text-ink-muted dark:text-slate-400">No revenue records for this period.</td>
                                </tr>
                                <tr v-for="(row, index) in pagedRecords" :key="row.id" class="border-t border-[#eef4f9] dark:border-white/10">
                                    <td class="px-3 py-2.5 text-ink-muted dark:text-slate-400">{{ recordFrom + index }}</td>
                                    <td class="px-3 py-2.5 whitespace-pre-line text-ink dark:text-sky-50">{{ row.dateTimeLabel }}</td>
                                    <td class="px-3 py-2.5 text-ink dark:text-sky-50">{{ row.typeLabel }}</td>
                                    <td class="px-3 py-2.5 font-medium text-[#1b7ad8] dark:text-sky-300">{{ row.reference }}</td>
                                    <td class="px-3 py-2.5 text-ink dark:text-sky-50">{{ row.boatName }}</td>
                                    <td class="px-3 py-2.5 text-ink dark:text-sky-50">{{ row.customer }}</td>
                                    <td class="px-3 py-2.5 font-medium text-[#0f4c8a] dark:text-sky-100">{{ row.amountLabel }}</td>
                                    <td class="px-3 py-2.5 text-ink dark:text-sky-50">{{ row.method }}</td>
                                    <td class="px-3 py-2.5">
                                        <span class="inline-flex rounded-full px-2.5 py-0.5 text-[11px] font-semibold" :class="statusTone(row.status)">
                                            {{ row.statusLabel }}
                                        </span>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <div class="flex justify-end gap-1 border-t border-[#eef4f9] dark:border-white/10 px-4 py-3">
                        <button type="button" class="flex size-8 items-center justify-center rounded-lg text-ink-soft disabled:opacity-40" :disabled="recordPage <= 1" @click="recordPage -= 1">
                            <ChevronLeft class="size-4" />
                        </button>
                        <span class="flex size-8 items-center justify-center rounded-lg bg-[#1b7ad8] text-sm font-semibold text-white">{{ recordPage }}</span>
                        <button type="button" class="flex size-8 items-center justify-center rounded-lg text-ink-soft disabled:opacity-40" :disabled="recordPage >= recordTotalPages" @click="recordPage += 1">
                            <ChevronRight class="size-4" />
                        </button>
                    </div>
                </section>
            </div>
        </template>

        <section
            v-else
            class="overflow-hidden rounded-[16px] border border-[#d7e8f6] bg-white dark:border-white/10 dark:bg-white/5 shadow-[0_2px_8px_rgba(15,76,138,0.05)]"
        >
            <div class="flex items-start justify-between gap-3 border-b border-[#eef4f9] px-5 py-4 dark:border-white/10">
                <div>
                    <h2 class="text-base font-semibold text-[#0f4c8a] dark:text-sky-100">{{ selectedBoat.name }} — {{ periodLabel }}</h2>
                    <p class="mt-0.5 text-xs text-ink-muted dark:text-slate-400">
                        {{ preset === 'today' ? 'Daily' : preset === 'week' ? 'Weekly' : 'Monthly' }} revenue proof
                        <span class="mx-1.5 text-[#c5d6e6]">|</span>
                        {{ selectedBoat.transactions }} transactions
                        <span class="mx-1.5 text-[#c5d6e6]">|</span>
                        {{ formatPeso(selectedBoat.total) }} total revenue
                    </p>
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
                    <button type="button" class="rounded-full p-1.5 text-ink-muted dark:text-slate-400 hover:bg-[#f4f9fd]" aria-label="Back" @click="closeBoat">
                        <X class="size-4" />
                    </button>
                </div>
            </div>

            <div class="grid grid-cols-3 gap-3 border-b border-[#eef4f9] bg-[#f8fbfe] px-5 py-4 dark:border-white/10 dark:bg-white/5">
                <div>
                    <p class="text-[11px] text-ink-muted dark:text-slate-400">Regular Trip Revenue</p>
                    <p class="text-sm font-semibold text-[#0f4c8a] dark:text-sky-100">{{ formatPeso(selectedBoat.regular) }}</p>
                </div>
                <div>
                    <p class="text-[11px] text-ink-muted dark:text-slate-400">Private Rental Revenue</p>
                    <p class="text-sm font-semibold text-[#0f4c8a] dark:text-sky-100">{{ formatPeso(selectedBoat.rental) }}</p>
                </div>
                <div class="flex items-center gap-2">
                    <span class="flex size-8 items-center justify-center rounded-lg bg-[#dbeafe] text-[#1d4ed8]">
                        <Wallet class="size-4" />
                    </span>
                    <div>
                        <p class="text-[11px] text-ink-muted dark:text-slate-400">Total Revenue</p>
                        <p class="text-sm font-semibold text-[#0f4c8a] dark:text-sky-100">{{ formatPeso(selectedBoat.total) }}</p>
                    </div>
                </div>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full min-w-[860px] text-left text-sm">
                    <thead class="bg-[#f4f9fd] text-[11px] tracking-wide text-ink-soft uppercase dark:bg-white/5 dark:text-slate-400">
                        <tr>
                            <th class="px-4 py-2.5 font-semibold">#</th>
                            <th class="px-4 py-2.5 font-semibold">Date & Time</th>
                            <th class="px-4 py-2.5 font-semibold">Transaction Type</th>
                            <th class="px-4 py-2.5 font-semibold">Reference ID</th>
                            <th class="px-4 py-2.5 font-semibold">Customer</th>
                            <th class="px-4 py-2.5 font-semibold">Amount</th>
                            <th class="px-4 py-2.5 font-semibold">Payment Method</th>
                            <th class="px-4 py-2.5 font-semibold">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-if="pagedRecords.length === 0">
                            <td colspan="8" class="px-4 py-8 text-center text-sm text-ink-muted dark:text-slate-400">No revenue records for this boat in the selected period.</td>
                        </tr>
                        <tr v-for="(row, index) in pagedRecords" :key="row.id" class="border-t border-[#eef4f9] dark:border-white/10">
                            <td class="px-4 py-2.5 text-ink-muted dark:text-slate-400">{{ recordFrom + index }}</td>
                            <td class="px-4 py-2.5 whitespace-pre-line text-ink dark:text-sky-50">{{ row.dateTimeLabel }}</td>
                            <td class="px-4 py-2.5 text-ink dark:text-sky-50">{{ row.typeLabel }}</td>
                            <td class="px-4 py-2.5 font-medium text-[#1b7ad8] dark:text-sky-300">{{ row.reference }}</td>
                            <td class="px-4 py-2.5 text-ink dark:text-sky-50">{{ row.customer }}</td>
                            <td class="px-4 py-2.5 font-medium text-[#0f4c8a] dark:text-sky-100">{{ row.amountLabel }}</td>
                            <td class="px-4 py-2.5 text-ink dark:text-sky-50">{{ row.method }}</td>
                            <td class="px-4 py-2.5">
                                <span class="inline-flex rounded-full px-2.5 py-0.5 text-[11px] font-semibold" :class="statusTone(row.status)">
                                    {{ row.statusLabel }}
                                </span>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <div class="flex items-center justify-between border-t border-[#eef4f9] dark:border-white/10 px-5 py-3">
                <p class="flex items-center gap-2 text-xs text-ink-muted dark:text-slate-400">
                    <Ship class="size-3.5" />
                    Showing {{ recordFrom }}–{{ recordTo }} of {{ visibleRecords.length }} records
                </p>
                <div class="flex gap-1">
                    <button type="button" class="flex size-8 items-center justify-center rounded-lg text-ink-soft disabled:opacity-40" :disabled="recordPage <= 1" @click="recordPage -= 1">
                        <ChevronLeft class="size-4" />
                    </button>
                    <span class="flex size-8 items-center justify-center rounded-lg bg-[#1b7ad8] text-sm font-semibold text-white">{{ recordPage }}</span>
                    <button type="button" class="flex size-8 items-center justify-center rounded-lg text-ink-soft disabled:opacity-40" :disabled="recordPage >= recordTotalPages" @click="recordPage += 1">
                        <ChevronRight class="size-4" />
                    </button>
                </div>
            </div>
        </section>
    </div>
</template>
