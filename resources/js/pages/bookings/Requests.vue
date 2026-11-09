
<script setup lang="ts">
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import {
    AlertTriangle,
    Ban,
    CalendarDays,
    CheckCircle2,
    CircleCheck,
    Clock,
    EllipsisVertical,
    Eye,
    History,
    Hourglass,
    MapPin,
    Printer,
    Receipt,
    Search,
    Ship,
    Ticket,
    Users,
    Wallet,
    XCircle,
} from '@lucide/vue';
import { computed, ref, watch } from 'vue';

const page = usePage();
const unreadRentalCount = computed(() => Number(page.props.pendingBookingRequests ?? 0));
import StatCards from '@/components/admin/StatCards.vue';
import type { StatCardItem } from '@/components/admin/StatCards.vue';

import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';

import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';

import type {
    BoatBookingRecord,
    BoatBookingStatus,
} from '@/types/booking';
import type { AdminNotice, AdminNoticeFeed } from '@/types/notification';

type StatusFilter =
    | 'all'
    | 'pending'
    | 'approved'
    | 'completed'
    | 'cancelled'
    | 'no_show';

type ListStatus =
    | 'all'
    | 'passenger_no_show'
    | 'operator_no_show'
    | 'cancelled'
    | 'refunded'
    | 'declined';

type ActionDialog =
    | 'payment'
    | 'history'
    | 'cancel'
    | 'reschedule'
    | null;

const props = defineProps<{
    bookings: BoatBookingRecord[];
    newBookingIds?: number[];
    stats: {
        total: number;
        pending: number;
        approved: number;
        completed: number;
        cancelled: number;
    };
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Rental Request',
                href: '/bookings/requests',
            },
        ],
    },
});

// Existing filters
const filter = ref<StatusFilter>('all');
const listStatus = ref<ListStatus>('all');

watch(listStatus, (value) => {
    if (value !== 'all') {
        filter.value = 'all';
    }
});
const boatFilter = ref('all');
const search = ref('');
const dateFrom = ref('');
const dateTo = ref('');

// Existing dialogs
const activeBooking = ref<BoatBookingRecord | null>(null);
const dialog = ref<ActionDialog>(null);

// Existing forms
const cancelForm = useForm({
    action: 'cancel',
    cancellation_reason: '',
});

const rescheduleForm = useForm({
    action: 'approve',
    rejection_reason: '',
});

function openDialog(
    booking: BoatBookingRecord,
    type: Exclude<ActionDialog, null>,
) {
    activeBooking.value = booking;
    dialog.value = type;

    if (type === 'cancel') {
        cancelForm.action =
            booking.status === 'pending'
                ? 'reject'
                : 'cancel';

        cancelForm.cancellation_reason = '';
        cancelForm.clearErrors();
    }

    if (type === 'reschedule') {
        rescheduleForm.action = 'approve';
        rescheduleForm.rejection_reason = '';
        rescheduleForm.clearErrors();
    }
}

function closeDialog() {
    dialog.value = null;
    activeBooking.value = null;
}

function canCancel(booking: BoatBookingRecord) {
    return (
        booking.can_cancel ??
        [
            'pending',
            'awaiting_payment',
            'confirmed',
            'ongoing',
        ].includes(booking.status)
    );
}

function confirmReschedule(
    action: 'approve' | 'reject',
) {
    const booking = activeBooking.value;

    if (!booking) {
return;
}

    rescheduleForm.action = action;

    rescheduleForm.patch(
        `/boat-bookings/${booking.id}/reschedule`,
        {
            preserveScroll: true,
            onSuccess: () => closeDialog(),
        },
    );
}

function confirmCancel() {
    const booking = activeBooking.value;

    if (!booking) {
return;
}

    cancelForm.patch(
        `/boat-bookings/${booking.id}/review`,
        {
            preserveScroll: true,
            onSuccess: () => closeDialog(),
        },
    );
}

// Existing print functionality
function escapeHtml(value: string) {
    return value
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;');
}

function printDetails(booking: BoatBookingRecord) {
    const payment = booking.payment;

    const rows = [
        ['Rental ID', `#${booking.code}`],
        ['Passenger', booking.customer_name],
        ['Contact', booking.contact_number || '—'],
        [
            'Boat',
            `${booking.boat.name} (${booking.boat.code})`,
        ],
        [
            'Operator',
            booking.boat.operator_name || '—',
        ],
        ['Destination', booking.destination],
        ['Rental Date', booking.booking_date_label],
        ['Rental Time', booking.start_time_label],
        [
            'Passenger Count',
            String(booking.passenger_count),
        ],
        [
            'Rental Price',
            payment?.rental_price_label || '—',
        ],
        [
            'Required Down Payment',
            payment?.down_payment_percent_label || '—',
        ],
        [
            'Down Payment Amount',
            payment?.down_payment_amount_label || '—',
        ],
        [
            'Verified Down Payment',
            payment?.payment_status === 'verified'
                ? payment.down_payment_amount_label || '—'
                : '—',
        ],
        [
            'Remaining Balance',
            payment?.remaining_balance_label || '—',
        ],
        [
            'Balance Payment Method',
            payment?.balance_method_label || '—',
        ],
        [
            'Balance Payment Status',
            payment?.balance_status_label || 'Unpaid',
        ],
        ['Rental Status', booking.status_label],
        [
            'Payment Status',
            payment?.payment_status_label || 'Unpaid',
        ],
    ];

    const body = rows
        .map(
            ([label, value]) =>
                `<tr><th>${escapeHtml(label)}</th><td>${escapeHtml(value)}</td></tr>`,
        )
        .join('');

    const html = `<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<title>Rental ${escapeHtml(booking.code)}</title>
<style>
body {
    font-family: Arial, sans-serif;
    color: #163a5f;
    margin: 32px;
}
h1 {
    color: #0f4c8a;
    font-size: 22px;
    margin: 0 0 4px;
}
p {
    color: #7d93a8;
    margin: 0 0 24px;
    font-size: 13px;
}
table {
    width: 100%;
    border-collapse: collapse;
}
th, td {
    border-bottom: 1px solid #d7e8f6;
    padding: 10px 8px;
    text-align: left;
    font-size: 14px;
}
th {
    width: 38%;
    color: #5b7c99;
    font-weight: 600;
}
</style>
</head>
<body>
<h1>SafeSakay Rental Summary</h1>
<p>Printer-friendly rental details</p>
<table>${body}</table>
</body>
</html>`;

    const frame = window.open(
        '',
        '_blank',
        'width=760,height=900',
    );

    if (!frame) {
return;
}

    frame.document.write(html);
    frame.document.close();
    frame.focus();
    frame.print();
}

function isActiveNoShow(booking: BoatBookingRecord): boolean {
    const incident = booking.no_show_incident;

    if (!incident) {
        return false;
    }

    if (incident.active === true) {
        return true;
    }

    if (incident.active === false) {
        return false;
    }

    return Boolean(incident.needs_action);
}

function isResolvedNoShow(booking: BoatBookingRecord): boolean {
    return Boolean(booking.no_show_incident) && !isActiveNoShow(booking);
}

function isPassengerNoShow(booking: BoatBookingRecord): boolean {
    return booking.no_show_incident?.kind === 'passenger' || booking.status === 'passenger_no_show';
}

function isOperatorNoShow(booking: BoatBookingRecord): boolean {
    return booking.no_show_incident?.kind === 'operator';
}

function isDeclinedRental(booking: BoatBookingRecord): boolean {
    return booking.status === 'rejected';
}

function isRefundedRental(booking: BoatBookingRecord): boolean {
    return Boolean(booking.is_refunded) || booking.payment?.overall_status === 'refunded';
}

function isCancelledRental(booking: BoatBookingRecord): boolean {
    return booking.status === 'cancelled' || booking.status === 'rejected' || isResolvedNoShow(booking);
}

const noShowRows = computed(() => props.bookings.filter((booking) => isActiveNoShow(booking)));
const cancelledRows = computed(() => props.bookings.filter((booking) => isCancelledRental(booking)));

const listStatusLabels: Record<ListStatus, string> = {
    all: 'All Status',
    passenger_no_show: 'Passenger No-Show',
    operator_no_show: 'Operator No-Show',
    cancelled: 'Cancelled',
    refunded: 'Refunded',
    declined: 'Declined Rentals',
};

function matchesListStatus(booking: BoatBookingRecord): boolean {
    if (listStatus.value === 'passenger_no_show') {
        return isPassengerNoShow(booking);
    }

    if (listStatus.value === 'operator_no_show') {
        return isOperatorNoShow(booking);
    }

    if (listStatus.value === 'cancelled') {
        return booking.status === 'cancelled' || isResolvedNoShow(booking);
    }

    if (listStatus.value === 'refunded') {
        return isRefundedRental(booking);
    }

    if (listStatus.value === 'declined') {
        return isDeclinedRental(booking);
    }

    return true;
}

function selectCard(key: string) {
    filter.value = key as StatusFilter;
    listStatus.value = 'all';
}
function isNewBooking(booking: BoatBookingRecord): boolean {
    return (props.newBookingIds ?? []).includes(booking.id);
}

const unreadNotices = computed(() => {
    const shared = page.props.headerNotifications as AdminNoticeFeed | AdminNotice[] | undefined;
    const items = Array.isArray(shared) ? shared : (shared?.items ?? []);

    return items.filter((item) => item.unread);
});

function unreadStartingWith(...prefixes: string[]): number {
    return unreadNotices.value.filter((item) =>
        prefixes.some((prefix) => item.id.startsWith(prefix)),
    ).length;
}

function notifyBadge(count: number): number | undefined {
    return count > 0 ? count : undefined;
}

// Summary cards — same values and filter behavior
const cards = computed<StatCardItem[]>(() => [
    { key: 'all', title: 'Total Rentals', value: props.stats.total, hint: 'All rental records', icon: CalendarDays, tone: 'blue' },
    {
        key: 'pending',
        title: 'Pending Rentals',
        value: props.stats.pending,
        hint: 'Waiting for approval',
        icon: Hourglass,
        tone: 'amber',
        badge: notifyBadge(
            Math.max(
                props.stats.pending,
                unreadStartingWith('booking-', 'payment-', 'reschedule-'),
            ),
        ),
    },
    {
        key: 'approved',
        title: 'Approved Rentals',
        value: props.stats.approved,
        hint: 'Confirmed rentals',
        icon: CheckCircle2,
        tone: 'green',
        badge: notifyBadge(unreadStartingWith('upcoming-', 'readiness-')),
    },
    { key: 'completed', title: 'Completed Trips', value: props.stats.completed, hint: 'Finished rentals', icon: Ship, tone: 'violet' },
    { key: 'cancelled', title: 'Cancelled Rentals', value: cancelledRows.value.length, hint: 'Cancelled, declined, or resolved no-shows', icon: Ban, tone: 'red' },
    {
        key: 'no_show',
        title: 'No-Show Active Incidents',
        value: noShowRows.value.length,
        hint: noShowRows.value.length ? 'Open boat or passenger no-shows' : 'No active incidents',
        icon: AlertTriangle,
        tone: 'amber',
        badge: notifyBadge(
            Math.max(
                noShowRows.value.length,
                unreadStartingWith('operator-no-show-', 'passenger-no-show-'),
            ),
        ),
    },
]);

const boatOptions = computed(() => {
    const seen = new Map<number, string>();

    for (const booking of props.bookings) {
        if (!seen.has(booking.boat.id)) {
            seen.set(
                booking.boat.id,
                booking.boat.name,
            );
        }
    }

    return [...seen.entries()]
        .map(([id, name]) => ({ id, name }))
        .sort((a, b) =>
            a.name.localeCompare(b.name),
        );
});

// Existing filtering logic preserved
const filteredBookings = computed(() => {
    const query = search.value
        .trim()
        .toLowerCase();

    let rows = props.bookings;

    if (filter.value === 'pending') {
        rows = rows.filter(
            (booking) =>
                booking.status === 'pending' ||
                booking.status === 'reschedule_pending',
        );
    } else if (filter.value === 'approved') {
        rows = rows.filter(
            (booking) =>
                booking.status === 'awaiting_payment' ||
                booking.status === 'confirmed' ||
                booking.status === 'ongoing' ||
                booking.status === 'weather_hold',
        );
    } else if (filter.value === 'cancelled') {
        rows = rows.filter((booking) => isCancelledRental(booking));
    } else if (filter.value === 'no_show') {
        rows = rows.filter((booking) => isActiveNoShow(booking));
    } else if (filter.value !== 'all') {
        rows = rows.filter(
            (booking) =>
                booking.status === filter.value,
        );
    }

    rows = rows.filter((booking) => matchesListStatus(booking));

    if (boatFilter.value !== 'all') {
        rows = rows.filter(
            (booking) =>
                booking.boat.id ===
                Number(boatFilter.value),
        );
    }

    rows = rows.filter((booking) => {
        if (
            dateFrom.value &&
            booking.booking_date < dateFrom.value
        ) {
            return false;
        }

        if (
            dateTo.value &&
            booking.booking_date > dateTo.value
        ) {
            return false;
        }

        if (!query) {
return true;
}

        const haystack = [
            booking.code,
            booking.customer_name,
            booking.boat.name,
            booking.boat.code,
            booking.destination,
        ]
            .join(' ')
            .toLowerCase();

        return haystack.includes(query);
    });

    return [...rows].sort((left, right) => {
        const newest =
            (right.created_at_unix ?? 0) -
            (left.created_at_unix ?? 0);

        return newest !== 0
            ? newest
            : right.id - left.id;
    });
});

function userInitial(name: string) {
    return (
        name.trim().charAt(0) || '?'
    ).toUpperCase();
}

function statusIcon(
    status:
        | BoatBookingStatus
        | 'weather_reschedule_confirmed'
        | 'reschedule_confirmed',
) {
    if (status === 'weather_reschedule_confirmed' || status === 'reschedule_confirmed') {
        return CircleCheck;
    }

    if (
        status === 'completed' ||
        status === 'confirmed' ||
        status === 'awaiting_payment' ||
        status === 'ongoing'
    ) {
        return CircleCheck;
    }

    if (
        status === 'cancelled' ||
        status === 'rejected' ||
        status === 'passenger_no_show'
    ) {
        return XCircle;
    }

    return Clock;
}

function listStatusKey(
    booking: BoatBookingRecord,
) {
    return (
        booking.display_status ??
        booking.status
    );
}

function statusTone(
    status:
        | BoatBookingStatus
        | 'weather_reschedule_confirmed'
        | 'reschedule_confirmed',
) {
    const tones: Record<string, string> = {
        pending:
            'bg-amber-50 text-amber-700 dark:bg-amber-500/15 dark:text-amber-200',
        awaiting_payment:
            'bg-sky-50 text-sky-700 dark:bg-sky-500/15 dark:text-sky-200',
        confirmed:
            'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-200',
        reschedule_confirmed:
            'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-200',
        weather_reschedule_confirmed:
            'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-200',
        ongoing:
            'bg-violet-50 text-violet-700 dark:bg-violet-500/15 dark:text-violet-200',
        weather_hold:
            'bg-red-50 text-red-800 dark:bg-red-500/15 dark:text-red-100',
        reschedule_pending:
            'bg-violet-100 text-violet-800 dark:bg-violet-500/20 dark:text-violet-100',
        completed:
            'bg-sky-50 text-sky-700 dark:bg-sky-500/15 dark:text-sky-200',
        passenger_no_show:
            'bg-red-50 text-red-700 dark:bg-red-500/15 dark:text-red-200',
        cancelled:
            'bg-red-50 text-red-700 dark:bg-red-500/15 dark:text-red-200',
        rejected:
            'bg-red-50 text-red-700 dark:bg-red-500/15 dark:text-red-200',
    };

    return (
        tones[status] ??
        'bg-slate-100 text-slate-700 dark:bg-white/10 dark:text-slate-200'
    );
}
</script>

<template>
    <Head title="Rental Request" />

    <div
        class="flex min-w-0 flex-1 flex-col gap-5 px-4 py-5 sm:px-6 lg:px-7"
    >
        <!-- PAGE HEADER -->
        <header class="space-y-1">
            <h1
                class="flex items-center gap-2 text-2xl font-bold tracking-tight text-[#0F4C8A] dark:text-sky-100"
            >
                Rental Request
                <span
                    v-if="unreadRentalCount > 0"
                    class="inline-flex min-w-6 items-center justify-center rounded-full bg-red-500 px-2 text-xs font-bold text-white"
                >
                    {{ unreadRentalCount }}
                </span>
            </h1>

            <p
                class="text-sm text-ink-muted dark:text-slate-400"
            >
                Newest requests appear at the top so you can review them first.
            </p>
        </header>

        <!-- SUMMARY CARDS -->
        <StatCards
            :items="cards"
            :active="filter"
            selectable
            @select="selectCard"
        />

        <!-- FILTERS -->
        <div
            class="grid grid-cols-1 gap-3 rounded-2xl border border-[#D7E8F6] bg-white p-4 shadow-sm dark:border-white/10 dark:bg-white/5 md:grid-cols-2 2xl:grid-cols-[1.6fr_2fr_1fr_1fr]"
        >
            <!-- DATE RANGE -->
            <div
                class="flex min-w-0 items-center gap-2 rounded-lg border border-[#D7E8F6] bg-white px-3 dark:border-white/10 dark:bg-white/5"
            >
                <CalendarDays
                    class="size-4 shrink-0 text-ink-muted"
                />

                <input
                    v-model="dateFrom"
                    type="date"
                    aria-label="From rental date"
                    class="h-11 min-w-0 w-full flex-1 border-0 bg-transparent text-xs text-ink outline-none dark:text-sky-100"
                />

                <span
                    class="shrink-0 text-[#9BB0C2]"
                >
                    –
                </span>

                <input
                    v-model="dateTo"
                    type="date"
                    aria-label="To rental date"
                    class="h-11 min-w-0 w-full flex-1 border-0 bg-transparent text-xs text-ink outline-none dark:text-sky-100"
                />
            </div>

            <!-- SEARCH -->
            <label
                class="flex min-w-0 items-center gap-2 rounded-lg border border-[#D7E8F6] bg-white px-3 dark:border-white/10 dark:bg-white/5"
            >
                <Search
                    class="size-4 shrink-0 text-ink-muted"
                />

                <input
                    v-model="search"
                    type="search"
                    placeholder="Search rental ID, user, boat, or destination..."
                    class="h-11 min-w-0 w-full flex-1 border-0 bg-transparent text-sm text-ink outline-none placeholder:text-[#9BB0C2] dark:text-sky-100"
                />
            </label>

            <!-- STATUS -->
            <select
                v-model="listStatus"
                aria-label="Filter by status"
                class="h-[46px] min-w-0 w-full rounded-lg border border-[#D7E8F6] bg-white px-3 text-sm font-medium text-ink outline-none focus:border-[#1479E8] dark:border-white/10 dark:bg-[#163A5F] dark:text-sky-100"
            >
                <option value="all">All Status</option>
                <option value="passenger_no_show">Passenger No-Show</option>
                <option value="operator_no_show">Operator No-Show</option>
                <option value="cancelled">Cancelled</option>
                <option value="refunded">Refunded</option>
                <option value="declined">Declined Rentals</option>
            </select>

            <!-- BOAT -->
            <select
                v-model="boatFilter"
                aria-label="Filter by boat"
                class="h-[46px] min-w-0 w-full rounded-lg border border-[#D7E8F6] bg-white px-3 text-sm font-medium text-ink outline-none focus:border-[#1479E8] dark:border-white/10 dark:bg-[#163A5F] dark:text-sky-100"
            >
                <option value="all">
                    All Boats
                </option>

                <option
                    v-for="boat in boatOptions"
                    :key="boat.id"
                    :value="boat.id"
                >
                    {{ boat.name }}
                </option>
            </select>
        </div>

        <!-- RENTAL TABLE -->
        <section
            class="min-w-0 overflow-hidden rounded-2xl border border-[#D7E8F6] bg-white shadow-sm dark:border-white/10 dark:bg-white/5"
        >
            <!-- TABLE HEADING -->
            <div
                class="flex flex-wrap items-center justify-between gap-3 px-5 py-4"
            >
                <div>
                    <h2
                        class="text-lg font-bold text-[#0F2F57] dark:text-sky-100"
                    >
                        {{
                            listStatus === 'all'
                                ? cards.find((card) => card.key === filter)?.title
                                : listStatusLabels[listStatus]
                        }}
                    </h2>

                    <p
                        class="mt-1 text-xs text-ink-muted dark:text-slate-400"
                    >
                        {{ filteredBookings.length }}
                        {{
                            filteredBookings.length === 1
                                ? 'record'
                                : 'records'
                        }}
                        shown

                        <span v-if="stats.pending > 0">
                            · {{ stats.pending }} pending
                        </span>
                    </p>
                </div>
            </div>

            <!-- EMPTY STATE -->
            <div
                v-if="filteredBookings.length === 0"
                class="px-5 py-16 text-center"
            >
                <Ticket
                    class="mx-auto size-10 text-[#C5DEF5]"
                />

                <p
                    class="mt-3 font-medium text-[#0F4C8A] dark:text-sky-100"
                >
                    No rental requests in this list yet
                </p>
            </div>

            <!-- TABLE CONTENT -->
            <div
                v-else
                class="w-full overflow-x-auto"
            >
                <table
                    class="w-full min-w-[1250px] border-collapse text-left text-sm"
                >
                    <thead
                        class="border-y border-[#D7E8F6] bg-[#EEF6FF] text-[11px] font-semibold tracking-wide text-ink-soft uppercase dark:border-white/10 dark:bg-white/10"
                    >
                        <tr>
                            <th
                                class="px-4 py-3 font-semibold"
                            >
                                Rental ID
                            </th>

                            <th
                                class="px-4 py-3 font-semibold"
                            >
                                User
                            </th>

                            <th
                                class="px-4 py-3 font-semibold"
                            >
                                Boat
                            </th>

                            <th
                                class="px-4 py-3 font-semibold"
                            >
                                Destination
                            </th>

                            <th
                                class="px-4 py-3 font-semibold"
                            >
                                Booked On
                            </th>

                            <th
                                class="px-4 py-3 font-semibold"
                            >
                                Rental Date & Time
                            </th>

                            <th
                                class="px-4 py-3 font-semibold"
                            >
                                Passenger
                            </th>

                            <th
                                class="px-4 py-3 font-semibold"
                            >
                                Status
                            </th>

                            <th
                                class="px-4 py-3 text-right font-semibold"
                            >
                                Actions
                            </th>
                        </tr>
                    </thead>

                    <tbody>
                        <tr
                            v-for="booking in filteredBookings"
                            :key="booking.id"
                            class="border-b border-[#EAF1F7] transition-colors hover:bg-[#F7FBFF] dark:border-white/10 dark:hover:bg-white/5"
                            :class="
                                isNewBooking(booking)
                                    ? 'bg-[#E8F4FF] shadow-[inset_3px_0_0_#1479E8] dark:bg-sky-500/15'
                                    : ''
                            "
                        >
                            <!-- RENTAL ID -->
                            <td
                                class="px-4 py-4 align-middle"
                            >
                                <span class="inline-flex items-center gap-2">
                                    <span
                                        class="font-semibold text-[#1479E8]"
                                    >
                                        #{{ booking.code }}
                                    </span>
                                    <span
                                        v-if="isNewBooking(booking)"
                                        class="rounded-full bg-[#1479E8] px-2 py-0.5 text-[10px] font-bold tracking-wide text-white uppercase"
                                    >
                                        New
                                    </span>
                                </span>
                            </td>

                            <!-- USER -->
                            <td
                                class="px-4 py-4 align-middle"
                            >
                                <div
                                    class="flex items-center gap-2.5"
                                >
                                    <span
                                        class="flex size-9 shrink-0 items-center justify-center rounded-full bg-[#E8F1FD] text-xs font-bold text-[#1479E8]"
                                    >
                                        {{
                                            userInitial(
                                                booking.customer_name,
                                            )
                                        }}
                                    </span>

                                    <span
                                        class="font-medium text-ink dark:text-slate-200"
                                    >
                                        {{
                                            booking.customer_name
                                        }}
                                    </span>
                                </div>
                            </td>

                            <!-- BOAT -->
                            <td
                                class="px-4 py-4 align-middle"
                            >
                                <div
                                    class="flex items-center gap-2.5"
                                >
                                    <img
                                        v-if="booking.boat.picture_url"
                                        :src="booking.boat.picture_url"
                                        :alt="booking.boat.name"
                                        class="size-10 shrink-0 rounded-lg object-cover"
                                    />

                                    <span
                                        v-else
                                        class="flex size-10 shrink-0 items-center justify-center rounded-lg bg-[#E8F1FD] text-[#1479E8]"
                                    >
                                        <Ship
                                            class="size-4"
                                        />
                                    </span>

                                    <div
                                        class="min-w-0"
                                    >
                                        <p
                                            class="font-semibold text-[#0F2F57] uppercase dark:text-sky-100"
                                        >
                                            {{
                                                booking.boat.name
                                            }}
                                        </p>

                                        <p
                                            class="mt-0.5 text-xs text-ink-muted"
                                        >
                                            {{
                                                booking.boat.capacity
                                            }}
                                            pax
                                        </p>
                                    </div>
                                </div>
                            </td>

                            <!-- DESTINATION -->
                            <td
                                class="px-4 py-4 align-middle"
                            >
                                <div
                                    class="flex items-center gap-1.5 text-ink dark:text-slate-200"
                                >
                                    <MapPin
                                        class="size-4 shrink-0 text-[#1479E8]"
                                    />

                                    <span>
                                        {{
                                            booking.destination
                                        }}
                                    </span>
                                </div>
                            </td>

                            <!-- BOOKED ON -->
                            <td
                                class="px-4 py-4 align-middle"
                            >
                                <div
                                    class="space-y-1"
                                >
                                    <p
                                        class="flex items-center gap-1.5 font-medium text-ink dark:text-slate-200"
                                    >
                                        <CalendarDays
                                            class="size-3.5 shrink-0 text-ink-muted"
                                        />

                                        {{
                                            booking.created_at_label ||
                                            booking.booking_date_label
                                        }}
                                    </p>

                                    <p
                                        class="flex items-center gap-1.5 text-xs text-ink-muted"
                                    >
                                        <Clock
                                            class="size-3.5"
                                        />

                                        {{
                                            booking.created_at_time_label ||
                                            booking.start_time_label
                                        }}
                                    </p>
                                </div>
                            </td>

                            <!-- RENTAL DATE -->
                            <td
                                class="px-4 py-4 align-middle"
                            >
                                <div
                                    class="space-y-1"
                                >
                                    <p
                                        class="flex items-center gap-1.5 font-medium text-ink dark:text-slate-200"
                                    >
                                        <CalendarDays
                                            class="size-3.5 shrink-0 text-ink-muted"
                                        />

                                        {{
                                            booking.booking_date_label
                                        }}
                                    </p>

                                    <p
                                        class="flex items-center gap-1.5 text-xs text-ink-muted"
                                    >
                                        <Clock
                                            class="size-3.5"
                                        />

                                        {{
                                            booking.start_time_label
                                        }}
                                    </p>
                                </div>
                            </td>

                            <!-- PASSENGERS -->
                            <td
                                class="px-4 py-4 align-middle"
                            >
                                <span
                                    class="inline-flex items-center gap-1.5 text-ink dark:text-slate-200"
                                >
                                    <Users
                                        class="size-4 text-ink-muted"
                                    />

                                    {{
                                        booking.passenger_count
                                    }}
                                </span>
                            </td>

                            <!-- STATUS -->
                            <td
                                class="px-4 py-4 align-middle"
                            >
                                <span
                                    class="inline-flex items-center gap-1.5 whitespace-nowrap rounded-full px-3 py-1.5 text-xs font-semibold"
                                    :class="
                                        statusTone(
                                            listStatusKey(
                                                booking,
                                            ),
                                        )
                                    "
                                >
                                    <component
                                        :is="
                                            statusIcon(
                                                listStatusKey(
                                                    booking,
                                                ),
                                            )
                                        "
                                        class="size-3.5"
                                    />

                                    {{
                                        booking.status_label
                                    }}
                                </span>
                                <span
                                    v-if="booking.no_show_incident"
                                    class="mt-1.5 flex w-fit items-center gap-1 rounded-full px-2 py-0.5 text-[11px] font-semibold"
                                    :class="booking.no_show_incident.needs_action ? 'bg-amber-100 text-amber-800 dark:bg-amber-500/15 dark:text-amber-200' : 'bg-slate-100 text-slate-700 dark:bg-white/10 dark:text-slate-300'"
                                    :title="`${booking.no_show_incident.label} · ${booking.no_show_incident.status_label}`"
                                >
                                    <AlertTriangle class="size-3" />
                                    {{ booking.no_show_incident.kind === 'passenger' ? 'Passenger no-show' : 'Boat no-show' }}
                                    · {{ booking.no_show_incident.status_label }}
                                </span>
                            </td>

                            <!-- ACTIONS -->
                            <td
                                class="px-4 py-4 align-middle"
                            >
                                <div
                                    class="flex items-center justify-end gap-2"
                                >
                                    <Link
                                        :href="`/bookings/requests/${booking.id}`"
                                        class="inline-flex h-9 items-center gap-1.5 rounded-lg border border-[#C5DEF5] bg-white px-3 text-xs font-semibold text-[#1479E8] transition hover:bg-[#E8F4FC] dark:border-white/10 dark:bg-white/5"
                                    >
                                        <Eye
                                            class="size-4"
                                        />
                                        View
                                    </Link>

                                    <DropdownMenu>
                                        <DropdownMenuTrigger
                                            as-child
                                        >
                                            <button
                                                type="button"
                                                aria-label="More actions"
                                                class="inline-flex size-9 items-center justify-center rounded-lg border border-[#C5DEF5] bg-white text-[#1479E8] transition hover:bg-[#E8F4FC] dark:border-white/10 dark:bg-white/5"
                                            >
                                                <EllipsisVertical
                                                    class="size-4"
                                                />
                                            </button>
                                        </DropdownMenuTrigger>

                                        <DropdownMenuContent
                                            align="end"
                                            side="bottom"
                                            :collision-padding="16"
                                            class="z-[80] w-48 rounded-xl border-[#D7E8F6] bg-white p-1 shadow-lg dark:bg-[#102036] dark:text-slate-100 dark:border-white/10"
                                        >
                                            <DropdownMenuItem
                                                class="gap-2 rounded-lg text-ink"
                                                @click="
                                                    openDialog(
                                                        booking,
                                                        'payment',
                                                    )
                                                "
                                            >
                                                <Wallet
                                                    class="size-4 text-[#1479E8]"
                                                />
                                                View Payment
                                            </DropdownMenuItem>

                                            <DropdownMenuItem
                                                v-if="
                                                    booking.can_review_reschedule
                                                "
                                                class="gap-2 rounded-lg text-ink"
                                                @click="
                                                    openDialog(
                                                        booking,
                                                        'reschedule',
                                                    )
                                                "
                                            >
                                                <CalendarDays
                                                    class="size-4 text-[#1479E8]"
                                                />
                                                Review Reschedule
                                            </DropdownMenuItem>

                                            <DropdownMenuItem
                                                class="gap-2 rounded-lg text-ink"
                                                @click="
                                                    openDialog(
                                                        booking,
                                                        'history',
                                                    )
                                                "
                                            >
                                                <History
                                                    class="size-4 text-[#1479E8]"
                                                />
                                                Activity History
                                            </DropdownMenuItem>

                                            <DropdownMenuItem
                                                class="gap-2 rounded-lg text-ink"
                                                @click="
                                                    printDetails(
                                                        booking,
                                                    )
                                                "
                                            >
                                                <Printer
                                                    class="size-4 text-[#1479E8]"
                                                />
                                                Print Details
                                            </DropdownMenuItem>

                                            <DropdownMenuSeparator
                                                class="bg-[#E8F1F8] dark:bg-white/10"
                                            />

                                            <DropdownMenuItem
                                                variant="destructive"
                                                class="gap-2 rounded-lg"
                                                :disabled="
                                                    !canCancel(
                                                        booking,
                                                    )
                                                "
                                                @click="
                                                    openDialog(
                                                        booking,
                                                        'cancel',
                                                    )
                                                "
                                            >
                                                <Ban
                                                    class="size-4"
                                                />
                                                Cancel Rental
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
    </div>

    <!-- RESCHEDULE DIALOG -->
    <Dialog
        :open="dialog === 'reschedule'"
        @update:open="(open) => !open && closeDialog()"
    >
        <DialogContent
            class="max-w-lg rounded-2xl border-[#D7E8F6] p-0"
        >
            <DialogHeader
                class="border-b border-[#E8F1F8] px-5 py-4"
            >
                <DialogTitle
                    class="text-[#0F4C8A]"
                >
                    Review reschedule
                </DialogTitle>

                <DialogDescription>
                    {{
                        activeBooking
                            ? `#${activeBooking.code}`
                            : ''
                    }}
                </DialogDescription>
            </DialogHeader>

            <div
                v-if="activeBooking"
                class="space-y-4 px-5 py-4 text-sm text-ink"
            >
                <div
                    class="rounded-xl border border-red-200 border-l-4 border-l-red-500 bg-red-50 p-3 text-red-900"
                >
                    <p class="font-bold">
                        Passenger requested a new date
                    </p>

                    <p
                        class="mt-1 text-xs leading-relaxed text-red-800"
                    >
                        Down payment stays with this rental.
                        Approve only if the boat is available
                        on the requested date.
                    </p>
                </div>

                <div
                    class="grid gap-3 sm:grid-cols-2"
                >
                    <div
                        class="rounded-xl border border-[#D7E8F6] bg-[#F8FBFE] p-3 dark:border-white/10 dark:bg-[#0b1b30] dark:text-slate-100"
                    >
                        <p
                            class="text-[11px] font-semibold uppercase text-ink-muted"
                        >
                            Original date
                        </p>

                        <p
                            class="mt-1 font-bold text-[#0F4C8A]"
                        >
                            {{
                                activeBooking.original_booking_date_label
                            }}
                        </p>
                    </div>

                    <div
                        class="rounded-xl border border-red-200 bg-red-50/50 p-3"
                    >
                        <p
                            class="text-[11px] font-semibold uppercase text-red-700"
                        >
                            Requested new date
                        </p>

                        <p
                            class="mt-1 font-bold text-[#0F4C8A]"
                        >
                            {{
                                activeBooking.requested_booking_date_label
                            }}
                        </p>
                    </div>
                </div>

                <p>
                    Down payment:
                    {{
                        activeBooking.payment
                            ?.down_payment_amount_label ||
                        '—'
                    }}
                    ·
                    {{
                        activeBooking.payment
                            ?.payment_status_label
                    }}
                </p>

                <label
                    class="block text-sm font-medium text-[#0F4C8A]"
                >
                    Rejection reason (required if you decline)

                    <textarea
                        v-model="
                            rescheduleForm.rejection_reason
                        "
                        rows="3"
                        class="mt-2 w-full rounded-xl border border-[#C5DEF5] px-3 py-2 text-sm"
                    />
                </label>

                <DialogFooter
                    class="gap-2 border-0 p-0 sm:justify-end"
                >
                    <button
                        type="button"
                        class="h-9 rounded-lg border px-3 text-sm"
                        @click="closeDialog"
                    >
                        Close
                    </button>

                    <button
                        type="button"
                        class="h-9 rounded-lg border border-red-300 px-3 text-sm font-semibold text-red-700"
                        :disabled="
                            rescheduleForm.processing
                        "
                        @click="
                            confirmReschedule('reject')
                        "
                    >
                        Decline date
                    </button>

                    <button
                        type="button"
                        class="h-9 rounded-lg bg-emerald-600 px-3 text-sm font-semibold text-white"
                        :disabled="
                            rescheduleForm.processing
                        "
                        @click="
                            confirmReschedule('approve')
                        "
                    >
                        Approve date
                    </button>
                </DialogFooter>
            </div>
        </DialogContent>
    </Dialog>

    <!-- PAYMENT DIALOG -->
    <Dialog
        :open="dialog === 'payment'"
        @update:open="(open) => !open && closeDialog()"
    >
        <DialogContent
            class="max-w-md rounded-2xl border-[#D7E8F6] p-0 sm:max-w-md"
        >
            <DialogHeader
                class="border-b border-[#E8F1F8] px-5 py-4"
            >
                <DialogTitle
                    class="flex items-center gap-2 text-[#0F4C8A]"
                >
                    <Wallet
                        class="size-4 text-[#1479E8]"
                    />
                    Payment Details
                </DialogTitle>

                <DialogDescription>
                    {{
                        activeBooking
                            ? `#${activeBooking.code}`
                            : ''
                    }}
                </DialogDescription>
            </DialogHeader>

            <div
                v-if="activeBooking"
                class="space-y-3 px-5 py-4 text-sm"
            >
                <template
                    v-if="
                        !activeBooking.payment?.submitted
                    "
                >
                    <p
                        class="rounded-xl bg-[#E8F4FC] px-4 py-3 text-ink dark:bg-[#0b1b30] dark:text-slate-100"
                    >
                        No payment submitted yet.
                    </p>

                    <dl
                        class="grid grid-cols-2 gap-x-3 gap-y-3 text-ink"
                    >
                        <dt class="text-ink-muted">
                            Total Rental Price
                        </dt>
                        <dd class="text-right font-semibold">
                            {{
                                activeBooking.payment
                                    ?.rental_price_label ||
                                '—'
                            }}
                        </dd>

                        <dt class="text-ink-muted">
                            Required Down Payment
                        </dt>
                        <dd class="text-right font-semibold">
                            {{
                                activeBooking.payment
                                    ?.down_payment_percent_label ||
                                '—'
                            }}
                        </dd>

                        <dt class="text-ink-muted">
                            Down Payment Amount
                        </dt>
                        <dd class="text-right font-semibold">
                            {{
                                activeBooking.payment
                                    ?.down_payment_amount_label ||
                                '—'
                            }}
                        </dd>

                        <dt class="text-ink-muted">
                            Remaining Balance
                        </dt>
                        <dd class="text-right font-semibold">
                            {{
                                activeBooking.payment
                                    ?.remaining_balance_label ||
                                '—'
                            }}
                        </dd>

                        <dt class="text-ink-muted">
                            Payment Status
                        </dt>
                        <dd class="text-right font-semibold">
                            {{
                                activeBooking.payment
                                    ?.payment_status_label ||
                                'Unpaid'
                            }}
                        </dd>
                    </dl>
                </template>

                <template v-else>
                    <dl
                        class="grid grid-cols-2 gap-x-3 gap-y-3 text-ink"
                    >
                        <dt class="text-ink-muted">
                            Total Rental Price
                        </dt>
                        <dd class="text-right font-semibold">
                            {{
                                activeBooking.payment
                                    ?.rental_price_label ||
                                '—'
                            }}
                        </dd>

                        <dt class="text-ink-muted">
                            Required Down Payment
                        </dt>
                        <dd class="text-right font-semibold">
                            {{
                                activeBooking.payment
                                    ?.down_payment_percent_label ||
                                '—'
                            }}
                        </dd>

                        <dt class="text-ink-muted">
                            Down Payment Amount
                        </dt>
                        <dd class="text-right font-semibold">
                            {{
                                activeBooking.payment
                                    ?.down_payment_amount_label ||
                                '—'
                            }}
                        </dd>

                        <dt class="text-ink-muted">
                            Remaining Balance
                        </dt>
                        <dd class="text-right font-semibold">
                            {{
                                activeBooking.payment
                                    ?.remaining_balance_label ||
                                '—'
                            }}
                        </dd>

                        <dt class="text-ink-muted">
                            Verified Down Payment
                        </dt>
                        <dd class="text-right font-semibold">
                            {{
                                activeBooking.payment
                                    ?.payment_status ===
                                'verified'
                                    ? activeBooking.payment
                                          ?.down_payment_amount_label
                                    : '—'
                            }}
                        </dd>

                        <dt class="text-ink-muted">
                            Payment Status
                        </dt>
                        <dd class="text-right font-semibold">
                            {{
                                activeBooking.payment
                                    ?.payment_status_label
                            }}
                        </dd>

                        <dt class="text-ink-muted">
                            Balance Payment Status
                        </dt>
                        <dd class="text-right font-semibold">
                            {{
                                activeBooking.payment
                                    ?.balance_status_label ||
                                'Unpaid'
                            }}
                        </dd>

                        <dt class="text-ink-muted">
                            Balance Payment Method
                        </dt>
                        <dd class="text-right font-semibold">
                            {{
                                activeBooking.payment
                                    ?.balance_method_label ||
                                '—'
                            }}
                        </dd>

                        <dt class="text-ink-muted">
                            Assigned Boat Operator
                        </dt>
                        <dd class="text-right font-semibold">
                            {{
                                activeBooking.boat
                                    .operator_name || '—'
                            }}
                        </dd>

                        <dt class="text-ink-muted">
                            GCash Reference Number
                        </dt>
                        <dd class="text-right font-semibold">
                            {{
                                activeBooking.payment
                                    ?.gcash_reference ||
                                '—'
                            }}
                        </dd>
                    </dl>

                    <p
                        class="text-xs leading-relaxed text-ink-muted"
                    >
                        Read-only monitoring. Verify barangay
                        GCash down payments on the rental
                        detail page. Operators record
                        remaining balance in the app.
                    </p>

                    <div
                        v-if="
                            activeBooking.payment?.receipt_url
                        "
                        class="pt-1"
                    >
                        <p
                            class="mb-2 flex items-center gap-1.5 text-xs font-medium text-ink-muted"
                        >
                            <Receipt
                                class="size-3.5 text-[#1479E8]"
                            />
                            Uploaded Payment Receipt
                        </p>

                        <a
                            :href="
                                activeBooking.payment
                                    .receipt_url
                            "
                            target="_blank"
                            rel="noreferrer"
                            class="block overflow-hidden rounded-xl border border-[#D7E8F6]"
                        >
                            <img
                                :src="
                                    activeBooking.payment
                                        .receipt_url
                                "
                                alt="Payment receipt"
                                class="max-h-56 w-full bg-[#F4F9FD] object-contain dark:bg-[#0b1b30]"
                            />
                        </a>
                    </div>
                </template>
            </div>
        </DialogContent>
    </Dialog>

    <!-- ACTIVITY HISTORY DIALOG -->
    <Dialog
        :open="dialog === 'history'"
        @update:open="(open) => !open && closeDialog()"
    >
        <DialogContent
            class="max-w-md rounded-2xl border-[#D7E8F6] p-0 sm:max-w-md"
        >
            <DialogHeader
                class="border-b border-[#E8F1F8] px-5 py-4"
            >
                <DialogTitle
                    class="flex items-center gap-2 text-[#0F4C8A]"
                >
                    <History
                        class="size-4 text-[#1479E8]"
                    />
                    Activity History
                </DialogTitle>

                <DialogDescription>
                    {{
                        activeBooking
                            ? `#${activeBooking.code}`
                            : ''
                    }}
                </DialogDescription>
            </DialogHeader>

            <ol
                v-if="activeBooking"
                class="max-h-[24rem] space-y-4 overflow-y-auto px-5 py-4"
            >
                <li
                    v-for="item in activeBooking.activities"
                    :key="item.id"
                    class="relative border-l-2 border-[#C5DEF5] pl-4"
                >
                    <span
                        class="absolute -left-1.5 top-1.5 size-3 rounded-full bg-[#1479E8]"
                    />

                    <p
                        class="text-sm font-semibold text-[#0F4C8A]"
                    >
                        {{ item.title }}
                    </p>

                    <p
                        class="mt-1 text-xs text-ink-muted"
                    >
                        {{
                            item.occurred_at_label ||
                            '—'
                        }}
                    </p>

                    <p
                        v-if="item.actor_name"
                        class="mt-1 text-xs text-ink"
                    >
                        {{ item.actor_name }}
                    </p>
                </li>
            </ol>
        </DialogContent>
    </Dialog>

    <!-- CANCEL RENTAL DIALOG -->
    <Dialog
        :open="dialog === 'cancel'"
        @update:open="(open) => !open && closeDialog()"
    >
        <DialogContent
            class="max-w-md rounded-2xl border-[#D7E8F6] p-0 sm:max-w-md"
        >
            <DialogHeader
                class="border-b border-[#E8F1F8] px-5 py-4"
            >
                <DialogTitle
                    class="flex items-center gap-2 text-[#0F4C8A]"
                >
                    <Ban
                        class="size-4 text-[#D45B5B]"
                    />
                    Cancel Rental
                </DialogTitle>

                <DialogDescription>
                    Cancel rental
                    {{
                        activeBooking
                            ? `#${activeBooking.code}`
                            : ''
                    }}.
                    This cannot be undone.
                </DialogDescription>
            </DialogHeader>

            <form
                class="space-y-4 px-5 py-4"
                @submit.prevent="confirmCancel"
            >
                <p
                    class="text-sm text-ink"
                >
                    Rental ID:

                    <span
                        class="font-semibold text-[#1479E8]"
                    >
                        #{{ activeBooking?.code }}
                    </span>
                </p>

                <label
                    class="block text-sm font-medium text-[#0F4C8A]"
                >
                    Cancellation reason

                    <textarea
                        v-model="
                            cancelForm.cancellation_reason
                        "
                        required
                        minlength="5"
                        rows="4"
                        placeholder="Enter the reason for cancelling this rental"
                        class="mt-2 w-full rounded-xl border border-[#C5DEF5] px-3 py-2 text-sm text-ink outline-none focus:border-[#1479E8]"
                    />
                </label>

                <p
                    v-if="
                        cancelForm.errors
                            .cancellation_reason
                    "
                    class="text-xs text-[#D45B5B]"
                >
                    {{
                        cancelForm.errors
                            .cancellation_reason
                    }}
                </p>

                <DialogFooter
                    class="gap-2 border-0 p-0"
                >
                    <button
                        type="button"
                        class="inline-flex h-9 items-center rounded-lg border border-[#C5DEF5] px-3 text-sm font-medium text-ink"
                        @click="closeDialog"
                    >
                        Close
                    </button>

                    <button
                        type="submit"
                        class="inline-flex h-9 items-center rounded-lg bg-[#D45B5B] px-3 text-sm font-semibold text-white disabled:opacity-60"
                        :disabled="
                            cancelForm.processing
                        "
                    >
                        Confirm Cancel
                    </button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
