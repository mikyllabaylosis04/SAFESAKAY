<script setup lang="ts">
import { ArrowLeft, Search } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import { Spinner } from '@/components/ui/spinner';
import { fetchLocations, type GeoItem } from '@/lib/psgc';

export type PickedAddress = {
    address: string;
    region: string;
    province: string;
    city: string;
    barangay: string;
};

type Step = 'region' | 'province' | 'city' | 'barangay';

const props = withDefaults(
    defineProps<{
        open: boolean;
        modelValue?: string;
        teleport?: boolean;
        takenLocations?: Array<{
            name: string;
            address: string;
        }>;
    }>(),
    {
        teleport: true,
    },
);

const emit = defineEmits<{
    'update:open': [value: boolean];
    select: [value: PickedAddress];
}>();

const step = ref<Step>('region');
const query = ref('');
const loading = ref(false);
const error = ref('');
const region = ref<GeoItem | null>(null);
const province = ref<GeoItem | null>(null);
const city = ref<GeoItem | null>(null);
const regions = ref<GeoItem[]>([]);
const provinces = ref<GeoItem[]>([]);
const cities = ref<GeoItem[]>([]);
const barangays = ref<GeoItem[]>([]);

const stepMeta: Record<
    Step,
    { current: string; list: string; parent: Step | null }
> = {
    region: { current: 'Select Region', list: 'Region', parent: null },
    province: { current: 'Select Province', list: 'Province', parent: 'region' },
    city: {
        current: 'Select City / Municipality',
        list: 'City / Municipality',
        parent: 'province',
    },
    barangay: { current: 'Select Barangay', list: 'Barangay', parent: 'city' },
};

const currentItems = computed(() => {
    const source =
        step.value === 'region'
            ? regions.value
            : step.value === 'province'
              ? provinces.value
              : step.value === 'city'
                ? cities.value
                : barangays.value;
    const needle = query.value.trim().toLowerCase();

    if (!needle) {
        return source;
    }

    return source.filter((item) => item.name.toLowerCase().includes(needle));
});

const groupedItems = computed(() => {
    const groups = new Map<string, GeoItem[]>();

    currentItems.value.forEach((item) => {
        const letter = item.name.charAt(0).toUpperCase() || '#';
        const bucket = groups.get(letter) ?? [];
        bucket.push(item);
        groups.set(letter, bucket);
    });

    return [...groups.entries()].sort(([a], [b]) => a.localeCompare(b));
});

function normalizePlace(value: string) {
    return value
        .toLowerCase()
        .replace(/\s+/g, ' ')
        .trim()
        .replace(/^barangay\s+/, '');
}

function isTakenBarangay(barangayName: string) {
    const barangayKey = normalizePlace(barangayName);
    const cityKey = normalizePlace(city.value?.name ?? '');

    return (props.takenLocations ?? []).some((location) => {
        const nameKey = normalizePlace(location.name);
        const addressKey = normalizePlace(location.address);

        if (nameKey === barangayKey) {
            return true;
        }

        if (cityKey !== '' && addressKey.includes(barangayKey) && addressKey.includes(cityKey)) {
            return true;
        }

        return addressKey.includes(barangayKey);
    });
}

function close() {
    emit('update:open', false);
}

function resetSelection() {
    step.value = 'region';
    query.value = '';
    region.value = null;
    province.value = null;
    city.value = null;
    provinces.value = [];
    cities.value = [];
    barangays.value = [];
    error.value = '';
}

async function load(type: string, code = '') {
    loading.value = true;
    error.value = '';

    try {
        const items = await fetchLocations(type, code);

        if (items.length === 0) {
            error.value = 'No locations found. Try again.';
        }

        return items;
    } catch {
        error.value = 'Unable to load locations. Check your internet connection.';
        return [];
    } finally {
        loading.value = false;
    }
}

watch(
    () => props.open,
    async (open) => {
        if (!open) {
            return;
        }

        query.value = '';
        error.value = '';

        if (regions.value.length === 0) {
            regions.value = await load('island-groups');
        }

        if (!region.value) {
            step.value = 'region';
        }
    },
);

async function choose(item: GeoItem) {
    query.value = '';

    if (step.value === 'region') {
        region.value = item;
        province.value = null;
        city.value = null;
        cities.value = [];
        barangays.value = [];
        provinces.value = await load('island-provinces', item.code.toLowerCase());
        step.value = 'province';
        return;
    }

    if (step.value === 'province') {
        province.value = item;
        city.value = null;
        barangays.value = [];
        cities.value = await load('cities', item.code);
        step.value = 'city';
        return;
    }

    if (step.value === 'city') {
        city.value = item;
        barangays.value = await load('barangays', item.code);
        step.value = 'barangay';
        return;
    }

    if (isTakenBarangay(item.name)) {
        error.value =
            'This barangay address is already registered. Sign in to the existing organization instead.';
        return;
    }

    emit('select', {
        address: [item.name, city.value?.name, province.value?.name]
            .filter(Boolean)
            .join(', '),
        region: region.value?.name ?? '',
        province: province.value?.name ?? '',
        city: city.value?.name ?? '',
        barangay: item.name,
    });
    close();
}

function jumpTo(target: Step) {
    query.value = '';
    error.value = '';
    step.value = target;

    if (target === 'region') {
        province.value = null;
        city.value = null;
        cities.value = [];
        barangays.value = [];
        return;
    }

    if (target === 'province') {
        city.value = null;
        barangays.value = [];
        return;
    }
}

function goBack() {
    const parent = stepMeta[step.value].parent;

    if (!parent) {
        close();
        return;
    }

    jumpTo(parent);
}
</script>

<template>
    <Teleport to="body" :disabled="!teleport">
        <div
            v-if="open"
            data-address-picker
            class="pointer-events-auto inset-0 z-[120] flex items-center justify-center bg-black/60 p-4"
            :class="teleport ? 'fixed' : 'absolute'"
            @pointerdown.stop
            @mousedown.stop
            @click.self="close"
        >
            <div
                class="flex h-[min(720px,92vh)] w-full max-w-[420px] flex-col overflow-hidden rounded-[28px] bg-white text-ink shadow-2xl dark:bg-white"
                :class="teleport ? '' : 'max-h-full'"
            >
                <div class="flex items-center gap-3 px-4 pt-4 pb-3">
                    <button
                        type="button"
                        class="flex size-9 items-center justify-center rounded-full text-ink-soft hover:bg-[#f3f8fe] dark:bg-[#0b1b30] dark:text-slate-100"
                        aria-label="Back"
                        @click="goBack"
                    >
                        <ArrowLeft class="size-5" />
                    </button>
                    <label
                        class="flex min-w-0 flex-1 items-center gap-2 rounded-full bg-[#f4f6f8] px-3 py-2.5 text-sm text-ink-muted dark:bg-[#0b1b30] dark:text-slate-100"
                    >
                        <Search class="size-4 shrink-0" />
                        <input
                            v-model="query"
                            type="search"
                            class="min-w-0 flex-1 bg-transparent text-ink outline-none placeholder:text-[#9aafc0]"
                            placeholder="Search City, District or Postal Code"
                        />
                    </label>
                </div>

                <div class="flex items-center justify-between px-5 pb-2">
                    <p class="text-sm font-medium text-[#9aafc0]">
                        Region Selected
                    </p>
                    <button
                        type="button"
                        class="text-sm font-semibold text-[#1b7ad8]"
                        @click="resetSelection"
                    >
                        Reset
                    </button>
                </div>

                <div class="px-5 pb-3">
                    <div v-if="region" class="relative pl-5">
                        <span
                            class="absolute top-2 left-[5px] h-[calc(100%-8px)] w-px bg-[#d9e2ea]"
                        />
                        <button
                            type="button"
                            class="relative mb-3 block text-left text-[15px] text-ink-muted"
                            @click="jumpTo('region')"
                        >
                            <span
                                class="absolute top-1.5 -left-5 size-2.5 rounded-full bg-[#d9e2ea]"
                            />
                            {{ region.name }}
                        </button>
                        <button
                            v-if="province"
                            type="button"
                            class="relative mb-3 block text-left text-[15px] text-ink-muted"
                            @click="jumpTo('province')"
                        >
                            <span
                                class="absolute top-1.5 -left-5 size-2.5 rounded-full bg-[#d9e2ea]"
                            />
                            {{ province.name }}
                        </button>
                        <button
                            v-if="city"
                            type="button"
                            class="relative mb-3 block text-left text-[15px] text-ink-muted"
                            @click="jumpTo('city')"
                        >
                            <span
                                class="absolute top-1.5 -left-5 size-2.5 rounded-full bg-[#d9e2ea]"
                            />
                            {{ city.name }}
                        </button>
                    </div>

                    <div
                        class="flex items-center gap-3 rounded-xl border border-[#1b7ad8] px-3 py-3"
                    >
                        <span
                            class="size-4 rounded-full border-[5px] border-[#1b7ad8]"
                        />
                        <p class="text-[15px] font-medium text-[#1b7ad8]">
                            {{ stepMeta[step].current }}
                        </p>
                    </div>
                </div>

                <div
                    class="bg-[#f3f5f7] px-5 py-2 text-sm font-medium text-ink-muted dark:bg-[#0b1b30] dark:text-slate-100"
                >
                    {{ stepMeta[step].list }}
                </div>

                <div class="min-h-0 flex-1 overflow-y-auto">
                    <div
                        v-if="loading"
                        class="flex h-40 items-center justify-center text-[#1b7ad8]"
                    >
                        <Spinner class="size-6" />
                    </div>
                    <p
                        v-else-if="error"
                        class="px-5 py-8 text-center text-sm text-[#e11d48]"
                    >
                        {{ error }}
                    </p>
                    <p
                        v-else-if="groupedItems.length === 0"
                        class="px-5 py-8 text-center text-sm text-ink-muted"
                    >
                        No matching places.
                    </p>
                    <div v-else>
                        <div
                            v-for="[letter, items] in groupedItems"
                            :key="letter"
                            class="flex"
                        >
                            <div
                                class="w-10 shrink-0 py-3 text-center text-sm font-semibold text-[#c5d0da]"
                            >
                                {{ letter }}
                            </div>
                            <div class="min-w-0 flex-1">
                                <button
                                    v-for="item in items"
                                    :key="item.code"
                                    type="button"
                                    class="block w-full border-b border-[#eef2f5] py-3 pr-5 text-left text-[15px] text-ink hover:bg-[#f7fbfe] disabled:cursor-not-allowed disabled:text-[#9aafc0] dark:bg-[#0b1b30] dark:text-slate-100"
                                    :disabled="
                                        step === 'barangay' &&
                                        isTakenBarangay(item.name)
                                    "
                                    @click="choose(item)"
                                >
                                    {{ item.name }}
                                    <span
                                        v-if="
                                            step === 'barangay' &&
                                            isTakenBarangay(item.name)
                                        "
                                        class="ml-2 text-xs text-[#e11d48]"
                                    >
                                        Registered
                                    </span>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </Teleport>
</template>
