<script setup lang="ts">
import {
    Anchor,
    CalendarDays,
    Copy,
    CreditCard,
    Info,
    LifeBuoy,
    MapPin,
    Maximize2,
    Minimize2,
    Phone,
    Radio,
    ShieldCheck,
    Ship,
    UserRound,
    Users,
    X,
} from '@lucide/vue';
import type { Component } from 'vue';
import { ref, watch } from 'vue';
import BoatPhotoGallery from '@/components/BoatPhotoGallery.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogTitle,
} from '@/components/ui/dialog';
import { cn } from '@/lib/utils';
import type { BookingBoatRecord } from '@/types/booking';

const ICON = 'text-[#1b7ad8]';
const ICON_WRAP = 'bg-[#e8f4fc] text-[#1b7ad8]';

const props = defineProps<{
    open: boolean;
    boat: BookingBoatRecord | null;
}>();

const emit = defineEmits<{
    'update:open': [value: boolean];
}>();

const maximized = ref(false);
const copied = ref(false);

watch(
    () => props.open,
    (open) => {
        if (!open) {
            maximized.value = false;
            copied.value = false;
        }
    },
);

function close() {
    emit('update:open', false);
}

function toggleMaximize() {
    maximized.value = !maximized.value;
}

function availabilityTone(boat: BookingBoatRecord) {
    if (boat.is_open_for_booking) {
        return 'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-200';
    }

    if (boat.status === 'on_trip') {
        return 'bg-violet-50 text-violet-700 dark:bg-violet-500/15 dark:text-violet-200';
    }

    return 'bg-amber-50 text-amber-700 dark:bg-amber-500/20 dark:text-amber-200';
}

async function copyCode() {
    if (!props.boat?.code) {
        return;
    }

    try {
        await navigator.clipboard.writeText(props.boat.code);
        copied.value = true;
        window.setTimeout(() => {
            copied.value = false;
        }, 1600);
    } catch {
        copied.value = false;
    }
}

function safetyIcon(label: string): Component {
    const key = label.toLowerCase();

    if (key.includes('life')) {
        return LifeBuoy;
    }

    if (key.includes('radio') || key.includes('communication')) {
        return Radio;
    }

    if (key.includes('anchor')) {
        return Anchor;
    }

    return ShieldCheck;
}
</script>

<template>
    <Dialog :open="open" @update:open="emit('update:open', $event)">
        <DialogContent
            v-if="boat"
            :show-close-button="false"
            :class="
                cn(
                    'flex max-h-[92vh] w-full max-w-[calc(100%-1.5rem)] flex-col gap-0 overflow-hidden rounded-2xl border-none p-0 shadow-2xl',
                    maximized
                        ? 'h-[92vh] sm:max-w-[calc(100%-1.5rem)]'
                        : 'sm:max-w-5xl',
                )
            "
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
                            Boat Details
                        </DialogTitle>
                        <DialogDescription class="text-sm text-white/80">
                            Private boat rental profile
                        </DialogDescription>
                    </div>
                </div>
                <div class="flex items-center gap-1">
                    <button
                        type="button"
                        class="rounded-full p-1.5 text-white/80 transition hover:bg-white/15 hover:text-white"
                        :aria-label="maximized ? 'Restore' : 'Maximize'"
                        @click="toggleMaximize"
                    >
                        <Minimize2 v-if="maximized" class="size-5" />
                        <Maximize2 v-else class="size-5" />
                    </button>
                    <button
                        type="button"
                        class="rounded-full p-1.5 text-white/80 transition hover:bg-white/15 hover:text-white"
                        aria-label="Close"
                        @click="close"
                    >
                        <X class="size-5" />
                    </button>
                </div>
            </div>

            <div class="min-h-0 flex-1 space-y-4 overflow-y-auto bg-[#f7fbfe] p-5 dark:bg-background">
                <section class="rounded-2xl border border-[#d7e8f6] bg-white p-4 dark:border-white/10 dark:bg-white/5">
                    <div class="grid gap-4 lg:grid-cols-[minmax(0,1.15fr)_minmax(0,1fr)]">
                        <BoatPhotoGallery
                            :pictures="boat.pictures"
                            :picture-url="boat.picture_url"
                            :alt="boat.name"
                            :height-class="maximized ? 'h-80' : 'h-56'"
                        />
                        <div class="flex min-w-0 flex-col justify-center">
                            <div class="flex flex-wrap items-start justify-between gap-3">
                                <div>
                                    <h3 class="text-2xl font-bold tracking-tight text-[#0f4c8a] dark:text-sky-100">
                                        {{ boat.name }}
                                    </h3>
                                    <p class="mt-1 inline-flex items-center gap-1.5 text-sm text-ink-soft">
                                        <Ship :class="cn('size-4', ICON)" />
                                        {{ boat.type || 'Pump Boat' }}
                                    </p>
                                </div>
                                <div
                                    class="rounded-xl border border-[#d7e8f6] bg-[#f4f9fd] px-3 py-2 text-right dark:border-white/10 dark:bg-white/5"
                                >
                                    <p class="text-[11px] text-ink-muted">Boat Code</p>
                                    <button
                                        type="button"
                                        class="mt-0.5 inline-flex items-center gap-1.5 text-sm font-semibold text-[#0f4c8a] dark:text-sky-100"
                                        @click="copyCode"
                                    >
                                        {{ boat.code }}
                                        <Copy :class="cn('size-3.5', ICON)" />
                                    </button>
                                    <p v-if="copied" class="text-[11px] font-medium text-[#1b7ad8]">
                                        Copied
                                    </p>
                                </div>
                            </div>
                            <span
                                class="mt-3 inline-flex w-fit items-center gap-1.5 rounded-full px-2.5 py-1 text-[11px] font-semibold"
                                :class="availabilityTone(boat)"
                            >
                                <span class="size-1.5 rounded-full bg-current" />
                                {{ boat.availability_label }}
                            </span>
                            <div class="mt-4 grid grid-cols-2 gap-2 sm:grid-cols-4">
                                <div class="rounded-xl border border-[#e8f1f8] bg-[#f8fbfe] px-3 py-2.5 dark:border-white/10 dark:bg-white/5">
                                    <Users :class="cn('mb-1.5 size-4', ICON)" />
                                    <p class="text-[11px] text-ink-muted">Capacity</p>
                                    <p class="text-sm font-semibold text-[#0f4c8a] dark:text-sky-100">
                                        {{ boat.capacity }} passengers
                                    </p>
                                </div>
                                <div class="rounded-xl border border-[#e8f1f8] bg-[#f8fbfe] px-3 py-2.5 dark:border-white/10 dark:bg-white/5">
                                    <MapPin :class="cn('mb-1.5 size-4', ICON)" />
                                    <p class="text-[11px] text-ink-muted">Home Port</p>
                                    <p class="text-sm font-semibold text-[#0f4c8a] dark:text-sky-100">
                                        {{ boat.home_port }}
                                    </p>
                                </div>
                                <div class="rounded-xl border border-[#e8f1f8] bg-[#f8fbfe] px-3 py-2.5 dark:border-white/10 dark:bg-white/5">
                                    <CalendarDays :class="cn('mb-1.5 size-4', ICON)" />
                                    <p class="text-[11px] text-ink-muted">Registered Date</p>
                                    <p class="text-sm font-semibold text-[#0f4c8a] dark:text-sky-100">
                                        {{ boat.registration_date_label ?? '—' }}
                                    </p>
                                </div>
                                <div class="rounded-xl border border-[#e8f1f8] bg-[#f8fbfe] px-3 py-2.5 dark:border-white/10 dark:bg-white/5">
                                    <CreditCard :class="cn('mb-1.5 size-4', ICON)" />
                                    <p class="text-[11px] text-ink-muted">License No.</p>
                                    <p class="text-sm font-semibold text-[#0f4c8a] dark:text-sky-100">
                                        {{ boat.license_no ?? '—' }}
                                    </p>
                                </div>
                            </div>
                        </div>
                    </div>
                </section>

                <div class="grid gap-4 xl:grid-cols-2">
                    <div class="space-y-4">
                        <section class="rounded-2xl border border-[#d7e8f6] bg-white p-4 dark:border-white/10 dark:bg-white/5">
                            <div class="mb-3 flex items-center gap-2 text-sm font-semibold text-[#0f4c8a] dark:text-sky-100">
                                <span :class="cn('flex size-7 items-center justify-center rounded-full', ICON_WRAP)">
                                    <Info class="size-3.5" />
                                </span>
                                Boat Information
                            </div>
                            <dl class="grid gap-y-2 text-sm">
                                <div class="flex items-center justify-between gap-4">
                                    <dt class="text-ink-muted">Boat Name</dt>
                                    <dd class="font-medium text-[#0f4c8a] dark:text-sky-100">{{ boat.name }}</dd>
                                </div>
                                <div class="flex items-center justify-between gap-4">
                                    <dt class="text-ink-muted">Boat Code</dt>
                                    <dd class="font-medium text-[#0f4c8a] dark:text-sky-100">{{ boat.code }}</dd>
                                </div>
                                <div class="flex items-center justify-between gap-4">
                                    <dt class="text-ink-muted">Type</dt>
                                    <dd class="font-medium text-[#0f4c8a] dark:text-sky-100">
                                        {{ boat.type || 'Pump Boat' }}
                                    </dd>
                                </div>
                                <div class="flex items-center justify-between gap-4">
                                    <dt class="text-ink-muted">Capacity</dt>
                                    <dd class="font-medium text-[#0f4c8a] dark:text-sky-100">
                                        {{ boat.capacity }} passengers
                                    </dd>
                                </div>
                                <div class="flex items-center justify-between gap-4">
                                    <dt class="text-ink-muted">Home Port</dt>
                                    <dd class="font-medium text-[#0f4c8a] dark:text-sky-100">{{ boat.home_port }}</dd>
                                </div>
                                <div class="flex items-center justify-between gap-4">
                                    <dt class="text-ink-muted">License No.</dt>
                                    <dd class="font-medium text-[#0f4c8a] dark:text-sky-100">
                                        {{ boat.license_no ?? '—' }}
                                    </dd>
                                </div>
                                <div class="flex items-center justify-between gap-4">
                                    <dt class="text-ink-muted">Registered Date</dt>
                                    <dd class="font-medium text-[#0f4c8a] dark:text-sky-100">
                                        {{ boat.registration_date_label ?? '—' }}
                                    </dd>
                                </div>
                            </dl>
                        </section>

                        <section class="rounded-2xl border border-[#d7e8f6] bg-white p-4 dark:border-white/10 dark:bg-white/5">
                            <div class="mb-3 flex items-center gap-2 text-sm font-semibold text-[#0f4c8a] dark:text-sky-100">
                                <span :class="cn('flex size-7 items-center justify-center rounded-full', ICON_WRAP)">
                                    <MapPin class="size-3.5" />
                                </span>
                                Available Rental Destinations & Prices
                            </div>
                            <p
                                v-if="!boat.rental_destinations?.length"
                                class="text-sm text-ink-muted"
                            >
                                No exclusive rental destinations assigned yet.
                            </p>
                            <div v-else>
                                <div class="mb-2 grid grid-cols-[1fr_auto] text-xs font-medium text-ink-muted">
                                    <span>Destination</span>
                                    <span>Rental Price (₱)</span>
                                </div>
                                <ul class="divide-y divide-[#e8f1f8] dark:divide-white/10">
                                    <li
                                        v-for="item in boat.rental_destinations"
                                        :key="item.id"
                                        class="grid grid-cols-[1fr_auto] items-center gap-3 py-2.5"
                                    >
                                        <span class="font-medium text-[#0f4c8a] dark:text-sky-100">
                                            {{ item.destination }}
                                        </span>
                                        <span class="text-sm font-semibold text-[#1b7ad8]">
                                            {{ item.rental_price_label || '—' }}
                                        </span>
                                    </li>
                                </ul>
                            </div>
                        </section>
                    </div>

                    <div class="space-y-4">
                        <section class="rounded-2xl border border-[#d7e8f6] bg-white p-4 dark:border-white/10 dark:bg-white/5">
                            <div class="mb-3 flex items-center gap-2 text-sm font-semibold text-[#0f4c8a] dark:text-sky-100">
                                <span :class="cn('flex size-7 items-center justify-center rounded-full', ICON_WRAP)">
                                    <UserRound class="size-3.5" />
                                </span>
                                Operator Information
                            </div>
                            <div class="flex items-center gap-3">
                                <div
                                    class="flex size-16 items-center justify-center overflow-hidden rounded-full bg-[#e8f3fc] dark:bg-white/10"
                                >
                                    <img
                                        v-if="boat.operator?.picture_url"
                                        :src="boat.operator.picture_url"
                                        :alt="boat.operator.full_name"
                                        class="h-full w-full object-cover"
                                    />
                                    <UserRound v-else :class="cn('size-7', ICON)" />
                                </div>
                                <div>
                                    <p class="font-semibold text-[#0f4c8a] dark:text-sky-100">
                                        {{ boat.operator?.full_name ?? 'Unassigned' }}
                                    </p>
                                    <p class="text-xs text-ink-muted">Assigned operator</p>
                                    <p
                                        v-if="boat.operator?.contact_number"
                                        class="mt-1 inline-flex items-center gap-1.5 text-sm text-ink dark:text-slate-200"
                                    >
                                        <Phone :class="cn('size-3.5', ICON)" />
                                        {{ boat.operator.contact_number }}
                                    </p>
                                </div>
                            </div>
                        </section>

                        <section class="rounded-2xl border border-[#d7e8f6] bg-white p-4 dark:border-white/10 dark:bg-white/5">
                            <div class="mb-3 flex items-center gap-2 text-sm font-semibold text-[#0f4c8a] dark:text-sky-100">
                                <span :class="cn('flex size-7 items-center justify-center rounded-full', ICON_WRAP)">
                                    <ShieldCheck class="size-3.5" />
                                </span>
                                Safety Information
                            </div>
                            <ul
                                v-if="boat.safety_equipment?.length"
                                class="space-y-2.5"
                            >
                                <li
                                    v-for="item in boat.safety_equipment"
                                    :key="item"
                                    class="flex items-center gap-2.5 text-sm font-medium text-[#0f4c8a] dark:text-sky-100"
                                >
                                    <component
                                        :is="safetyIcon(item)"
                                        :class="cn('size-4 shrink-0', ICON)"
                                    />
                                    {{ item }}
                                </li>
                            </ul>
                            <p v-else class="text-sm text-ink-muted">
                                {{
                                    boat.safety_information ||
                                    'No safety information recorded.'
                                }}
                            </p>
                        </section>
                    </div>
                </div>

                <div
                    class="flex items-start gap-3 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900 dark:border-amber-500/20 dark:bg-amber-500/10 dark:text-amber-100"
                >
                    <Info class="mt-0.5 size-4 shrink-0 text-amber-500" />
                    <p>
                        This boat is available for private rental only. Operator
                        information is for admin use. Guests will only see it after
                        the boat operator accepts their booking.
                    </p>
                </div>
            </div>

            <div
                class="flex items-center justify-end border-t border-[#d7e8f6] bg-white px-5 py-4 dark:border-white/10 dark:bg-background"
            >
                <Button
                    type="button"
                    class="h-10 rounded-xl bg-[#1b7ad8] px-5 text-white hover:bg-[#1567c4]"
                    @click="close"
                >
                    Close
                </Button>
            </div>
        </DialogContent>
    </Dialog>
</template>
