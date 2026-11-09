<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { Bell, ImagePlus, LockKeyhole, Save, Trash2, Upload } from '@lucide/vue';
import { computed, ref } from 'vue';
import type { Component } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';

type Settings = {
    platform_name: string;
    platform_description: string | null;
    logo_url: string | null;
    notify_organization_registration: boolean;
    notify_approval_requests: boolean;
    notify_system_activity: boolean;
    session_timeout: number;
    max_login_attempts: number;
};

const props = defineProps<{
    settings: Settings;
    options: {
        session_timeouts: number[];
        login_attempts: number[];
    };
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Platform', href: '/platform' },
            { title: 'System Settings', href: '/platform/system-settings' },
        ],
    },
});

type Tab = 'branding' | 'notifications' | 'security';

const tabs: { id: Tab; label: string; icon: Component }[] = [
    { id: 'branding', label: 'Branding', icon: ImagePlus },
    { id: 'notifications', label: 'Notifications', icon: Bell },
    { id: 'security', label: 'Security', icon: LockKeyhole },
];

const activeTab = ref<Tab>('branding');

const form = useForm({
    _method: 'put',
    platform_name: props.settings.platform_name ?? 'SafeSakay',
    platform_description: props.settings.platform_description ?? '',
    logo: null as File | null,
    remove_logo: false,
    notify_organization_registration: props.settings.notify_organization_registration,
    notify_approval_requests: props.settings.notify_approval_requests,
    notify_system_activity: props.settings.notify_system_activity,
    session_timeout: props.settings.session_timeout,
    max_login_attempts: props.settings.max_login_attempts,
});

const logoInput = ref<HTMLInputElement | null>(null);
const logoPreview = ref<string | null>(props.settings.logo_url);

const notificationItems = [
    { key: 'notify_organization_registration', title: 'New registrations', hint: 'Barangays waiting for approval' },
    { key: 'notify_approval_requests', title: 'Organization requests', hint: 'Restore, closure, and reactivation' },
    { key: 'notify_system_activity', title: 'Platform activity', hint: 'Approvals, deactivations, and changes' },
] as const;

const hasChanges = computed(() => form.isDirty);

function timeoutLabel(minutes: number) {
    return minutes >= 60 ? `${minutes / 60} ${minutes === 60 ? 'hour' : 'hours'}` : `${minutes} minutes`;
}

function pickLogo(event: Event) {
    const input = event.target as HTMLInputElement;
    const file = input.files?.[0] ?? null;

    if (!file) {
        return;
    }

    if (logoPreview.value?.startsWith('blob:')) {
        URL.revokeObjectURL(logoPreview.value);
    }

    form.logo = file;
    form.remove_logo = false;
    logoPreview.value = URL.createObjectURL(file);
}

function removeLogo() {
    if (logoPreview.value?.startsWith('blob:')) {
        URL.revokeObjectURL(logoPreview.value);
    }

    form.logo = null;
    form.remove_logo = Boolean(props.settings.logo_url);
    logoPreview.value = null;

    if (logoInput.value) {
        logoInput.value.value = '';
    }
}

function save() {
    form.post('/platform/system-settings', {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => {
            form.logo = null;
            form.remove_logo = false;
            logoPreview.value = props.settings.logo_url;
            form.defaults();
        },
    });
}

const fieldClass =
    'h-11 w-full rounded-xl border border-[#c5def5] bg-white px-3 text-sm text-ink outline-none focus:border-[#1b7ad8] focus:ring-2 focus:ring-[#1b7ad8]/15 dark:border-white/10 dark:bg-white/5 dark:text-sky-50';
</script>

<template>
    <Head title="System Settings" />

    <div class="flex flex-1 flex-col gap-6 p-6">
        <div class="flex flex-wrap items-end justify-between gap-3">
            <div>
                <h1 class="text-2xl font-bold tracking-tight text-[#0f4c8a] dark:text-sky-100">System Settings</h1>
                <p class="mt-1 text-sm text-ink-muted dark:text-slate-400">Platform branding, alerts, and sign-in security.</p>
            </div>
            <Button
                type="button"
                class="h-10 rounded-xl bg-[#1b7ad8] px-4 text-white hover:bg-[#1567c4]"
                :disabled="form.processing || !hasChanges"
                @click="save"
            >
                <Spinner v-if="form.processing" class="size-4" />
                <Save v-else class="size-4" />
                Save
            </Button>
        </div>

        <div class="flex flex-wrap gap-2">
            <button
                v-for="tab in tabs"
                :key="tab.id"
                type="button"
                class="inline-flex items-center gap-2 rounded-full px-4 py-2 text-sm font-semibold transition"
                :class="activeTab === tab.id ? 'bg-[#1b7ad8] text-white' : 'bg-[#e8f4fc] text-[#1b7ad8] dark:bg-white/10 dark:text-sky-200'"
                @click="activeTab = tab.id"
            >
                <component :is="tab.icon" class="size-4" />
                {{ tab.label }}
            </button>
        </div>

        <section
            class="rounded-2xl border border-[#d7e8f6] bg-white p-5 shadow-[0_8px_24px_rgba(15,76,138,0.06)] dark:border-white/10 dark:bg-white/5"
        >
            <div v-if="activeTab === 'branding'" class="grid gap-6 lg:grid-cols-[minmax(0,1fr)_260px]">
                <div class="space-y-4">
                    <div class="grid gap-1.5">
                        <label for="platform_name" class="text-sm font-medium text-ink dark:text-sky-100">Platform name</label>
                        <input id="platform_name" v-model="form.platform_name" type="text" maxlength="100" :class="fieldClass" />
                        <InputError :message="form.errors.platform_name" />
                    </div>
                    <div class="grid gap-1.5">
                        <label for="platform_description" class="text-sm font-medium text-ink dark:text-sky-100">Description</label>
                        <textarea
                            id="platform_description"
                            v-model="form.platform_description"
                            rows="3"
                            maxlength="500"
                            :class="[fieldClass, 'h-auto resize-none py-2.5']"
                        />
                        <InputError :message="form.errors.platform_description" />
                    </div>
                </div>

                <div class="grid gap-2">
                    <span class="text-sm font-medium text-ink dark:text-sky-100">Logo</span>
                    <div class="flex aspect-square items-center justify-center overflow-hidden rounded-2xl border border-dashed border-[#c5def5] bg-[#f4f9fd] dark:border-white/10 dark:bg-white/5">
                        <img v-if="logoPreview" :src="logoPreview" alt="Platform logo" class="size-full object-contain p-4" />
                        <img v-else src="/images/safesakay-logo.png?v=2" alt="Default logo" class="size-full object-contain p-6 opacity-50" />
                    </div>
                    <input ref="logoInput" type="file" accept="image/png,image/jpeg,image/webp" class="hidden" @change="pickLogo" />
                    <div class="flex gap-2">
                        <Button type="button" variant="outline" class="h-9 flex-1 rounded-xl border-[#c5def5] text-[#1b7ad8]" @click="logoInput?.click()">
                            <Upload class="size-4" />
                            Upload
                        </Button>
                        <Button
                            v-if="logoPreview"
                            type="button"
                            variant="outline"
                            class="h-9 rounded-xl border-rose-200 text-rose-600 hover:bg-rose-50"
                            @click="removeLogo"
                        >
                            <Trash2 class="size-4" />
                        </Button>
                    </div>
                    <p class="text-xs text-ink-muted dark:text-slate-400">PNG, JPG, or WEBP · max 2 MB</p>
                    <InputError :message="form.errors.logo" />
                </div>
            </div>

            <div v-else-if="activeTab === 'notifications'" class="divide-y divide-[#eef4f9] dark:divide-white/10">
                <label
                    v-for="item in notificationItems"
                    :key="item.key"
                    class="flex cursor-pointer items-center justify-between gap-4 py-4 first:pt-0 last:pb-0"
                >
                    <span>
                        <span class="block text-sm font-semibold text-ink dark:text-sky-100">{{ item.title }}</span>
                        <span class="block text-xs text-ink-muted dark:text-slate-400">{{ item.hint }}</span>
                    </span>
                    <input v-model="form[item.key]" type="checkbox" class="size-5 shrink-0 rounded border-[#c5def5] accent-[#1b7ad8]" />
                </label>
            </div>

            <div v-else class="grid gap-5 md:grid-cols-2">
                <div class="grid gap-1.5">
                    <label for="session_timeout" class="text-sm font-medium text-ink dark:text-sky-100">Sign out when idle for</label>
                    <select id="session_timeout" v-model.number="form.session_timeout" :class="fieldClass">
                        <option v-for="minutes in options.session_timeouts" :key="minutes" :value="minutes">{{ timeoutLabel(minutes) }}</option>
                    </select>
                    <InputError :message="form.errors.session_timeout" />
                </div>
                <div class="grid gap-1.5">
                    <label for="max_login_attempts" class="text-sm font-medium text-ink dark:text-sky-100">Login attempts per minute</label>
                    <select id="max_login_attempts" v-model.number="form.max_login_attempts" :class="fieldClass">
                        <option v-for="attempts in options.login_attempts" :key="attempts" :value="attempts">{{ attempts }} attempts</option>
                    </select>
                    <InputError :message="form.errors.max_login_attempts" />
                </div>
                <p class="text-xs text-ink-muted md:col-span-2 dark:text-slate-400">
                    Applies to Admin, Super Admin, and mobile app sign-in.
                </p>
            </div>
        </section>
    </div>
</template>
