<script setup lang="ts">
import { ClipboardList, Eye } from '@lucide/vue';
import { computed, ref } from 'vue';
import PassengerManifestModal from '@/components/PassengerManifestModal.vue';
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
    ManifestBoatOption,
    ManifestStatusOption,
    ReportRegularTrip,
} from '@/types/report';

const props = defineProps<{
    trips: ReportRegularTrip[];
    boats: ManifestBoatOption[];
    routes: string[];
    statuses: ManifestStatusOption[];
    canViewPassengerDetails: boolean;
}>();

const fieldClass =
    'h-10 w-full rounded-xl border border-[#c5def5] bg-[#f7fbfe] text-ink shadow-none focus-visible:border-[#1b7ad8] focus-visible:ring-[#1b7ad8]/20 dark:border-white/10 dark:bg-white/5 dark:text-sky-50';

function localDate(value = new Date()) {
    const month = String(value.getMonth() + 1).padStart(2, '0');
    const day = String(value.getDate()).padStart(2, '0');

    return `${value.getFullYear()}-${month}-${day}`;
}

const draft = ref({
    date: localDate(),
    boat: 'all',
    route: 'all',
    status: 'all',
});

const applied = ref({ ...draft.value });
const viewOpen = ref(false);
const selectedTrip = ref<ReportRegularTrip | null>(null);

const filteredTrips = computed(() =>
    props.trips
        .filter((trip) => {
            if (applied.value.date && trip.trip_date !== applied.value.date) {
                return false;
            }

            if (applied.value.boat !== 'all' && String(trip.boat.id) !== applied.value.boat) {
                return false;
            }

            if (applied.value.route !== 'all' && trip.direction !== applied.value.route) {
                return false;
            }

            if (applied.value.status !== 'all' && trip.status !== applied.value.status) {
                return false;
            }

            return true;
        })
        .sort((left, right) => {
            const dateCompare = left.trip_date.localeCompare(right.trip_date);

            return dateCompare !== 0
                ? dateCompare
                : left.departure_time.localeCompare(right.departure_time);
        }),
);

function applyFilters() {
    applied.value = { ...draft.value };
    viewOpen.value = false;
    selectedTrip.value = null;
}

function resetFilters() {
    draft.value = {
        date: localDate(),
        boat: 'all',
        route: 'all',
        status: 'all',
    };
    applyFilters();
}

function openView(trip: ReportRegularTrip) {
    selectedTrip.value = trip;
    viewOpen.value = true;
}

function backToReports() {
    viewOpen.value = false;
    selectedTrip.value = null;
}

function statusTone(status: string) {
    return {
        scheduled: 'bg-sky-50 text-sky-700',
        on_trip: 'bg-violet-50 text-violet-700',
        delayed: 'bg-amber-50 text-amber-700',
        completed: 'bg-emerald-50 text-emerald-700',
        cancelled: 'bg-rose-50 text-rose-700',
    }[status] ?? 'bg-slate-100 text-slate-600';
}

defineExpose({
    getExport() {
        return {
            trips: filteredTrips.value,
            periodLabel: applied.value.date || 'Filtered trips',
        };
    },
});
</script>

<template>
    <div>
        <div class="border-b border-[#e8f1f8] px-5 py-4 dark:border-white/10">
            <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-4">
                <div class="grid gap-1.5">
                    <label class="text-xs font-medium text-ink-soft">Date</label>
                    <Input v-model="draft.date" type="date" :class="cn(fieldClass)" />
                </div>
                <div class="grid gap-1.5">
                    <label class="text-xs font-medium text-ink-soft">Boat</label>
                    <Select v-model="draft.boat">
                        <SelectTrigger :class="cn(fieldClass, 'w-full')">
                            <SelectValue placeholder="All boats" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">All boats</SelectItem>
                            <SelectItem
                                v-for="boat in boats"
                                :key="boat.id"
                                :value="String(boat.id)"
                            >
                                {{ boat.name }}
                            </SelectItem>
                        </SelectContent>
                    </Select>
                </div>
                <div class="grid gap-1.5">
                    <label class="text-xs font-medium text-ink-soft">Route</label>
                    <Select v-model="draft.route">
                        <SelectTrigger :class="cn(fieldClass, 'w-full')">
                            <SelectValue placeholder="All routes" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">All routes</SelectItem>
                            <SelectItem
                                v-for="route in routes"
                                :key="route"
                                :value="route"
                            >
                                {{ route }}
                            </SelectItem>
                        </SelectContent>
                    </Select>
                </div>
                <div class="grid gap-1.5">
                    <label class="text-xs font-medium text-ink-soft">
                        Trip Status
                    </label>
                    <Select v-model="draft.status">
                        <SelectTrigger :class="cn(fieldClass, 'w-full')">
                            <SelectValue placeholder="All" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">All</SelectItem>
                            <SelectItem
                                v-for="status in statuses"
                                :key="status.value"
                                :value="status.value"
                            >
                                {{ status.label }}
                            </SelectItem>
                        </SelectContent>
                    </Select>
                </div>
            </div>

            <div class="mt-4 flex flex-wrap items-center justify-between gap-3">
                <p class="text-xs text-ink-muted">
                    {{ filteredTrips.length }}
                    {{ filteredTrips.length === 1 ? 'trip' : 'trips' }} found
                </p>
                <div class="flex gap-2">
                    <button
                        type="button"
                        class="h-10 rounded-xl border border-[#c5def5] bg-white px-4 text-sm font-semibold text-[#1b7ad8] transition hover:bg-[#e8f4fc] dark:bg-[#102036] dark:text-slate-100 dark:border-white/10"
                        @click="resetFilters"
                    >
                        Reset
                    </button>
                    <button
                        type="button"
                        class="h-10 rounded-xl bg-[#1b7ad8] px-4 text-sm font-semibold text-white transition hover:bg-[#1567c4]"
                        @click="applyFilters"
                    >
                        Apply Filter
                    </button>
                </div>
            </div>
        </div>

        <div v-if="filteredTrips.length === 0" class="px-5 py-16 text-center">
            <ClipboardList class="mx-auto size-10 text-[#c5def5]" />
            <p class="mt-3 font-medium text-[#0f4c8a] dark:text-sky-100">
                No trip records for these filters
            </p>
            <p class="mt-1 text-sm text-ink-muted">
                Change the date, boat, route, or status, then apply the filter.
            </p>
        </div>

        <div v-else class="overflow-x-auto">
            <table class="w-full min-w-[980px] text-left text-sm">
                <thead
                    class="bg-[#f4f9fd] text-xs tracking-wide text-ink-soft uppercase dark:bg-white/5"
                >
                    <tr>
                        <th class="px-5 py-3 font-semibold">Date</th>
                        <th class="px-5 py-3 font-semibold">Departure Time</th>
                        <th class="px-5 py-3 font-semibold">Boat</th>
                        <th class="px-5 py-3 font-semibold">Route</th>
                        <th class="px-5 py-3 font-semibold">Operator</th>
                        <th class="px-5 py-3 font-semibold">Passengers</th>
                        <th class="px-5 py-3 font-semibold">Status</th>
                        <th class="px-5 py-3 font-semibold text-right">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="trip in filteredTrips"
                        :key="trip.id"
                        class="border-t border-[#eef4f9] dark:border-white/10"
                    >
                        <td class="px-5 py-3 font-medium text-[#0f4c8a] dark:text-sky-100">
                            {{ trip.trip_date_label }}
                        </td>
                        <td class="px-5 py-3 text-ink dark:text-slate-200">
                            {{ trip.departure_time_label }}
                        </td>
                        <td class="px-5 py-3">
                            <p class="font-medium text-[#0f4c8a] dark:text-sky-100">
                                {{ trip.boat.name }}
                            </p>
                            <p class="text-xs text-ink-muted">{{ trip.boat.code }}</p>
                        </td>
                        <td class="px-5 py-3 text-ink dark:text-slate-200">
                            {{ trip.direction }}
                        </td>
                        <td class="px-5 py-3 text-ink dark:text-slate-200">
                            {{ trip.boat.operator_name || 'Unassigned' }}
                        </td>
                        <td class="px-5 py-3 font-semibold text-[#0f4c8a] dark:text-sky-100">
                            {{ trip.passenger_count }} / {{ trip.boat.capacity }}
                        </td>
                        <td class="px-5 py-3">
                            <span
                                class="inline-flex rounded-full px-2.5 py-1 text-xs font-semibold"
                                :class="statusTone(trip.status)"
                            >
                                {{ trip.status_label }}
                            </span>
                        </td>
                        <td class="px-5 py-3">
                            <div class="flex justify-end">
                                <button
                                    type="button"
                                    class="inline-flex h-8 items-center gap-1.5 rounded-lg border border-[#c5def5] bg-white px-2.5 text-xs font-medium text-[#1b7ad8] transition hover:bg-[#e8f4fc] dark:bg-[#102036] dark:text-slate-100 dark:border-white/10"
                                    @click="openView(trip)"
                                >
                                    <Eye class="size-3.5" />
                                    View
                                </button>
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <PassengerManifestModal
            v-model:open="viewOpen"
            :trip="selectedTrip"
            :can-view-passenger-details="canViewPassengerDetails"
            @back="backToReports"
        />
    </div>
</template>
