<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { ArrowLeft } from '@lucide/vue';
import { dashboard } from '@/routes';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: dashboard() },
            { title: 'Admin Guide', href: '/admin-guide' },
        ],
    },
});

const sections = [
    {
        id: 'dashboard',
        title: 'Dashboard',
        bullets: [
            'Summary cards show boats, today’s trips, passengers, and rentals.',
            'Red or yellow banners mean weather hold or watch—check Announcements for details.',
            'Use Live Operations and Today’s private bookings before opening every menu.',
            'Sidebar badges on Rental Request and Operator No-Show mean something needs you.',
        ],
    },
    {
        id: 'rentals',
        title: 'Rental Request (exclusive boat rental)',
        bullets: [
            'Passenger books in the mobile app → status Pending.',
            'Assigned operator accepts in the app → Awaiting Payment.',
            'Passenger pays online through SafeSakay (GCash via Xendit). The rental is confirmed automatically once Xendit confirms the payment.',
            'Open the rental from Rental Request → View to see payment details and receipt.',
            'After payment → Confirmed. Operator completes the trip in the app when done.',
        ],
    },
    {
        id: 'payments',
        title: 'Where does the money go?',
        bullets: [
            'Passenger payments are sent to SafeSakay through Xendit. No barangay GCash QR is needed.',
            'Xendit confirms online payments automatically; check them under Payments.',
            'Remaining balance is paid to the operator (GCash or cash). The operator records and verifies balance in the mobile app; you monitor status on web.',
            'SafeSakay releases each operator share from Operator Payouts after the trip is completed.',
        ],
    },
    {
        id: 'noshow',
        title: 'Boat did not arrive',
        bullets: [
            'Passenger reports in the app when the boat did not arrive (after the grace period).',
            'Step 1: Verify or reject the report on Boat did not arrive.',
            'Step 2: Reschedule (down payment stays), approve refund then record GCash, or assign another boat.',
            'Reschedule stays open until the booking date is updated on Rental Request.',
        ],
    },
    {
        id: 'commission',
        title: 'Commission',
        bullets: [
            'Commission is tied to verified exclusive rentals.',
            'Commission is taken from the first payment SafeSakay receives. Set the rate in Settings → Commission.',
            'Operator share from the rental is tracked; remaining balance goes directly to the operator after down payment is verified.',
        ],
    },
    {
        id: 'reports',
        title: 'Reports',
        bullets: [
            'Pick a report card (Regular Trips, Rentals, Manifest, Payment, Boats, Commission).',
            'Set the date range, then filter. Export when you need a file for meetings.',
            'Going and return regular trips are separate rows—check both for a full day.',
        ],
    },
    {
        id: 'settings',
        title: 'Settings & setup',
        bullets: [
            'Profile: your name and email.',
            'Commission: barangay commission rate. Rental Policies: no-show grace period and rental rules. Notifications: bell alert preferences.',
            'Security: change your password.',
            'User Management: barangay staff accounts.',
        ],
    },
];
</script>

<template>
    <Head title="Admin Guide" />

    <div class="flex flex-1 flex-col gap-6 bg-[#f4f7fb] p-4 lg:p-6 dark:bg-transparent">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <Link
                    :href="dashboard()"
                    class="mb-2 inline-flex items-center gap-1 text-sm font-semibold text-[#1b7ad8] hover:underline"
                >
                    <ArrowLeft class="size-4" />
                    Back to dashboard
                </Link>
                <h1 class="text-2xl font-bold tracking-tight text-[#0f4c8a] dark:text-sky-100">
                    Barangay admin guide
                </h1>
                <p class="mt-1 max-w-2xl text-sm text-ink-muted">
                    Plain-language reference for SafeSakay web admin. Operators and passengers use the mobile app for trips and payments.
                </p>
            </div>
        </div>

        <nav class="flex flex-wrap gap-2">
            <a
                v-for="section in sections"
                :key="section.id"
                :href="`#${section.id}`"
                class="rounded-full bg-white px-3 py-1.5 text-xs font-semibold text-[#1b7ad8] shadow-sm ring-1 ring-[#d7e8f6] hover:bg-[#e8f4fc] dark:bg-white/5 dark:ring-white/10"
            >
                {{ section.title }}
            </a>
        </nav>

        <div class="space-y-4">
            <section
                v-for="section in sections"
                :id="section.id"
                :key="section.id"
                class="scroll-mt-6 rounded-[12px] border border-[#e4ebf2] bg-white p-5 shadow-sm dark:border-white/10 dark:bg-white/5"
            >
                <h2 class="text-lg font-bold text-[#0f4c8a] dark:text-sky-100">
                    {{ section.title }}
                </h2>
                <ul class="mt-3 list-disc space-y-2 pl-5 text-sm leading-relaxed text-ink-soft dark:text-slate-300">
                    <li v-for="(line, index) in section.bullets" :key="index">
                        {{ line }}
                    </li>
                </ul>
            </section>
        </div>
    </div>
</template>
