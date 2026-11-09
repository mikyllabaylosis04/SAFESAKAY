<script setup lang="ts">
import { Link, useForm } from '@inertiajs/vue3';
import { CalendarDays, CheckCircle2, Clock3, CloudRain, CloudSun, MapPin, Megaphone } from '@lucide/vue';
import { computed, ref } from 'vue';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Spinner } from '@/components/ui/spinner';

type WeatherAlert = {
    id?: number;
    code?: string;
    headline: string;
    reason_label: string;
    message?: string | null;
    affected_date_label?: string | null;
    duration_label?: string | null;
    affected_routes_label?: string | null;
};

const props = withDefaults(
    defineProps<{
        alert: WeatherAlert;
        variant?: 'hold' | 'watch';
        context?: 'dashboard' | 'trips' | 'rentals';
        canLift?: boolean;
    }>(),
    { variant: 'hold', context: 'dashboard', canLift: false },
);

const isHold = computed(() => props.variant === 'hold');

const impact = computed(() => {
    if (!isHold.value) {
        return ['Bookings stay open, but trips are not confirmed yet.', 'If the hold is not lifted, this date becomes a full suspension.'];
    }

    return {
        rentals: ['Private rentals on this date are moved to Weather Hold.', 'Guests pick a new date. No new payment is needed.'],
        trips: ['Regular trips on the affected routes are stopped.', 'Boats cannot depart until the hold is lifted.'],
        dashboard: ['Regular trips are stopped.', 'Private rentals move to Weather Hold and guests reschedule.'],
    }[props.context];
});

const details = computed(() =>
    [
        { icon: CalendarDays, text: props.alert.affected_date_label },
        { icon: Clock3, text: props.alert.duration_label },
        { icon: MapPin, text: props.alert.affected_routes_label },
    ].filter((item) => item.text),
);

const liftOpen = ref(false);
const liftForm = useForm({ lift_notes: '' });

function confirmLift() {
    if (!props.alert.id) {
        return;
    }

    liftForm.patch(`/weather-suspensions/${props.alert.id}/lift`, {
        preserveScroll: true,
        onSuccess: () => {
            liftOpen.value = false;
            liftForm.reset();
        },
    });
}
</script>

<template>
    <section
        class="overflow-hidden rounded-2xl border shadow-sm"
        :class="isHold ? 'border-red-200 bg-white dark:border-red-500/30 dark:bg-white/5' : 'border-amber-200 bg-white dark:border-amber-500/30 dark:bg-white/5'"
    >
        <div class="flex flex-wrap items-start gap-3 px-4 py-3.5" :class="isHold ? 'bg-red-50 dark:bg-red-500/10' : 'bg-amber-50 dark:bg-amber-500/10'">
            <div
                class="flex size-10 shrink-0 items-center justify-center rounded-xl text-white"
                :class="isHold ? 'bg-red-600' : 'bg-amber-500'"
            >
                <CloudRain v-if="isHold" class="size-5" />
                <CloudSun v-else class="size-5" />
            </div>

            <div class="min-w-0 flex-1">
                <div class="flex flex-wrap items-center gap-2">
                    <h2 class="text-base font-bold" :class="isHold ? 'text-red-800 dark:text-red-200' : 'text-amber-900 dark:text-amber-200'">
                        {{ alert.headline }}
                    </h2>
                    <span
                        class="rounded-full px-2 py-0.5 text-[11px] font-semibold"
                        :class="isHold ? 'bg-red-600 text-white' : 'bg-amber-500 text-white'"
                    >
                        {{ isHold ? 'Suspended' : 'Watch' }}
                    </span>
                    <span v-if="alert.code" class="text-xs text-slate-500 dark:text-slate-400">{{ alert.code }}</span>
                </div>

                <ul class="mt-1.5 flex flex-wrap gap-x-4 gap-y-1 text-xs font-medium" :class="isHold ? 'text-red-700 dark:text-red-300' : 'text-amber-800 dark:text-amber-300'">
                    <li v-for="item in details" :key="item.text ?? ''" class="inline-flex items-center gap-1.5">
                        <component :is="item.icon" class="size-3.5" />
                        {{ item.text }}
                    </li>
                </ul>
            </div>

            <div class="flex shrink-0 gap-2">
                <Link
                    href="/announcements"
                    class="inline-flex h-8 items-center rounded-lg border bg-white px-3 text-xs font-semibold dark:bg-[#102036] dark:text-slate-100 dark:border-white/10"
                    :class="isHold ? 'border-red-200 text-red-700 hover:bg-red-50' : 'border-amber-200 text-amber-800 hover:bg-amber-50'"
                >
                    View
                </Link>
                <button
                    v-if="canLift && alert.id"
                    type="button"
                    class="inline-flex h-8 items-center gap-1.5 rounded-lg bg-emerald-600 px-3 text-xs font-semibold text-white hover:bg-emerald-700"
                    @click="liftOpen = true"
                >
                    <CheckCircle2 class="size-3.5" />
                    Lift hold
                </button>
            </div>
        </div>

        <div class="grid gap-3 px-4 py-3 text-sm md:grid-cols-[minmax(0,1fr)_minmax(0,1fr)]">
            <div v-if="isHold && alert.message" class="flex gap-2 text-ink dark:text-slate-200">
                <Megaphone class="mt-0.5 size-4 shrink-0 text-ink-muted" />
                <p class="leading-relaxed">{{ alert.message }}</p>
            </div>
            <ul class="space-y-1 text-ink-soft dark:text-slate-400" :class="{ 'md:col-span-2': !(isHold && alert.message) }">
                <li v-for="line in impact" :key="line" class="flex gap-2">
                    <span class="mt-2 size-1.5 shrink-0 rounded-full" :class="isHold ? 'bg-red-500' : 'bg-amber-500'" />
                    {{ line }}
                </li>
            </ul>
        </div>

        <Dialog :open="liftOpen" @update:open="(open) => (liftOpen = open)">
            <DialogContent class="max-w-md rounded-2xl bg-white dark:bg-slate-900">
                <DialogHeader>
                    <DialogTitle class="text-lg font-bold text-[#0F2854] dark:text-sky-100">Lift weather hold?</DialogTitle>
                    <DialogDescription class="text-sm text-ink-soft dark:text-slate-300">Trips can run again after safety clearance.</DialogDescription>
                </DialogHeader>

                <div class="rounded-xl border border-[#d7e8f6] bg-[#f8fbfe] px-4 py-3 dark:border-white/10">
                    <div class="flex items-center justify-between gap-2">
                        <p class="text-sm font-semibold text-ink dark:text-slate-100">{{ alert.reason_label }}</p>
                        <span v-if="alert.code" class="text-xs font-medium text-ink-soft">{{ alert.code }}</span>
                    </div>
                    <ul class="mt-1.5 space-y-1 text-sm text-ink dark:text-slate-200">
                        <li v-for="item in details" :key="item.text ?? ''" class="flex items-center gap-1.5">
                            <component :is="item.icon" class="size-4 text-ink-soft" />
                            {{ item.text }}
                        </li>
                    </ul>
                </div>

                <ul class="space-y-1.5 text-sm text-[#0F2854] dark:text-slate-100">
                    <li class="flex gap-2">
                        <span class="mt-2 size-1.5 shrink-0 rounded-full bg-emerald-500" />
                        Regular trips and new rentals can proceed.
                    </li>
                    <li class="flex gap-2">
                        <span class="mt-2 size-1.5 shrink-0 rounded-full bg-emerald-500" />
                        Operators and passengers are notified.
                    </li>
                    <li class="flex gap-2">
                        <span class="mt-2 size-1.5 shrink-0 rounded-full bg-amber-500" />
                        Rentals on Weather Hold keep their reschedule requests.
                    </li>
                </ul>

                <div class="space-y-1.5">
                    <label for="lift_notes" class="text-sm font-semibold text-[#0F2854] dark:text-slate-100">
                        Notes <span class="font-normal text-ink-soft">(optional)</span>
                    </label>
                    <textarea
                        id="lift_notes"
                        v-model="liftForm.lift_notes"
                        rows="2"
                        maxlength="500"
                        class="w-full resize-none rounded-xl border border-[#c5def5] bg-white px-3 py-2 text-sm text-[#0F2854] placeholder:text-[#8aa2b8] outline-none focus:border-[#1b7ad8] dark:border-white/10 dark:bg-white/5 dark:text-slate-100"
                        placeholder="e.g. Seas calmed after coast guard inspection"
                    />
                    <p class="text-xs text-ink-soft dark:text-slate-400">Shown in the "Weather hold lifted" announcement.</p>
                    <p v-if="liftForm.errors.lift_notes" class="text-xs text-rose-600">{{ liftForm.errors.lift_notes }}</p>
                </div>

                <DialogFooter class="gap-2">
                    <button
                        type="button"
                        class="inline-flex h-9 items-center rounded-lg border border-[#c5def5] px-3 text-sm font-semibold text-[#0F2854] hover:bg-[#f4f9fd] dark:bg-[#0b1b30] dark:text-slate-100"
                        @click="liftOpen = false"
                    >
                        Cancel
                    </button>
                    <button
                        type="button"
                        class="inline-flex h-9 items-center gap-2 rounded-lg bg-emerald-600 px-3 text-sm font-semibold text-white hover:bg-emerald-700 disabled:opacity-60"
                        :disabled="liftForm.processing"
                        @click="confirmLift"
                    >
                        <Spinner v-if="liftForm.processing" class="size-4" />
                        Lift hold
                    </button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </section>
</template>
