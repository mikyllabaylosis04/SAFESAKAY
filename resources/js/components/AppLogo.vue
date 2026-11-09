<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

type PlatformBranding = {
    name: string;
    logo_url: string | null;
};

const page = usePage();
const branding = computed(() => page.props.platform as PlatformBranding | undefined);
const logoSrc = computed(() => branding.value?.logo_url || '/images/safesakay-logo.png?v=2');
const name = computed(() => branding.value?.name || 'SafeSakay');
</script>

<template>
    <img
        :src="logoSrc"
        :alt="name"
        class="size-14 object-contain drop-shadow-md"
    />
    <div
        class="ml-1 grid min-w-0 flex-1 text-left group-data-[collapsible=icon]:hidden"
    >
        <span
            class="truncate text-sm font-extrabold tracking-[0.16em] text-white uppercase italic"
        >
            {{ name }}
        </span>
    </div>
</template>
