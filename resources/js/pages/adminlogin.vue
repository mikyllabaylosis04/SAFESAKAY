<script setup lang="ts">
import { Form, Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { Eye, EyeOff, Info, Lock, LogIn, ShieldCheck, User } from '@lucide/vue';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogTitle,
} from '@/components/ui/dialog';
import { computed, ref } from 'vue';
import AuthSceneBackground from '@/components/AuthSceneBackground.vue';
import InputError from '@/components/InputError.vue';
import { Spinner } from '@/components/ui/spinner';
import { store } from '@/routes/login';
import { request } from '@/routes/password';

defineOptions({
    layout: false,
});

const props = defineProps<{
    status?: string;
    canResetPassword: boolean;
    restore_request_reasons?: string[];
}>();

const otherRestoreReason = 'Other reason';
const defaultRestoreReasons = [
    'Resuming pump boat operations',
    'Ready to use SafeSakay again',
    'Organization operations have resumed',
    'Financial or subscription concerns resolved',
    'Returning from another management system',
    'Other reason',
];
const restoreReasonOptions = computed(
    () => props.restore_request_reasons ?? defaultRestoreReasons,
);

const page = usePage();
const showPassword = ref(false);
const showRestoreRequest = ref(false);
const restoreEligible = ref(false);
const submittedLoginError = ref('');
const loginError = computed(() => submittedLoginError.value || String((page.props.errors as { email?: string } | undefined)?.email ?? ''));
const loginEmail = ref('');
const restoreForm = useForm({
    email: '',
    reason: '',
    specify_reason: '',
    details: '',
});

const isOtherRestoreReason = computed(() => restoreForm.reason === otherRestoreReason);
const canSubmitRestore = computed(() => {
    if (restoreForm.processing || !restoreForm.reason || restoreForm.details.trim().length < 10) {
        return false;
    }

    return !isOtherRestoreReason.value || restoreForm.specify_reason.trim().length >= 3;
});

function isOrganizationDeactivatedError(message?: string) {
    return (message || '').toLowerCase().includes('temporarily suspended by the safesakay super admin');
}

function isRestoreEligibleError(message?: string) {
    const text = (message || '').toLowerCase();

    return isOrganizationDeactivatedError(text) || text.includes('suspended') || text.includes('inactive') || text.includes('deactivated');
}

function syncRestoreEligibility(emailError?: string) {
    submittedLoginError.value = emailError || '';
    restoreEligible.value = isRestoreEligibleError(emailError);

    if (restoreEligible.value) {
        restoreForm.email = restoreForm.email || loginEmail.value;
        return;
    }

    showRestoreRequest.value = false;
}

function openRestoreRequest(emailError?: string) {
    syncRestoreEligibility(emailError);

    if (restoreEligible.value && !isOrganizationDeactivatedError(emailError)) {
        showRestoreRequest.value = true;
    }
}

function toggleRestoreRequest() {
    showRestoreRequest.value = !showRestoreRequest.value;

    if (showRestoreRequest.value && !restoreForm.email) {
        restoreForm.email = loginEmail.value;
    }
}

function submitRestoreRequest() {
    restoreForm.post('/restore-request', {
        preserveScroll: true,
        onSuccess: () => {
            restoreForm.reset('reason', 'specify_reason', 'details');
            showRestoreRequest.value = false;
        },
    });
}
</script>

<template>
    <Head title="Admin Login">
        <link rel="preconnect" href="https://fonts.bunny.net" />
        <link
            href="https://fonts.bunny.net/css?family=poppins:400,500,600,700,800"
            rel="stylesheet"
        />
    </Head>

    <div class="admin-login">
        <AuthSceneBackground />

        <div class="admin-login__shell">
            <aside class="admin-login__brand" aria-label="SafeSakay">
                <img
                    src="/images/safesakay-logo.png?v=2"
                    alt="SafeSakay"
                    class="admin-login__logo"
                />
                <h1 class="admin-login__name">SAFESAKAY</h1>
                <div class="admin-login__rule" aria-hidden="true">
                    <span />
                    <svg viewBox="0 0 24 24" fill="none">
                        <path
                            d="M2 12c2.5-3 5-3 7.5 0s5 3 7.5 0 5-3 7 0"
                            stroke="currentColor"
                            stroke-width="1.8"
                            stroke-linecap="round"
                        />
                    </svg>
                    <span />
                </div>
                <p class="admin-login__slogan">Safe Boats, Safe Trips, SafeSakay</p>
            </aside>

            <main class="admin-login__panel">
                <div class="admin-login__card">
                    <div class="admin-login__header">
                        <div class="admin-login__avatar" aria-hidden="true">
                            <User class="size-7" :stroke-width="2.2" />
                        </div>
                        <h2>Admin Login</h2>
                        <p>Sign in to access the admin dashboard</p>
                    </div>

                    <div
                        v-if="status"
                        class="mb-4 rounded-xl bg-emerald-50 px-3 py-2 text-center text-sm font-medium text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-300"
                    >
                        {{ status }}
                    </div>

                    <div
                        v-if="isOrganizationDeactivatedError(loginError)"
                        class="admin-login__deactivated"
                    >
                        <p class="admin-login__deactivated-title">
                            <Info class="size-4 shrink-0" />
                            Organization Temporarily Deactivated
                        </p>
                        <p>Your organization's access has been temporarily suspended by the SafeSakay Super Admin.</p>
                        <p>If you believe access should be restored, you may submit a restoration request for review.</p>
                        <button type="button" class="admin-login__deactivated-action" @click="toggleRestoreRequest">
                            Request Restoration
                        </button>
                    </div>

                    <Form
                        v-bind="store.form()"
                        :reset-on-success="['password']"
                        v-slot="{ errors, processing }"
                        class="flex flex-col gap-4"
                        :on-error="(formErrors) => syncRestoreEligibility(formErrors.email)"
                    >
                        <div>
                            <label class="sr-only" for="email">Username</label>
                            <div class="admin-login__field">
                                <User
                                    class="admin-login__icon"
                                    aria-hidden="true"
                                />
                                <input
                                    id="email"
                                    v-model="loginEmail"
                                    type="text"
                                    name="email"
                                    required
                                    autofocus
                                    :tabindex="1"
                                    autocomplete="username"
                                    placeholder="Enter your username"
                                    @blur="openRestoreRequest(errors.email)"
                                />
                            </div>
                            <InputError
                                v-if="!isOrganizationDeactivatedError(errors.email)"
                                class="mt-1.5 px-2"
                                :message="errors.email"
                            />
                            <button
                                v-if="isRestoreEligibleError(errors.email) && !isOrganizationDeactivatedError(errors.email)"
                                type="button"
                                class="admin-login__forgot mt-2 px-2"
                                @click="toggleRestoreRequest"
                            >
                                Request restore
                            </button>
                        </div>

                        <div>
                            <label class="sr-only" for="password"
                                >Password</label
                            >
                            <div class="admin-login__field">
                                <Lock
                                    class="admin-login__icon"
                                    aria-hidden="true"
                                />
                                <input
                                    id="password"
                                    :type="showPassword ? 'text' : 'password'"
                                    name="password"
                                    required
                                    :tabindex="2"
                                    autocomplete="current-password"
                                    placeholder="Enter your password"
                                />
                                <button
                                    type="button"
                                    class="admin-login__toggle"
                                    :aria-label="
                                        showPassword
                                            ? 'Hide password'
                                            : 'Show password'
                                    "
                                    :tabindex="-1"
                                    @click="showPassword = !showPassword"
                                >
                                    <EyeOff
                                        v-if="showPassword"
                                        class="size-4"
                                    />
                                    <Eye v-else class="size-4" />
                                </button>
                            </div>
                            <InputError
                                class="mt-1.5 px-2"
                                :message="errors.password"
                            />
                        </div>

                        <div class="admin-login__meta">
                            <label for="remember">
                                <input
                                    id="remember"
                                    type="checkbox"
                                    name="remember"
                                    :tabindex="3"
                                />
                                Remember me
                            </label>
                            <Link
                                v-if="canResetPassword"
                                :href="request()"
                                class="admin-login__forgot"
                                :tabindex="5"
                            >
                                Forgot password?
                            </Link>
                        </div>

                        <button
                            type="submit"
                            class="admin-login__submit"
                            :tabindex="4"
                            :disabled="processing"
                            data-test="login-button"
                        >
                            <Spinner
                                v-if="processing"
                                class="size-5 text-white"
                            />
                            <LogIn
                                v-else
                                class="size-5"
                                :stroke-width="2.3"
                            />
                            Login
                        </button>
                    </Form>

                    <p class="admin-login__secure">
                        <span />
                        <span>
                            <ShieldCheck class="size-3.5" />
                            Security Access
                        </span>
                        <span />
                    </p>

                    <p v-if="(restoreEligible || showRestoreRequest) && !isOrganizationDeactivatedError(loginError)" class="admin-login__switch">
                        Organization deactivated?
                        <button type="button" class="admin-login__forgot" @click="toggleRestoreRequest">
                            Request restore
                        </button>
                    </p>

                    <p class="admin-login__switch">
                        New organization?
                        <Link href="/register">Create Organization</Link>
                    </p>
                </div>
            </main>
        </div>

        <Dialog :open="showRestoreRequest" @update:open="(value) => (showRestoreRequest = value)">
            <DialogContent class="max-h-[90vh] overflow-y-auto border-[#2a2a2a] bg-[#111111] p-5 text-white sm:max-w-md">
                <DialogTitle class="pr-8 text-base font-bold text-white">Request Organization Restoration</DialogTitle>
                <DialogDescription class="text-sm leading-relaxed text-white/80">
                    Explain why this organization should be restored. Super Admin reviews the explanation before access is turned back on.
                </DialogDescription>
                <form class="mt-2 flex flex-col gap-3" @submit.prevent="submitRestoreRequest">
                    <div>
                        <label class="mb-1 block text-xs font-semibold text-white/80" for="restore-email">Admin email</label>
                        <input
                            id="restore-email"
                            v-model="restoreForm.email"
                            type="text"
                            required
                            autocomplete="username"
                            placeholder="Barangay admin email"
                            class="h-10 w-full rounded-lg border border-white/15 bg-black px-3 text-sm text-white outline-none placeholder:text-white/40"
                        />
                        <InputError class="mt-1.5" :message="restoreForm.errors.email" />
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-semibold text-white/80" for="restore-reason">Reason</label>
                        <select
                            id="restore-reason"
                            v-model="restoreForm.reason"
                            required
                            class="h-10 w-full rounded-lg border border-white/15 bg-black px-3 text-sm text-white outline-none"
                        >
                            <option disabled value="">Select reason for restore</option>
                            <option v-for="option in restoreReasonOptions" :key="option" :value="option">
                                {{ option }}
                            </option>
                        </select>
                        <InputError class="mt-1.5" :message="restoreForm.errors.reason" />
                    </div>
                    <div v-if="isOtherRestoreReason">
                        <label class="mb-1 block text-xs font-semibold text-white/80" for="restore-specify-reason">Specify reason</label>
                        <input
                            id="restore-specify-reason"
                            v-model="restoreForm.specify_reason"
                            type="text"
                            maxlength="160"
                            required
                            placeholder="Specify the reason for restore"
                            class="h-10 w-full rounded-lg border border-white/15 bg-black px-3 text-sm text-white outline-none placeholder:text-white/40"
                        />
                        <InputError class="mt-1.5" :message="restoreForm.errors.specify_reason" />
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-semibold text-white/80" for="restore-details">Explanation</label>
                        <textarea
                            id="restore-details"
                            v-model="restoreForm.details"
                            rows="4"
                            maxlength="1000"
                            required
                            placeholder="Explain why access should be restored"
                            class="w-full rounded-lg border border-white/15 bg-black px-3 py-2 text-sm text-white outline-none placeholder:text-white/40"
                        />
                        <InputError class="mt-1.5" :message="restoreForm.errors.details" />
                    </div>
                    <button type="submit" class="admin-login__submit" :disabled="!canSubmitRestore">
                        <Spinner v-if="restoreForm.processing" class="size-5 text-white" />
                        Submit Request
                    </button>
                </form>
            </DialogContent>
        </Dialog>
    </div>
</template>

<style scoped src="../../css/admin-auth.css"></style>
