<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import AdminNotificationDropdown from '@/components/AdminNotificationDropdown.vue';
import ThemeToggle from '@/components/ThemeToggle.vue';
import UserInfo from '@/components/UserInfo.vue';
import UserMenuContent from '@/components/UserMenuContent.vue';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { SidebarTrigger } from '@/components/ui/sidebar';
import type { User } from '@/types';

const page = usePage();
const user = computed(() => page.props.auth.user as User);
</script>

<template>
    <header
        class="relative isolate flex h-[4.5rem] shrink-0 items-center justify-between border-b border-[#d7e8f6] px-4 dark:border-white/10 md:px-6"
    >
        <div class="pointer-events-none absolute inset-0 overflow-hidden">
        <svg
            class="h-full w-full"
            viewBox="0 0 1440 90"
            preserveAspectRatio="none"
            aria-hidden="true"
        >
            <path
                fill="#1b7ad8"
                fill-opacity="0.12"
                d="M0 70 C180 20 320 90 520 40 C740 -10 900 80 1120 30 C1280 -5 1380 40 1440 18 V90 H0 Z"
            />
            <path
                fill="#4aa4f0"
                fill-opacity="0.16"
                d="M0 78 C220 40 380 95 600 55 C820 18 980 88 1200 50 C1320 28 1400 60 1440 48 V90 H0 Z"
            />
        </svg>
        </div>

        <div class="relative z-10 flex items-center gap-2">
            <SidebarTrigger class="-ml-1 text-[#0f4c8a] dark:text-sky-100" />
        </div>

        <div class="relative z-10 flex items-center gap-3">
            <ThemeToggle />

            <AdminNotificationDropdown />

            <DropdownMenu>
                <DropdownMenuTrigger as-child>
                    <button
                        type="button"
                        class="flex max-w-56 items-center gap-2 rounded-full border border-[#c5def5] bg-white py-1 pr-3 pl-1 shadow-sm transition hover:bg-[#f3f9ff] dark:border-white/10 dark:bg-white/10"
                    >
                        <UserInfo :user="user" />
                    </button>
                </DropdownMenuTrigger>
                <DropdownMenuContent
                    align="end"
                    :side-offset="8"
                    class="z-[120] min-w-56 rounded-xl p-1"
                >
                    <UserMenuContent :user="user" />
                </DropdownMenuContent>
            </DropdownMenu>
        </div>
    </header>
</template>
