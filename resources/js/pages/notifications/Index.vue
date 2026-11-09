<script setup lang="ts">
import { Head, router, usePage } from '@inertiajs/vue3';
import { Bell, Building2, ClipboardList, FileText, List, Megaphone, TriangleAlert } from '@lucide/vue';
import { computed, ref } from 'vue';
import type {
    AdminNotice,
    NotificationGroup,
} from '@/types/notification';

const props = defineProps<{
    notifications: AdminNotice[];
    counts: {
        all: number;
        announcements: number;
        rentals: number;
        boats: number;
        organizations?: number;
        weather?: number;
        activity?: number;
    };
    unreadCount: number;
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Notifications', href: '/notifications' }],
    },
});

type FilterKey = 'all' | 'no-show' | 'registrations' | 'requests' | NotificationGroup;

const page = usePage();
const isSuperAdmin = computed(() => Boolean(page.props.auth?.is_super_admin));
const filter = ref<FilterKey>('all');

function isRegistration(item: AdminNotice) {
    return item.id.startsWith('org-pending-') || item.type_label === 'Pending Approval';
}

function isOrgRequest(item: AdminNotice) {
    return (
        item.id.startsWith('org-restore-') ||
        item.id.startsWith('org-closure-') ||
        ['Restore Request', 'Reactivation Request', 'Closure Request'].includes(item.type_label)
    );
}

const filterTabs = computed(() => {
    if (isSuperAdmin.value) {
        return [
            { key: 'all' as const, label: 'All', count: props.notifications.length, icon: null },
            {
                key: 'registrations' as const,
                label: 'Registrations',
                count: props.notifications.filter(isRegistration).length,
                icon: Building2,
            },
            {
                key: 'requests' as const,
                label: 'Requests',
                count: props.notifications.filter(isOrgRequest).length,
                icon: ClipboardList,
            },
            {
                key: 'activity' as const,
                label: 'Activity',
                count: props.notifications.filter((item) => item.group === 'activity').length,
                icon: List,
            },
        ];
    }

    return [
        { key: 'all' as const, label: 'All', count: props.counts.all },
        { key: 'announcements' as const, label: 'Announcements', count: props.counts.announcements, icon: Megaphone },
        { key: 'rentals' as const, label: 'Rental Requests', count: props.counts.rentals, icon: FileText },
        { key: 'boats' as const, label: 'Boat Updates', count: props.counts.boats, icon: Bell },
        {
            key: 'no-show' as const,
            label: 'No-Show',
            count: props.notifications.filter((item) => item.type_label === 'No-Show').length,
            icon: TriangleAlert,
        },
    ];
});

const visible = computed(() => {
    if (filter.value === 'all') {
        return props.notifications;
    }

    if (filter.value === 'no-show') {
        return props.notifications.filter((item) => item.type_label === 'No-Show');
    }

    if (filter.value === 'registrations') {
        return props.notifications.filter(isRegistration);
    }

    if (filter.value === 'requests') {
        return props.notifications.filter(isOrgRequest);
    }

    return props.notifications.filter((item) => item.group === filter.value);
});

function cardLook(item: AdminNotice) {
    if (item.type_label === 'No-Show' || item.tone === 'delay') {
        return { card: 'border-l-red-500 bg-[#fff6f6] dark:bg-red-950/40', badge: 'bg-red-100 text-red-700' };
    }

    if (item.type_label === 'Schedule' || item.tone === 'schedule') {
        return { card: 'border-l-violet-500 bg-[#f7f3ff] dark:bg-violet-950/40', badge: 'bg-violet-100 text-violet-700' };
    }

    if (item.type_label === 'Rental Request' || item.group === 'rentals') {
        return { card: 'border-l-emerald-500 bg-[#f3fbf6] dark:bg-emerald-950/40', badge: 'bg-emerald-100 text-emerald-700' };
    }

    if (item.tone === 'maintenance') {
        return { card: 'border-l-amber-500 bg-[#fff8ef] dark:bg-amber-950/40', badge: 'bg-amber-100 text-amber-800' };
    }

    return { card: 'border-l-[#1b7ad8] bg-[#f5f9ff]', badge: 'bg-blue-100 text-blue-700' };
}

function openNotice(item: AdminNotice) {
    router.post(`/notifications/${encodeURIComponent(item.id)}/read`, {}, {
        onFinish: () => router.visit(item.href),
    });
}

function markAllRead() {
    router.post('/notifications/read-all', {}, { preserveScroll: true });
}
</script>

<template>
    <Head title="Notifications" />

    <div class="flex flex-1 flex-col gap-6 p-6">
        <div class="flex flex-col gap-3 lg:flex-row lg:items-end lg:justify-between">
            <div>
                <h1 class="text-2xl font-bold tracking-tight text-[#0f4c8a] dark:text-sky-100">
                    Notifications
                </h1>
                <p class="mt-1 text-sm text-ink-muted">
                    {{
                        isSuperAdmin
                            ? 'Live platform notices from pending registrations, weather holds, and access changes.'
                            : 'Stay updated with system activities, requests, and important advisories.'
                    }}
                </p>
            </div>
            <div class="flex flex-wrap gap-2">
                <button
                    type="button"
                    class="inline-flex h-10 items-center rounded-xl border border-[#c5def5] bg-white px-4 text-sm font-semibold text-[#0f4c8a] disabled:opacity-50 dark:bg-[#102036] dark:text-slate-100 dark:border-white/10"
                    :disabled="unreadCount === 0"
                    @click="markAllRead"
                >
                    Mark all as read
                </button>
            </div>
        </div>

        <div class="grid gap-1.5" :class="isSuperAdmin ? 'grid-cols-4' : 'grid-cols-5'">
            <button
                v-for="item in filterTabs"
                :key="item.key"
                type="button"
                class="inline-flex h-10 min-w-0 items-center justify-center gap-1.5 rounded-full px-2 text-sm font-semibold whitespace-nowrap"
                :class="
                    filter === item.key
                        ? 'bg-[#d7ebfb] text-[#1b6cb5]'
                        : 'bg-transparent text-[#5b7c99]'
                "
                @click="filter = item.key as FilterKey"
            >
                <component :is="item.icon" v-if="'icon' in item && item.icon" class="size-4" />
                {{ item.label }}
                <span class="text-sm font-bold tabular-nums">{{ item.count }}</span>
            </button>
        </div>

        <section class="space-y-3">
            <p
                v-if="visible.length === 0"
                class="flex flex-col items-center gap-2 rounded-2xl border border-[#d7e8f6] bg-white py-16 text-center text-sm text-ink-muted dark:bg-[#102036] dark:text-slate-100 dark:border-white/10"
            >
                <Bell class="size-8 text-[#c5def5]" />
                You're all caught up. No notifications in this category.
            </p>
            <button
                v-for="item in visible"
                :key="item.id"
                type="button"
                class="w-full rounded-xl border border-black/5 border-l-4 px-4 py-3.5 text-left"
                :class="cardLook(item).card"
                @click="openNotice(item)"
            >
                <div class="flex items-start justify-between gap-3">
                    <span class="inline-flex rounded-md px-2 py-0.5 text-xs font-bold" :class="cardLook(item).badge">
                        {{ item.type_label }}
                    </span>
                    <p class="text-[13px] font-medium whitespace-nowrap text-[#5c7286]">
                        {{ item.date_label }} · {{ item.time_label }}
                    </p>
                </div>
                <h2 class="mt-2 text-[15px] leading-snug font-bold text-[#16324f]">
                    {{ item.title }}
                </h2>
                <p class="mt-1 text-sm leading-6 text-[#3d5166]">
                    {{ item.body }}
                </p>
                <p v-if="item.subtitle" class="mt-0.5 text-sm leading-6 text-[#3d5166]">
                    {{ item.subtitle }}
                </p>
            </button>
        </section>
    </div>
</template>
