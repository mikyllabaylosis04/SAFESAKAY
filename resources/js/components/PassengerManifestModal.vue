<script setup lang="ts">
import { ChevronDown, FileDown, X } from '@lucide/vue';
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
import { usePage } from '@inertiajs/vue3';
import { downloadReport, type ReportExportSpec } from '@/lib/reportExport';
import type { ReportRegularTrip } from '@/types/report';

const props = defineProps<{
    open: boolean;
    trip: ReportRegularTrip | null;
    canViewPassengerDetails: boolean;
}>();

const emit = defineEmits<{
    'update:open': [value: boolean];
    back: [];
}>();

function close() {
    emit('update:open', false);
}

function backToReports() {
    emit('back');
}

function openPrintView() {
    if (!props.trip) {
        return;
    }

    window.open(
        `/reports/manifest/${props.trip.id}/print`,
        '_blank',
        'width=1024,height=720',
    );
}

function downloadTrip(format: 'pdf' | 'excel' | 'csv' | 'print') {
    if (!props.canViewPassengerDetails) {
        return;
    }

    if (format === 'print') {
        openPrintView();
        return;
    }

    const spec = tripExportSpec();

    if (!spec) {
        return;
    }

    downloadReport(format, spec);
}

function tripExportSpec(): ReportExportSpec | null {
    const trip = props.trip;

    if (!trip) {
        return null;
    }

    const passengers = trip.passengers ?? [];

    return {
        title: `Passenger Manifest — ${trip.boat.name}`,
        subtitle: `${trip.code} · ${trip.trip_date_label} · ${trip.departure_time_label} · ${trip.direction}`,
        filename: `Passenger-Manifest-${trip.boat.name.replace(/[^A-Za-z0-9]+/g, '-')}-${trip.trip_date}`,
        organization: usePage().props.auth.organization?.name || 'SafeSakay',
        tagline: 'Official trip record',
        sectionTitle: 'Passenger Details',
        meta: [
            { label: 'Boat', value: trip.boat.name },
            { label: 'Route', value: trip.direction },
            { label: 'Date', value: trip.trip_date_label },
            { label: 'Departure Time', value: trip.departure_time_label },
            { label: 'Operator', value: trip.boat.operator_name || 'Unassigned' },
            { label: 'Passenger Count', value: `${trip.passenger_count} / ${trip.boat.capacity}` },
        ],
        note: `This manifest is linked to trip ${trip.code} and boat ${trip.boat.code}. Passenger names and contact numbers are for authorized SafeSakay administrators and official safety records only.`,
        emptyMessage: 'No passengers have been recorded for this trip.',
        columns: [
            { key: 'number', header: '#' },
            { key: 'name', header: 'Full Name' },
            { key: 'age', header: 'Age' },
            { key: 'gender', header: 'Gender' },
            { key: 'contact', header: 'Contact Number' },
            { key: 'remarks', header: 'Remarks' },
        ],
        rows: passengers.map((passenger, index) => ({
            number: index + 1,
            name: passenger.full_name,
            age: passenger.age,
            gender: passenger.gender,
            contact: passenger.contact_number,
            remarks: passenger.remarks || '—',
        })),
    };
}
</script>

<template>
    <Dialog :open="open" @update:open="emit('update:open', $event)">
        <DialogContent
            v-if="trip"
            :show-close-button="false"
            class="flex max-h-[92vh] w-full max-w-[calc(100%-1.5rem)] flex-col gap-0 overflow-hidden rounded-2xl border-none p-0 shadow-2xl sm:max-w-3xl"
        >
            <div
                class="flex items-start justify-between bg-[#1b7ad8] px-5 py-4 text-white"
            >
                <div class="flex items-center gap-3">
                    <div
                        class="flex size-11 items-center justify-center overflow-hidden rounded-full bg-white/15"
                    >
                        <img
                            src="/images/safesakay-logo.png?v=2"
                            alt="SafeSakay"
                            class="size-9 object-contain"
                        />
                    </div>
                    <div>
                        <DialogTitle class="text-lg font-semibold text-white">
                            Passenger Manifest — {{ trip.boat.name }}
                        </DialogTitle>
                        <DialogDescription class="text-sm text-white/80">
                            Official trip record for {{ trip.code }}
                        </DialogDescription>
                    </div>
                </div>
                <button
                    type="button"
                    class="rounded-full p-1.5 text-white/80 transition hover:bg-white/15 hover:text-white"
                    aria-label="Close"
                    @click="close"
                >
                    <X class="size-5" />
                </button>
            </div>

            <div class="min-h-0 flex-1 space-y-4 overflow-y-auto p-5">
                <dl class="grid gap-3 sm:grid-cols-2">
                    <div>
                        <dt class="text-xs text-ink-muted">Boat</dt>
                        <dd class="font-medium text-[#0f4c8a] dark:text-sky-100">
                            {{ trip.boat.name }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs text-ink-muted">Route</dt>
                        <dd class="font-medium text-[#0f4c8a] dark:text-sky-100">
                            {{ trip.direction }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs text-ink-muted">Date</dt>
                        <dd class="font-medium text-[#0f4c8a] dark:text-sky-100">
                            {{ trip.trip_date_label }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs text-ink-muted">Departure Time</dt>
                        <dd class="font-medium text-[#0f4c8a] dark:text-sky-100">
                            {{ trip.departure_time_label }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs text-ink-muted">Operator</dt>
                        <dd class="font-medium text-[#0f4c8a] dark:text-sky-100">
                            {{ trip.boat.operator_name || 'Unassigned' }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs text-ink-muted">Passenger Count</dt>
                        <dd class="font-semibold text-[#0f4c8a] dark:text-sky-100">
                            {{ trip.passenger_count }} / {{ trip.boat.capacity }}
                        </dd>
                    </div>
                </dl>

                <div>
                    <h3 class="mb-2 text-sm font-semibold text-[#0f4c8a] dark:text-sky-100">
                        Passenger Details
                    </h3>

                    <p
                        v-if="!canViewPassengerDetails"
                        class="rounded-xl bg-[#fbf3e6] px-4 py-3 text-sm text-[#d4922a]"
                    >
                        Passenger names and contact numbers are available to
                        authorized administrators only.
                    </p>

                    <div
                        v-else-if="trip.passengers.length === 0"
                        class="rounded-xl bg-[#f4f9fd] px-4 py-6 text-center text-sm text-ink-muted dark:bg-[#0b1b30] dark:text-slate-100"
                    >
                        No passengers have been recorded for this trip.
                    </div>

                    <div v-else class="overflow-x-auto rounded-xl border border-[#d7e8f6]">
                        <table class="w-full min-w-[640px] text-left text-sm">
                            <thead
                                class="bg-[#f4f9fd] text-xs tracking-wide text-ink-soft uppercase dark:bg-[#0b1b30] dark:text-slate-100"
                            >
                                <tr>
                                    <th class="px-4 py-2.5 font-semibold">#</th>
                                    <th class="px-4 py-2.5 font-semibold">Full Name</th>
                                    <th class="px-4 py-2.5 font-semibold">Age</th>
                                    <th class="px-4 py-2.5 font-semibold">Gender</th>
                                    <th class="px-4 py-2.5 font-semibold">
                                        Contact Number
                                    </th>
                                    <th class="px-4 py-2.5 font-semibold">Remarks</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr
                                    v-for="(passenger, index) in trip.passengers"
                                    :key="passenger.id"
                                    class="border-t border-[#eef4f9]"
                                >
                                    <td class="px-4 py-2.5 text-ink-muted">
                                        {{ index + 1 }}
                                    </td>
                                    <td class="px-4 py-2.5 font-medium text-[#0f4c8a]">
                                        {{ passenger.full_name }}
                                    </td>
                                    <td class="px-4 py-2.5 text-ink">
                                        {{ passenger.age }}
                                    </td>
                                    <td class="px-4 py-2.5 text-ink">
                                        {{ passenger.gender }}
                                    </td>
                                    <td class="px-4 py-2.5 text-ink">
                                        {{ passenger.contact_number }}
                                    </td>
                                    <td class="px-4 py-2.5 text-ink">
                                        {{ passenger.remarks || '—' }}
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div
                class="flex flex-wrap items-center justify-end gap-3 border-t border-[#d7e8f6] bg-white px-5 py-4 dark:border-white/10 dark:bg-background"
            >
                <Button
                    type="button"
                    variant="outline"
                    class="h-10 rounded-xl border-[#1b7ad8] px-4 text-[#1b7ad8] hover:bg-[#e8f4fc]"
                    @click="backToReports"
                >
                    Back to Reports
                </Button>
                <DropdownMenu>
                    <DropdownMenuTrigger as-child>
                        <Button
                            type="button"
                            class="h-10 rounded-xl bg-[#1b7ad8] px-4 text-white hover:bg-[#1567c4]"
                            :disabled="!canViewPassengerDetails"
                        >
                            <FileDown class="size-4" />
                            Download Report
                            <ChevronDown class="size-4" />
                        </Button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent
                        align="end"
                        class="min-w-44 rounded-xl border-[#d7e8f6]"
                    >
                        <DropdownMenuItem
                            class="cursor-pointer text-ink"
                            @click="downloadTrip('pdf')"
                        >
                            PDF
                        </DropdownMenuItem>
                        <DropdownMenuItem
                            class="cursor-pointer text-ink"
                            @click="downloadTrip('excel')"
                        >
                            Excel
                        </DropdownMenuItem>
                        <DropdownMenuItem
                            class="cursor-pointer text-ink"
                            @click="downloadTrip('csv')"
                        >
                            CSV
                        </DropdownMenuItem>
                        <DropdownMenuItem
                            class="cursor-pointer text-ink"
                            @click="downloadTrip('print')"
                        >
                            Print
                        </DropdownMenuItem>
                    </DropdownMenuContent>
                </DropdownMenu>
            </div>
        </DialogContent>
    </Dialog>
</template>
