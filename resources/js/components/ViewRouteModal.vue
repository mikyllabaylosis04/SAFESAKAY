<script setup lang="ts">
import { MapPin, X } from '@lucide/vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogTitle,
} from '@/components/ui/dialog';
import type { OrganizationRouteRecord } from '@/types/route';

defineProps<{
    open: boolean;
    route: OrganizationRouteRecord | null;
}>();

const emit = defineEmits<{
    'update:open': [value: boolean];
    edit: [route: OrganizationRouteRecord];
}>();

function close() {
    emit('update:open', false);
}
</script>

<template>
    <Dialog :open="open" @update:open="emit('update:open', $event)">
        <DialogContent
            v-if="route"
            :show-close-button="false"
            class="flex max-h-[92vh] w-full max-w-[calc(100%-1.5rem)] flex-col gap-0 overflow-hidden rounded-2xl border-none p-0 shadow-2xl sm:max-w-lg"
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
                            {{ route.name }}
                        </DialogTitle>
                        <DialogDescription class="text-sm text-white/80">
                            Organization route details
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

            <div class="space-y-4 overflow-y-auto p-5">
                <div class="flex items-center gap-3">
                    <div
                        class="flex size-12 items-center justify-center rounded-xl bg-[#e8f4fc] text-[#1b7ad8]"
                    >
                        <MapPin class="size-5" />
                    </div>
                    <div>
                        <p class="font-semibold text-[#0f4c8a] dark:text-sky-100">
                            {{ route.name }}
                        </p>
                        <p class="text-xs text-ink-muted">
                            {{ route.origin }} to {{ route.destination }}
                        </p>
                    </div>
                </div>

                <dl class="grid gap-3 sm:grid-cols-2">
                    <div>
                        <dt class="text-xs text-ink-muted">Origin</dt>
                        <dd class="font-medium text-[#0f4c8a] dark:text-sky-100">
                            {{ route.origin }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs text-ink-muted">Destination</dt>
                        <dd class="font-medium text-[#0f4c8a] dark:text-sky-100">
                            {{ route.destination }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs text-ink-muted">Route Type</dt>
                        <dd class="font-medium text-[#0f4c8a] dark:text-sky-100">
                            {{ route.trip_type_label }}
                        </dd>
                    </div>
                    <div v-if="route.trip_type === 'regular_public'">
                        <dt class="text-xs text-ink-muted">Regular Fare</dt>
                        <dd class="font-medium text-[#0f4c8a] dark:text-sky-100">
                            {{ route.regular_fare_label || 'Not set' }}
                        </dd>
                    </div>
                    <div v-if="route.trip_type === 'regular_public'">
                        <dt class="text-xs text-ink-muted">Student</dt>
                        <dd class="font-medium text-[#0f4c8a] dark:text-sky-100">
                            {{ route.student_fare_label || '—' }}
                            <span class="text-xs font-normal text-ink-muted">
                                ({{ route.student_discount_percent ?? 20 }}% off)
                            </span>
                        </dd>
                    </div>
                    <div v-if="route.trip_type === 'regular_public'">
                        <dt class="text-xs text-ink-muted">Senior</dt>
                        <dd class="font-medium text-[#0f4c8a] dark:text-sky-100">
                            {{ route.senior_fare_label || '—' }}
                            <span class="text-xs font-normal text-ink-muted">
                                ({{ route.senior_discount_percent ?? 20 }}% off)
                            </span>
                        </dd>
                    </div>
                    <div v-if="route.trip_type === 'private_booking'">
                        <dt class="text-xs text-ink-muted">Rental Price</dt>
                        <dd class="font-medium text-[#0f4c8a] dark:text-sky-100">
                            {{ route.rental_price_label || '—' }}
                        </dd>
                    </div>
                    <div v-if="route.trip_type === 'private_booking'">
                        <dt class="text-xs text-ink-muted">Required Down Payment</dt>
                        <dd class="font-medium text-[#0f4c8a] dark:text-sky-100">
                            {{ route.down_payment_percent_label || '—' }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs text-ink-muted">Status</dt>
                        <dd>
                            <span
                                class="inline-flex rounded-full px-2.5 py-1 text-xs font-semibold"
                                :class="
                                    route.is_active
                                        ? 'bg-emerald-50 text-emerald-700'
                                        : 'bg-amber-50 text-amber-700'
                                "
                            >
                                {{ route.status_label }}
                            </span>
                        </dd>
                    </div>
                </dl>
            </div>

            <div
                class="flex items-center justify-end gap-3 border-t border-[#d7e8f6] bg-white px-5 py-4 dark:border-white/10 dark:bg-background"
            >
                <Button
                    type="button"
                    variant="outline"
                    class="h-10 rounded-xl border-[#1b7ad8] px-5 text-[#1b7ad8] hover:bg-[#e8f4fc]"
                    @click="close"
                >
                    Close
                </Button>
                <Button
                    type="button"
                    class="h-10 rounded-xl bg-[#1b7ad8] px-5 text-white hover:bg-[#1567c4]"
                    @click="emit('edit', route)"
                >
                    Edit Route
                </Button>
            </div>
        </DialogContent>
    </Dialog>
</template>
