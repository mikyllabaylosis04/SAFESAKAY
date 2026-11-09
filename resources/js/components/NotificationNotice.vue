
<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import {
    Bell,
    X,
    Check,
    CheckCheck,
    Megaphone,
    CalendarDays,
    ClipboardList,
    AlertTriangle,
    ArrowRight,
    Inbox,
} from '@lucide/vue';

import {
    computed,
    ref,
    onMounted,
    onUnmounted,
} from 'vue';

type Category =
    | 'announcement'
    | 'rental'
    | 'boat'
    | 'no-show';

type NotificationItem = {
    id: string | number;
    category: Category;
    title: string;
    message: string;
    created_at: string;
    read_at?: string | null;
    href?: string | null;
};

const props = defineProps<{
    notifications?: NotificationItem[];
}>();

const emit = defineEmits<{
    'mark-read': [id: string | number];
    'mark-all-read': [];
}>();

const demoNotifications: NotificationItem[] = [
    {
        id: 1,
        category: 'no-show',
        title: 'Passenger no-show recorded',
        message:
            'Allykim · BR-2026-00097 — Rental cancelled, no refund.',
        created_at: '2026-11-08T06:16:00+08:00',
        read_at: null,
    },
    {
        id: 2,
        category: 'boat',
        title: 'Boat assigned to trip',
        message:
            'CUTEEEE has been assigned to a regular trip (Lakandula → Placer).',
        created_at: '2026-11-08T05:21:00+08:00',
        read_at: null,
    },
    {
        id: 3,
        category: 'boat',
        title: 'Boat assigned to trip',
        message:
            'ANGEL BOMB has been assigned to a regular trip (Lakandula → Placer).',
        created_at: '2026-11-08T05:21:00+08:00',
        read_at: null,
    },
    {
        id: 4,
        category: 'rental',
        title: 'New rental request',
        message:
            'BR-2026-00103 · A new boat rental request was submitted.',
        created_at: '2026-11-07T21:14:00+08:00',
        read_at: null,
    },
    {
        id: 5,
        category: 'announcement',
        title: 'New announcement',
        message:
            'A new barangay announcement has been posted.',
        created_at: '2026-11-07T16:30:00+08:00',
        read_at: null,
    },
];

const root = ref<HTMLElement | null>(null);
const isOpen = ref(false);
const showAll = ref(false);
const activeTab = ref('all');

const locallyReadIds = ref<string[]>([]);

const items = computed(
    () => props.notifications ?? demoNotifications,
);

const tabs = [
    { key: 'all', label: 'All' },
    { key: 'announcement', label: 'Announcements' },
    { key: 'rental', label: 'Rental Requests' },
    { key: 'boat', label: 'Boat Updates' },
    { key: 'no-show', label: 'No-Show' },
];

function isUnread(item: NotificationItem) {
    return (
        !item.read_at &&
        !locallyReadIds.value.includes(String(item.id))
    );
}

const unreadCount = computed(
    () => items.value.filter(isUnread).length,
);

const filteredItems = computed(() => {
    const data =
        activeTab.value === 'all'
            ? items.value
            : items.value.filter(
                  (item) => item.category === activeTab.value,
              );

    return [...data].sort(
        (a, b) =>
            new Date(b.created_at).getTime() -
            new Date(a.created_at).getTime(),
    );
});

const visibleItems = computed(() =>
    showAll.value
        ? filteredItems.value
        : filteredItems.value.slice(0, 5),
);

function tabCount(category: string) {
    if (category === 'all') {
        return items.value.length;
    }

    return items.value.filter(
        (item) => item.category === category,
    ).length;
}

function categoryLabel(category: Category) {
    switch (category) {
        case 'announcement':
            return 'Announcement';
        case 'rental':
            return 'Rental Request';
        case 'boat':
            return 'Schedule';
        case 'no-show':
            return 'No-Show';
    }
}

function categoryIcon(category: Category) {
    switch (category) {
        case 'announcement':
            return Megaphone;
        case 'rental':
            return ClipboardList;
        case 'boat':
            return CalendarDays;
        case 'no-show':
            return AlertTriangle;
    }
}

function categoryClasses(category: Category) {
    switch (category) {
        case 'announcement':
            return {
                card: 'border-l-blue-500',
                icon: 'bg-blue-100 text-blue-600',
                badge: 'bg-blue-100 text-blue-700',
            };

        case 'rental':
            return {
                card: 'border-l-emerald-500',
                icon: 'bg-emerald-100 text-emerald-600',
                badge: 'bg-emerald-100 text-emerald-700',
            };

        case 'boat':
            return {
                card: 'border-l-violet-500',
                icon: 'bg-violet-100 text-violet-600',
                badge: 'bg-violet-100 text-violet-700',
            };

        case 'no-show':
            return {
                card: 'border-l-red-500',
                icon: 'bg-red-100 text-red-600',
                badge: 'bg-red-100 text-red-700',
            };
    }
}

function formatDate(value: string) {
    const date = new Date(value);

    if (Number.isNaN(date.getTime())) {
        return 'Date unavailable';
    }

    return new Intl.DateTimeFormat('en-PH', {
        timeZone: 'Asia/Manila',
        month: 'short',
        day: '2-digit',
        year: 'numeric',
        hour: 'numeric',
        minute: '2-digit',
    }).format(date);
}

function markRead(item: NotificationItem) {
    if (!isUnread(item)) {
        return;
    }

    locallyReadIds.value = [
        ...locallyReadIds.value,
        String(item.id),
    ];

    emit('mark-read', item.id);
}

function markAllRead() {
    const unread = items.value.filter(isUnread);

    if (unread.length === 0) {
        return;
    }

    locallyReadIds.value = [
        ...new Set([
            ...locallyReadIds.value,
            ...unread.map((item) => String(item.id)),
        ]),
    ];

    emit('mark-all-read');
}

function openNotification(item: NotificationItem) {
    markRead(item);

    if (item.href?.startsWith('/')) {
        isOpen.value = false;
        router.visit(item.href);
    }
}

function viewAll() {
    activeTab.value = 'all';
    showAll.value = true;
}

function selectTab(key: string) {
    activeTab.value = key;
    showAll.value = false;
}

function closeOutside(event: PointerEvent) {
    if (
        root.value &&
        !root.value.contains(event.target as Node)
    ) {
        isOpen.value = false;
    }
}

onMounted(() => {
    document.addEventListener('pointerdown', closeOutside);
});

onUnmounted(() => {
    document.removeEventListener('pointerdown', closeOutside);
});
</script>

<template>
    <div
        ref="root"
        class="relative"
        @keydown.esc.window="isOpen = false"
    >
        <button
            type="button"
            aria-label="Notifications"
            :aria-expanded="isOpen"
            class="relative flex h-11 w-11 items-center justify-center rounded-xl border border-slate-200 bg-white text-[#1b7ad8] transition hover:bg-blue-50 dark:bg-[#102036] dark:text-slate-100 dark:border-white/10"
            @click="isOpen = !isOpen"
        >
            <Bell class="h-5 w-5" />

            <span
                v-if="unreadCount > 0"
                class="absolute -right-2 -top-2 flex h-5 min-w-5 items-center justify-center rounded-full bg-red-500 px-1 text-[10px] font-bold text-white"
            >
                {{ unreadCount > 99 ? '99+' : unreadCount }}
            </span>
        </button>

        <div
            v-if="isOpen"
            class="fixed inset-x-3 top-20 z-[100] flex max-h-[85vh] flex-col overflow-hidden rounded-2xl border border-[#d7e8f6] bg-white shadow-2xl sm:absolute sm:inset-x-auto sm:right-0 sm:top-full sm:mt-3 sm:w-[640px] sm:max-w-[calc(100vw-24px)] dark:bg-[#102036] dark:text-slate-100 dark:border-white/10"
        >
            <div
                class="flex shrink-0 items-center justify-between gap-3 bg-[#1b7ad8] px-5 py-5 text-white"
            >
                <div class="flex items-center gap-3">
                    <div
                        class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-white/20"
                    >
                        <Bell class="h-6 w-6" />
                    </div>

                    <div>
                        <div class="flex items-center gap-2">
                            <h2 class="text-xl font-bold">
                                Notifications
                            </h2>

                            <span
                                v-if="unreadCount > 0"
                                class="rounded-full bg-red-500 px-2 py-0.5 text-xs font-bold"
                            >
                                {{ unreadCount }}
                            </span>
                        </div>

                        <p class="mt-1 text-xs text-blue-100">
                            Recent system activities and updates
                        </p>
                    </div>
                </div>

                <button
                    type="button"
                    aria-label="Close notifications"
                    class="rounded-lg p-2 text-white hover:bg-white/20"
                    @click="isOpen = false"
                >
                    <X class="h-5 w-5" />
                </button>
            </div>

            <div
                class="shrink-0 border-b border-slate-200 bg-white px-4 py-3 dark:bg-[#102036] dark:text-slate-100 dark:border-white/10"
            >
                <div
                    class="flex gap-2 overflow-x-auto pb-1"
                >
                    <button
                        v-for="tab in tabs"
                        :key="tab.key"
                        type="button"
                        class="flex shrink-0 items-center gap-1.5 rounded-lg border px-3 py-2 text-xs font-semibold transition"
                        :class="
                            activeTab === tab.key
                                ? 'border-[#1b7ad8] bg-[#1b7ad8] text-white'
                                : 'border-slate-200 bg-white text-slate-600 hover:bg-slate-50 dark:bg-[#102036] dark:text-slate-100 dark:border-white/10'
                        "
                        @click="selectTab(tab.key)"
                    >
                        {{ tab.label }}

                        <span
                            class="rounded-md px-1.5 py-0.5 text-[11px]"
                            :class="
                                activeTab === tab.key
                                    ? 'bg-white/20 text-white'
                                    : 'bg-slate-100 text-slate-600'
                            "
                        >
                            {{ tabCount(tab.key) }}
                        </span>
                    </button>
                </div>
            </div>

            <div
                class="min-h-0 flex-1 space-y-2 overflow-y-auto bg-[#f8fafc] p-3 sm:max-h-[480px] dark:bg-[#0b1b30] dark:text-slate-100"
            >
                <div
                    v-if="visibleItems.length === 0"
                    class="flex flex-col items-center justify-center py-14 text-center"
                >
                    <div
                        class="mb-3 flex h-14 w-14 items-center justify-center rounded-full bg-blue-50"
                    >
                        <Inbox class="h-7 w-7 text-blue-500" />
                    </div>

                    <h3 class="text-sm font-semibold text-slate-800">
                        No notifications
                    </h3>
                </div>

                <div
                    v-for="item in visibleItems"
                    :key="item.id"
                    class="group flex items-start gap-2 rounded-xl border border-slate-200 border-l-4 p-3 transition hover:shadow-sm"
                    :class="[
                        categoryClasses(item.category).card,
                        isUnread(item)
                            ? 'bg-[#f1f7ff]'
                            : 'bg-white dark:bg-[#102036] dark:text-slate-100',
                    ]"
                >
                    <button
                        type="button"
                        class="flex min-w-0 flex-1 items-start gap-3 text-left"
                        @click="openNotification(item)"
                    >
                        <div
                            class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl"
                            :class="categoryClasses(item.category).icon"
                        >
                            <component
                                :is="categoryIcon(item.category)"
                                class="h-5 w-5"
                            />
                        </div>

                        <div class="min-w-0 flex-1">
                            <div
                                class="flex flex-wrap items-center gap-2"
                            >
                                <span
                                    class="rounded-md px-2 py-0.5 text-[11px] font-semibold"
                                    :class="categoryClasses(item.category).badge"
                                >
                                    {{ categoryLabel(item.category) }}
                                </span>

                                <span
                                    v-if="isUnread(item)"
                                    class="h-2 w-2 rounded-full bg-[#1b7ad8]"
                                />
                            </div>

                            <h3
                                class="mt-1.5 text-sm font-bold leading-5 text-[#163d68]"
                            >
                                {{ item.title }}
                            </h3>

                            <p
                                class="mt-1 break-words text-[13px] leading-5 text-slate-600"
                            >
                                {{ item.message }}
                            </p>

                            <p
                                class="mt-2 text-xs text-slate-500"
                            >
                                {{ formatDate(item.created_at) }}
                            </p>
                        </div>
                    </button>

                    <div class="flex shrink-0 flex-col gap-2">
                        <button
                            v-if="isUnread(item)"
                            type="button"
                            class="rounded-lg p-1.5 text-slate-400 hover:bg-white hover:text-[#1b7ad8]"
                            :aria-label="'Mark ' + item.title + ' as read'"
                            title="Mark as read"
                            @click="markRead(item)"
                        >
                            <Check class="h-4 w-4" />
                        </button>

                        <ArrowRight
                            v-else-if="item.href"
                            class="h-4 w-4 text-slate-400"
                        />
                    </div>
                </div>

                <div
                    v-if="
                        !showAll &&
                        filteredItems.length > visibleItems.length
                    "
                    class="pt-2 text-center"
                >
                    <button
                        type="button"
                        class="text-sm font-semibold text-[#1b7ad8] hover:underline"
                        @click="showAll = true"
                    >
                        Show more notifications
                    </button>
                </div>
            </div>

            <div
                class="flex shrink-0 flex-wrap items-center justify-between gap-3 border-t border-slate-200 bg-white px-4 py-4 dark:bg-[#102036] dark:text-slate-100 dark:border-white/10"
            >
                <button
                    type="button"
                    class="flex items-center gap-2 text-sm font-semibold text-[#1b7ad8] hover:text-blue-800 disabled:cursor-not-allowed disabled:text-slate-400"
                    :disabled="unreadCount === 0"
                    @click="markAllRead"
                >
                    <CheckCheck class="h-4 w-4" />
                    Mark all as read
                </button>

                <button
                    type="button"
                    class="flex h-10 items-center gap-2 rounded-lg bg-[#1b7ad8] px-4 text-sm font-semibold text-white hover:bg-[#1567c4]"
                    @click="viewAll"
                >
                    View All Notifications
                    <ArrowRight class="h-4 w-4" />
                </button>
            </div>
        </div>
    </div>
</template>
