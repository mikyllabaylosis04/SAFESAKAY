<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { Bell, FileText, Percent, ShieldCheck, UserRound } from '@lucide/vue';
import type { Component } from 'vue';
import { computed } from 'vue';
import { useCurrentUrl } from '@/composables/useCurrentUrl';

type SettingsNavItem = { title: string; description: string; href: string; icon: Component };

const page = usePage();
const isSuperAdmin = computed(() => Boolean(page.props.auth?.is_super_admin));

const navItems = computed<SettingsNavItem[]>(() => {
    const profile = { title: 'Profile', description: 'Name and email', href: '/settings/profile', icon: UserRound };
    const security = { title: 'Security', description: 'Change password', href: '/settings/security', icon: ShieldCheck };

    if (isSuperAdmin.value) {
        return [profile, security];
    }

    return [
        profile,
        { title: 'Commission', description: 'Barangay rate', href: '/settings/commission', icon: Percent },
        { title: 'Rental Policies', description: 'Rules and grace period', href: '/settings/rental-policies', icon: FileText },
        { title: 'Notifications', description: 'Alert preferences', href: '/settings/notifications', icon: Bell },
        security,
    ];
});

const { isCurrentOrParentUrl } = useCurrentUrl();
</script>

<template>
    <div class="flex flex-1 flex-col gap-5 p-4 sm:p-6">
        <div>
            <h1 class="text-2xl font-bold tracking-tight text-[#0f4c8a] dark:text-sky-100">Settings</h1>
            <p class="mt-1 text-sm text-ink-muted dark:text-slate-400">Manage your account and barangay rental settings.</p>
        </div>

        <div class="flex flex-col gap-5 lg:flex-row lg:items-start">
            <aside class="w-full lg:sticky lg:top-6 lg:w-60 lg:shrink-0">
                <nav
                    class="flex gap-1 overflow-x-auto rounded-2xl border border-[#d7e8f6] bg-white p-1.5 shadow-sm lg:flex-col lg:overflow-visible lg:p-2 dark:border-white/10 dark:bg-white/5"
                    aria-label="Settings sections"
                >
                    <Link
                        v-for="item in navItems"
                        :key="item.href"
                        :href="item.href"
                        :aria-current="isCurrentOrParentUrl(item.href) ? 'page' : undefined"
                        class="group flex shrink-0 items-center gap-3 rounded-xl px-3 py-2 text-sm transition lg:py-2.5"
                        :class="
                            isCurrentOrParentUrl(item.href)
                                ? 'bg-[#1b7ad8] text-white shadow-[0_6px_14px_rgba(27,122,216,0.25)]'
                                : 'text-ink hover:bg-[#eef6fd] dark:text-slate-200 dark:hover:bg-white/10'
                        "
                    >
                        <component
                            :is="item.icon"
                            class="size-4 shrink-0"
                            :class="isCurrentOrParentUrl(item.href) ? 'text-white' : 'text-[#1b7ad8] dark:text-sky-300'"
                        />
                        <span class="flex flex-col">
                            <span class="font-semibold whitespace-nowrap">{{ item.title }}</span>
                            <span
                                class="hidden text-xs lg:block"
                                :class="isCurrentOrParentUrl(item.href) ? 'text-white/80' : 'text-ink-muted dark:text-slate-400'"
                            >
                                {{ item.description }}
                            </span>
                        </span>
                    </Link>
                </nav>
            </aside>

            <section class="min-w-0 flex-1">
                <slot />
            </section>
        </div>
    </div>
</template>
