<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import { Eye, EyeOff, Lock, LogIn, ShieldCheck, User } from '@lucide/vue';
import { ref } from 'vue';
import AuthSceneBackground from '@/components/AuthSceneBackground.vue';
import InputError from '@/components/InputError.vue';
import { Spinner } from '@/components/ui/spinner';

defineOptions({
    layout: false,
});

const showPassword = ref(false);
</script>

<template>
    <Head title="Super Admin Login">
        <link rel="preconnect" href="https://fonts.bunny.net" />
        <link
            href="https://fonts.bunny.net/css?family=poppins:400,500,600,700,800"
            rel="stylesheet"
        />
    </Head>

    <div class="admin-login">
        <AuthSceneBackground />

        <div class="admin-login__shell">
            <aside class="admin-login__brand" aria-label="SafeSakay Super Admin">
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
                <p class="admin-login__slogan">Platform Super Admin</p>
            </aside>

            <main class="admin-login__panel">
                <div class="admin-login__card">
                    <div class="admin-login__header">
                        <div class="admin-login__avatar" aria-hidden="true">
                            <ShieldCheck class="size-7" :stroke-width="2.2" />
                        </div>
                        <h2>Super Admin Login</h2>
                        <p>Sign in to manage SafeSakay organizations</p>
                    </div>

                    <Form
                        action="/superadmin"
                        method="post"
                        :reset-on-success="['password']"
                        v-slot="{ errors, processing }"
                        class="flex flex-col gap-4"
                    >
                        <div>
                            <label class="sr-only" for="email">Email</label>
                            <div class="admin-login__field">
                                <User class="admin-login__icon" aria-hidden="true" />
                                <input
                                    id="email"
                                    type="text"
                                    name="email"
                                    required
                                    autofocus
                                    autocomplete="username"
                                    placeholder="Super Admin email"
                                />
                            </div>
                            <InputError class="mt-1.5 px-2" :message="errors.email" />
                        </div>

                        <div>
                            <label class="sr-only" for="password">Password</label>
                            <div class="admin-login__field">
                                <Lock class="admin-login__icon" aria-hidden="true" />
                                <input
                                    id="password"
                                    :type="showPassword ? 'text' : 'password'"
                                    name="password"
                                    required
                                    autocomplete="current-password"
                                    placeholder="Enter your password"
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

                        <div class="admin-login__meta">
                            <label for="remember">
                                <input id="remember" type="checkbox" name="remember" />
                                Remember me
                            </label>
                        </div>

                        <button
                            type="submit"
                            class="admin-login__submit"
                            :disabled="processing"
                            data-test="superadmin-login-button"
                        >
                            <Spinner v-if="processing" class="size-5 text-white" />
                            <LogIn v-else class="size-5" :stroke-width="2.3" />
                            Login
                        </button>
                    </Form>

                    <p class="admin-login__secure">
                        <span />
                        <span>
                            <ShieldCheck class="size-3.5" />
                            Super Admin Access
                        </span>
                        <span />
                    </p>
                </div>
            </main>
        </div>
    </div>
</template>

<style scoped src="../../../css/admin-auth.css"></style>
