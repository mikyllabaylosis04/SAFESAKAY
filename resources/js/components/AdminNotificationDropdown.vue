<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3';
import {
    Bell,
    Building2,
    Check,
    ClipboardList,
    FileText,
    List,
    Megaphone,
    MoreVertical,
    TriangleAlert,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import type {
    AdminNotice,
    AdminNoticeFeed,
    NotificationGroup,
} from '@/types/notification';

type FilterKey = 'all' | 'no-show' | 'registrations' | 'requests' | NotificationGroup;

const page = usePage();
const open = ref(false);
const filter = ref<FilterKey>('all');
const menuOpenId = ref<string | null>(null);

const feed = computed<AdminNoticeFeed>(() => {
    const shared = page.props.headerNotifications as AdminNoticeFeed | AdminNotice[] | undefined;

    if (Array.isArray(shared)) {
        return {
            items: shared,
            unread_count: shared.filter((item) => item.unread).length,
            counts: {
                all: shared.length,
                announcements: shared.filter((item) => item.group === 'announcements').length,
                rentals: shared.filter((item) => item.group === 'rentals').length,
                boats: shared.filter((item) => item.group === 'boats').length,
                organizations: shared.filter((item) => item.group === 'organizations').length,
                weather: shared.filter((item) => item.group === 'weather').length,
                activity: shared.filter((item) => item.group === 'activity').length,
            },
        };
    }

    return {
        items: shared?.items ?? [],
        unread_count: shared?.unread_count ?? 0,
        counts: shared?.counts ?? {
            all: 0,
            announcements: 0,
            rentals: 0,
            boats: 0,
            organizations: 0,
            weather: 0,
            activity: 0,
        },
    };
});

const isSuperAdmin = computed(() => Boolean(page.props.auth?.is_super_admin));

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

const filters = computed(() => {
    const items = feed.value.items;

    if (isSuperAdmin.value) {
        return [
            { key: 'all' as const, label: 'All', count: items.length, icon: null },
            {
                key: 'registrations' as const,
                label: 'Registrations',
                count: items.filter(isRegistration).length,
                icon: Building2,
            },
            {
                key: 'requests' as const,
                label: 'Requests',
                count: items.filter(isOrgRequest).length,
                icon: ClipboardList,
            },
            {
                key: 'activity' as const,
                label: 'Activity',
                count: items.filter((item) => item.group === 'activity').length,
                icon: List,
            },
        ];
    }

    return [
        { key: 'all' as const, label: 'All', count: feed.value.counts.all, icon: null },
        {
            key: 'announcements' as const,
            label: 'Announcements',
            count: feed.value.counts.announcements,
            icon: Megaphone,
        },
        {
            key: 'rentals' as const,
            label: 'Rental Requests',
            count: feed.value.counts.rentals,
            icon: FileText,
        },
        {
            key: 'boats' as const,
            label: 'Boat Updates',
            count: feed.value.counts.boats,
            icon: Bell,
        },
        {
            key: 'no-show' as const,
            label: 'No-Show',
            count: feed.value.items.filter((item) => item.type_label === 'No-Show').length,
            icon: TriangleAlert,
        },
    ];
});

const visibleNotices = computed(() => {
    const items = [...feed.value.items].sort((a, b) =>
        String(b.occurred_at).localeCompare(String(a.occurred_at)),
    );

    if (filter.value === 'all') {
        return items;
    }

    if (filter.value === 'no-show') {
        return items.filter((item) => item.type_label === 'No-Show');
    }

    if (filter.value === 'registrations') {
        return items.filter(isRegistration);
    }

    if (filter.value === 'requests') {
        return items.filter(isOrgRequest);
    }

    return items.filter((item) => item.group === filter.value);
});

const unreadCount = computed(() => feed.value.unread_count);

function cardLook(item: AdminNotice) {
    if (item.type_label === 'No-Show' || item.tone === 'delay') {
        return {
            card: 'border-l-red-500 bg-[#fff6f6] dark:bg-red-950/40',
            badge: 'bg-red-100 text-red-700',
        };
    }

    if (item.type_label === 'Schedule' || item.tone === 'schedule') {
        return {
            card: 'border-l-violet-500 bg-[#f7f3ff] dark:bg-violet-950/40',
            badge: 'bg-violet-100 text-violet-700',
        };
    }

    if (item.type_label === 'Rental Request' || item.group === 'rentals') {
        return {
            card: 'border-l-emerald-500 bg-[#f3fbf6] dark:bg-emerald-950/40',
            badge: 'bg-emerald-100 text-emerald-700',
        };
    }

    if (item.tone === 'maintenance') {
        return {
            card: 'border-l-amber-500 bg-[#fff8ef] dark:bg-amber-950/40',
            badge: 'bg-amber-100 text-amber-800',
        };
    }

    return {
        card: 'border-l-[#1b7ad8] bg-[#f5f9ff]',
        badge: 'bg-blue-100 text-blue-700',
    };
}

function keepOpen(options: { onFinish?: () => void } = {}) {
    return {
        preserveScroll: true,
        preserveState: true,
        onFinish: () => {
            open.value = true;
            options.onFinish?.();
        },
    };
}

function markRead(id: string) {
    router.post(`/notifications/${encodeURIComponent(id)}/read`, {}, keepOpen());
}

function markAllRead() {
    router.post('/notifications/read-all', {}, keepOpen());
}

function removeNotice(id: string) {
    router.delete(`/notifications/${encodeURIComponent(id)}`, keepOpen({
        onFinish: () => {
            menuOpenId.value = null;
        },
    }));
}

function openNotice(item: AdminNotice) {
    open.value = false;

    router.post(`/notifications/${encodeURIComponent(item.id)}/read`, {}, {
        preserveScroll: true,
        onSuccess: () => router.visit(item.href),
        onError: () => router.visit(item.href),
    });
}
</script>

<template>
    <DropdownMenu v-model:open="open">
        <DropdownMenuTrigger as-child>
            <button
                type="button"
                class="relative inline-flex size-10 items-center justify-center rounded-full border border-[#c5def5] bg-white text-[#1b7ad8] shadow-sm transition hover:bg-[#f3f9ff] dark:border-white/10 dark:bg-white/10 dark:text-sky-200"
                aria-label="Notifications"
            >
                <Bell class="size-[18px]" />
                <span
                    v-if="unreadCount"
                    class="absolute -top-0.5 -right-0.5 flex h-[18px] min-w-[18px] items-center justify-center rounded-full bg-red-500 px-1 text-[11px] font-bold text-white"
                >
                    {{ unreadCount }}
                </span>
            </button>
        </DropdownMenuTrigger>

        <DropdownMenuContent
            align="end"
            :side-offset="10"
            class="z-[80] flex w-[min(58rem,calc(100vw-1.25rem))] max-h-[min(650px,calc(100vh-4.75rem))] flex-col overflow-hidden rounded-2xl border border-[#d7e8f6] bg-white p-0 shadow-[0_18px_50px_rgba(15,76,138,0.18)] dark:border-white/10 dark:bg-[#0f2438]"
        >
            <div class="border-b border-[#eef4f9] px-4 py-3.5 dark:border-white/10">
                <div class="flex min-w-0 items-start gap-3">
                    <div class="flex size-10 shrink-0 items-center justify-center rounded-full bg-[#e8f4fc] text-[#1b7ad8]">
                        <Bell class="size-5" />
                    </div>
                    <div class="min-w-0">
                        <div class="flex items-center gap-2">
                            <h2 class="text-base font-bold text-[#0f4c8a] dark:text-sky-100">
                                Notifications
                            </h2>
                            <span
                                v-if="unreadCount"
                                class="inline-flex h-5 min-w-5 items-center justify-center rounded-full bg-red-500 px-1.5 text-[11px] font-bold text-white"
                            >
                                {{ unreadCount }}
                            </span>
                        </div>
                        <p class="mt-0.5 text-xs leading-relaxed text-ink-muted">
                            Stay updated with system activities, requests, and important advisories.
                        </p>
                    </div>
                </div>
            </div>

            <div class="grid shrink-0 gap-1.5 border-b border-[#eef4f9] px-3 py-3 dark:border-white/10" :class="isSuperAdmin ? 'grid-cols-4' : 'grid-cols-5'">
                <button
                    v-for="item in filters"
                    :key="item.key"
                    type="button"
                    class="inline-flex h-9 min-w-0 items-center justify-center gap-1.5 rounded-full px-2 text-sm font-semibold whitespace-nowrap transition"
                    :class="
                        filter === item.key
                            ? 'bg-[#d7ebfb] text-[#1b6cb5]'
                            : 'bg-transparent text-[#5b7c99] hover:bg-[#f4f8fc] dark:bg-[#0b1b30] dark:text-slate-100'
                    "
                    @click="filter = item.key"
                >
                    <component :is="item.icon" v-if="item.icon" class="size-4" />
                    {{ item.label }}
                    <span class="text-sm font-bold tabular-nums">{{ item.count }}</span>
                </button>
            </div>

            <div class="min-h-0 flex-1 space-y-2.5 overflow-y-auto px-4 pb-3">
                <p
                    v-if="visibleNotices.length === 0"
                    class="rounded-2xl bg-[#f7fbfe] px-4 py-10 text-center text-sm text-ink-muted dark:bg-white/5"
                >
                    You're all caught up. No notifications in this category.
                </p>

                <article
                    v-for="item in visibleNotices"
                    :key="item.id"
                    class="cursor-pointer rounded-xl border border-black/5 border-l-4 px-4 py-3.5 text-left"
                    :class="cardLook(item).card"
                    @click="openNotice(item)"
                >
                    <div class="flex items-start justify-between gap-3">
                        <span
                            class="inline-flex rounded-md px-2 py-0.5 text-xs font-bold tracking-wide"
                            :class="cardLook(item).badge"
                        >
                            {{ item.type_label }}
                        </span>
                        <div class="flex shrink-0 items-center gap-1">
                            <p class="text-[13px] font-medium whitespace-nowrap text-[#5c7286]">
                                {{ item.date_label }} · {{ item.time_label }}
                            </p>
                            <DropdownMenu
                                :open="menuOpenId === item.id"
                                @update:open="menuOpenId = $event ? item.id : null"
                            >
                                <DropdownMenuTrigger as-child>
                                    <button
                                        type="button"
                                        class="inline-flex size-7 items-center justify-center rounded-md text-[#5c7286] hover:bg-white/80"
                                        aria-label="Notification actions"
                                        @click.stop
                                    >
                                        <MoreVertical class="size-4" />
                                    </button>
                                </DropdownMenuTrigger>
                                <DropdownMenuContent align="end" class="z-[90] w-40">
                                    <DropdownMenuItem
                                        v-if="item.unread"
                                        @click.stop="markRead(item.id)"
                                    >
                                        Mark as Read
                                    </DropdownMenuItem>
                                    <DropdownMenuItem
                                        class="text-rose-600"
                                        @click.stop="removeNotice(item.id)"
                                    >
                                        Delete
                                    </DropdownMenuItem>
                                </DropdownMenuContent>
                            </DropdownMenu>
                        </div>
                    </div>
                    <h3 class="mt-2 text-[15px] leading-snug font-bold text-[#16324f] dark:text-sky-50">
                        {{ item.title }}
                    </h3>
                    <p
                        v-if="item.body"
                        class="mt-1 text-sm leading-6 text-[#3d5166] dark:text-slate-200"
                    >
                        {{ item.body }}
                    </p>
                    <p
                        v-if="item.subtitle"
                        class="mt-0.5 text-sm leading-6 text-[#3d5166]"
                    >
                        {{ item.subtitle }}
                    </p>
                    <span
                        v-if="item.group === 'rentals' && item.href"
                        class="mt-3 inline-flex h-8 items-center rounded-lg border border-[#1b7ad8] bg-white px-3 text-sm font-semibold text-[#1b7ad8] dark:bg-[#102036] dark:text-slate-100 dark:border-white/10"
                    >
                        View Request
                    </span>
                </article>
            </div>

            <div class="flex items-center justify-between gap-3 border-t border-[#eef4f9] px-4 py-3 dark:border-white/10">
                <button
                    type="button"
                    class="inline-flex items-center gap-1.5 text-sm font-medium text-[#0f4c8a] hover:underline disabled:text-[#7d93a8] disabled:no-underline"
                    :disabled="unreadCount === 0"
                    @click="markAllRead"
                >
                    <Check class="size-4" />
                    Mark all as read
                </button>
                <Link
                    href="/notifications"
                    class="inline-flex h-9 items-center gap-1.5 rounded-xl border border-[#1b7ad8] px-3 text-sm font-semibold text-[#1b7ad8] hover:bg-[#e8f4fc]"
                    @click="open = false"
                >
                    <List class="size-4" />
                    View All Notifications
                </Link>
            </div>
        </DropdownMenuContent>
    </DropdownMenu>
</template>
