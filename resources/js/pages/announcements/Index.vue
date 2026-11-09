
<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';

import {
    ArrowRight,
    Bell,
    CalendarDays,
    ChevronLeft,
    ChevronRight,
    Clock3,
    CloudRain,
    FileText,
    MapPin,
    Megaphone,
    Plus,
    Search,
    ShieldAlert,
    Users,
    X,
} from '@lucide/vue';
import { computed, ref, watch } from 'vue';

import AddAnnouncementModal from '@/components/AddAnnouncementModal.vue';
import StatCards from '@/components/admin/StatCards.vue';
import type { StatCardItem } from '@/components/admin/StatCards.vue';

type Category = 'weather' | 'delay' | 'general' | 'emergency';

interface AnnouncementWeather {
    id: number;
    code: string;
    reason_label: string;
    affected_date_label: string | null;
    duration_label: string;
    routes_label: string;
    affected_trips_count: number;
    affected_rentals_count: number;
    lifted_at_label: string | null;
    lifted_by_name: string | null;
}

interface Announcement {
    id: number | string;
    title: string;
    category: string;
    body?: string | null;
    message?: string | null;
    audience?: string[] | string | null;
    audience_labels?: string[];
    recipient_count?: number;
    author_name?: string | null;
    author_role?: string | null;
    status?: string | null;
    status_label?: string | null;
    published_at?: string | null;
    published_at_label?: string | null;
    posted_date_label?: string | null;
    posted_time_label?: string | null;
    posted_ago?: string | null;
    weather?: AnnouncementWeather | null;
}

interface RouteOption {
    value: string;
    label: string;
    group: string | null;
}

interface WeatherSuspension {
    id: number | string;
    suspension_id?: string | null;
    code?: string | null;
    affected_date?: string | null;
    affected_routes?: string[] | string | null;
    affected_routes_label?: string | null;
    affected_date_label?: string | null;
    duration_label?: string | null;
    reason_label?: string | null;
    status_label?: string | null;
    announcement_message?: string | null;
    affected_trips_count?: number;
    affected_rentals_count?: number;
    declared_by_name?: string | null;
    declared_at_label?: string | null;
    declared_date_label?: string | null;
    declared_time_label?: string | null;
    declared_ago?: string | null;
    lifted_by_name?: string | null;
    lifted_at_label?: string | null;
    reason?: string | null;
    status?: string | null;
    duration_type?: string | null;
    starts_at?: string | null;
    ends_at?: string | null;
    created_at?: string | null;
}

interface Paginated<T> {
    data: T[];
    total?: number;
}

const props = withDefaults(
    defineProps<{
        announcements?: Announcement[] | Paginated<Announcement>;
        weatherSuspensions?: WeatherSuspension[] | Paginated<WeatherSuspension>;
        suspensions?: WeatherSuspension[] | Paginated<WeatherSuspension>;
        weatherRouteOptions?: RouteOption[];
    }>(),
    {
        announcements: () => [],
        weatherSuspensions: () => [],
        suspensions: () => [],
        weatherRouteOptions: () => [],
    }
);

const showCreateModal = ref(false);
const selectedAnnouncement = ref<Announcement | null>(null);
const selectedSuspension = ref<WeatherSuspension | null>(null);
const showAllSuspensions = ref(false);

const search = ref('');
const categoryFilter = ref('all');
const currentPage = ref(1);
const perPage = 8;

const liftingSuspensionId = ref<string | number | null>(null);
const confirmingLift = ref<WeatherSuspension | null>(null);

function unwrap<T>(value?: T[] | Paginated<T>): T[] {
    return Array.isArray(value) ? value : value?.data ?? [];
}

const announcementRecords = computed(() =>
    unwrap(props.announcements)
);

const suspensionRecords = computed(() => {
    const primary = unwrap(props.weatherSuspensions);

    return primary.length
        ? primary
        : unwrap(props.suspensions);
});

function normalizeCategory(
    value?: string | null
): Category | 'other' {
    const key = String(value ?? '')
        .trim()
        .toLowerCase()
        .replace(/[\s-]+/g, '_');

    if (['weather', 'weather_advisory'].includes(key)) {
        return 'weather';
    }

    if (['delay', 'trip_delay'].includes(key)) {
        return 'delay';
    }

    if (['general', 'general_notice'].includes(key)) {
        return 'general';
    }

    if (['emergency', 'emergency_advisory', 'urgent'].includes(key)) {
        return 'emergency';
    }

    return 'other';
}

function categoryLabel(value: string) {
    const labels = {
        weather: 'Weather',
        delay: 'Trip Delay',
        general: 'General Notice',
        emergency: 'Emergency',
        other: 'Other',
    };

    return labels[normalizeCategory(value)];
}

function categoryIcon(value: string) {
    return {
        weather: CloudRain,
        delay: Clock3,
        general: Megaphone,
        emergency: ShieldAlert,
        other: Bell,
    }[normalizeCategory(value)];
}

function categoryIconClass(value: string) {
    return {
        weather: 'bg-blue-50 text-blue-600',
        delay: 'bg-orange-50 text-orange-600',
        general: 'bg-violet-50 text-violet-600',
        emergency: 'bg-red-50 text-red-600',
        other: 'bg-slate-100 text-slate-600',
    }[normalizeCategory(value)];
}

function categoryBadgeClass(value: string) {
    return {
        weather: 'bg-blue-50 text-blue-700',
        delay: 'bg-orange-50 text-orange-700',
        general: 'bg-violet-50 text-violet-700',
        emergency: 'bg-red-50 text-red-700',
        other: 'bg-slate-100 text-slate-600',
    }[normalizeCategory(value)];
}

// =====================================================
// SUMMARY CARDS
// =====================================================

const totalAnnouncements = computed(() => {
    if (
        !Array.isArray(props.announcements) &&
        typeof props.announcements?.total === 'number'
    ) {
        return props.announcements.total;
    }

    return announcementRecords.value.length;
});

function countCategory(category: Category) {
    return announcementRecords.value.filter(
        item => normalizeCategory(item.category) === category
    ).length;
}

const summaryCards = computed<StatCardItem[]>(() => [
    { key: 'total', title: 'Total Announcements', value: totalAnnouncements.value, hint: 'All posted announcements', icon: FileText, tone: 'blue' },
    { key: 'weather', title: 'Weather Advisories', value: countCategory('weather'), hint: 'Weather-related announcements', icon: CloudRain, tone: 'amber' },
    { key: 'delay', title: 'Trip Delays', value: countCategory('delay'), hint: 'Departure delay announcements', icon: Clock3, tone: 'green' },
    { key: 'general', title: 'General & Emergency', value: countCategory('general') + countCategory('emergency'), hint: 'General and urgent notices', icon: ShieldAlert, tone: 'violet' },
]);

// =====================================================
// FORMATTING
// =====================================================

function formatDate(value?: string | null) {
    if (!value) {
return '—';
}

    const parsed = new Date(
        /^\d{4}-\d{2}-\d{2}$/.test(value)
            ? `${value}T00:00:00`
            : value
    );

    if (Number.isNaN(parsed.getTime())) {
return value;
}

    return new Intl.DateTimeFormat('en-US', {
        month: 'short',
        day: 'numeric',
        year: 'numeric',
        timeZone: 'Asia/Manila',
    }).format(parsed);
}

function formatTime(value?: string | null) {
    if (!value || /^\d{4}-\d{2}-\d{2}$/.test(value)) {
        return '';
    }

    const parsed = new Date(value);

    if (Number.isNaN(parsed.getTime())) {
return '';
}

    return new Intl.DateTimeFormat('en-US', {
        hour: 'numeric',
        minute: '2-digit',
        timeZone: 'Asia/Manila',
    }).format(parsed);
}

function announcementDate(item: Announcement) {
    return item.published_at ?? null;
}

function recipientsSummary(item: Announcement) {
    const audience = item.audience_labels?.length
        ? item.audience_labels.join(' & ')
        : recipientsLabel(item.audience);
    const count = item.recipient_count ?? 0;

    return count > 0 ? `${audience} · ${count} notified` : audience;
}

function recipientsLabel(value?: string[] | string | null) {
    if (!value) {
return '—';
}

    const roles = (Array.isArray(value) ? value : [value])
        .map(role => String(role).toLowerCase());

    const operator = roles.includes('operator');
    const passenger =
        roles.includes('passenger') || roles.includes('user');

    if (operator && passenger) {
return 'All Users';
}

    if (operator) {
return 'Boat Operators';
}

    if (passenger) {
return 'Passengers';
}

    return roles.join(', ');
}

function statusLabel(value?: string | null) {
    if (!value) {
return 'Posted';
}

    const status = value.toLowerCase();

    return status.charAt(0).toUpperCase() + status.slice(1);
}

function statusClass(value?: string | null) {
    const status = String(value ?? 'posted').toLowerCase();

    if (['active', 'posted', 'published'].includes(status)) {
        return 'bg-emerald-50 text-emerald-700';
    }

    if (status === 'lifted') {
        return 'bg-teal-50 text-teal-700';
    }

    return 'bg-slate-100 text-slate-600';
}

// =====================================================
// WEATHER SUSPENSIONS
// =====================================================

function suspensionStatus(item: WeatherSuspension) {
    return String(item.status ?? '').toLowerCase();
}

function isActive(item: WeatherSuspension) {
    return ['active', 'ongoing', 'suspended'].includes(
        suspensionStatus(item)
    );
}

function suspensionCode(item: WeatherSuspension) {
    return item.suspension_id || item.code || String(item.id);
}

function routeLabel(value?: string[] | string | null) {
    if (!value) {
return 'All routes';
}

    if (typeof value === 'string') {
        return value.toLowerCase() === 'all'
            ? 'All routes'
            : value;
    }

    if (!value.length || value.includes('all')) {
        return 'All routes';
    }

    return value.join(', ');
}

function reasonLabel(value?: string | null) {
    if (!value) {
return 'Not specified';
}

    return value
        .replace(/_/g, ' ')
        .replace(/\b\w/g, letter => letter.toUpperCase());
}

const sortedSuspensions = computed(() =>
    [...suspensionRecords.value].sort((a, b) => {
        const activeFirst =
            Number(isActive(b)) - Number(isActive(a));

        if (activeFirst !== 0) {
return activeFirst;
}

        const dateA =
            Date.parse(a.affected_date || a.created_at || '') || 0;

        const dateB =
            Date.parse(b.affected_date || b.created_at || '') || 0;

        return dateB - dateA;
    })
);

const activeSuspensionCount = computed(() =>
    suspensionRecords.value.filter(isActive).length
);

const visibleSuspensions = computed(() =>
    showAllSuspensions.value
        ? sortedSuspensions.value
        : sortedSuspensions.value.slice(0, 3)
);

// =====================================================
// LIFT WEATHER SUSPENSION - PATCH FIX
// =====================================================

function requestLift(item: WeatherSuspension) {
    if (!isActive(item) || liftingSuspensionId.value !== null) {
        return;
    }

    confirmingLift.value = item;
}

function cancelLift() {
    if (liftingSuspensionId.value !== null) {
return;
}

    confirmingLift.value = null;
}

function confirmLift() {
    const item = confirmingLift.value;

    if (
        !item ||
        !isActive(item) ||
        liftingSuspensionId.value !== null
    ) {
        return;
    }

    liftingSuspensionId.value = item.id;

    // FIXED: Laravel route requires PATCH, not POST.
    router.patch(
        `/weather-suspensions/${item.id}/lift`,
        {},
        {
            preserveScroll: true,

            onSuccess: () => {
                confirmingLift.value = null;
                selectedSuspension.value = null;
            },

            onError: errors => {
                console.error(
                    'Failed to lift weather suspension:',
                    errors
                );

                window.alert(
                    'Unable to lift the weather suspension. ' +
                    'Please try again.'
                );
            },

            onFinish: () => {
                liftingSuspensionId.value = null;
            },
        }
    );
}

// =====================================================
// SEARCH, FILTER AND PAGINATION
// =====================================================

const filteredAnnouncements = computed(() => {
    const keyword = search.value.trim().toLowerCase();

    return announcementRecords.value.filter(item => {
        const category = normalizeCategory(item.category);

        const matchesCategory =
            categoryFilter.value === 'all' ||
            categoryFilter.value === category;

        const searchable = [
            item.title,
            item.body,
            item.message,
            categoryLabel(item.category),
            recipientsLabel(item.audience),
        ]
            .filter(Boolean)
            .join(' ')
            .toLowerCase();

        return (
            matchesCategory &&
            (!keyword || searchable.includes(keyword))
        );
    });
});

const totalPages = computed(() =>
    Math.max(
        1,
        Math.ceil(filteredAnnouncements.value.length / perPage)
    )
);

const paginatedAnnouncements = computed(() => {
    const start = (currentPage.value - 1) * perPage;

    return filteredAnnouncements.value.slice(
        start,
        start + perPage
    );
});

watch([search, categoryFilter], () => {
    currentPage.value = 1;
});

watch(totalPages, value => {
    if (currentPage.value > value) {
        currentPage.value = value;
    }
});

function changePage(page: number) {
    if (page < 1 || page > totalPages.value) {
return;
}

    currentPage.value = page;
}
</script>

<template>
    <Head title="Announcements" />

    <div class="min-h-screen bg-[#f7f9fc] px-4 py-6 sm:px-6 lg:px-8 dark:bg-[#0b1b30] dark:text-slate-100">
        <div class="mx-auto max-w-[1500px] space-y-6">

            <!-- PAGE HEADER -->
        <div
                class="flex flex-col justify-between gap-4 sm:flex-row sm:items-center"
        >
            <div>
                <h1
                        class="text-2xl font-bold tracking-tight text-[#0f4c8a] dark:text-sky-100"
                >
                    Announcements
                </h1>

                    <p class="mt-1 text-sm text-slate-500">
                        Manage weather advisories, trip delays,
                        general notices, and emergency updates.
                </p>
            </div>

            <button
                type="button"
                    class="inline-flex min-h-11 items-center justify-center gap-2 rounded-xl bg-blue-600 px-5 text-sm font-semibold text-white shadow-sm transition hover:bg-blue-700"
                    @click="showCreateModal = true"
            >
                    <Plus class="size-5" />
                New Announcement
            </button>
        </div>

            <!-- SUMMARY CARDS -->
            <StatCards :items="summaryCards" />

            <!-- WEATHER SUSPENSIONS -->
            <section
                class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm dark:bg-[#102036] dark:text-slate-100 dark:border-white/10"
            >
                <div
                    class="flex flex-wrap items-start justify-between gap-3 border-b border-slate-100 px-5 py-5"
                >
                    <div class="flex items-start gap-3">
                        <div
                            class="flex size-11 shrink-0 items-center justify-center rounded-xl bg-red-50 text-red-600"
                        >
                            <CloudRain class="size-5" />
                        </div>

                        <div>
                            <h2 class="text-base font-bold text-slate-900">
                                Active Weather Suspensions
                            </h2>

                            <p class="mt-1 text-xs leading-5 text-slate-500">
                                Weather suspension records and affected routes.
                                Active records appear first.
                            </p>
                        </div>
                    </div>

                    <span
                        class="rounded-full bg-red-50 px-3 py-1 text-xs font-bold text-red-600"
                    >
                        {{ activeSuspensionCount }} Active
                    </span>
                </div>

                <!-- SUSPENSION LIST -->
                <ul
                    v-if="visibleSuspensions.length"
                    class="divide-y divide-slate-100"
                >
                    <li
                        v-for="item in visibleSuspensions"
                        :key="item.id"
                        class="flex flex-wrap items-center gap-4 px-5 py-4 transition hover:bg-slate-50"
                        :class="isActive(item) ? 'border-l-4 border-l-red-500 bg-red-50/30' : 'border-l-4 border-l-transparent'"
                    >
                        <div
                            class="flex size-10 shrink-0 items-center justify-center rounded-xl"
                            :class="isActive(item) ? 'bg-red-600 text-white' : 'bg-slate-100 text-slate-500'"
                        >
                            <CloudRain class="size-5" />
                        </div>

                        <div class="min-w-[180px] flex-1">
                            <div class="flex flex-wrap items-center gap-2">
                                <button
                                    type="button"
                                    class="text-sm font-bold text-blue-700 hover:underline"
                                    @click="selectedSuspension = item"
                                >
                                    {{ suspensionCode(item) }}
                                </button>
                                <span class="text-xs font-medium text-slate-500">{{ item.reason_label || reasonLabel(item.reason) }}</span>
                            </div>
                            <p class="mt-0.5 text-xs text-slate-500">
                                Posted {{ item.declared_date_label || '—' }}
                                <template v-if="item.declared_time_label"> · {{ item.declared_time_label }}</template>
                                <template v-if="item.declared_by_name"> · {{ item.declared_by_name }}</template>
                            </p>
                        </div>

                        <dl class="grid min-w-[260px] flex-[1.4] grid-cols-2 gap-x-4 gap-y-1 text-xs">
                            <div class="flex items-center gap-1.5 text-slate-600">
                                <CalendarDays class="size-3.5 shrink-0 text-ink-muted" />
                                <dt class="sr-only">Affected date</dt>
                                <dd class="font-medium">{{ item.affected_date_label || formatDate(item.affected_date) }}</dd>
                            </div>
                            <div class="flex items-center gap-1.5 text-slate-600">
                                <Clock3 class="size-3.5 shrink-0 text-ink-muted" />
                                <dt class="sr-only">Duration</dt>
                                <dd>{{ item.duration_label || 'Whole Day' }}</dd>
                            </div>
                            <div class="col-span-2 flex min-w-0 items-center gap-1.5 text-slate-600">
                                <MapPin class="size-3.5 shrink-0 text-ink-muted" />
                                <dt class="sr-only">Routes</dt>
                                <dd class="truncate">{{ item.affected_routes_label || routeLabel(item.affected_routes) }}</dd>
                            </div>
                        </dl>

                        <span
                            class="inline-flex shrink-0 rounded-full px-3 py-1 text-xs font-semibold"
                            :class="isActive(item) ? 'bg-red-600 text-white' : 'bg-emerald-50 text-emerald-700'"
                        >
                            {{ item.status_label || statusLabel(item.status) }}
                        </span>

                        <div class="flex shrink-0 items-center gap-2">
                            <button
                                type="button"
                                class="rounded-lg px-3 py-2 text-xs font-semibold text-blue-600 hover:bg-blue-50"
                                @click="selectedSuspension = item"
                            >
                                View
                            </button>
                            <button
                                v-if="isActive(item)"
                                type="button"
                                :disabled="liftingSuspensionId !== null"
                                class="rounded-lg bg-emerald-600 px-3 py-2 text-xs font-semibold whitespace-nowrap text-white transition hover:bg-emerald-700 disabled:opacity-50"
                                @click="requestLift(item)"
                            >
                                {{ liftingSuspensionId === item.id ? 'Lifting...' : 'Lift hold' }}
                            </button>
                        </div>
                    </li>
                </ul>

                <!-- EMPTY STATE -->
                <div v-else class="px-5 py-10 text-center">
                    <CloudRain
                        class="mx-auto size-8 text-slate-300"
                    />

                    <p class="mt-3 text-sm text-slate-500">
                        No weather suspension records available.
                    </p>
                </div>

                <!-- VIEW ALL -->
                <div
                    v-if="sortedSuspensions.length > 3"
                    class="border-t border-slate-100 px-5 py-3 text-center"
                >
                    <button
                        type="button"
                        class="inline-flex items-center gap-2 text-sm font-semibold text-blue-600 hover:text-blue-800"
                        @click="showAllSuspensions = !showAllSuspensions"
                    >
                        {{
                            showAllSuspensions
                                ? 'Show fewer suspensions'
                                : 'View all suspensions'
                        }}

                        <ArrowRight class="size-4" />
            </button>
        </div>
            </section>

            <!-- ANNOUNCEMENTS TABLE -->
        <section
                class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm dark:bg-[#102036] dark:text-slate-100 dark:border-white/10"
        >
                <!-- TABLE HEADER -->
            <div
                    class="flex flex-col justify-between gap-4 border-b border-slate-100 px-5 py-5 lg:flex-row lg:items-center"
            >
                <div>
                        <h2 class="text-base font-bold text-slate-900">
                            Announcements
                    </h2>

                        <p class="mt-1 text-xs text-slate-500">
                            Browse all weather, trip delay, general,
                            and emergency announcements.
                        </p>
                    </div>

                    <!-- SEARCH AND FILTER -->
                    <div class="flex flex-col gap-3 sm:flex-row">
                        <div class="relative">
                            <Search
                                class="absolute left-3 top-1/2 size-4 -translate-y-1/2 text-ink-muted"
                            />

                            <input
                                v-model="search"
                                type="search"
                                placeholder="Search announcements..."
                                class="h-10 w-full rounded-xl border border-slate-200 bg-white pl-10 pr-4 text-sm outline-none focus:border-blue-400 focus:ring-2 focus:ring-blue-100 sm:w-64 dark:bg-[#102036] dark:text-slate-100 dark:border-white/10"
                            />
                        </div>

                        <select
                            v-model="categoryFilter"
                            class="h-10 rounded-xl border border-slate-200 bg-white px-3 text-sm text-slate-700 outline-none focus:border-blue-400 dark:bg-[#102036] dark:text-slate-100 dark:border-white/10"
                        >
                            <option value="all">All Types</option>
                            <option value="weather">Weather</option>
                            <option value="delay">Trip Delay</option>
                            <option value="general">General Notice</option>
                            <option value="emergency">
                                Emergency Advisory
                            </option>
                        </select>
                    </div>
                </div>

                <!-- TABLE -->
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[850px] text-left">
                        <thead>
                            <tr
                                class="border-b border-slate-100 bg-slate-50 text-[11px] font-bold uppercase tracking-wide text-slate-500"
                            >
                                <th class="px-5 py-4">
                                    Announcement
                                </th>

                                <th class="px-4 py-4">
                                    Type
                                </th>

                                <th class="px-4 py-4">
                                    Recipients
                                </th>

                                <th class="px-4 py-4">
                                    Date Posted
                                </th>

                                <th class="px-4 py-4">
                                    Status
                                </th>

                                <th class="px-5 py-4 text-right">
                                    Actions
                                </th>
                            </tr>
                        </thead>

                        <tbody class="divide-y divide-slate-100">
                            <tr
                                v-for="item in paginatedAnnouncements"
                                :key="item.id"
                                class="transition hover:bg-slate-50"
                            >
                                <!-- ANNOUNCEMENT -->
                                <td class="px-5 py-4">
                                    <div class="flex items-start gap-3">
                                        <div
                                            class="flex size-10 shrink-0 items-center justify-center rounded-xl"
                                            :class="categoryIconClass(item.category)"
                                        >
                                            <component
                                                :is="categoryIcon(item.category)"
                                                class="size-5"
                                            />
                                        </div>

                                        <div class="min-w-0">
                                            <p
                                                class="max-w-[340px] truncate text-sm font-semibold text-slate-900"
                                            >
                                                {{ item.title }}
                                            </p>

                                            <p
                                                class="mt-1 max-w-[340px] truncate text-xs text-slate-500"
                                            >
                                                {{
                                                    item.body ||
                                                    item.message ||
                                                    'No message provided'
                                                }}
                                            </p>
                                            <p
                                                v-if="item.weather"
                                                class="mt-1 max-w-[340px] truncate text-[11px] font-medium text-blue-700"
                                            >
                                                {{ item.weather.affected_date_label }} · {{ item.weather.routes_label }}
                                            </p>
                                        </div>
                                    </div>
                                </td>

                                <!-- TYPE -->
                                <td class="px-4 py-4">
                                    <span
                                        class="inline-flex whitespace-nowrap rounded-full px-3 py-1 text-xs font-semibold"
                                        :class="categoryBadgeClass(item.category)"
                                    >
                                        {{ categoryLabel(item.category) }}
                                    </span>
                                </td>

                                <!-- RECIPIENTS -->
                                <td class="px-4 py-4">
                                    <span
                                        class="inline-flex items-center gap-2 whitespace-nowrap text-xs text-slate-600"
                                    >
                                        <Users
                                            class="size-4 text-ink-muted"
                                        />

                                        {{ recipientsSummary(item) }}
                                    </span>
                                </td>

                                <!-- DATE -->
                                <td class="px-4 py-4">
                                    <p
                                        class="whitespace-nowrap text-xs font-medium text-slate-700"
                                    >
                                        {{ item.posted_date_label || formatDate(announcementDate(item)) }}
                                        <span class="text-ink-muted">·</span>
                                        {{ item.posted_time_label || formatTime(announcementDate(item)) }}
                                    </p>

                                    <p class="mt-1 whitespace-nowrap text-xs text-ink-muted">
                                        {{ item.author_name || 'Admin' }}<template v-if="item.posted_ago"> · {{ item.posted_ago }}</template>
                                    </p>
                                </td>

                                <!-- STATUS -->
                                <td class="px-4 py-4">
                                    <span
                                        class="inline-flex rounded-full px-3 py-1 text-xs font-semibold"
                                        :class="statusClass(item.status)"
                                    >
                                        {{ item.status_label || statusLabel(item.status) }}
                                    </span>
                                </td>

                                <!-- ACTION -->
                                <td class="px-5 py-4 text-right">
                                    <button
                                        type="button"
                                        class="rounded-lg px-3 py-2 text-xs font-semibold text-blue-600 hover:bg-blue-50"
                                        @click="selectedAnnouncement = item"
                                    >
                                        View
                                    </button>
                                </td>
                            </tr>

                            <!-- EMPTY STATE -->
                            <tr v-if="!paginatedAnnouncements.length">
                                <td
                                    colspan="6"
                                    class="px-5 py-14 text-center"
                                >
                                    <Megaphone
                                        class="mx-auto size-9 text-slate-300"
                                    />

                                    <p
                                        class="mt-3 text-sm font-semibold text-slate-700"
                                    >
                                        No announcements found
                                    </p>

                                    <p class="mt-1 text-xs text-ink-muted">
                                        Try another search or category.
                                    </p>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- PAGINATION -->
                <div
                    class="flex flex-col justify-between gap-3 border-t border-slate-100 px-5 py-4 sm:flex-row sm:items-center"
                >
                    <p class="text-xs text-slate-500">
                        Showing
                        {{
                            filteredAnnouncements.length
                                ? (currentPage - 1) * perPage + 1
                                : 0
                        }}
                        –
                        {{
                            Math.min(
                                currentPage * perPage,
                                filteredAnnouncements.length
                            )
                        }}
                        of {{ filteredAnnouncements.length }} announcements
                    </p>

                    <div class="flex items-center gap-2">
                        <button
                            type="button"
                            :disabled="currentPage <= 1"
                            class="flex size-9 items-center justify-center rounded-lg border border-slate-200 text-slate-600 hover:bg-slate-50 disabled:opacity-40"
                            @click="changePage(currentPage - 1)"
                        >
                            <ChevronLeft class="size-4" />
                        </button>

                        <span class="px-2 text-xs font-medium text-slate-600">
                            Page {{ currentPage }} of {{ totalPages }}
                        </span>

                        <button
                            type="button"
                            :disabled="currentPage >= totalPages"
                            class="flex size-9 items-center justify-center rounded-lg border border-slate-200 text-slate-600 hover:bg-slate-50 disabled:opacity-40"
                            @click="changePage(currentPage + 1)"
                        >
                            <ChevronRight class="size-4" />
                        </button>
                    </div>
                </div>
            </section>
            </div>

        <!-- CREATE ANNOUNCEMENT MODAL -->
        <AddAnnouncementModal
            v-model:open="showCreateModal"
            :weather-route-options="weatherRouteOptions"
        />

        <!-- VIEW ANNOUNCEMENT MODAL -->
        <div
            v-if="selectedAnnouncement"
            class="fixed inset-0 z-[100] flex items-center justify-center bg-slate-950/50 p-4"
            @click.self="selectedAnnouncement = null"
        >
            <div
                role="dialog"
                aria-modal="true"
                aria-label="Announcement details"
                class="w-full max-w-xl overflow-hidden rounded-2xl bg-white shadow-2xl dark:bg-[#102036] dark:text-slate-100"
            >
                <div
                    class="flex items-start justify-between gap-4 border-b border-slate-100 px-6 py-5"
                >
                    <div>
                        <span
                            class="inline-flex rounded-full px-3 py-1 text-xs font-semibold"
                            :class="categoryBadgeClass(selectedAnnouncement.category)"
                        >
                            {{ categoryLabel(selectedAnnouncement.category) }}
                        </span>

                        <h3 class="mt-3 text-lg font-bold text-slate-900">
                            {{ selectedAnnouncement.title }}
                        </h3>
                    </div>

                    <button
                        type="button"
                        aria-label="Close"
                        class="rounded-lg p-2 text-slate-500 hover:bg-slate-100"
                        @click="selectedAnnouncement = null"
                    >
                        <X class="size-5" />
                    </button>
            </div>

                <div
                    class="max-h-[65vh] space-y-5 overflow-y-auto px-6 py-6"
                >
                    <div>
                        <p
                            class="text-xs font-bold uppercase tracking-wide text-ink-muted"
                        >
                            Message
                        </p>

                        <p
                            class="mt-2 whitespace-pre-wrap text-sm leading-7 text-slate-700"
                        >
                            {{
                                selectedAnnouncement.body ||
                                selectedAnnouncement.message ||
                                'No message provided.'
                            }}
                                </p>
                            </div>

                    <dl
                        class="grid gap-4 border-t border-slate-100 pt-5 sm:grid-cols-2"
                    >
                        <div>
                            <dt class="text-xs text-ink-muted">Date Posted</dt>
                            <dd class="mt-1 text-sm font-semibold text-slate-800">
                                {{ selectedAnnouncement.posted_date_label || formatDate(announcementDate(selectedAnnouncement)) }}
                            </dd>
                        </div>
                        <div>
                            <dt class="text-xs text-ink-muted">Time Posted</dt>
                            <dd class="mt-1 text-sm font-semibold text-slate-800">
                                {{ selectedAnnouncement.posted_time_label || formatTime(announcementDate(selectedAnnouncement)) || '—' }}
                                <span v-if="selectedAnnouncement.posted_ago" class="font-normal text-ink-muted">
                                    ({{ selectedAnnouncement.posted_ago }})
                                </span>
                            </dd>
                        </div>
                        <div>
                            <dt class="text-xs text-ink-muted">Posted By</dt>
                            <dd class="mt-1 text-sm font-semibold text-slate-800">
                                {{ selectedAnnouncement.author_name || 'Admin' }}
                                <span v-if="selectedAnnouncement.author_role" class="block text-xs font-normal text-ink-muted">
                                    {{ selectedAnnouncement.author_role }}
                                </span>
                            </dd>
                        </div>
                        <div>
                            <dt class="text-xs text-ink-muted">Recipients</dt>
                            <dd class="mt-1 text-sm font-semibold text-slate-800">
                                {{ selectedAnnouncement.audience_labels?.length ? selectedAnnouncement.audience_labels.join(' & ') : recipientsLabel(selectedAnnouncement.audience) }}
                                <span class="block text-xs font-normal text-ink-muted">
                                    {{ selectedAnnouncement.recipient_count ?? 0 }} notified
                                </span>
                            </dd>
                        </div>
                    </dl>

                    <div
                        v-if="selectedAnnouncement.weather"
                        class="rounded-xl border border-blue-100 bg-blue-50/60 p-4"
                    >
                        <div class="flex items-center justify-between gap-3">
                            <p class="text-xs font-bold tracking-wide text-blue-700 uppercase">
                                Trip Suspension · {{ selectedAnnouncement.weather.code }}
                            </p>
                            <span
                                class="inline-flex rounded-full px-2.5 py-0.5 text-xs font-semibold"
                                :class="statusClass(selectedAnnouncement.status)"
                            >
                                {{ selectedAnnouncement.status_label }}
                            </span>
                        </div>
                        <dl class="mt-3 grid gap-3 text-sm sm:grid-cols-2">
                            <div>
                                <dt class="text-xs text-slate-500">Reason</dt>
                                <dd class="font-semibold text-slate-800">{{ selectedAnnouncement.weather.reason_label }}</dd>
                            </div>
                            <div>
                                <dt class="text-xs text-slate-500">Affected Date</dt>
                                <dd class="font-semibold text-slate-800">{{ selectedAnnouncement.weather.affected_date_label || '—' }}</dd>
                            </div>
                            <div>
                                <dt class="text-xs text-slate-500">Duration</dt>
                                <dd class="font-semibold text-slate-800">{{ selectedAnnouncement.weather.duration_label }}</dd>
                            </div>
                            <div>
                                <dt class="text-xs text-slate-500">Affected Routes</dt>
                                <dd class="font-semibold text-slate-800">{{ selectedAnnouncement.weather.routes_label }}</dd>
                            </div>
                            <div>
                                <dt class="text-xs text-slate-500">Trips Suspended</dt>
                                <dd class="font-semibold text-slate-800">{{ selectedAnnouncement.weather.affected_trips_count }}</dd>
                            </div>
                            <div>
                                <dt class="text-xs text-slate-500">Rentals on Hold</dt>
                                <dd class="font-semibold text-slate-800">{{ selectedAnnouncement.weather.affected_rentals_count }}</dd>
                            </div>
                            <div v-if="selectedAnnouncement.weather.lifted_at_label" class="sm:col-span-2">
                                <dt class="text-xs text-slate-500">Lifted</dt>
                                <dd class="font-semibold text-slate-800">
                                    {{ selectedAnnouncement.weather.lifted_at_label }}
                                    <span v-if="selectedAnnouncement.weather.lifted_by_name" class="font-normal text-slate-500">
                                        by {{ selectedAnnouncement.weather.lifted_by_name }}
                                    </span>
                                </dd>
                            </div>
                        </dl>
                    </div>
                </div>

                <div
                    class="flex justify-end border-t border-slate-100 px-6 py-4"
                >
                    <button
                        type="button"
                        class="rounded-xl bg-blue-600 px-5 py-2.5 text-sm font-semibold text-white hover:bg-blue-700"
                        @click="selectedAnnouncement = null"
                    >
                        Close
                    </button>
                </div>
            </div>
        </div>

        <!-- VIEW WEATHER SUSPENSION MODAL -->
        <div
            v-if="selectedSuspension"
            class="fixed inset-0 z-[100] flex items-center justify-center bg-slate-950/50 p-4"
            @click.self="selectedSuspension = null"
        >
            <div
                role="dialog"
                aria-modal="true"
                aria-label="Weather suspension details"
                class="w-full max-w-lg overflow-hidden rounded-2xl bg-white shadow-2xl dark:bg-[#102036] dark:text-slate-100"
            >
                <div
                    class="flex items-start justify-between gap-4 border-b border-slate-100 px-6 py-5"
                >
                    <div>
                        <h3 class="text-lg font-bold text-slate-900">
                            Weather Suspension Details
                        </h3>

                        <p class="mt-1 text-sm text-blue-600">
                            {{ suspensionCode(selectedSuspension) }}
                        </p>
                    </div>

                    <button
                        type="button"
                        aria-label="Close"
                        class="rounded-lg p-2 text-slate-500 hover:bg-slate-100"
                        @click="selectedSuspension = null"
                    >
                        <X class="size-5" />
                    </button>
                </div>

                <div class="grid gap-5 px-6 py-6 sm:grid-cols-2">
                    <div>
                        <p class="text-xs text-ink-muted">
                            Reason
                        </p>

                        <p class="mt-1 text-sm font-semibold">
                            {{ reasonLabel(selectedSuspension.reason) }}
                        </p>
                    </div>

                    <div>
                        <p class="text-xs text-ink-muted">
                            Status
                        </p>

                        <p class="mt-1 text-sm font-semibold">
                            {{ statusLabel(selectedSuspension.status) }}
                        </p>
                    </div>

                    <div>
                        <p class="text-xs text-ink-muted">
                            Affected Date
                        </p>

                        <p class="mt-1 text-sm font-semibold">
                            {{ formatDate(selectedSuspension.affected_date) }}
                        </p>
                    </div>

                    <div>
                        <p class="text-xs text-ink-muted">
                            Affected Routes
                        </p>

                        <p class="mt-1 text-sm font-semibold">
                            {{ selectedSuspension.affected_routes_label || routeLabel(selectedSuspension.affected_routes) }}
                        </p>
                    </div>

                    <div>
                        <p class="text-xs text-ink-muted">
                            Duration
                        </p>

                        <p class="mt-1 text-sm font-semibold">
                            {{ selectedSuspension.duration_label || 'Whole Day' }}
                        </p>
                    </div>

                    <div>
                        <p class="text-xs text-ink-muted">
                            Posted
                        </p>

                        <p class="mt-1 text-sm font-semibold">
                            {{ selectedSuspension.declared_date_label || '—' }}
                            <span class="font-normal text-slate-500">{{ selectedSuspension.declared_time_label }}</span>
                        </p>
                        <p v-if="selectedSuspension.declared_by_name || selectedSuspension.declared_ago" class="text-xs text-ink-muted">
                            {{ selectedSuspension.declared_by_name }}<template v-if="selectedSuspension.declared_by_name && selectedSuspension.declared_ago"> · </template>{{ selectedSuspension.declared_ago }}
                        </p>
                    </div>

                    <div>
                        <p class="text-xs text-ink-muted">
                            Trips Suspended
                        </p>
                        <p class="mt-1 text-sm font-semibold">{{ selectedSuspension.affected_trips_count ?? 0 }}</p>
                    </div>

                    <div>
                        <p class="text-xs text-ink-muted">
                            Rentals on Hold
                        </p>
                        <p class="mt-1 text-sm font-semibold">{{ selectedSuspension.affected_rentals_count ?? 0 }}</p>
                    </div>

                    <div v-if="selectedSuspension.lifted_at_label" class="sm:col-span-2">
                        <p class="text-xs text-ink-muted">
                            Lifted
                        </p>
                        <p class="mt-1 text-sm font-semibold">
                            {{ selectedSuspension.lifted_at_label }}
                            <span v-if="selectedSuspension.lifted_by_name" class="font-normal text-slate-500">by {{ selectedSuspension.lifted_by_name }}</span>
                        </p>
                    </div>

                    <div v-if="selectedSuspension.announcement_message" class="sm:col-span-2">
                        <p class="text-xs text-ink-muted">
                            Announcement
                        </p>
                        <p class="mt-1 rounded-xl bg-slate-50 p-3 text-sm leading-6 whitespace-pre-wrap text-slate-700">
                            {{ selectedSuspension.announcement_message }}
                        </p>
                    </div>
                </div>

                <div
                    class="flex justify-end gap-3 border-t border-slate-100 px-6 py-4"
                >
                    <button
                        type="button"
                        class="rounded-xl border border-slate-200 px-5 py-2.5 text-sm font-semibold text-slate-700 hover:bg-slate-50"
                        @click="selectedSuspension = null"
                    >
                        Close
                    </button>

                    <button
                        v-if="isActive(selectedSuspension)"
                        type="button"
                        class="rounded-xl bg-red-600 px-5 py-2.5 text-sm font-semibold text-white hover:bg-red-700"
                        @click="
                            requestLift(selectedSuspension);
                            selectedSuspension = null;
                        "
                    >
                        Lift Suspension
                    </button>
                </div>
            </div>
        </div>

        <!-- CONFIRM LIFT MODAL -->
        <div
            v-if="confirmingLift"
            class="fixed inset-0 z-[110] flex items-center justify-center bg-slate-950/60 p-4"
            @click.self="cancelLift"
        >
            <div
                role="alertdialog"
                aria-modal="true"
                aria-label="Confirm lift suspension"
                class="w-full max-w-md rounded-2xl bg-white p-6 shadow-2xl dark:bg-[#102036] dark:text-slate-100"
            >
                <div
                    class="flex size-12 items-center justify-center rounded-xl bg-red-50 text-red-600"
                >
                    <ShieldAlert class="size-6" />
                </div>

                <h3 class="mt-4 text-lg font-bold text-slate-900">
                    Lift Weather Suspension?
                </h3>

                <p class="mt-2 text-sm leading-6 text-slate-600">
                    Are you sure you want to lift
                    <strong>{{ suspensionCode(confirmingLift) }}</strong>?
                </p>

                <p
                    class="mt-3 rounded-xl bg-amber-50 p-4 text-sm leading-6 text-amber-800"
                >
                    This will end the weather suspension.
                    Previously suspended trips and rentals will not
                    automatically be rescheduled.
                </p>

                <div class="mt-6 flex justify-end gap-3">
                    <button
                        type="button"
                        :disabled="liftingSuspensionId !== null"
                        class="rounded-xl border border-slate-200 px-5 py-2.5 text-sm font-semibold text-slate-700 hover:bg-slate-50 disabled:opacity-50"
                        @click="cancelLift"
                    >
                        Cancel
                    </button>

                    <button
                        type="button"
                        :disabled="liftingSuspensionId !== null"
                        class="rounded-xl bg-red-600 px-5 py-2.5 text-sm font-semibold text-white hover:bg-red-700 disabled:opacity-50"
                        @click="confirmLift"
                    >
                        {{
                            liftingSuspensionId !== null
                                ? 'Lifting...'
                                : 'Yes, Lift Suspension'
                        }}
                    </button>
                </div>
            </div>
        </div>
    </div>
</template>
