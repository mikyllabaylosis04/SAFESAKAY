<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import {
    Check,
    Eye,
    EyeOff,
    KeyRound,
    Lock,
    Mail,
    MailCheck,
    Send,
    ShieldCheck,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import AuthSceneBackground from '@/components/AuthSceneBackground.vue';
import InputError from '@/components/InputError.vue';
import { Spinner } from '@/components/ui/spinner';

type Step = 'email' | 'code' | 'password';

const props = defineProps<{
    status?: string;
    resetCode?: string | null;
    email?: string | null;
    step: Step;
    codeExpiresInMinutes: number;
    passwordRules?: string;
}>();

const steps: { key: Step; label: string }[] = [
    { key: 'email', label: 'Email' },
    { key: 'code', label: 'Code' },
    { key: 'password', label: 'New password' },
];

const emailForm = useForm({ email: props.email ?? '' });
const resendForm = useForm({ email: '' });
const codeForm = useForm({ code: '' });
const passwordForm = useForm({ password: '', password_confirmation: '' });

const showPassword = ref(false);
const showConfirmation = ref(false);

const stepIndex = computed(() =>
    steps.findIndex((item) => item.key === props.step),
);

const header = computed(() => {
    switch (props.step) {
        case 'code':
            return {
                icon: MailCheck,
                title: 'Enter Code',
                text: `Type the 6-digit code we sent to ${props.email}.`,
            };
        case 'password':
            return {
                icon: KeyRound,
                title: 'New Password',
                text: 'Choose a new password for your admin account.',
            };
        default:
            return {
                icon: Mail,
                title: 'Forgot Password',
                text: 'Enter your admin email and we will send you a 6-digit code.',
            };
    }
});

function sendCode() {
    emailForm.post('/forgot-password/send-code', { preserveScroll: true });
}

function resendCode() {
    resendForm.email = props.email ?? '';
    resendForm.post('/forgot-password/send-code', {
        preserveScroll: true,
        onSuccess: () => codeForm.reset('code'),
    });
}

function onCodeInput(event: Event) {
    const input = event.target as HTMLInputElement;
    const digits = input.value.replace(/\D/g, '').slice(0, 6);

    input.value = digits;
    codeForm.code = digits;
    codeForm.clearErrors('code');
}

function verifyCode() {
    codeForm.post('/forgot-password/verify-code', {
        preserveScroll: true,
        onError: () => codeForm.reset('code'),
    });
}

function resetPassword() {
    passwordForm.post('/forgot-password/reset', {
        onFinish: () => passwordForm.reset(),
    });
}

function startOver() {
    router.post('/forgot-password/start-over');
}
</script>

<template>
    <Head title="Forgot Password">
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
                            <component
                                :is="header.icon"
                                class="size-7"
                                :stroke-width="2.2"
                            />
                        </div>
                        <h2>{{ header.title }}</h2>
                        <p>{{ header.text }}</p>
                    </div>

                    <ol class="admin-login__steps" aria-label="Reset steps">
                        <li
                            v-for="(item, index) in steps"
                            :key="item.key"
                            :class="{
                                'is-active': index === stepIndex,
                                'is-done': index < stepIndex,
                            }"
                        >
                            <span>
                                <Check
                                    v-if="index < stepIndex"
                                    class="size-3"
                                    :stroke-width="3"
                                />
                                <template v-else>{{ index + 1 }}</template>
                            </span>
                            {{ item.label }}
                        </li>
                    </ol>

                    <div
                        v-if="status"
                        class="mb-4 rounded-xl bg-emerald-50 px-3 py-2 text-center text-sm font-medium text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-300"
                    >
                        {{ status }}
                    </div>
                    <div
                        v-if="resetCode"
                        class="mb-4 rounded-xl bg-amber-50 px-3 py-3 text-center dark:bg-amber-500/15"
                    >
                        <p class="text-xs font-medium text-amber-800 dark:text-amber-200">
                            Reset code
                        </p>
                        <p class="mt-1 font-mono text-2xl font-bold tracking-[0.3em] text-amber-950 dark:text-amber-100">
                            {{ resetCode }}
                        </p>
                    </div>

                    <form
                        v-if="step === 'email'"
                        class="flex flex-col gap-4"
                        @submit.prevent="sendCode"
                    >
                        <div>
                            <label class="sr-only" for="email">Email</label>
                            <div class="admin-login__field">
                                <Mail
                                    class="admin-login__icon"
                                    aria-hidden="true"
                                />
                                <input
                                    id="email"
                                    v-model="emailForm.email"
                                    type="email"
                                    required
                                    autofocus
                                    autocomplete="email"
                                    placeholder="Enter your admin email"
                                />
                            </div>
                            <InputError
                                class="mt-1.5 px-2"
                                :message="emailForm.errors.email"
                            />
                        </div>

                        <button
                            type="submit"
                            class="admin-login__submit"
                            :disabled="emailForm.processing"
                            data-test="send-reset-code-button"
                        >
                            <Spinner
                                v-if="emailForm.processing"
                                class="size-5 text-white"
                            />
                            <Send v-else class="size-5" :stroke-width="2.3" />
                            Send Code
                        </button>
                    </form>

                    <form
                        v-else-if="step === 'code'"
                        class="flex flex-col gap-4"
                        @submit.prevent="verifyCode"
                    >
                        <div>
                            <label class="sr-only" for="code"
                                >6-digit code</label
                            >
                            <input
                                id="code"
                                :value="codeForm.code"
                                class="admin-login__code"
                                type="text"
                                inputmode="numeric"
                                pattern="[0-9]{6}"
                                maxlength="6"
                                required
                                autofocus
                                autocomplete="one-time-code"
                                placeholder="000000"
                                @input="onCodeInput"
                            />
                            <InputError
                                class="mt-1.5 px-2 text-center"
                                :message="
                                    codeForm.errors.code ||
                                    resendForm.errors.email
                                "
                            />
                            <p class="admin-login__hint">
                                The code expires in
                                {{ codeExpiresInMinutes }} minutes.
                            </p>
                        </div>

                        <button
                            type="submit"
                            class="admin-login__submit"
                            :disabled="
                                codeForm.processing ||
                                codeForm.code.length !== 6
                            "
                            data-test="verify-reset-code-button"
                        >
                            <Spinner
                                v-if="codeForm.processing"
                                class="size-5 text-white"
                            />
                            <ShieldCheck
                                v-else
                                class="size-5"
                                :stroke-width="2.3"
                            />
                            Verify Code
                        </button>

                        <div class="admin-login__actions">
                            <button
                                type="button"
                                class="admin-login__link"
                                :disabled="resendForm.processing"
                                @click="resendCode"
                            >
                                {{
                                    resendForm.processing
                                        ? 'Sending...'
                                        : 'Resend code'
                                }}
                            </button>
                            <button
                                type="button"
                                class="admin-login__link"
                                @click="startOver"
                            >
                                Use another email
                            </button>
                        </div>
                    </form>

                    <form
                        v-else
                        class="flex flex-col gap-4"
                        @submit.prevent="resetPassword"
                    >
                        <div>
                            <label class="sr-only" for="password"
                                >New password</label
                            >
                            <div class="admin-login__field">
                                <Lock
                                    class="admin-login__icon"
                                    aria-hidden="true"
                                />
                                <input
                                    id="password"
                                    v-model="passwordForm.password"
                                    :type="showPassword ? 'text' : 'password'"
                                    required
                                    autofocus
                                    autocomplete="new-password"
                                    placeholder="New password"
                                    :passwordrules="passwordRules"
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
                            <p class="mt-1.5 px-2 text-xs text-ink-muted dark:text-slate-400">
                                At least 8 characters, with uppercase and lowercase letters, a number, and a special character (! @ # $ %).
                            </p>
                            <InputError
                                class="mt-1.5 px-2"
                                :message="passwordForm.errors.password"
                            />
                        </div>

                        <div>
                            <label class="sr-only" for="password_confirmation"
                                >Confirm new password</label
                            >
                            <div class="admin-login__field">
                                <Lock
                                    class="admin-login__icon"
                                    aria-hidden="true"
                                />
                                <input
                                    id="password_confirmation"
                                    v-model="passwordForm.password_confirmation"
                                    :type="
                                        showConfirmation ? 'text' : 'password'
                                    "
                                    required
                                    autocomplete="new-password"
                                    placeholder="Confirm new password"
                                    :passwordrules="passwordRules"
                                />
                                <button
                                    type="button"
                                    class="admin-login__toggle"
                                    :aria-label="
                                        showConfirmation
                                            ? 'Hide password'
                                            : 'Show password'
                                    "
                                    :tabindex="-1"
                                    @click="
                                        showConfirmation = !showConfirmation
                                    "
                                >
                                    <EyeOff
                                        v-if="showConfirmation"
                                        class="size-4"
                                    />
                                    <Eye v-else class="size-4" />
                                </button>
                            </div>
                            <InputError
                                class="mt-1.5 px-2"
                                :message="
                                    passwordForm.errors.password_confirmation
                                "
                            />
                            <p class="admin-login__hint">
                                Use at least 8 characters.
                            </p>
                        </div>

                        <button
                            type="submit"
                            class="admin-login__submit"
                            :disabled="passwordForm.processing"
                            data-test="reset-password-button"
                        >
                            <Spinner
                                v-if="passwordForm.processing"
                                class="size-5 text-white"
                            />
                            <KeyRound
                                v-else
                                class="size-5"
                                :stroke-width="2.3"
                            />
                            Reset Password
                        </button>
                    </form>

                    <p class="admin-login__switch">
                        Remembered your password?
                        <Link href="/login">Back to login</Link>
                    </p>
                </div>
            </main>
        </div>
    </div>
</template>

<style scoped src="../../../css/admin-auth.css"></style>
