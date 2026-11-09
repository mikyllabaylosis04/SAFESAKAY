<script setup lang="ts">
import { Form, Head, Link, usePage } from '@inertiajs/vue3';
import { ArrowLeft, Eye, EyeOff, Lock, ShieldCheck } from '@lucide/vue';
import { computed, ref } from 'vue';
import AuthSceneBackground from '@/components/AuthSceneBackground.vue';
import InputError from '@/components/InputError.vue';
import { Spinner } from '@/components/ui/spinner';
import { store } from '@/routes/password/confirm';

defineOptions({
    layout: false,
});

const page = usePage();
const showPassword = ref(false);
const backHref = computed(() =>
    page.props.auth?.is_super_admin ? '/platform' : '/dashboard',
);
</script>

<template>
    <Head title="Confirm password">
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
                    <Link
                        :href="backHref"
                        class="mb-4 inline-flex size-9 items-center justify-center rounded-full text-[#1b7ad8] hover:bg-[#e8f3fc] dark:text-sky-300 dark:hover:bg-white/10"
                        aria-label="Back"
                    >
                        <ArrowLeft class="size-5" />
                    </Link>

                    <div class="admin-login__header">
                        <div class="admin-login__avatar" aria-hidden="true">
                            <ShieldCheck class="size-7" :stroke-width="2.2" />
                        </div>
                        <h2>Confirm password</h2>
                        <p>Enter your current password to open account settings.</p>
                    </div>

                    <Form
                        v-bind="store.form()"
                        reset-on-success
                        v-slot="{ errors, processing }"
                        class="flex flex-col gap-4"
                    >
                        <div>
                            <label class="sr-only" for="password">Password</label>
                            <div class="admin-login__field">
                                <Lock class="admin-login__icon" aria-hidden="true" />
                                <input
                                    id="password"
                                    :type="showPassword ? 'text' : 'password'"
                                    name="password"
                                    required
                                    autofocus
                                    autocomplete="current-password"
                                    placeholder="Enter your password"
                                    data-test="confirm-password-input"
                                />
                                <button
                                    type="button"
                                    class="admin-login__toggle"
                                    :aria-label="showPassword ? 'Hide password' : 'Show password'"
                                    @click="showPassword = !showPassword"
                                >
                                    <EyeOff v-if="showPassword" class="size-4" />
                                    <Eye v-else class="size-4" />
                                </button>
                            </div>
                            <InputError class="mt-1.5 px-2" :message="errors.password" />
                        </div>

                        <button
                            type="submit"
                            class="admin-login__submit"
                            :disabled="processing"
                            data-test="confirm-password-button"
                        >
                            <Spinner v-if="processing" class="size-5 text-white" />
                            <Lock v-else class="size-5" :stroke-width="2.3" />
                            Continue
                        </button>
                    </Form>
                </div>
            </main>
        </div>
    </div>
</template>

<style scoped src="../../../css/admin-auth.css"></style>
