<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { Eye, MapPin, Pencil, Plus, Route, Ship } from '@lucide/vue';
import { computed, ref } from 'vue';
import AddRouteModal from '@/components/AddRouteModal.vue';
import StatCards from '@/components/admin/StatCards.vue';
import type { StatCardItem } from '@/components/admin/StatCards.vue';
import ViewRouteModal from '@/components/ViewRouteModal.vue';
import type { OrganizationRouteRecord } from '@/types/route';

const props = defineProps<{
    routes: OrganizationRouteRecord[];
    home_port?: string;
    stats: {
        total: number;
        active: number;
        regular: number;
        exclusive?: number;
    };
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Route Management', href: '/routes' }],
    },
});

type RouteCardKey = 'all' | 'active' | 'regular' | 'exclusive';

const addOpen = ref(false);
const viewOpen = ref(false);
const selectedRoute = ref<OrganizationRouteRecord | null>(null);
const editingRoute = ref<OrganizationRouteRecord | null>(null);
const selectedCard = ref<RouteCardKey>('all');

const regularRoutes = computed(() =>
    props.routes.filter((route) => route.trip_type === 'regular_public'),
);
const exclusiveRoutes = computed(() =>
    props.routes.filter((route) => route.trip_type === 'private_booking'),
);

const cards = computed<StatCardItem[]>(() => [
    { key: 'all', title: 'Total Routes', value: props.stats.total, hint: 'Configured for this organization', icon: Route, tone: 'blue' },
    { key: 'active', title: 'Active Routes', value: props.stats.active, hint: 'Available for trip scheduling', icon: MapPin, tone: 'green' },
    { key: 'regular', title: 'Regular Trip Routes', value: props.stats.regular, hint: 'Used in regular trip rotation', icon: Route, tone: 'indigo' },
    { key: 'exclusive', title: 'Rental Destinations', value: props.stats.exclusive ?? exclusiveRoutes.value.length, hint: 'Private rental destinations only', icon: Ship, tone: 'violet' },
]);

const routeSections = computed(() => {
    const sections = [
        {
            key: 'regular' as const,
            title: 'Regular Trip Routes',
            hint: 'These routes are used in Regular Trip Rotation.',
            empty: 'No regular trip routes added yet.',
            items: regularRoutes.value,
        },
        {
            key: 'exclusive' as const,
            title: 'Exclusive Boat Rental Destinations',
            hint: 'Private rental destinations are kept separate from regular routes.',
            empty: 'No rental destinations added yet.',
            items: exclusiveRoutes.value,
        },
    ];

    return sections
        .filter((section) => {
            if (selectedCard.value === 'regular') {
                return section.key === 'regular';
            }

            if (selectedCard.value === 'exclusive') {
                return section.key === 'exclusive';
            }

            return true;
        })
        .map((section) => ({
            ...section,
            items:
                selectedCard.value === 'active'
                    ? section.items.filter((route) => route.is_active)
                    : section.items,
            empty:
                selectedCard.value === 'active'
                    ? 'No active routes in this group.'
                    : section.empty,
        }));
});

function selectCard(key: RouteCardKey) {
    selectedCard.value = selectedCard.value === key ? 'all' : key;
}

function openAdd() {
    editingRoute.value = null;
    addOpen.value = true;
}

function openEdit(route: OrganizationRouteRecord) {
    viewOpen.value = false;
    selectedRoute.value = route;
    editingRoute.value = route;
    addOpen.value = true;
}

function openView(route: OrganizationRouteRecord) {
    selectedRoute.value = route;
    viewOpen.value = true;
}

function onAddOpenChange(open: boolean) {
    addOpen.value = open;

    if (!open) {
        editingRoute.value = null;
    }
}

function toggleStatus(route: OrganizationRouteRecord) {
    router.patch(
        `/routes/${route.id}/status`,
        { is_active: !route.is_active },
        { preserveScroll: true },
    );
}
</script>

<template>
    <Head title="Route Management" />

    <div class="flex flex-1 flex-col gap-6 p-6">
        <div
            class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between"
        >
            <div>
                <h1
                    class="text-2xl font-bold tracking-tight text-[#0f4c8a] dark:text-sky-100"
                >
                    Route Management
                </h1>
                <p
                    class="mt-1 flex items-center gap-2 text-sm text-ink-muted dark:text-slate-400"
                >
                    <svg
                        class="size-4 text-[#1b7ad8]"
                        viewBox="0 0 24 24"
                        fill="none"
                        aria-hidden="true"
                    >
                        <path
                            d="M2 12c2.5-3 5-3 7.5 0s5 3 7.5 0 5-3 7 0"
                            stroke="currentColor"
                            stroke-width="1.8"
                            stroke-linecap="round"
                        />
                    </svg>
                    Manage the routes available for regular and other boat trips
                    in your organization.
                </p>
            </div>

            <button
                type="button"
                class="inline-flex h-11 items-center justify-center gap-2 rounded-xl bg-[#1b7ad8] px-4 text-sm font-semibold text-white shadow-[0_8px_18px_rgba(27,122,216,0.28)] transition hover:bg-[#1567c4]"
                @click="openAdd"
            >
                <Plus class="size-4" />
                Add Route
            </button>
        </div>

        <StatCards :items="cards" :active="selectedCard" selectable @select="(key) => selectCard(key as RouteCardKey)" />

        <section
            v-for="section in routeSections"
            :key="section.title"
            class="overflow-hidden rounded-2xl border border-[#d7e8f6] bg-white shadow-sm dark:border-white/10 dark:bg-white/5"
        >
            <div
                class="flex flex-col gap-2 border-b border-[#e8f1f8] px-5 py-4 sm:flex-row sm:items-center sm:justify-between dark:border-white/10"
            >
                <div>
                    <h2 class="font-semibold text-[#0f4c8a] dark:text-sky-100">
                        {{ section.title }}
                    </h2>
                    <p class="text-xs text-ink-muted dark:text-slate-400">
                        {{ section.hint }}
                    </p>
                </div>
                <p class="text-xs font-medium text-ink-soft">
                    {{ section.items.length }}
                    {{ section.items.length === 1 ? 'item' : 'items' }}
                </p>
            </div>

            <div v-if="section.items.length === 0" class="px-5 py-12 text-center">
                <MapPin class="mx-auto size-10 text-[#c5def5]" />
                <p class="mt-3 font-medium text-[#0f4c8a] dark:text-sky-100">
                    {{ section.empty }}
                </p>
                <button
                    type="button"
                    class="mt-5 inline-flex h-10 items-center justify-center gap-2 rounded-xl bg-[#1b7ad8] px-4 text-sm font-semibold text-white transition hover:bg-[#1567c4]"
                    @click="openAdd"
                >
                    <Plus class="size-4" />
                    Add Route
                </button>
            </div>

            <div v-else class="overflow-x-auto">
                <table class="w-full min-w-[920px] text-left text-sm">
                    <thead
                        class="bg-[#f4f9fd] text-xs tracking-wide text-ink-soft uppercase dark:bg-white/5"
                    >
                        <tr>
                            <th class="px-5 py-3 font-semibold">Route Name</th>
                            <th class="px-5 py-3 font-semibold">Origin</th>
                            <th class="px-5 py-3 font-semibold">Destination</th>
                            <th class="px-5 py-3 font-semibold">Route Type</th>
                            <th class="px-5 py-3 font-semibold">Fare / Price</th>
                            <th class="px-5 py-3 font-semibold">Discounts / Down Payment</th>
                            <th class="px-5 py-3 font-semibold">Status</th>
                            <th class="px-5 py-3 font-semibold text-right">
                                Actions
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="item in section.items"
                            :key="item.id"
                            class="border-t border-[#eef4f9] dark:border-white/10"
                        >
                            <td class="px-5 py-3 font-semibold text-[#0f4c8a] dark:text-sky-100">
                                {{ item.name }}
                            </td>
                            <td class="px-5 py-3 text-ink dark:text-slate-200">
                                {{ item.origin }}
                            </td>
                            <td class="px-5 py-3 text-ink dark:text-slate-200">
                                {{ item.destination }}
                            </td>
                            <td class="px-5 py-3 text-ink dark:text-slate-200">
                                {{ item.trip_type_label }}
                            </td>
                            <td class="px-5 py-3 font-medium text-ink dark:text-slate-200">
                                {{
                                    item.trip_type === 'private_booking'
                                        ? item.rental_price_label || '—'
                                        : item.regular_fare_label || '—'
                                }}
                            </td>
                            <td class="px-5 py-3 font-medium text-ink dark:text-slate-200">
                                <template v-if="item.trip_type === 'private_booking'">
                                    {{ item.down_payment_percent_label || '—' }}
                                </template>
                                <template v-else>
                                    <span
                                        v-if="item.student_discount_percent == null && item.senior_discount_percent == null"
                                    >
                                        —
                                    </span>
                                    <span v-else>
                                        Student {{ item.student_discount_percent ?? 0 }}%
                                        · Senior {{ item.senior_discount_percent ?? 0 }}%
                                    </span>
                                </template>
                            </td>
                            <td class="px-5 py-3">
                                <span
                                    class="inline-flex rounded-full px-2.5 py-1 text-xs font-semibold"
                                    :class="
                                        item.is_active
                                            ? 'bg-emerald-50 text-emerald-700'
                                            : 'bg-amber-50 text-amber-700'
                                    "
                                >
                                    {{ item.status_label }}
                                </span>
                            </td>
                            <td class="px-5 py-3">
                                <div class="flex items-center justify-end gap-2">
                                    <button
                                        type="button"
                                        class="inline-flex h-8 items-center gap-1.5 rounded-lg border border-[#c5def5] bg-white px-2.5 text-xs font-medium text-[#1b7ad8] transition hover:bg-[#e8f4fc] dark:border-white/10 dark:bg-white/5"
                                        @click="openView(item)"
                                    >
                                        <Eye class="size-3.5" />
                                        View
                                    </button>
                                    <button
                                        type="button"
                                        class="inline-flex h-8 items-center gap-1.5 rounded-lg bg-[#1b7ad8] px-2.5 text-xs font-medium text-white transition hover:bg-[#1567c4]"
                                        @click="openEdit(item)"
                                    >
                                        <Pencil class="size-3.5" />
                                        Edit
                                    </button>
                                    <button
                                        type="button"
                                        class="inline-flex h-8 items-center rounded-lg px-2.5 text-xs font-medium transition"
                                        :class="
                                            item.is_active
                                                ? 'bg-[#fbf3e6] text-[#d4922a] hover:bg-[#f6e6c8]'
                                                : 'bg-[#e6f7f4] text-[#0d9b8a] hover:bg-[#d4f0ea]'
                                        "
                                        @click="toggleStatus(item)"
                                    >
                                        {{ item.is_active ? 'Deactivate' : 'Activate' }}
                                    </button>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>
    </div>

    <AddRouteModal
        :open="addOpen"
        :route="editingRoute"
        :home-port="home_port ?? ''"
        @update:open="onAddOpenChange"
    />
    <ViewRouteModal
        v-model:open="viewOpen"
        :route="selectedRoute"
        @edit="openEdit"
    />
</template>
