<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { Bell, Lock, Save } from '@lucide/vue';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';

type PreferenceItem = { key: string; label: string; description: string; locked: boolean; enabled: boolean };

const props = defineProps<{ items: PreferenceItem[] }>();

defineOptions({
    layout: { breadcrumbs: [{ title: 'Settings', href: '/settings/profile' }, { title: 'Notifications', href: '/settings/notifications' }] },
});

const form = useForm({
    preferences: Object.fromEntries(props.items.map((item) => [item.key, item.enabled])) as Record<string, boolean>,
});

function toggle(item: PreferenceItem) {
    if (!item.locked) {
        form.preferences[item.key] = !form.preferences[item.key];
    }
}

function save() {
    form.post('/settings/notifications', { preserveScroll: true });
}
</script>

<template>
    <Head title="Notification settings" />

    <section class="rounded-2xl border border-[#d7e8f6] bg-white shadow-sm dark:border-white/10 dark:bg-white/5">
        <header class="flex items-center gap-3 border-b border-[#eef4f9] px-5 py-4 dark:border-white/10">
            <span class="flex size-9 items-center justify-center rounded-xl bg-[#1b7ad8] text-white">
                <Bell class="size-4" />
            </span>
            <div>
                <h2 class="font-semibold text-[#0f4c8a] dark:text-sky-100">Notifications</h2>
                <p class="text-xs text-ink-muted dark:text-slate-400">Choose what appears in your notification bell</p>
            </div>
        </header>

        <form @submit.prevent="save">
            <ul class="divide-y divide-[#eef4f9] dark:divide-white/10">
                <li v-for="item in items" :key="item.key" class="flex items-center justify-between gap-4 px-5 py-3">
                    <div class="min-w-0">
                        <p class="flex items-center gap-2 text-sm font-semibold text-ink dark:text-sky-100">
                            {{ item.label }}
                            <span v-if="item.locked" class="inline-flex items-center gap-1 rounded-full bg-[#eef7fd] px-2 py-0.5 text-[11px] font-semibold text-[#1b7ad8] dark:bg-sky-500/10 dark:text-sky-200">
                                <Lock class="size-3" /> Always on
                            </span>
                        </p>
                        <p class="text-xs text-ink-muted dark:text-slate-400">{{ item.description }}</p>
                    </div>
                    <button
                        type="button"
                        role="switch"
                        :aria-checked="form.preferences[item.key]"
                        :aria-label="item.label"
                        :disabled="item.locked"
                        class="relative inline-flex h-6 w-11 shrink-0 items-center rounded-full transition disabled:cursor-not-allowed disabled:opacity-70"
                        :class="form.preferences[item.key] ? 'bg-[#1b7ad8]' : 'bg-slate-300 dark:bg-white/20'"
                        @click="toggle(item)"
                    >
                        <span class="inline-block size-5 rounded-full bg-white shadow transition" :class="form.preferences[item.key] ? 'translate-x-5' : 'translate-x-0.5'" />
                    </button>
                </li>
            </ul>

            <div class="flex flex-wrap items-center justify-between gap-3 border-t border-[#eef4f9] px-5 py-4 dark:border-white/10">
                <p class="max-w-md text-xs text-ink-muted dark:text-slate-400">
                    Turning an alert off only hides it from the bell. Rentals, payments, and refunds still appear on their pages. Safety alerts cannot be turned off.
                </p>
                <div class="flex items-center gap-3">
                    <span v-if="form.recentlySuccessful" class="text-xs font-medium text-emerald-600">Saved</span>
                    <Button type="submit" class="h-10 rounded-xl bg-[#1b7ad8] px-4 hover:bg-[#1567c4]" :disabled="form.processing">
                        <Spinner v-if="form.processing" class="size-4" />
                        <Save v-else class="size-4" />
                        Save Changes
                    </Button>
                </div>
            </div>
        </form>
    </section>
</template>
