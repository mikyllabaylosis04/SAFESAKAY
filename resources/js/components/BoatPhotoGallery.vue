<script setup lang="ts">
import { ChevronLeft, ChevronRight, Ship, X } from '@lucide/vue';
import { computed, onMounted, onUnmounted, ref, watch } from 'vue';
import type { BoatPictureItem } from '@/types/boat';

const props = withDefaults(
    defineProps<{
        pictures?: BoatPictureItem[];
        pictureUrl?: string | null;
        alt: string;
        heightClass?: string;
    }>(),
    {
        pictures: () => [],
        pictureUrl: null,
        heightClass: 'h-40',
    },
);

const SWIPE_THRESHOLD = 40;

const active = ref(0);
const viewerOpen = ref(false);
const swipeStartX = ref<number | null>(null);
const swipeStartY = ref<number | null>(null);
const swiping = ref(false);

const items = computed(() => {
    if (props.pictures.length > 0) {
        return props.pictures;
    }

    return props.pictureUrl ? [{ id: 0, url: props.pictureUrl }] : [];
});

const current = computed(() => items.value[active.value] ?? null);
const pictureKey = computed(() =>
    items.value.map((picture) => `${picture.id}:${picture.url}`).join('|'),
);

watch(pictureKey, () => {
    active.value = 0;
    viewerOpen.value = false;
});

function openViewer(index: number) {
    if (!items.value[index]) {
        return;
    }

    active.value = index;
    viewerOpen.value = true;
}

function closeViewer() {
    viewerOpen.value = false;
    swipeStartX.value = null;
    swipeStartY.value = null;
    swiping.value = false;
}

function showPrev() {
    if (items.value.length < 2) {
        return;
    }

    active.value =
        (active.value - 1 + items.value.length) % items.value.length;
}

function showNext() {
    if (items.value.length < 2) {
        return;
    }

    active.value = (active.value + 1) % items.value.length;
}

function onPointerDown(event: PointerEvent) {
    swipeStartX.value = event.clientX;
    swipeStartY.value = event.clientY;
    swiping.value = false;
}

function onPointerMove(event: PointerEvent) {
    if (swipeStartX.value === null || swipeStartY.value === null) {
        return;
    }

    const deltaX = event.clientX - swipeStartX.value;
    const deltaY = event.clientY - swipeStartY.value;

    if (Math.abs(deltaX) > 8 && Math.abs(deltaX) > Math.abs(deltaY)) {
        swiping.value = true;
    }
}

function onPointerUp(event: PointerEvent) {
    if (swipeStartX.value === null || swipeStartY.value === null) {
        return;
    }

    const deltaX = event.clientX - swipeStartX.value;
    const deltaY = event.clientY - swipeStartY.value;
    const wasSwiping = swiping.value;

    swipeStartX.value = null;
    swipeStartY.value = null;
    swiping.value = false;

    if (
        items.value.length > 1 &&
        Math.abs(deltaX) >= SWIPE_THRESHOLD &&
        Math.abs(deltaX) > Math.abs(deltaY)
    ) {
        if (deltaX < 0) {
            showNext();
        } else {
            showPrev();
        }

        return;
    }

    const target = event.target as HTMLElement | null;

    if (!wasSwiping && target?.dataset.closeViewer === 'true') {
        closeViewer();
    }
}

function onKeydown(event: KeyboardEvent) {
    if (!viewerOpen.value) {
        return;
    }

    if (event.key === 'Escape') {
        event.preventDefault();
        event.stopImmediatePropagation();
        closeViewer();
    }

    if (event.key === 'ArrowLeft') {
        event.preventDefault();
        event.stopImmediatePropagation();
        showPrev();
    }

    if (event.key === 'ArrowRight') {
        event.preventDefault();
        event.stopImmediatePropagation();
        showNext();
    }
}

onMounted(() => {
    window.addEventListener('keydown', onKeydown, true);
});

onUnmounted(() => {
    window.removeEventListener('keydown', onKeydown, true);
});
</script>

<template>
    <div>
        <button
            v-if="items[active]"
            type="button"
            class="block w-full overflow-hidden rounded-xl bg-[#d7ebfa] dark:bg-sky-500/10"
            :class="heightClass"
            :aria-label="`View ${alt} full size`"
            @click="openViewer(active)"
        >
            <img
                :src="items[active].url"
                :alt="alt"
                class="h-full w-full cursor-zoom-in object-cover"
            />
        </button>
        <div
            v-else
            class="flex items-center justify-center overflow-hidden rounded-xl bg-[#d7ebfa] dark:bg-sky-500/10"
            :class="heightClass"
        >
            <Ship class="size-12 text-[#1b7ad8]" />
        </div>
        <div v-if="items.length > 1" class="mt-2 flex flex-wrap gap-2">
            <button
                v-for="(picture, index) in items"
                :key="`${picture.id}-${index}`"
                type="button"
                class="size-12 overflow-hidden rounded-lg ring-offset-2 transition"
                :class="
                    index === active
                        ? 'ring-2 ring-[#1b7ad8]'
                        : 'ring-1 ring-[#c5def5] hover:ring-[#1b7ad8]'
                "
                :aria-label="`View ${alt} picture ${index + 1} full size`"
                @click="openViewer(index)"
            >
                <img
                    :src="picture.url"
                    :alt="`${alt} ${index + 1}`"
                    class="h-full w-full cursor-zoom-in object-cover"
                />
            </button>
        </div>
    </div>

    <Teleport to="body">
        <div
            v-if="viewerOpen && current"
            class="fixed inset-0 z-[200] flex touch-none items-center justify-center bg-black/90 p-4 pointer-events-auto sm:p-8"
            role="dialog"
            aria-modal="true"
            :aria-label="`${alt} full size`"
            data-close-viewer="true"
            @pointerdown="onPointerDown"
            @pointermove="onPointerMove"
            @pointerup="onPointerUp"
            @pointercancel="onPointerUp"
        >
            <button
                type="button"
                class="absolute top-4 right-4 z-10 flex size-10 items-center justify-center rounded-full bg-white/15 text-white transition hover:bg-white/25"
                aria-label="Close picture"
                @click.stop="closeViewer"
                @pointerdown.stop
            >
                <X class="size-5" />
            </button>
            <button
                v-if="items.length > 1"
                type="button"
                class="absolute top-1/2 left-3 z-10 flex size-10 -translate-y-1/2 items-center justify-center rounded-full bg-white/15 text-white transition hover:bg-white/25 sm:left-6"
                aria-label="Previous picture"
                @click.stop="showPrev"
                @pointerdown.stop
            >
                <ChevronLeft class="size-6" />
            </button>
            <img
                :src="current.url"
                :alt="alt"
                draggable="false"
                class="max-h-[90vh] max-w-[min(96vw,1100px)] select-none rounded-xl object-contain shadow-2xl"
            />
            <button
                v-if="items.length > 1"
                type="button"
                class="absolute top-1/2 right-3 z-10 flex size-10 -translate-y-1/2 items-center justify-center rounded-full bg-white/15 text-white transition hover:bg-white/25 sm:right-6"
                aria-label="Next picture"
                @click.stop="showNext"
                @pointerdown.stop
            >
                <ChevronRight class="size-6" />
            </button>
        </div>
    </Teleport>
</template>
