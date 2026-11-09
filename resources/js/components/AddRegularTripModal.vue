
<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { Save, X } from '@lucide/vue';
import { computed, watch } from 'vue';

import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';

import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogTitle,
} from '@/components/ui/dialog';

import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';

import { Spinner } from '@/components/ui/spinner';

import type {
    OrganizationRouteOption,
    RegularTripRecord,
    RegularTripScheduleMode,
    ScheduleBoatOption,
} from '@/types/schedule';

const props = defineProps<{
    open: boolean;
    boats: ScheduleBoatOption[];
    organizationRoutes: OrganizationRouteOption[];
    trips?: RegularTripRecord[];
    trip?: RegularTripRecord | null;
    defaultDate?: string;
    defaultRoute?: string;
    defaultScheduleMode?: RegularTripScheduleMode;
    dayHasLineup?: boolean;
}>();

const emit = defineEmits<{
    'update:open': [value: boolean];
}>();

const fieldClass =
    'h-11 w-full rounded-lg border border-slate-300 bg-white px-3 text-[15px] text-slate-800 shadow-none focus-visible:border-[#1b7ad8] focus-visible:ring-2 focus-visible:ring-[#1b7ad8]/20 dark:border-white/20 dark:bg-slate-900 dark:text-white';

const form = useForm({
    boat_id: '',
    route: '',
    trip_date: '',
    departure_time: '',
    schedule_mode: 'rush' as RegularTripScheduleMode,
    rotation_order: '1',
    remarks: '',
});

const isEditing = computed(() => Boolean(props.trip));

const selectedBoat = computed(() =>
    props.boats.find(
        (boat) => String(boat.id) === String(form.boat_id),
    ),
);

const operatorName = computed(
    () => selectedBoat.value?.operator_name || 'Unassigned',
);

function leaveOrderLabel(order: number) {
    const value = order % 100;

    const suffix =
        value >= 11 && value <= 13
            ? 'th'
            : { 1: 'st', 2: 'nd', 3: 'rd' }[value % 10] ?? 'th';

    return `${order}${suffix} to leave`;
}

function waitingOutbound(
    date: string,
    routeName = form.route,
) {
    return (props.trips ?? []).filter(
        (trip) =>
            trip.trip_date === date &&
            trip.is_outbound &&
            (trip.status === 'scheduled' ||
                trip.status === 'delayed') &&
            trip.boat?.is_active !== false &&
            trip.id !== props.trip?.id &&
            (!routeName || trip.route === routeName),
    );
}

function nextLeaveOrder(date: string) {
    return String(
        Math.min(waitingOutbound(date).length + 1, 12),
    );
}

const rotationChoices = computed(() => {
    const next = Number(
        nextLeaveOrder(
            form.trip_date || props.defaultDate || '',
        ),
    );

    const length = Math.max(1, Math.min(next, 12));

    return Array.from({ length }, (_, index) => ({
        value: String(index + 1),
        label: leaveOrderLabel(index + 1),
    }));
});

watch(
    () => [props.open, props.trip] as const,
    ([open, trip]) => {
        if (!open) {
            form.reset();
            form.clearErrors();
            return;
        }

        if (trip) {
            form.boat_id = String(trip.boat.id);
            form.route = trip.route;
            form.trip_date = trip.trip_date;
            form.schedule_mode = 'rush';
            form.departure_time = '06:00';
            form.rotation_order = String(
                trip.rotation_order || 1,
            );
            form.remarks = trip.remarks ?? '';
            form.clearErrors();
            return;
        }

        const today = new Date();

        const month = String(
            today.getMonth() + 1,
        ).padStart(2, '0');

        const day = String(
            today.getDate(),
        ).padStart(2, '0');

        const tripDate =
            props.defaultDate ||
            `${today.getFullYear()}-${month}-${day}`;

        form.trip_date = tripDate;
        form.schedule_mode = 'rush';
        form.departure_time = '06:00';

        form.route =
            props.defaultRoute &&
            props.organizationRoutes.some(
                (item) =>
                    item.name === props.defaultRoute,
            )
                ? props.defaultRoute
                : (props.organizationRoutes[0]?.name ?? '');

        form.boat_id = props.boats[0]
            ? String(props.boats[0].id)
            : '';

        form.rotation_order = nextLeaveOrder(tripDate);
        form.remarks = '';
        form.clearErrors();
    },
);

watch(
    () => [form.trip_date, form.route] as const,
    ([date]) => {
        if (!props.open || props.trip || !date) {
            return;
        }

        form.rotation_order = nextLeaveOrder(date);
    },
);

function close() {
    emit('update:open', false);
}

function submit() {
    const options = {
        preserveScroll: true,
        onSuccess: () => close(),
    };

    if (props.trip) {
        form.post(
            `/regular-trips/${props.trip.id}`,
            options,
        );
        return;
    }

    form.post('/regular-trips', options);
}
</script>

<template>
    <Dialog
        :open="open"
        @update:open="emit('update:open', $event)"
    >
        <DialogContent
            :show-close-button="false"
            class="flex max-h-[90vh] w-full max-w-[calc(100%-1.5rem)] flex-col gap-0 overflow-hidden rounded-2xl border border-slate-200 bg-white p-0 shadow-2xl sm:max-w-[550px] dark:border-slate-700 dark:bg-slate-950"
        >
            <div
                class="flex items-center justify-between bg-[#1b7ad8] px-6 py-5"
            >
                <div class="flex items-center gap-3">
                    <div
                        class="flex size-10 shrink-0 items-center justify-center rounded-full bg-white/20"
                    >
                        <img
                            src="/images/safesakay-logo.png?v=2"
                            alt="SafeSakay"
                            class="size-8 object-contain"
                        />
                    </div>

                    <div>
                        <DialogTitle
                            class="text-lg font-semibold text-white"
                        >
                            {{
                                isEditing
                                    ? 'Edit Boat Lineup'
                                    : 'Add Boat to Lineup'
                            }}
                        </DialogTitle>

                        <DialogDescription class="sr-only">
                            Boat lineup form
                        </DialogDescription>
                    </div>
                </div>

                <button
                    type="button"
                    aria-label="Close"
                    class="flex size-9 items-center justify-center rounded-lg text-white transition hover:bg-white/20"
                    @click="close"
                >
                    <X class="size-5" />
                </button>
            </div>

            <form
                class="flex min-h-0 flex-1 flex-col"
                @submit.prevent="submit"
            >
                <div
                    class="flex-1 space-y-5 overflow-y-auto px-6 py-6"
                >
                    <div
                        class="grid grid-cols-1 gap-4 sm:grid-cols-2"
                    >
                        <div class="space-y-2">
                            <Label
                                for="trip-date"
                                class="text-sm font-semibold text-slate-700 dark:text-slate-200"
                            >
                                Date
                            </Label>

                            <Input
                                id="trip-date"
                                v-model="form.trip_date"
                                type="date"
                                required
                                :class="fieldClass"
                            />

                            <InputError
                                :message="form.errors.trip_date"
                            />
                        </div>

                        <div class="space-y-2">
                            <Label
                                for="rotation-order"
                                class="text-sm font-semibold text-slate-700 dark:text-slate-200"
                            >
                                Departure Order
                            </Label>

                            <Select
                                v-model="form.rotation_order"
                            >
                                <SelectTrigger
                                    id="rotation-order"
                                    :class="fieldClass"
                                >
                                    <SelectValue
                                        placeholder="Select order"
                                    />
                                </SelectTrigger>

                                <SelectContent>
                                    <SelectItem
                                        v-for="order in rotationChoices"
                                        :key="order.value"
                                        :value="order.value"
                                    >
                                        {{ order.label }}
                                    </SelectItem>
                                </SelectContent>
                            </Select>

                            <InputError
                                :message="form.errors.rotation_order"
                            />
                        </div>
                    </div>

                    <div class="space-y-2">
                        <Label
                            for="regular-route"
                            class="text-sm font-semibold text-slate-700 dark:text-slate-200"
                        >
                            Regular Route
                        </Label>

                        <Select v-model="form.route">
                            <SelectTrigger
                                id="regular-route"
                                :class="fieldClass"
                            >
                                <SelectValue
                                    placeholder="Select route"
                                />
                            </SelectTrigger>

                            <SelectContent>
                                <SelectItem
                                    v-for="route in organizationRoutes"
                                    :key="route.id"
                                    :value="route.name"
                                >
                                    {{ route.name }}
                                </SelectItem>
                            </SelectContent>
                        </Select>

                        <InputError
                            :message="form.errors.route"
                        />
                    </div>

                    <div class="space-y-2">
                        <Label
                            for="boat-select"
                            class="text-sm font-semibold text-slate-700 dark:text-slate-200"
                        >
                            Boat
                        </Label>

                        <Select v-model="form.boat_id">
                            <SelectTrigger
                                id="boat-select"
                                :class="fieldClass"
                            >
                                <SelectValue
                                    placeholder="Select boat"
                                />
                            </SelectTrigger>

                            <SelectContent>
                                <SelectItem
                                    v-for="boat in boats"
                                    :key="boat.id"
                                    :value="String(boat.id)"
                                >
                                    {{ boat.name }}
                                    ({{ boat.code }})
                                </SelectItem>
                            </SelectContent>
                        </Select>

                        <InputError
                            :message="form.errors.boat_id"
                        />
                    </div>

                    <div class="space-y-2">
                        <Label
                            for="trip-operator"
                            class="text-sm font-semibold text-slate-700 dark:text-slate-200"
                        >
                            Assigned Operator
                        </Label>

                        <Input
                            id="trip-operator"
                            :model-value="operatorName"
                            type="text"
                            readonly
                            tabindex="-1"
                            :class="[
                                fieldClass,
                                'cursor-default border-slate-200 bg-slate-100 font-medium text-slate-700 dark:bg-slate-800 dark:text-slate-200',
                            ]"
                        />
                    </div>
                </div>

                <div
                    class="flex items-center justify-end gap-3 border-t border-slate-200 bg-white px-6 py-4 dark:border-slate-700 dark:bg-slate-950"
                >
                    <Button
                        type="button"
                        variant="outline"
                        class="h-11 min-w-[105px] rounded-lg border-slate-300 bg-white px-5 text-sm font-semibold text-slate-700 shadow-none hover:bg-slate-100 dark:border-slate-600 dark:bg-slate-900 dark:text-white"
                        @click="close"
                    >
                        Cancel
                    </Button>

                    <Button
                        type="submit"
                        class="h-11 min-w-[150px] gap-2 rounded-lg bg-[#1b7ad8] px-5 text-sm font-semibold text-white shadow-none hover:bg-[#1567c4]"
                        :disabled="form.processing"
                    >
                        <Spinner
                            v-if="form.processing"
                            class="size-4"
                        />

                        <Save
                            v-else
                            class="size-4"
                        />

                        {{
                            isEditing
                                ? 'Save Changes'
                                : 'Add to Lineup'
                        }}
                    </Button>
                </div>
            </form>
        </DialogContent>
    </Dialog>
</template>
