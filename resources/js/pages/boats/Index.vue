<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import {
    Check,
    ChevronDown,
    CircleOff,
    Eye,
    LockKeyhole,
    Pencil,
    Plus,
    Search,
    Ship,
    UserRound,
} from '@lucide/vue';
import { computed, onMounted, ref, watch } from 'vue';
import AddBoatModal from '@/components/AddBoatModal.vue';
import StatCards from '@/components/admin/StatCards.vue';
import type { StatCardItem } from '@/components/admin/StatCards.vue';
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
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import ViewBoatModal from '@/components/ViewBoatModal.vue';
import type { BoatRecord, BoatStatus, RentalDestinationOption, StatusOption } from '@/types/boat';

type StatusFilter = 'all' | 'available' | 'on_duty' | 'on_trip' | 'not_available';
type ListScope = 'active' | 'archived' | 'all';

const props = defineProps<{
    boats: BoatRecord[];
    stats: {
        total: number;
        available: number;
        onDuty: number;
        onTrip: number;
        notAvailable: number;
        archived: number;
    };
    statusOptions: StatusOption[];
    serviceOptions: StatusOption[];
    rentalDestinations?: RentalDestinationOption[];
    createOperatorBoatId?: number | null;
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Boat Management', href: '/boats' }],
    },
});

const addOpen = ref(false);
const viewOpen = ref(false);
const selectedBoat = ref<BoatRecord | null>(null);
const editingBoat = ref<BoatRecord | null>(null);
const resumeOperatorBoat = ref<BoatRecord | null>(null);
const archiveBoat = ref<BoatRecord | null>(null);
const archiving = ref(false);
const filter = ref<StatusFilter>('all');
const listScope = ref<ListScope>('active');
const search = ref('');

watch(
    () => props.boats,
    (boats) => {
        if (!selectedBoat.value) {
            return;
        }

        selectedBoat.value =
            boats.find((boat) => boat.id === selectedBoat.value?.id) ??
            selectedBoat.value;
    },
);

const cards = computed<StatCardItem[]>(() => [
    { key: 'all', title: 'Total Boats', value: props.stats.total, hint: 'Active boats', icon: Ship, tone: 'blue' },
    { key: 'available', title: 'Available', value: props.stats.available, hint: 'Ready for assignment', icon: Ship, tone: 'green' },
    { key: 'on_duty', title: 'On Duty', value: props.stats.onDuty, hint: 'Assigned for today', icon: UserRound, tone: 'indigo' },
    { key: 'on_trip', title: 'On Trip', value: props.stats.onTrip, hint: 'Currently at sea', icon: Ship, tone: 'violet' },
    { key: 'not_available', title: 'Not Available', value: props.stats.notAvailable, hint: 'Maintenance or unavailable', icon: CircleOff, tone: 'amber' },
]);

const scopedBoats = computed(() => {
    if (listScope.value === 'active') {
        return props.boats.filter((boat) => boat.is_active !== false);
    }

    if (listScope.value === 'archived') {
        return props.boats.filter((boat) => boat.is_active === false);
    }

    return props.boats;
});

const filteredBoats = computed(() => {
    const query = search.value.trim().toLowerCase();

    return scopedBoats.value.filter((boat) => {
        if (listScope.value === 'active' || listScope.value === 'all') {
            if (filter.value === 'available' && boat.status !== 'available') {
                return false;
            }

            if (filter.value === 'on_duty' && boat.status !== 'on_duty') {
                return false;
            }

            if (filter.value === 'on_trip' && boat.status !== 'on_trip') {
                return false;
            }

            if (
                filter.value === 'not_available' &&
                boat.status !== 'maintenance' &&
                boat.status !== 'unavailable'
            ) {
                return false;
            }
        }

        if (!query) {
            return true;
        }

        return (
            boat.name.toLowerCase().includes(query) ||
            boat.code.toLowerCase().includes(query) ||
            (boat.operator?.full_name ?? '').toLowerCase().includes(query)
        );
    });
});

const listTitle = computed(() => {
    if (listScope.value === 'archived') {
        return 'Archived Boats';
    }

    if (listScope.value === 'all') {
        return 'All Boats';
    }

    if (filter.value === 'all') {
        return 'Active Boats';
    }

    return `${cards.value.find((card) => card.key === filter.value)?.title ?? ''} Boats`;
});

function statusTone(status: BoatStatus) {
    return {
        available:
            'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-200',
        on_duty: 'bg-sky-50 text-sky-700 dark:bg-sky-500/15 dark:text-sky-200',
        on_trip:
            'bg-violet-50 text-violet-700 dark:bg-violet-500/15 dark:text-violet-200',
        maintenance:
            'bg-amber-50 text-amber-700 dark:bg-amber-500/15 dark:text-amber-200',
        unavailable:
            'bg-slate-100 text-slate-700 dark:bg-slate-500/15 dark:text-slate-200',
    }[status];
}

function selectStatusCard(key: StatusFilter) {
    listScope.value = 'active';
    filter.value = key;
}

function boatNeedingOperatorAccount(id?: number | null): BoatRecord | null {
    if (!id) {
        return null;
    }

    const boat = props.boats.find((item) => item.id === id) ?? null;

    if (!boat || boat.operator?.user_id) {
        return null;
    }

    return boat;
}

function openAdd() {
    editingBoat.value = null;
    resumeOperatorBoat.value = null;
    addOpen.value = true;
}

function openOperatorAccount(boat: BoatRecord) {
    if (!boat.operator || boat.operator.user_id) {
        return;
    }

    viewOpen.value = false;
    editingBoat.value = null;
    resumeOperatorBoat.value = boat;
    addOpen.value = true;
}

watch(
    () => props.createOperatorBoatId,
    (id) => {
        const boat = boatNeedingOperatorAccount(id);

        if (boat) {
            openOperatorAccount(boat);
        }
    },
    { immediate: true },
);

onMounted(() => {
    const params = new URLSearchParams(window.location.search);

    if (params.get('add') === '1') {
        openAdd();
        params.delete('add');
        const query = params.toString();
        window.history.replaceState(
            {},
            '',
            `${window.location.pathname}${query ? `?${query}` : ''}`,
        );
    }
});

function openEdit(boat: BoatRecord) {
    viewOpen.value = false;
    selectedBoat.value = boat;
    resumeOperatorBoat.value = null;
    editingBoat.value = boat;
    addOpen.value = true;
}

function openView(boat: BoatRecord) {
    selectedBoat.value = boat;
    viewOpen.value = true;
}

function onAddOpenChange(open: boolean) {
    addOpen.value = open;

    if (!open) {
        editingBoat.value = null;
        resumeOperatorBoat.value = null;
    }
}

const actionStatuses: { value: BoatStatus; label: string; swatch: string; item: string }[] = [
    {
        value: 'available',
        label: 'Available',
        swatch: 'bg-emerald-500',
        item: 'text-emerald-800 focus:bg-emerald-50 focus:text-emerald-900 data-[highlighted]:bg-emerald-50',
    },
    {
        value: 'maintenance',
        label: 'Maintenance',
        swatch: 'bg-amber-500',
        item: 'text-amber-800 focus:bg-amber-50 focus:text-amber-900 data-[highlighted]:bg-amber-50',
    },
    {
        value: 'unavailable',
        label: 'Unavailable',
        swatch: 'bg-slate-500',
        item: 'text-slate-700 focus:bg-slate-100 focus:text-slate-900 data-[highlighted]:bg-slate-100',
    },
];

function actionValue(
    boat: BoatRecord,
): 'available' | 'maintenance' | 'unavailable' | 'archive' | null {
    if (boat.is_active === false) {
        return 'archive';
    }

    if (
        boat.status === 'available' ||
        boat.status === 'maintenance' ||
        boat.status === 'unavailable'
    ) {
        return boat.status;
    }

    return null;
}

function setBoatStatus(boat: BoatRecord, status: BoatStatus) {
    if (boat.is_active !== false && boat.status === status) {
        return;
    }

    router.patch(
        `/boats/${boat.id}/status`,
        { status },
        { preserveScroll: true },
    );
}

function applyBoatAction(
    boat: BoatRecord,
    action: 'available' | 'maintenance' | 'unavailable' | 'archive' | 'restore',
) {
    if (action === 'archive') {
        openArchive(boat);

        return;
    }

    if (action === 'restore') {
        restoreBoat(boat);

        return;
    }

    setBoatStatus(boat, action);
}

function openArchive(boat: BoatRecord) {
    if (boat.is_active === false) {
        return;
    }

    viewOpen.value = false;
    archiveBoat.value = boat;
}

function closeArchive() {
    if (archiving.value) {
        return;
    }

    archiveBoat.value = null;
}

function confirmArchive() {
    if (!archiveBoat.value || archiving.value) {
        return;
    }

    const boatId = archiveBoat.value.id;
    archiving.value = true;

    router.patch(`/boats/${boatId}/archive`, {}, {
        preserveScroll: true,
        onSuccess: () => {
            listScope.value = 'archived';
            filter.value = 'all';
            search.value = '';
            viewOpen.value = false;
            archiveBoat.value = null;
        },
        onError: () => {
            archiving.value = false;
        },
        onFinish: () => {
            archiving.value = false;
        },
    });
}

function restoreBoat(boat: BoatRecord) {
    viewOpen.value = false;

    router.patch(`/boats/${boat.id}/restore`, {}, {
        preserveScroll: true,
        onSuccess: () => {
            listScope.value = 'active';
            filter.value = 'all';
        },
    });
}
</script>

<template>
    <Head title="Boat Management" />

    <div class="flex flex-1 flex-col gap-6 p-6">
        <div>
            <h1
                class="text-2xl font-bold tracking-tight text-[#0f4c8a] dark:text-sky-100"
            >
                Boat Management
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
                Active fleet overview. Archived boats stay under the Archived tab.
            </p>
        </div>

        <StatCards
            :items="cards"
            :active="listScope === 'active' ? filter : null"
            selectable
            @select="(key) => selectStatusCard(key as StatusFilter)"
        />

        <section
            class="overflow-hidden rounded-2xl border border-[#d7e8f6] bg-white shadow-sm dark:border-white/10 dark:bg-white/5"
        >
            <div
                class="flex flex-col gap-4 border-b border-[#e8f1f8] px-5 py-4 dark:border-white/10"
            >
                <div class="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
                    <div>
                        <h2 class="font-semibold text-[#0f4c8a] dark:text-sky-100">
                            Boats
                        </h2>
                        <p class="text-xs text-ink-muted dark:text-slate-400">
                            {{ filteredBoats.length }}
                            {{ filteredBoats.length === 1 ? 'boat' : 'boats' }}
                            in {{ listTitle.toLowerCase() }}
                            <span v-if="stats.archived > 0">
                                · {{ stats.archived }} archived
                            </span>
                        </p>
                    </div>

                    <div class="flex flex-col gap-2 sm:flex-row sm:items-center">
                        <div class="inline-flex rounded-xl bg-[#e8f4fc] p-1 dark:bg-sky-500/10">
                            <button
                                v-for="scope in [
                                    { key: 'active' as ListScope, label: 'Active' },
                                    { key: 'archived' as ListScope, label: 'Archived' },
                                    { key: 'all' as ListScope, label: 'All' },
                                ]"
                                :key="scope.key"
                                type="button"
                                class="rounded-lg px-3 py-1.5 text-xs font-semibold transition"
                                :class="
                                    listScope === scope.key
                                        ? 'bg-white text-[#0f4c8a] shadow-sm dark:bg-white/10 dark:text-sky-100'
                                        : 'text-ink-soft hover:text-[#0f4c8a]'
                                "
                                @click="
                                    listScope = scope.key;
                                    if (scope.key === 'archived') {
                                        filter = 'all';
                                    }
                                "
                            >
                                {{ scope.label }}
                                <span
                                    v-if="scope.key === 'archived' && stats.archived > 0"
                                    class="ml-1 rounded-full bg-[#d7e8f6] px-1.5 py-0.5 text-[11px] dark:bg-white/10"
                                >
                                    {{ stats.archived }}
                                </span>
                            </button>
                        </div>

                        <div class="relative min-w-[12rem] flex-1 sm:w-56">
                            <Search
                                class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-ink-muted"
                            />
                            <Input
                                v-model="search"
                                type="search"
                                placeholder="Search boat..."
                                class="h-10 rounded-xl border-[#c5def5] bg-[#f7fbfe] pl-9 text-ink shadow-none dark:bg-[#0b1b30] dark:text-slate-100"
                            />
                        </div>

                        <button
                            type="button"
                            class="inline-flex h-10 items-center justify-center gap-2 rounded-xl bg-[#1b7ad8] px-4 text-sm font-semibold text-white shadow-[0_8px_18px_rgba(27,122,216,0.28)] transition hover:bg-[#1567c4]"
                            @click="openAdd"
                        >
                            <Plus class="size-4" />
                            Add New Boat
                        </button>
                    </div>
                </div>
            </div>

            <div v-if="filteredBoats.length === 0" class="px-5 py-16 text-center">
                <Ship class="mx-auto size-10 text-[#c5def5]" />
                <p class="mt-3 font-medium text-[#0f4c8a] dark:text-sky-100">
                    {{
                        listScope === 'archived'
                            ? 'No archived boats'
                            : 'No boats in this list yet'
                    }}
                </p>
                <p class="mt-1 text-sm text-ink-muted">
                    {{
                        listScope === 'archived'
                            ? 'Archived boats will appear here.'
                            : 'Add a boat to start monitoring the fleet.'
                    }}
                </p>
            </div>

            <div v-else class="overflow-x-auto">
                <table class="w-full min-w-[720px] text-left text-sm">
                    <thead
                        class="bg-[#f4f9fd] text-xs tracking-wide text-ink-soft uppercase dark:bg-white/5"
                    >
                        <tr>
                            <th class="px-5 py-3 font-semibold">Boat Name</th>
                            <th class="px-5 py-3 font-semibold">Boat Code</th>
                            <th class="px-5 py-3 font-semibold">Capacity</th>
                            <th class="px-5 py-3 font-semibold">Status</th>
                            <th class="px-5 py-3 font-semibold">
                                Registered Date
                            </th>
                            <th class="px-5 py-3 font-semibold text-right">
                                Actions
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="boat in filteredBoats"
                            :key="boat.id"
                            class="border-t border-[#eef4f9] dark:border-white/10"
                        >
                            <td class="px-5 py-3 font-semibold text-[#0f4c8a] dark:text-sky-100">
                                {{ boat.name }}
                                <span
                                    v-if="!boat.is_active"
                                    class="ml-2 inline-flex rounded-full bg-slate-100 px-2 py-0.5 text-[11px] font-semibold text-slate-600"
                                >
                                    Archived
                                </span>
                            </td>
                            <td class="px-5 py-3 text-ink dark:text-slate-200">
                                {{ boat.code }}
                            </td>
                            <td class="px-5 py-3 text-ink dark:text-slate-200">
                                {{ boat.capacity }} pax
                            </td>
                            <td class="px-5 py-3">
                                <span
                                    class="inline-flex rounded-full px-2.5 py-1 text-xs font-semibold"
                                    :class="statusTone(boat.status)"
                                >
                                    {{ boat.status_label }}
                                </span>
                            </td>
                            <td class="px-5 py-3 text-ink dark:text-slate-200">
                                {{ boat.registration_date_label ?? '—' }}
                            </td>
                            <td class="px-5 py-3">
                                <div class="flex items-center justify-end gap-2">
                                    <button
                                        type="button"
                                        class="inline-flex h-8 items-center gap-1.5 rounded-lg border border-[#c5def5] bg-white px-2.5 text-xs font-medium text-[#1b7ad8] transition hover:bg-[#e8f4fc] dark:border-white/10 dark:bg-white/5"
                                        @click="openView(boat)"
                                    >
                                        <Eye class="size-3.5" />
                                        View
                                    </button>
                                    <button
                                        v-if="boat.is_active && boat.operator && !boat.operator.user_id"
                                        type="button"
                                        class="inline-flex h-8 items-center gap-1.5 rounded-lg border border-[#1b7ad8] bg-white px-2.5 text-xs font-medium text-[#1b7ad8] transition hover:bg-[#e8f4fc] dark:bg-[#102036] dark:text-slate-100 dark:border-white/10"
                                        @click="openOperatorAccount(boat)"
                                    >
                                        <LockKeyhole class="size-3.5" />
                                        Create Login
                                    </button>
                                    <button
                                        v-if="boat.is_active"
                                        type="button"
                                        class="inline-flex h-8 items-center gap-1.5 rounded-lg bg-[#1b7ad8] px-2.5 text-xs font-medium text-white transition hover:bg-[#1567c4]"
                                        @click="openEdit(boat)"
                                    >
                                        <Pencil class="size-3.5" />
                                        Edit
                                    </button>
                                    <DropdownMenu>
                                        <DropdownMenuTrigger as-child>
                                            <button
                                                type="button"
                                                class="inline-flex h-9 items-center gap-1.5 rounded-lg border border-[#c5def5] bg-[#e8f4fc] px-3 text-sm font-semibold text-[#1b7ad8] transition hover:bg-[#d7ebfb] dark:border-white/10 dark:bg-white/5"
                                            >
                                                Status
                                                <ChevronDown class="size-4" />
                                            </button>
                                        </DropdownMenuTrigger>
                                        <DropdownMenuContent
                                            align="end"
                                            class="min-w-56 rounded-xl border-[#d7e8f6] p-1.5"
                                        >
                                            <DropdownMenuItem
                                                v-for="option in actionStatuses"
                                                :key="option.value"
                                                class="cursor-pointer rounded-lg px-3 py-2.5 text-sm font-semibold"
                                                :class="option.item"
                                                :disabled="boat.is_active === false"
                                                @click="applyBoatAction(boat, option.value)"
                                            >
                                                <span
                                                    class="size-2.5 shrink-0 rounded-full"
                                                    :class="option.swatch"
                                                />
                                                {{ option.label }}
                                                <Check
                                                    class="ml-auto size-4"
                                                    :class="
                                                        actionValue(boat) === option.value
                                                            ? 'opacity-100'
                                                            : 'opacity-0'
                                                    "
                                                />
                                            </DropdownMenuItem>
                                            <DropdownMenuSeparator class="my-1 bg-[#e8f1f8]" />
                                            <DropdownMenuItem
                                                v-if="boat.is_active"
                                                class="cursor-pointer rounded-lg px-3 py-2.5 text-sm font-semibold text-slate-600 focus:bg-slate-100 focus:text-slate-800 data-[highlighted]:bg-slate-100"
                                                @click="applyBoatAction(boat, 'archive')"
                                            >
                                                <span class="size-2.5 shrink-0 rounded-full bg-slate-400" />
                                                Archive
                                                <Check
                                                    class="ml-auto size-4"
                                                    :class="
                                                        actionValue(boat) === 'archive'
                                                            ? 'opacity-100'
                                                            : 'opacity-0'
                                                    "
                                                />
                                            </DropdownMenuItem>
                                            <DropdownMenuItem
                                                v-else
                                                class="cursor-pointer rounded-lg px-3 py-2.5 text-sm font-semibold text-emerald-800 focus:bg-emerald-50 focus:text-emerald-900 data-[highlighted]:bg-emerald-50"
                                                @click="applyBoatAction(boat, 'restore')"
                                            >
                                                <span class="size-2.5 shrink-0 rounded-full bg-emerald-500" />
                                                Restore
                                                <Check class="ml-auto size-4" />
                                            </DropdownMenuItem>
                                        </DropdownMenuContent>
                                    </DropdownMenu>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>
    </div>

    <AddBoatModal
        :open="addOpen"
        :boat="editingBoat"
        :resume-operator-account="resumeOperatorBoat"
        :existing-codes="boats.map((boat) => boat.code)"
        :status-options="statusOptions"
        :service-options="serviceOptions"
        :rental-destinations="rentalDestinations ?? []"
        @update:open="onAddOpenChange"
    />
    <ViewBoatModal
        v-model:open="viewOpen"
        :boat="selectedBoat"
        @edit="openEdit"
        @status="({ boat, status }) => setBoatStatus(boat, status)"
        @archive="openArchive"
        @restore="restoreBoat"
    />
    <Dialog :open="Boolean(archiveBoat)" @update:open="(open) => !open && closeArchive()">
        <DialogContent
            :show-close-button="false"
            class="w-full max-w-[calc(100%-1.5rem)] gap-0 overflow-hidden rounded-2xl border-none p-0 shadow-2xl sm:max-w-md"
        >
            <div class="bg-[#1b7ad8] px-5 py-4 text-white">
                <DialogTitle class="text-lg font-semibold text-white">
                    Archive Boat
                </DialogTitle>
                <DialogDescription class="text-sm text-white/80">
                    Moves the boat out of Active into the Archived tab.
                </DialogDescription>
            </div>
            <div class="space-y-4 bg-white p-5 dark:bg-background">
                <p class="text-sm text-ink dark:text-slate-200">
                    Archive
                    <span class="font-semibold text-[#0f4c8a] dark:text-sky-100">
                        {{ archiveBoat?.name }}
                    </span>
                    ({{ archiveBoat?.code }})?
                </p>
                <ul class="list-disc space-y-1 pl-5 text-xs text-ink-muted">
                    <li>Leaves the Active Boats list</li>
                    <li>Shows under Archived</li>
                    <li>Cannot be assigned to trips or rentals</li>
                    <li>Can be restored anytime</li>
                </ul>
                <div class="flex justify-end gap-3">
                    <Button
                        type="button"
                        variant="outline"
                        class="h-10 rounded-xl border-[#c5def5] px-5 text-[#1b7ad8] hover:bg-[#e8f4fc]"
                        :disabled="archiving"
                        @click="closeArchive"
                    >
                        Cancel
                    </Button>
                    <Button
                        type="button"
                        class="h-10 rounded-xl bg-slate-600 px-5 text-white hover:bg-slate-700"
                        :disabled="archiving"
                        @click="confirmArchive"
                    >
                        {{ archiving ? 'Archiving...' : 'Archive Boat' }}
                    </Button>
                </div>
            </div>
        </DialogContent>
    </Dialog>
</template>
