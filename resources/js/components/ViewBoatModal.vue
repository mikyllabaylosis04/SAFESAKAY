
<script setup lang="ts">
import {
    Archive,
    BarChart3,
    Check,
    ClipboardList,
    History,
    LifeBuoy,
    Maximize2,
    Minimize2,
    Pencil,
    RefreshCw,
    Ship,
    UserRound,
    X,
} from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import BoatPhotoGallery from '@/components/BoatPhotoGallery.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { cn } from '@/lib/utils';
import type { BoatRecord, BoatStatus } from '@/types/boat';

const props = defineProps<{
    open: boolean;
    boat: BoatRecord | null;
}>();

const emit = defineEmits<{
    'update:open': [value: boolean];
    edit: [boat: BoatRecord];
    status: [payload: { boat: BoatRecord; status: BoatStatus }];
    archive: [boat: BoatRecord];
    restore: [boat: BoatRecord];
}>();

type Tab = 'overview' | 'usage' | 'activity';

const activeTab = ref<Tab>('overview');
const maximized = ref(false);
const statusPickerOpen = ref(false);

watch(
    () => props.open,
    (open) => {
        if (!open) {
            maximized.value = false;
            statusPickerOpen.value = false;
            activeTab.value = 'overview';
        }
    },
);

watch(
    () => props.boat?.id,
    () => {
        activeTab.value = 'overview';
        statusPickerOpen.value = false;
    },
);

const tabs = [
    { id: 'overview', label: 'Overview', icon: ClipboardList },
    { id: 'usage', label: 'Usage Summary', icon: BarChart3 },
    { id: 'activity', label: 'Recent Activity', icon: History },
] as const;

const currentStatusTone = computed(() => {
    const status = props.boat?.status;

    return {
        available: 'bg-emerald-100 text-emerald-700',
        on_duty: 'bg-sky-100 text-sky-700',
        on_trip: 'bg-violet-100 text-violet-700',
        maintenance: 'bg-amber-100 text-amber-700',
        unavailable: 'bg-slate-100 text-slate-600',
    }[status ?? 'unavailable'];
});

const safetyChecklist = computed(
    () => props.boat?.safety_checklist ?? [],
);

const safetyReady = computed(
    () => safetyChecklist.value.filter((item) => item.checked).length,
);

const usage = computed(
    () =>
        props.boat?.usage ?? {
            regular_trips: 0,
            exclusive_rentals: 0,
            total_trips: 0,
            last_trip_label: null,
        },
);

const recentActivity = computed(
    () => props.boat?.recent_activity ?? [],
);

const isArchived = computed(
    () => props.boat?.is_active === false,
);

const manualStatuses: Array<{
    value: BoatStatus;
    label: string;
    hint: string;
}> = [
    {
        value: 'available',
        label: 'Available',
        hint: 'Ready for assignment',
    },
    {
        value: 'maintenance',
        label: 'Maintenance',
        hint: 'Temporarily out of service',
    },
    {
        value: 'unavailable',
        label: 'Unavailable',
        hint: 'Not in service',
    },
];

function close() {
    emit('update:open', false);
}

function toggleMaximize() {
    maximized.value = !maximized.value;
}

function canSetStatus(boat: BoatRecord, status: BoatStatus) {
    if (status === boat.status) return false;

    if (status === 'available') {
        return (
            boat.status === 'maintenance' ||
            boat.status === 'unavailable'
        );
    }

    return status === 'maintenance' || status === 'unavailable';
}

function applyStatus(value: string | number | null | undefined) {
    if (!props.boat || value == null) return;

    const status = String(value) as BoatStatus;

    if (!canSetStatus(props.boat, status)) return;

    emit('status', { boat: props.boat, status });
    statusPickerOpen.value = false;
}
</script>

<template>
    <Dialog
        :open="open"
        @update:open="emit('update:open', $event)"
    >
        <DialogContent
            v-if="boat"
            :show-close-button="false"
            :class="cn(
                'flex max-h-[94vh] w-[calc(100%-1rem)] max-w-none flex-col gap-0 overflow-hidden rounded-2xl border-0 bg-[#f8fbff] p-0 shadow-2xl sm:w-[calc(100%-2rem)] dark:bg-[#0b1b30] dark:text-slate-100',
                maximized
                    ? 'h-[94vh] sm:max-w-[calc(100%-2rem)]'
                    : 'sm:max-w-6xl',
            )"
        >
            <!-- HEADER -->
            <header
                class="flex shrink-0 items-center justify-between gap-4 bg-gradient-to-r from-[#0756b5] to-[#2089e8] px-5 py-4 text-white sm:px-6"
            >
                <div class="flex min-w-0 items-center gap-3">
                    <div
                        class="flex size-11 shrink-0 items-center justify-center rounded-xl bg-white/15"
                    >
                        <Ship class="size-6" />
                    </div>

                    <div class="min-w-0">
                        <DialogTitle
                            class="text-lg font-bold text-white sm:text-xl"
                        >
                            Boat Details
                        </DialogTitle>
                        <DialogDescription
                            class="text-xs text-white/80 sm:text-sm"
                        >
                            View boat information and operational records
                        </DialogDescription>
                    </div>
                </div>

                <div class="flex shrink-0 items-center gap-1">
                    <button
                        type="button"
                        class="rounded-lg p-2 text-white/90 transition hover:bg-white/15"
                        :aria-label="maximized ? 'Restore size' : 'Maximize'"
                        @click="toggleMaximize"
                    >
                        <Minimize2
                            v-if="maximized"
                            class="size-5"
                        />
                        <Maximize2 v-else class="size-5" />
                    </button>

                    <button
                        type="button"
                        class="rounded-lg p-2 text-white/90 transition hover:bg-white/15"
                        aria-label="Close"
                        @click="close"
                    >
                        <X class="size-5" />
                    </button>
                </div>
            </header>

            <!-- SCROLLABLE CONTENT -->
            <div
                class="min-h-0 flex-1 space-y-4 overflow-y-auto px-4 py-4 sm:px-6"
            >
                <!-- BOAT PROFILE -->
                <section
                    class="grid gap-5 rounded-2xl border border-[#dce8f5] bg-white p-4 shadow-sm lg:grid-cols-[230px_minmax(0,1fr)] dark:bg-[#102036] dark:text-slate-100 dark:border-white/10"
                >
                    <BoatPhotoGallery
                        :pictures="boat.pictures"
                        :picture-url="boat.picture_url"
                        :alt="boat.name"
                        height-class="h-48"
                    />

                    <div class="min-w-0 space-y-4">
                        <div
                            class="flex flex-wrap items-start justify-between gap-3"
                        >
                            <div class="min-w-0">
                                <p
                                    class="mb-1 text-xs font-medium text-slate-500"
                                >
                                    Boat Name
                                </p>
                                <h3
                                    class="break-words text-xl font-bold tracking-tight text-ink sm:text-2xl"
                                >
                                    {{ boat.name }}
                                </h3>
                                <p
                                    class="mt-1 text-sm font-semibold text-[#2776c9]"
                                >
                                    {{ boat.code }}
                                </p>
                            </div>

                            <span
                                class="inline-flex items-center rounded-full px-3 py-1.5 text-xs font-semibold"
                                :class="currentStatusTone"
                            >
                                <span
                                    class="mr-2 size-2 rounded-full bg-current"
                                />
                                {{ boat.status_label }}
                            </span>
                        </div>

                        <div class="grid gap-4 sm:grid-cols-2">
                            <div>
                                <p class="text-xs text-slate-500">
                                    Service Availability
                                </p>
                                <p
                                    class="mt-1 text-sm font-semibold text-ink"
                                >
                                    {{
                                        boat.service_availability_label ||
                                        boat.service_type_label ||
                                        '—'
                                    }}
                                </p>
                            </div>

                            <div>
                                <p class="text-xs text-slate-500">
                                    Passenger Capacity
                                </p>
                                <p
                                    class="mt-1 text-sm font-semibold text-ink"
                                >
                                    {{ boat.capacity }} passengers
                                </p>
                            </div>
                        </div>

                        <div v-if="boat.rental_destinations?.length">
                            <p class="mb-2 text-xs text-slate-500">
                                Rental Destinations
                            </p>

                            <div class="flex flex-wrap gap-2">
                                <span
                                    v-for="(destination, index) in boat.rental_destinations"
                                    :key="index"
                                    class="inline-flex max-w-full flex-wrap gap-1 rounded-lg bg-[#edf5ff] px-3 py-2 text-xs font-medium text-[#15589d]"
                                >
                                    {{ destination.destination }}
                                    <span
                                        v-if="destination.rental_price_label"
                                        class="font-semibold"
                                    >
                                        · {{ destination.rental_price_label }}
                                    </span>
                                </span>
                            </div>
                        </div>
                    </div>
                </section>

                <!-- TABS -->
                <nav
                    class="flex gap-2 overflow-x-auto border-b border-[#dce8f5] pb-2"
                    aria-label="Boat details sections"
                >
                    <button
                        v-for="tab in tabs"
                        :key="tab.id"
                        type="button"
                        class="inline-flex shrink-0 items-center gap-2 rounded-lg px-4 py-2.5 text-sm font-semibold transition"
                        :class="
                            activeTab === tab.id
                                ? 'bg-[#176fcd] text-white shadow-sm'
                                : 'bg-white text-ink-soft hover:bg-[#eaf3fd] dark:bg-[#102036] dark:text-slate-100'
                        "
                        :aria-current="
                            activeTab === tab.id ? 'page' : undefined
                        "
                        @click="activeTab = tab.id"
                    >
                        <component
                            :is="tab.icon"
                            class="size-4"
                        />
                        {{ tab.label }}
                    </button>
                </nav>

                <!-- OVERVIEW TAB -->
                <div
                    v-if="activeTab === 'overview'"
                    class="space-y-4"
                >
                    <div class="grid gap-4 lg:grid-cols-2">
                        <!-- BOAT INFORMATION -->
                        <section
                            class="rounded-2xl border border-[#dce8f5] bg-white p-5 shadow-sm dark:bg-[#102036] dark:text-slate-100 dark:border-white/10"
                        >
                            <div
                                class="mb-4 flex items-center gap-2 border-b border-slate-100 pb-3"
                            >
                                <Ship class="size-5 text-[#1877d4]" />
                                <h4
                                    class="text-sm font-bold text-ink"
                                >
                                    Boat Information
                                </h4>
                            </div>

                            <dl class="grid gap-x-5 gap-y-4 sm:grid-cols-2">
                                <div>
                                    <dt class="text-xs text-slate-500">
                                        Registration / License No.
                                    </dt>
                                    <dd
                                        class="mt-1 break-words text-sm font-semibold text-ink"
                                    >
                                        {{ boat.license_no || '—' }}
                                    </dd>
                                </div>

                                <div>
                                    <dt class="text-xs text-slate-500">
                                        Registration Date
                                    </dt>
                                    <dd
                                        class="mt-1 text-sm font-semibold text-ink"
                                    >
                                        {{
                                            boat.registration_date_label ||
                                            '—'
                                        }}
                                    </dd>
                                </div>

                                <div>
                                    <dt class="text-xs text-slate-500">
                                        Registered in SafeSakay
                                    </dt>
                                    <dd
                                        class="mt-1 text-sm font-semibold text-ink"
                                    >
                                        {{
                                            boat.registered_in_safesakay_label ||
                                            '—'
                                        }}
                                    </dd>
                                </div>

                                <div>
                                    <dt class="text-xs text-slate-500">
                                        Home Port / Assigned Area
                                    </dt>
                                    <dd
                                        class="mt-1 text-sm font-semibold text-ink"
                                    >
                                        {{ boat.home_port || '—' }}
                                    </dd>
                                </div>
                            </dl>
                        </section>

                        <!-- ASSIGNED OPERATOR -->
                        <section
                            class="rounded-2xl border border-[#dce8f5] bg-white p-5 shadow-sm dark:bg-[#102036] dark:text-slate-100 dark:border-white/10"
                        >
                            <div
                                class="mb-4 flex items-center gap-2 border-b border-slate-100 pb-3"
                            >
                                <UserRound
                                    class="size-5 text-[#1877d4]"
                                />
                                <h4
                                    class="text-sm font-bold text-ink"
                                >
                                    Assigned Operator
                                </h4>
                            </div>

                            <div class="mb-5 flex items-center gap-3">
                                <div
                                    class="flex size-14 shrink-0 items-center justify-center overflow-hidden rounded-full bg-[#edf5ff]"
                                >
                                    <img
                                        v-if="boat.operator?.picture_url"
                                        :src="boat.operator.picture_url"
                                        :alt="boat.operator.full_name"
                                        class="size-full object-cover"
                                    />
                                    <UserRound
                                        v-else
                                        class="size-6 text-[#1877d4]"
                                    />
                                </div>

                                <div class="min-w-0">
                                    <p
                                        class="break-words text-sm font-bold text-ink"
                                    >
                                        {{
                                            boat.operator?.full_name ??
                                            'Unassigned'
                                        }}
                                    </p>

                                    <span
                                        v-if="boat.operator"
                                        class="mt-1 inline-flex rounded-full px-2.5 py-1 text-xs font-semibold"
                                        :class="
                                            boat.operator.is_active !== false
                                                ? 'bg-emerald-100 text-emerald-700'
                                                : 'bg-slate-100 text-slate-600'
                                        "
                                    >
                                        {{
                                            boat.operator.status_label ??
                                            'Active'
                                        }}
                                    </span>
                                </div>
                            </div>

                            <dl class="grid gap-4 sm:grid-cols-2">
                                <div>
                                    <dt class="text-xs text-slate-500">
                                        Contact Number
                                    </dt>
                                    <dd
                                        class="mt-1 break-words text-sm font-semibold text-ink"
                                    >
                                        {{
                                            boat.operator?.contact_number ||
                                            '—'
                                        }}
                                    </dd>
                                </div>

                                <div
                                    v-if="
                                        boat.operator?.emergency_contact_name ||
                                        boat.operator?.emergency_contact_number
                                    "
                                >
                                    <dt class="text-xs text-slate-500">
                                        Emergency Contact
                                    </dt>
                                    <dd
                                        class="mt-1 break-words text-sm font-semibold text-ink"
                                    >
                                        {{
                                            [
                                                boat.operator.emergency_contact_name,
                                                boat.operator.emergency_contact_number,
                                            ]
                                                .filter(Boolean)
                                                .join(' · ') || '—'
                                        }}
                                    </dd>
                                </div>
                            </dl>
                        </section>
                    </div>

                    <!-- SAFETY EQUIPMENT -->
                    <section
                        class="rounded-2xl border border-[#dce8f5] bg-white p-5 shadow-sm dark:bg-[#102036] dark:text-slate-100 dark:border-white/10"
                    >
                        <div
                            class="mb-4 flex flex-wrap items-center justify-between gap-2 border-b border-slate-100 pb-3"
                        >
                            <div class="flex items-center gap-2">
                                <LifeBuoy
                                    class="size-5 text-[#1877d4]"
                                />
                                <h4
                                    class="text-sm font-bold text-ink"
                                >
                                    Safety Equipment
                                </h4>
                            </div>

                            <span class="text-xs text-slate-500">
                                {{ safetyReady }} /
                                {{ safetyChecklist.length }} recorded as available
                            </span>
                        </div>

                        <ul
                            class="grid gap-2 sm:grid-cols-2 xl:grid-cols-4"
                        >
                            <li
                                v-for="item in safetyChecklist"
                                :key="item.value"
                                class="flex min-w-0 items-center gap-2 rounded-xl border border-slate-100 bg-[#f8fbff] px-3 py-3 text-xs dark:bg-[#0b1b30] dark:text-slate-100"
                            >
                                <span
                                    class="flex size-6 shrink-0 items-center justify-center rounded-full"
                                    :class="
                                        item.checked
                                            ? 'bg-emerald-100 text-emerald-700'
                                            : 'bg-slate-100 text-ink-muted'
                                    "
                                >
                                    <Check
                                        v-if="item.checked"
                                        class="size-4"
                                    />
                                    <X v-else class="size-3.5" />
                                </span>

                                <span
                                    class="break-words"
                                    :class="
                                        item.checked
                                            ? 'font-medium text-ink'
                                            : 'text-slate-500'
                                    "
                                >
                                    {{ item.label }}
                                </span>
                            </li>
                        </ul>

                        <p class="mt-3 text-xs text-slate-500">
                            Last checked:
                            {{
                                boat.safety_last_checked_label ||
                                'Not recorded'
                            }}
                        </p>
                    </section>

                    <!-- OPERATOR VIOLATIONS -->
                    <section
                        v-if="
                            (boat.operator_no_show_violations?.length ?? 0) > 0
                        "
                        class="rounded-2xl border border-amber-200 bg-amber-50 p-5"
                    >
                        <h4
                            class="mb-3 text-sm font-bold text-amber-900"
                        >
                            Operator No-Show Violations
                        </h4>

                        <ul class="space-y-2">
                            <li
                                v-for="row in boat.operator_no_show_violations"
                                :key="row.id"
                                class="rounded-lg bg-white p-3 text-sm dark:bg-[#102036] dark:text-slate-100"
                            >
                                <p class="font-semibold text-amber-900">
                                    {{ row.strike_label }} ·
                                    {{ row.booking_code }}
                                </p>
                                <p class="mt-1 text-xs text-slate-500">
                                    {{ row.incident_date_label }}
                                </p>
                                <p class="mt-1 text-sm text-slate-700">
                                    {{ row.reason }}
                                </p>
                            </li>
                        </ul>
                    </section>
                </div>

                <!-- USAGE TAB -->
                <section
                    v-else-if="activeTab === 'usage'"
                    class="rounded-2xl border border-[#dce8f5] bg-white p-5 shadow-sm dark:bg-[#102036] dark:text-slate-100 dark:border-white/10"
                >
                    <div
                        class="mb-5 flex items-center gap-2 border-b border-slate-100 pb-3"
                    >
                        <BarChart3 class="size-5 text-[#1877d4]" />
                        <h4 class="text-sm font-bold text-ink">
                            Boat Usage Summary
                        </h4>
                    </div>

                    <dl class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                        <div
                            class="rounded-xl bg-[#edf5ff] p-4"
                        >
                            <dt class="text-xs text-slate-500">
                                Total Trips
                            </dt>
                            <dd
                                class="mt-2 text-2xl font-bold text-ink"
                            >
                                {{ usage.total_trips }}
                            </dd>
                        </div>

                        <div
                            class="rounded-xl bg-[#edf9f3] p-4"
                        >
                            <dt class="text-xs text-slate-500">
                                Regular Trips
                            </dt>
                            <dd
                                class="mt-2 text-2xl font-bold text-ink"
                            >
                                {{ usage.regular_trips }}
                            </dd>
                        </div>

                        <div
                            class="rounded-xl bg-[#f3efff] p-4"
                        >
                            <dt class="text-xs text-slate-500">
                                Exclusive Rentals
                            </dt>
                            <dd
                                class="mt-2 text-2xl font-bold text-ink"
                            >
                                {{ usage.exclusive_rentals }}
                            </dd>
                        </div>

                        <div
                            class="rounded-xl bg-[#fff7ed] p-4 dark:bg-orange-950/40 dark:text-slate-100"
                        >
                            <dt class="text-xs text-slate-500">
                                Last Trip
                            </dt>
                            <dd
                                class="mt-2 break-words text-sm font-bold text-ink"
                            >
                                {{ usage.last_trip_label || '—' }}
                            </dd>
                        </div>
                    </dl>
                </section>

                <!-- ACTIVITY TAB -->
                <section
                    v-else
                    class="rounded-2xl border border-[#dce8f5] bg-white p-5 shadow-sm dark:bg-[#102036] dark:text-slate-100 dark:border-white/10"
                >
                    <div
                        class="mb-5 flex items-center gap-2 border-b border-slate-100 pb-3"
                    >
                        <History class="size-5 text-[#1877d4]" />
                        <h4 class="text-sm font-bold text-ink">
                            Recent Activity
                        </h4>
                    </div>

                    <div
                        v-if="recentActivity.length === 0"
                        class="flex flex-col items-center justify-center py-12 text-center"
                    >
                        <History
                            class="mb-3 size-10 text-slate-300"
                        />
                        <p class="text-sm font-medium text-slate-600">
                            No recent activity recorded yet.
                        </p>
                    </div>

                    <ul v-else class="space-y-3">
                        <li
                            v-for="item in recentActivity"
                            :key="item.id"
                            class="rounded-xl border border-slate-100 bg-[#f8fbff] p-4 dark:bg-[#0b1b30] dark:text-slate-100"
                        >
                            <p class="text-xs text-slate-500">
                                {{ item.occurred_on_label }}
                            </p>
                            <p
                                class="mt-1 text-sm font-semibold text-ink"
                            >
                                {{ item.title }}
                            </p>
                        </li>
                    </ul>
                </section>
            </div>

            <!-- FOOTER ACTIONS -->
            <footer
                class="flex shrink-0 flex-col gap-3 border-t border-[#dce8f5] bg-white px-4 py-3 sm:flex-row sm:items-center sm:justify-between sm:px-6 dark:bg-[#102036] dark:text-slate-100 dark:border-white/10"
            >
                <div class="flex flex-wrap items-center gap-2">
                    <Button
                        type="button"
                        class="h-10 gap-2 rounded-xl bg-[#176fcd] px-4 text-white hover:bg-[#095bb3]"
                        @click="emit('edit', boat)"
                    >
                        <Pencil class="size-4" />
                        Edit Boat
                    </Button>

                    <div
                        v-if="statusPickerOpen"
                        class="min-w-[11rem]"
                    >
                        <Select @update:model-value="applyStatus">
                            <SelectTrigger
                                class="h-10 rounded-xl border-[#bcd8f5] bg-white text-sm text-[#176fcd] dark:bg-[#102036] dark:text-slate-100 dark:border-white/10"
                            >
                                <SelectValue
                                    placeholder="Select Status"
                                />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem
                                    v-for="option in manualStatuses"
                                    :key="option.value"
                                    :value="option.value"
                                    :disabled="
                                        !canSetStatus(boat, option.value)
                                    "
                                >
                                    {{ option.label }}
                                </SelectItem>
                            </SelectContent>
                        </Select>
                    </div>

                    <Button
                        v-else
                        type="button"
                        variant="outline"
                        class="h-10 gap-2 rounded-xl border-[#bcd8f5] px-4 text-[#176fcd] hover:bg-[#edf5ff]"
                        @click="statusPickerOpen = true"
                    >
                        <RefreshCw class="size-4" />
                        Update Status
                    </Button>

                    <Button
                        v-if="isArchived"
                        type="button"
                        variant="outline"
                        class="h-10 gap-2 rounded-xl border-emerald-300 px-4 text-emerald-700 hover:bg-emerald-50"
                        @click="emit('restore', boat)"
                    >
                        <RefreshCw class="size-4" />
                        Restore / Reactivate
                    </Button>

                    <Button
                        v-else
                        type="button"
                        variant="outline"
                        class="h-10 gap-2 rounded-xl border-slate-300 px-4 text-slate-600 hover:bg-slate-50"
                        @click="emit('archive', boat)"
                    >
                        <Archive class="size-4" />
                        Archive Boat
                    </Button>
                </div>

                <Button
                    type="button"
                    variant="outline"
                    class="h-10 rounded-xl border-[#bcd8f5] px-5 text-[#176fcd] hover:bg-[#edf5ff]"
                    @click="close"
                >
                    Close
                </Button>
            </footer>
        </DialogContent>
    </Dialog>
</template>
