<script setup lang="ts">
import { ChevronLeft, ChevronRight, Ship } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import type { BoatUsageRow } from '@/types/report';

const props = defineProps<{
    rows: BoatUsageRow[];
    periodLabel: string;
}>();

const page = ref(1);
const pageSize = 8;

watch(
    () => props.rows,
    () => {
        page.value = 1;
    },
);

const totalPages = computed(() =>
    Math.max(1, Math.ceil(props.rows.length / pageSize)),
);

const pagedRows = computed(() => {
    const start = (page.value - 1) * pageSize;

    return props.rows.slice(start, start + pageSize);
});

const rangeLabel = computed(() => {
    if (props.rows.length === 0) {
        return 'Showing 0 of 0 boats';
    }

    return `Showing ${pagedRows.value.length} of ${props.rows.length} boats`;
});

function previousPage() {
    page.value = Math.max(1, page.value - 1);
}

function nextPage() {
    page.value = Math.min(totalPages.value, page.value + 1);
}
</script>

<template>
    <div>
        <div class="flex items-center gap-2 px-5 pt-5 pb-3">
            <Ship class="size-4 text-[#1b7ad8]" />
            <h3 class="font-semibold text-[#0f4c8a]">Boat Usage Details</h3>
        </div>

        <div v-if="rows.length === 0" class="px-5 py-14 text-center">
            <Ship class="mx-auto size-10 text-[#c5def5]" />
            <p class="mt-3 font-medium text-[#0f4c8a]">No boat usage records</p>
            <p class="mt-1 text-sm text-ink-muted">
                Try another date range or choose All records.
            </p>
        </div>

        <div v-else class="overflow-x-auto">
            <table class="w-full min-w-[1080px] text-left text-sm">
                <thead class="bg-[#f6f9fc] text-[11px] tracking-wide text-ink-muted uppercase dark:bg-[#0b1b30] dark:text-slate-100">
                    <tr>
                        <th class="px-5 py-3 font-semibold">Date</th>
                        <th class="px-5 py-3 font-semibold">Boat Name / Number</th>
                        <th class="px-5 py-3 font-semibold">Operator</th>
                        <th class="px-5 py-3 font-semibold">Regular Trips</th>
                        <th class="px-5 py-3 font-semibold">Private Bookings</th>
                        <th class="px-5 py-3 font-semibold">Total Trips</th>
                        <th class="px-5 py-3 font-semibold">Maintenance Records</th>
                        <th class="px-5 py-3 font-semibold">Usage Frequency</th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="boat in pagedRows"
                        :key="boat.id"
                        class="border-t border-[#eef2f6]"
                    >
                        <td class="px-5 py-4 text-ink-soft">
                            {{ periodLabel }}
                        </td>
                        <td class="px-5 py-4">
                            <span class="font-semibold text-[#1b7ad8]">{{ boat.name }}</span>
                            <span class="text-ink-soft"> / {{ boat.code }}</span>
                        </td>
                        <td class="px-5 py-4 text-ink-soft">
                            {{ boat.operator_name || 'Unassigned' }}
                        </td>
                        <td class="px-5 py-4 font-semibold text-[#1b7ad8]">
                            {{ boat.regular_trips }}
                        </td>
                        <td class="px-5 py-4 text-ink-soft">
                            {{ boat.private_bookings }}
                        </td>
                        <td class="px-5 py-4 font-semibold text-ink">
                            {{ boat.total_trips }}
                        </td>
                        <td class="px-5 py-4 text-ink-soft">
                            {{ boat.maintenance_records }}
                        </td>
                        <td class="px-5 py-4 text-ink-soft">
                            {{ boat.usage_share }}%
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div
            class="flex flex-col gap-3 border-t border-[#eef2f6] px-5 py-3 sm:flex-row sm:items-center sm:justify-between"
        >
            <p class="text-xs text-ink-muted">{{ rangeLabel }}</p>
            <div class="flex items-center gap-1.5">
                <button
                    type="button"
                    class="inline-flex size-8 items-center justify-center rounded-md border border-[#dce6ee] text-ink-muted disabled:opacity-40"
                    :disabled="page <= 1"
                    aria-label="Previous page"
                    @click="previousPage"
                >
                    <ChevronLeft class="size-4" />
                </button>
                <span
                    class="inline-flex size-8 items-center justify-center rounded-md bg-[#1b7ad8] text-xs font-semibold text-white"
                >
                    {{ page }}
                </span>
                <button
                    type="button"
                    class="inline-flex size-8 items-center justify-center rounded-md border border-[#dce6ee] text-ink-muted disabled:opacity-40"
                    :disabled="page >= totalPages"
                    aria-label="Next page"
                    @click="nextPage"
                >
                    <ChevronRight class="size-4" />
                </button>
            </div>
        </div>
    </div>
</template>
