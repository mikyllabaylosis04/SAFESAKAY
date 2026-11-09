<script setup lang="ts">
import { Check, X, ZoomIn, ZoomOut } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import { Button } from '@/components/ui/button';

const VIEW = 280;
const OUTPUT = 512;
const MIN_ZOOM = 1;
const MAX_ZOOM = 3;

const props = withDefaults(
    defineProps<{
        open: boolean;
        sourceUrl: string | null;
        teleport?: boolean;
    }>(),
    {
        teleport: true,
    },
);

const emit = defineEmits<{
    'update:open': [value: boolean];
    apply: [file: File];
}>();

const sourceImage = ref<HTMLImageElement | null>(null);
const naturalWidth = ref(0);
const naturalHeight = ref(0);
const zoom = ref(1);
const offsetX = ref(0);
const offsetY = ref(0);
const dragging = ref(false);
const lastX = ref(0);
const lastY = ref(0);
const applying = ref(false);
const ready = ref(false);

const baseScale = computed(() => {
    if (!naturalWidth.value || !naturalHeight.value) {
        return 1;
    }

    return VIEW / Math.min(naturalWidth.value, naturalHeight.value);
});

const imageStyle = computed(() => {
    const scale = baseScale.value * zoom.value;

    return {
        width: `${naturalWidth.value * scale}px`,
        height: `${naturalHeight.value * scale}px`,
        transform: `translate(-50%, -50%) translate(${offsetX.value}px, ${offsetY.value}px)`,
    };
});

function clampOffsets() {
    const scale = baseScale.value * zoom.value;
    const maxX = Math.max(0, (naturalWidth.value * scale - VIEW) / 2);
    const maxY = Math.max(0, (naturalHeight.value * scale - VIEW) / 2);

    offsetX.value = Math.min(maxX, Math.max(-maxX, offsetX.value));
    offsetY.value = Math.min(maxY, Math.max(-maxY, offsetY.value));
}

function loadImage(url: string) {
    ready.value = false;
    sourceImage.value = null;

    const next = new Image();
    next.onload = () => {
        sourceImage.value = next;
        naturalWidth.value = next.naturalWidth;
        naturalHeight.value = next.naturalHeight;
        zoom.value = 1;
        offsetX.value = 0;
        offsetY.value = 0;
        ready.value = true;
    };
    next.src = url;
}

watch(
    () => [props.open, props.sourceUrl] as const,
    ([open, url]) => {
        if (open && url) {
            loadImage(url);
        }
    },
    { immediate: true },
);

watch(zoom, () => clampOffsets());

function close() {
    emit('update:open', false);
}

function onPointerDown(event: PointerEvent) {
    event.preventDefault();
    event.stopPropagation();
    dragging.value = true;
    lastX.value = event.clientX;
    lastY.value = event.clientY;
    (event.currentTarget as HTMLElement).setPointerCapture(event.pointerId);
}

function onPointerMove(event: PointerEvent) {
    if (!dragging.value) {
        return;
    }

    event.preventDefault();
    offsetX.value += event.clientX - lastX.value;
    offsetY.value += event.clientY - lastY.value;
    lastX.value = event.clientX;
    lastY.value = event.clientY;
    clampOffsets();
}

function onPointerUp(event: PointerEvent) {
    dragging.value = false;

    try {
        (event.currentTarget as HTMLElement).releasePointerCapture(event.pointerId);
    } catch {
        // Capture may already be released.
    }
}

function setZoom(value: number) {
    zoom.value = Math.min(MAX_ZOOM, Math.max(MIN_ZOOM, Number(value) || MIN_ZOOM));
}

function onWheel(event: WheelEvent) {
    setZoom(zoom.value + (event.deltaY > 0 ? -0.08 : 0.08));
}

async function apply() {
    if (!sourceImage.value || !ready.value || applying.value) {
        return;
    }

    applying.value = true;

    const canvas = document.createElement('canvas');
    canvas.width = OUTPUT;
    canvas.height = OUTPUT;
    const context = canvas.getContext('2d');

    if (!context) {
        applying.value = false;
        return;
    }

    const scale = baseScale.value * zoom.value;
    const left = (VIEW - naturalWidth.value * scale) / 2 + offsetX.value;
    const top = (VIEW - naturalHeight.value * scale) / 2 + offsetY.value;

    context.drawImage(
        sourceImage.value,
        -left / scale,
        -top / scale,
        VIEW / scale,
        VIEW / scale,
        0,
        0,
        OUTPUT,
        OUTPUT,
    );

    const blob = await new Promise<Blob | null>((resolve) =>
        canvas.toBlob(resolve, 'image/jpeg', 0.92),
    );

    applying.value = false;

    if (!blob) {
        return;
    }

    emit(
        'apply',
        new File([blob], 'operator-picture.jpg', { type: 'image/jpeg' }),
    );
    close();
}
</script>

<template>
    <Teleport to="body" :disabled="!teleport">
        <div
            v-if="open"
            data-operator-adjuster
            class="pointer-events-auto inset-0 z-[120] flex items-center justify-center bg-black/60 p-4"
            :class="teleport ? 'fixed' : 'absolute'"
            @pointerdown.stop
            @mousedown.stop
            @click.self="close"
        >
            <div
                class="w-full max-w-md overflow-hidden rounded-2xl bg-white shadow-2xl dark:bg-slate-950"
            >
                <div
                    class="flex items-center justify-between border-b border-[#d7e8f6] px-5 py-3 dark:border-white/10"
                >
                    <div>
                        <h3 class="font-semibold text-[#0f4c8a] dark:text-sky-100">
                            Adjust Operator Picture
                        </h3>
                        <p class="text-xs text-ink-muted">
                            Zoom in, then drag the photo to position it.
                        </p>
                    </div>
                    <button
                        type="button"
                        class="rounded-lg p-1.5 text-ink-soft hover:bg-[#e8f4fc]"
                        @click="close"
                    >
                        <X class="size-4" />
                    </button>
                </div>

                <div class="flex flex-col items-center gap-4 px-5 py-5">
                    <div
                        class="relative size-[280px] touch-none cursor-grab overflow-hidden rounded-full border-4 border-[#c5def5] bg-[#f7fbfe] active:cursor-grabbing dark:bg-[#0b1b30] dark:text-slate-100"
                        @pointerdown="onPointerDown"
                        @pointermove="onPointerMove"
                        @pointerup="onPointerUp"
                        @pointercancel="onPointerUp"
                        @wheel.prevent="onWheel"
                    >
                        <img
                            v-if="ready && sourceUrl"
                            :src="sourceUrl"
                            alt="Operator crop"
                            class="absolute top-1/2 left-1/2 max-w-none shrink-0 select-none"
                            draggable="false"
                            :style="imageStyle"
                        />
                    </div>

                    <div class="flex w-full items-center gap-3">
                        <button
                            type="button"
                            class="rounded-lg p-1.5 text-[#1b7ad8] hover:bg-[#e8f4fc]"
                            @click="setZoom(zoom - 0.2)"
                        >
                            <ZoomOut class="size-4" />
                        </button>
                        <input
                            :value="zoom"
                            type="range"
                            :min="MIN_ZOOM"
                            :max="MAX_ZOOM"
                            step="0.01"
                            class="h-1.5 w-full accent-[#1b7ad8]"
                            @input="
                                setZoom(
                                    Number(($event.target as HTMLInputElement).value),
                                )
                            "
                        />
                        <button
                            type="button"
                            class="rounded-lg p-1.5 text-[#1b7ad8] hover:bg-[#e8f4fc]"
                            @click="setZoom(zoom + 0.2)"
                        >
                            <ZoomIn class="size-4" />
                        </button>
                    </div>
                </div>

                <div
                    class="flex justify-end gap-2 border-t border-[#d7e8f6] px-5 py-3 dark:border-white/10"
                >
                    <Button type="button" variant="outline" @click="close">
                        Cancel
                    </Button>
                    <Button
                        type="button"
                        class="bg-[#1b7ad8] hover:bg-[#1567c4]"
                        :disabled="!ready || applying"
                        @click="apply"
                    >
                        <Check class="size-4" />
                        Use Photo
                    </Button>
                </div>
            </div>
        </div>
    </Teleport>
</template>
