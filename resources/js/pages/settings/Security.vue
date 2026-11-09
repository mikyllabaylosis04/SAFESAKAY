<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import { Eye, EyeOff, ShieldCheck } from '@lucide/vue';
import { ref } from 'vue';
import SecurityController from '@/actions/App/Http/Controllers/Settings/SecurityController';
import InputError from '@/components/InputError.vue';
import type { Props as ManagePasskeysProps } from '@/components/ManagePasskeys.vue';
import ManagePasskeys from '@/components/ManagePasskeys.vue';
import type { Props as ManageTwoFactorProps } from '@/components/ManageTwoFactor.vue';
import ManageTwoFactor from '@/components/ManageTwoFactor.vue';
import { Spinner } from '@/components/ui/spinner';
import { edit } from '@/routes/security';

type Props = {
    passwordRules: string;
} & ManagePasskeysProps &
    ManageTwoFactorProps;

defineProps<Props>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Settings', href: '/settings/profile' }, { title: 'Security', href: edit() }],
    },
});

const showCurrent = ref(false);
const showPassword = ref(false);
const showConfirm = ref(false);

const fieldClass =
    'h-10 w-full rounded-xl border border-[#c5def5] bg-[#f7fbfe] px-3 pr-10 text-sm text-ink outline-none focus:border-[#1b7ad8] focus:bg-white dark:bg-[#0b1b30] dark:text-slate-100';
</script>

<template>
    <Head title="Security settings" />

    <div class="space-y-4">
        <section class="rounded-2xl border border-[#d7e8f6] bg-white shadow-sm dark:border-white/10 dark:bg-white/5">
            <header class="flex items-center gap-3 border-b border-[#eef4f9] px-5 py-4 dark:border-white/10">
                <span class="flex size-9 items-center justify-center rounded-xl bg-[#1b7ad8] text-white">
                    <ShieldCheck class="size-4" />
                </span>
                <div>
                    <h2 class="font-semibold text-[#0f4c8a] dark:text-sky-100">Change Password</h2>
                    <p class="text-xs text-ink-muted dark:text-slate-400">Change the password you use to sign in to SafeSakay</p>
                </div>
            </header>

            <Form
                v-bind="SecurityController.update.form()"
                :options="{ preserveScroll: true }"
                reset-on-success
                :reset-on-error="['password', 'password_confirmation', 'current_password']"
                class="max-w-xl space-y-4 p-5"
                v-slot="{ errors, processing, recentlySuccessful }"
            >
                <label class="block text-sm">
                    <span class="mb-1 block font-medium text-ink dark:text-sky-100">Current Password</span>
                    <div class="relative">
                        <input
                            id="current_password"
                            :type="showCurrent ? 'text' : 'password'"
                            name="current_password"
                            :class="fieldClass"
                            autocomplete="current-password"
                            placeholder="Current password"
                        />
                        <button
                            type="button"
                            class="absolute top-1/2 right-2 flex size-7 -translate-y-1/2 items-center justify-center text-ink-muted hover:text-[#1b7ad8]"
                            :aria-label="showCurrent ? 'Hide password' : 'Show password'"
                            @click="showCurrent = !showCurrent"
                        >
                            <EyeOff v-if="showCurrent" class="size-4" />
                            <Eye v-else class="size-4" />
                        </button>
                    </div>
                    <InputError class="mt-1" :message="errors.current_password" />
                </label>

                <label class="block text-sm">
                    <span class="mb-1 block font-medium text-ink dark:text-sky-100">New Password</span>
                    <div class="relative">
                        <input
                            id="password"
                            :type="showPassword ? 'text' : 'password'"
                            name="password"
                            :class="fieldClass"
                            autocomplete="new-password"
                            placeholder="New password"
                        />
                        <button
                            type="button"
                            class="absolute top-1/2 right-2 flex size-7 -translate-y-1/2 items-center justify-center text-ink-muted hover:text-[#1b7ad8]"
                            :aria-label="showPassword ? 'Hide password' : 'Show password'"
                            @click="showPassword = !showPassword"
                        >
                            <EyeOff v-if="showPassword" class="size-4" />
                            <Eye v-else class="size-4" />
                        </button>
                    </div>
                    <p class="mt-1 text-xs text-ink-muted">
                        At least 8 characters, with uppercase and lowercase letters, a number, and a special character (! @ # $ %).
                    </p>
                    <InputError class="mt-1" :message="errors.password" />
                </label>

                <label class="block text-sm">
                    <span class="mb-1 block font-medium text-ink dark:text-sky-100">Confirm New Password</span>
                    <div class="relative">
                        <input
                            id="password_confirmation"
                            :type="showConfirm ? 'text' : 'password'"
                            name="password_confirmation"
                            :class="fieldClass"
                            autocomplete="new-password"
                            placeholder="Confirm password"
                        />
                        <button
                            type="button"
                            class="absolute top-1/2 right-2 flex size-7 -translate-y-1/2 items-center justify-center text-ink-muted hover:text-[#1b7ad8]"
                            :aria-label="showConfirm ? 'Hide password' : 'Show password'"
                            @click="showConfirm = !showConfirm"
                        >
                            <EyeOff v-if="showConfirm" class="size-4" />
                            <Eye v-else class="size-4" />
                        </button>
                    </div>
                    <InputError class="mt-1" :message="errors.password_confirmation" />
                </label>

                <div class="flex items-center gap-3">
                    <button
                        type="submit"
                        class="inline-flex h-10 items-center gap-2 rounded-xl bg-[#1b7ad8] px-4 text-sm font-semibold text-white hover:bg-[#1567c4] disabled:opacity-60"
                        :disabled="processing"
                        data-test="update-password-button"
                    >
                        <Spinner v-if="processing" class="size-4" />
                        Change Password
                    </button>
                    <span v-if="recentlySuccessful" class="text-xs font-medium text-emerald-600">Password changed.</span>
                </div>
            </Form>
        </section>

        <ManageTwoFactor
            v-if="canManageTwoFactor"
            :canManageTwoFactor="canManageTwoFactor"
            :requiresConfirmation="requiresConfirmation"
            :twoFactorEnabled="twoFactorEnabled"
        />

        <ManagePasskeys
            v-if="canManagePasskeys"
            :canManagePasskeys="canManagePasskeys"
            :passkeys="passkeys"
        />
    </div>
</template>
