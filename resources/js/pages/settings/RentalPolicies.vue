<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { BadgeCheck, CloudRain, FileText, RotateCcw, Save, ShieldCheck, Ship, Timer, UserX, XCircle } from '@lucide/vue';
import type { Component } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';

type PolicySection = { key: string; title: string; body: string };

const props = defineProps<{
    can_manage: boolean;
    rules: { passenger_grace_minutes: number; operator_grace_minutes: number };
    policy: { version: string; acceptances: number; sections: PolicySection[] };
}>();

defineOptions({
    layout: { breadcrumbs: [{ title: 'Settings', href: '/settings/profile' }, { title: 'Rental Policies', href: '/settings/rental-policies' }] },
});

const form = useForm({
    passenger_grace_minutes: props.rules.passenger_grace_minutes,
    operator_grace_minutes: props.rules.operator_grace_minutes,
});

const icons: Record<string, Component> = {
    operator_eligibility: ShieldCheck,
    readiness: BadgeCheck,
    passenger_cancellation: XCircle,
    passenger_no_show: UserX,
    operator_no_show: Ship,
    weather: CloudRain,
    refund: RotateCcw,
};

function save() {
    form.post('/settings/payments/no-show-rules', { preserveScroll: true });
}
</script>

<template>
    <Head title="Rental policies" />

    <div class="space-y-4">
        <section class="rounded-2xl border border-[#d7e8f6] bg-white shadow-sm dark:border-white/10 dark:bg-white/5">
            <header class="flex items-center gap-3 border-b border-[#eef4f9] px-5 py-4 dark:border-white/10">
                <span class="flex size-9 items-center justify-center rounded-xl bg-[#1b7ad8] text-white">
                    <Timer class="size-4" />
                </span>
                <div>
                    <h2 class="font-semibold text-[#0f4c8a] dark:text-sky-100">No-Show Grace Period</h2>
                    <p class="text-xs text-ink-muted dark:text-slate-400">Minutes after the scheduled departure before a no-show can be reported</p>
                </div>
            </header>

            <form class="space-y-4 p-5" @submit.prevent="save">
                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="grid gap-1.5">
                        <Label for="passenger_grace_minutes" class="text-ink dark:text-sky-100">Passenger no-show grace period</Label>
                        <div class="relative max-w-[12rem]">
                            <Input
                                id="passenger_grace_minutes"
                                v-model="form.passenger_grace_minutes"
                                type="number"
                                min="5"
                                max="120"
                                class="h-10 rounded-xl border-[#c5def5] bg-[#f7fbfe] pr-12 dark:border-white/10 dark:bg-white/5"
                                :disabled="!can_manage"
                            />
                            <span class="pointer-events-none absolute top-1/2 right-3 -translate-y-1/2 text-sm font-semibold text-ink-soft">min</span>
                        </div>
                        <p class="text-xs text-ink-muted">The operator waits this long before reporting the passenger. Default 15.</p>
                        <InputError :message="form.errors.passenger_grace_minutes" />
                    </div>
                    <div class="grid gap-1.5">
                        <Label for="operator_grace_minutes" class="text-ink dark:text-sky-100">Boat / operator no-show grace period</Label>
                        <div class="relative max-w-[12rem]">
                            <Input
                                id="operator_grace_minutes"
                                v-model="form.operator_grace_minutes"
                                type="number"
                                min="5"
                                max="120"
                                class="h-10 rounded-xl border-[#c5def5] bg-[#f7fbfe] pr-12 dark:border-white/10 dark:bg-white/5"
                                :disabled="!can_manage"
                            />
                            <span class="pointer-events-none absolute top-1/2 right-3 -translate-y-1/2 text-sm font-semibold text-ink-soft">min</span>
                        </div>
                        <p class="text-xs text-ink-muted">The passenger waits this long before reporting the boat. Default 15.</p>
                        <InputError :message="form.errors.operator_grace_minutes" />
                    </div>
                </div>

                <div class="flex items-center justify-end gap-3">
                    <span v-if="!can_manage" class="text-xs text-ink-muted">Administrator only</span>
                    <span v-else-if="form.recentlySuccessful" class="text-xs font-medium text-emerald-600">Saved</span>
                    <Button type="submit" class="h-10 rounded-xl bg-[#1b7ad8] px-4 hover:bg-[#1567c4]" :disabled="!can_manage || form.processing">
                        <Spinner v-if="form.processing" class="size-4" />
                        <Save v-else class="size-4" />
                        Save Changes
                    </Button>
                </div>
            </form>
        </section>

        <section class="rounded-2xl border border-[#d7e8f6] bg-white shadow-sm dark:border-white/10 dark:bg-white/5">
            <header class="flex flex-wrap items-center justify-between gap-3 border-b border-[#eef4f9] px-5 py-4 dark:border-white/10">
                <div class="flex items-center gap-3">
                    <span class="flex size-9 items-center justify-center rounded-xl bg-[#1b7ad8] text-white">
                        <FileText class="size-4" />
                    </span>
                    <div>
                        <h2 class="font-semibold text-[#0f4c8a] dark:text-sky-100">Rental Rules</h2>
                        <p class="text-xs text-ink-muted dark:text-slate-400">Shown to passengers, who must accept them before paying</p>
                    </div>
                </div>
                <div class="text-right text-xs text-ink-soft dark:text-slate-400">
                    <span class="rounded-full bg-[#eef7fd] px-2.5 py-1 font-semibold text-[#0f4c8a] dark:bg-sky-500/10 dark:text-sky-200">Version {{ policy.version }}</span>
                    <p class="mt-1">{{ policy.acceptances }} passenger {{ policy.acceptances === 1 ? 'acceptance' : 'acceptances' }} on this version</p>
                </div>
            </header>

            <ul class="divide-y divide-[#eef4f9] dark:divide-white/10">
                <li v-for="section in policy.sections" :key="section.key" class="flex gap-3 px-5 py-3.5">
                    <component :is="icons[section.key] ?? FileText" class="mt-0.5 size-4 shrink-0 text-[#1b7ad8]" />
                    <div>
                        <p class="text-sm font-semibold text-ink dark:text-sky-100">{{ section.title }}</p>
                        <p class="mt-0.5 text-sm leading-relaxed text-ink-soft dark:text-slate-400">{{ section.body }}</p>
                    </div>
                </li>
            </ul>

            <p class="border-t border-[#eef4f9] px-5 py-3 text-xs text-ink-muted dark:border-white/10 dark:text-slate-400">
                Payment and refund terms are versioned. A new version applies only to bookings paid after it is published; passengers keep the terms they already accepted. Refunds follow the booking terms, the verified incident details, and consumer protection rules.
            </p>
        </section>
    </div>
</template>
