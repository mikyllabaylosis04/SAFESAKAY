<script setup lang="ts">
import { Form, Head, Link, router } from '@inertiajs/vue3';
import {
    Building2,
    CircleCheck,
    Eye,
    EyeOff,
    Lock,
    Mail,
    MapPin,
    Phone,
    ShieldCheck,
    User,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import AddressPicker, {
    type PickedAddress,
} from '@/components/AddressPicker.vue';
import AuthSceneBackground from '@/components/AuthSceneBackground.vue';
import InputError from '@/components/InputError.vue';
import { Spinner } from '@/components/ui/spinner';

defineOptions({
    layout: false,
});

const props = defineProps<{
    accountCreated?: boolean;
    takenLocations?: Array<{
        name: string;
        address: string;
    }>;
}>();

const showPassword = ref(false);
const showConfirmPassword = ref(false);
const addressPickerOpen = ref(false);
const selectedAddress = ref<PickedAddress | null>(null);

const composedAddress = computed(
    () => selectedAddress.value?.address ?? '',
);

function applyAddress(value: PickedAddress) {
    selectedAddress.value = value;
}

function continueToLogin() {
    router.visit('/login');
}
</script>

<template>
    <Head title="Create Organization">
        <link rel="preconnect" href="https://fonts.bunny.net" />
        <link
            href="https://fonts.bunny.net/css?family=poppins:400,500,600,700,800"
            rel="stylesheet"
        />
    </Head>

    <div class="org-register">
        <AuthSceneBackground />

        <div class="org-register__shell">
            <aside class="org-register__brand" aria-label="SafeSakay">
                <img
                    src="/images/safesakay-logo.png?v=2"
                    alt="SafeSakay"
                    class="org-register__logo"
                />
                <h1 class="org-register__name">SAFESAKAY</h1>
                <div class="org-register__rule" aria-hidden="true">
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
                <p class="org-register__slogan">Safe Boats, Safe Trips, SafeSakay</p>
            </aside>

            <main class="org-register__panel">
                <div class="org-register__card">
                    <div class="org-register__header">
                        <div class="org-register__avatar" aria-hidden="true">
                            <Building2 class="size-6" :stroke-width="2.2" />
                        </div>
                        <div>
                            <h2>Create Organization</h2>
                            <p>
                                Register your barangay to start managing boat
                                trips.
                            </p>
                        </div>
                    </div>

                    <Form
                        action="/register"
                        method="post"
                        :reset-on-success="['password', 'password_confirmation']"
                        v-slot="{ errors, processing }"
                        class="org-register__form"
                    >
                        <input
                            type="hidden"
                            name="organization_address"
                            :value="composedAddress"
                        />
                        <input
                            type="hidden"
                            name="address_region"
                            :value="selectedAddress?.region ?? ''"
                        />
                        <input
                            type="hidden"
                            name="address_province"
                            :value="selectedAddress?.province ?? ''"
                        />
                        <input
                            type="hidden"
                            name="address_city"
                            :value="selectedAddress?.city ?? ''"
                        />
                        <input
                            type="hidden"
                            name="address_barangay"
                            :value="selectedAddress?.barangay ?? ''"
                        />

                        <div class="org-register__scroll">
                            <section class="org-register__section">
                                <div class="org-register__section-head">
                                    <span
                                        class="org-register__section-icon"
                                        aria-hidden="true"
                                    >
                                        <User class="size-4" />
                                    </span>
                                    <div>
                                        <h3>Admin Information</h3>
                                        <p>Your login for this workspace</p>
                                    </div>
                                </div>

                                <div class="org-register__field-block">
                                    <label for="name">
                                        Full Name <span>*</span>
                                    </label>
                                    <div class="org-register__field">
                                        <User class="org-register__icon" />
                                        <input
                                            id="name"
                                            type="text"
                                            name="name"
                                            required
                                            autofocus
                                            autocomplete="name"
                                            placeholder="Juan Dela Cruz"
                                        />
                                    </div>
                                    <InputError
                                        class="mt-1.5 px-2"
                                        :message="errors.name"
                                    />
                                </div>

                                <div class="org-register__field-block">
                                    <label for="email">
                                        Email Address <span>*</span>
                                    </label>
                                    <div class="org-register__field">
                                        <Mail class="org-register__icon" />
                                        <input
                                            id="email"
                                            type="email"
                                            name="email"
                                            required
                                            autocomplete="email"
                                            placeholder="admin@barangay.gov.ph"
                                        />
                                    </div>
                                    <InputError
                                        class="mt-1.5 px-2"
                                        :message="errors.email"
                                    />
                                </div>

                                <div class="org-register__pair">
                                    <div class="org-register__field-block">
                                        <label for="password">
                                            Password <span>*</span>
                                        </label>
                                        <div class="org-register__field">
                                            <Lock class="org-register__icon" />
                                            <input
                                                id="password"
                                                :type="
                                                    showPassword
                                                        ? 'text'
                                                        : 'password'
                                                "
                                                name="password"
                                                required
                                                autocomplete="new-password"
                                                placeholder="Password"
                                            />
                                            <button
                                                type="button"
                                                class="org-register__toggle"
                                                :aria-label="
                                                    showPassword
                                                        ? 'Hide password'
                                                        : 'Show password'
                                                "
                                                @click="
                                                    showPassword = !showPassword
                                                "
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
                                            :message="errors.password"
                                        />
                                    </div>

                                    <div class="org-register__field-block">
                                        <label for="password_confirmation">
                                            Confirm Password <span>*</span>
                                        </label>
                                        <div class="org-register__field">
                                            <Lock class="org-register__icon" />
                                            <input
                                                id="password_confirmation"
                                                :type="
                                                    showConfirmPassword
                                                        ? 'text'
                                                        : 'password'
                                                "
                                                name="password_confirmation"
                                                required
                                                autocomplete="new-password"
                                                placeholder="Confirm Password"
                                            />
                                            <button
                                                type="button"
                                                class="org-register__toggle"
                                                :aria-label="
                                                    showConfirmPassword
                                                        ? 'Hide password'
                                                        : 'Show password'
                                                "
                                                @click="
                                                    showConfirmPassword =
                                                        !showConfirmPassword
                                                "
                                            >
                                                <EyeOff
                                                    v-if="showConfirmPassword"
                                                    class="size-4"
                                                />
                                                <Eye v-else class="size-4" />
                                            </button>
                                        </div>
                                        <InputError
                                            class="mt-1.5 px-2"
                                            :message="
                                                errors.password_confirmation
                                            "
                                        />
                                    </div>
                                </div>
                            </section>

                            <section class="org-register__section">
                                <div class="org-register__section-head">
                                    <span
                                        class="org-register__section-icon"
                                        aria-hidden="true"
                                    >
                                        <Building2 class="size-4" />
                                    </span>
                                    <div>
                                        <h3>Organization Information</h3>
                                        <p>Barangay details for this account</p>
                                    </div>
                                </div>

                                <div class="org-register__field-block">
                                    <label for="organization_name">
                                        Barangay / Organization Name
                                        <span>*</span>
                                    </label>
                                    <div class="org-register__field">
                                        <Building2
                                            class="org-register__icon"
                                        />
                                        <input
                                            id="organization_name"
                                            type="text"
                                            name="organization_name"
                                            required
                                            autocomplete="organization"
                                            placeholder="Barangay Lakandula"
                                        />
                                    </div>
                                    <InputError
                                        class="mt-1.5 px-2"
                                        :message="errors.organization_name"
                                    />
                                </div>

                                <div class="org-register__field-block">
                                    <p class="org-register__legend">
                                        Address <span>*</span>
                                    </p>

                                    <button
                                        type="button"
                                        class="org-register__address-trigger"
                                        :class="{
                                            'is-empty': !selectedAddress,
                                        }"
                                        @click="addressPickerOpen = true"
                                    >
                                        <MapPin class="org-register__icon" />
                                        <span>
                                            {{
                                                selectedAddress?.address ||
                                                'Select region, province, city, and barangay'
                                            }}
                                        </span>
                                    </button>
                                    <InputError
                                        class="mt-1.5 px-2"
                                        :message="errors.organization_address"
                                    />
                                </div>

                                <div class="org-register__field-block">
                                    <label for="contact_number">
                                        Contact Number <span>*</span>
                                    </label>
                                    <div class="org-register__field">
                                        <Phone class="org-register__icon" />
                                        <input
                                            id="contact_number"
                                            type="text"
                                            name="contact_number"
                                            required
                                            autocomplete="tel"
                                            placeholder="09XXXXXXXXX"
                                        />
                                    </div>
                                    <InputError
                                        class="mt-1.5 px-2"
                                        :message="errors.contact_number"
                                    />
                                </div>
                            </section>
                        </div>

                        <div class="org-register__foot">
                            <label class="org-register__terms" for="terms">
                                <input
                                    id="terms"
                                    type="checkbox"
                                    name="terms"
                                    value="1"
                                    required
                                />
                                I agree to the Terms of Service and Privacy
                                Policy
                            </label>
                            <InputError class="px-2" :message="errors.terms" />

                            <button
                                type="submit"
                                class="org-register__submit"
                                :disabled="processing"
                            >
                                <Spinner
                                    v-if="processing"
                                    class="size-5 text-white"
                                />
                                <Building2
                                    v-else
                                    class="size-5"
                                    :stroke-width="2.3"
                                />
                                Create Account
                            </button>

                            <p class="org-register__secure">
                                <span />
                                <span>
                                    <ShieldCheck class="size-3.5" />
                                    Isolated workspace
                                </span>
                                <span />
                            </p>

                            <p class="org-register__switch">
                                Already have an account?
                                <Link href="/login">Sign In</Link>
                            </p>
                        </div>
                    </Form>
                </div>
            </main>
        </div>

        <div
            v-if="accountCreated"
            class="org-register__success"
            role="dialog"
            aria-modal="true"
            aria-labelledby="account-created-title"
        >
            <div class="org-register__success-card">
                <div class="org-register__success-icon" aria-hidden="true">
                    <CircleCheck class="size-10" :stroke-width="2.2" />
                </div>
                <h2 id="account-created-title">Registration submitted</h2>
                <p>
                    Super Admin will approve this organization first. After approval, sign in with your credentials.
                </p>
                <button
                    type="button"
                    class="org-register__submit"
                    @click="continueToLogin"
                >
                    Continue to Login
                </button>
            </div>
        </div>

        <AddressPicker
            :open="addressPickerOpen"
            :model-value="composedAddress"
            :taken-locations="props.takenLocations ?? []"
            @update:open="addressPickerOpen = $event"
            @select="applyAddress"
        />
    </div>
</template>

<style scoped>
.org-register {
    --ss-ink: #0f4c8a;
    --ss-text: #163a5f;
    --ss-muted: #7d93a8;
    --ss-field: #f7fbfe;
    --ss-border: #d4e6f5;
    --ss-icon: #8aa9c4;
    --ss-accent: #1b7ad8;
    --ss-card: #ffffff;
    --ss-placeholder: #9bb0c3;
    --ss-meta: #3d5f7c;
    --ss-line: #e4eef6;
    position: relative;
    isolation: isolate;
    height: 100svh;
    overflow: hidden;
    background: #b4daf2;
    color: var(--ss-text);
    font-family: Poppins, ui-sans-serif, system-ui, sans-serif;
    color-scheme: light;
}

html.dark .org-register {
    --ss-ink: #dbeeff;
    --ss-text: #e2eefa;
    --ss-muted: #8fa7bf;
    --ss-field: #0f2034;
    --ss-border: #24415f;
    --ss-icon: #6f92b3;
    --ss-accent: #4da3f0;
    --ss-card: #0c1a2b;
    --ss-placeholder: #5f7d99;
    --ss-meta: #a9c1d8;
    --ss-line: #1d3550;
    background: #071320;
    color-scheme: dark;
}

html.dark .org-register__logo {
    filter: drop-shadow(0 10px 22px rgba(0, 0, 0, 0.45));
}

html.dark .org-register__name,
html.dark .org-register__rule svg {
    color: #5fb0f5;
}

html.dark .org-register__rule span {
    background: #2a5378;
}

html.dark .org-register__slogan {
    color: #8fbfe6;
}

html.dark .org-register__card {
    background: var(--ss-card);
    border: 1px solid rgba(120, 170, 220, 0.12);
    box-shadow: 0 18px 46px rgba(0, 0, 0, 0.45);
}

html.dark .org-register__scroll {
    scrollbar-color: #2a4a6b transparent;
}

html.dark .org-register__scroll::-webkit-scrollbar-thumb {
    background: #2a4a6b;
}

html.dark .org-register__section {
    border-color: #1d3550;
    background: linear-gradient(180deg, #0f2135 0%, #0d1d30 100%);
}

html.dark .org-register__section-icon {
    background: rgba(77, 163, 240, 0.14);
    color: #5fb0f5;
}

html.dark .org-register__section-head h3 {
    color: #5fb0f5;
}

html.dark .org-register__field-block label span,
html.dark .org-register__legend span {
    color: #fb7185;
}

html.dark .org-register__address-trigger,
html.dark .org-register__field input[type='text'],
html.dark .org-register__field input[type='email'],
html.dark .org-register__field input[type='password'] {
    background: var(--ss-field);
    box-shadow: inset 0 1px 4px rgba(0, 0, 0, 0.3);
}

html.dark .org-register__address-trigger:hover,
html.dark .org-register__address-trigger:focus-visible,
html.dark .org-register__field input:focus {
    background: #132842;
    box-shadow: 0 0 0 4px rgba(77, 163, 240, 0.18);
}

html.dark .org-register__foot {
    background: var(--ss-card);
}

html.dark .org-register__submit {
    background: #1b7ad8;
    box-shadow: 0 10px 22px rgba(0, 0, 0, 0.35);
}

html.dark .org-register__submit:hover:not(:disabled) {
    background: #2386e6;
}

html.dark .org-register__success {
    background: rgba(2, 8, 16, 0.7);
}

html.dark .org-register__success-card {
    background: var(--ss-card);
    border: 1px solid rgba(120, 170, 220, 0.12);
    box-shadow: 0 24px 48px rgba(0, 0, 0, 0.5);
}

html.dark .org-register__success-icon {
    background: rgba(16, 185, 129, 0.15);
    color: #34d399;
}

.org-register__shell {
    position: relative;
    z-index: 1;
    display: flex;
    height: 100svh;
    flex-direction: column;
}

.org-register__brand {
    display: flex;
    flex-shrink: 0;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    padding: 1.1rem 1.25rem 0.5rem;
    text-align: center;
}

.org-register__logo {
    height: 5.25rem;
    width: auto;
    max-width: 10.5rem;
    object-fit: contain;
    filter: drop-shadow(0 10px 18px rgba(15, 76, 138, 0.16));
}

.org-register__name {
    margin-top: 0.5rem;
    font-size: 1.45rem;
    font-weight: 800;
    letter-spacing: 0.14em;
    line-height: 1;
    color: #1b7ad8;
}

.org-register__rule {
    display: flex;
    width: 12rem;
    align-items: center;
    gap: 0.7rem;
    margin-top: 0.75rem;
}

.org-register__rule span {
    height: 1px;
    flex: 1;
    background: #9cc7ea;
}

.org-register__rule svg {
    width: 1.15rem;
    height: 1.15rem;
    color: #1b7ad8;
}

.org-register__slogan {
    margin-top: 0.55rem;
    font-size: 0.88rem;
    font-weight: 500;
    color: #5b8fbf;
}

.org-register__panel {
    display: flex;
    min-height: 0;
    flex: 1;
    align-items: stretch;
    justify-content: center;
    padding: 0.75rem 1.15rem 1.15rem;
}

.org-register__card {
    display: flex;
    width: 100%;
    max-width: 520px;
    min-height: 0;
    flex-direction: column;
    overflow: hidden;
    border-radius: 1.6rem;
    background: #ffffff;
    box-shadow: 0 18px 46px rgba(15, 76, 138, 0.12);
}

.org-register__header {
    display: flex;
    flex-shrink: 0;
    flex-direction: column;
    align-items: center;
    padding: 1.35rem 1.5rem 1.1rem;
    text-align: center;
    border-bottom: 1px solid var(--ss-line);
}

.org-register__avatar {
    display: flex;
    height: 3.25rem;
    width: 3.25rem;
    align-items: center;
    justify-content: center;
    margin-bottom: 0.75rem;
    border-radius: 9999px;
    background: #1b7ad8;
    color: #fff;
    box-shadow: 0 8px 18px rgba(27, 122, 216, 0.28);
}

.org-register__header h2 {
    font-size: 1.5rem;
    font-weight: 700;
    letter-spacing: -0.02em;
    color: var(--ss-ink);
}

.org-register__header p {
    margin-top: 0.28rem;
    font-size: 0.84rem;
    color: var(--ss-muted);
}

.org-register__form {
    display: flex;
    min-height: 0;
    flex: 1;
    flex-direction: column;
}

.org-register__scroll {
    min-height: 0;
    flex: 1;
    padding: 1.15rem 1.45rem 0.35rem;
    overflow-x: hidden;
    overflow-y: auto;
    overscroll-behavior: contain;
    scrollbar-width: thin;
    scrollbar-color: #b7d4ee transparent;
}

.org-register__scroll::-webkit-scrollbar {
    width: 8px;
}

.org-register__scroll::-webkit-scrollbar-thumb {
    border-radius: 999px;
    background: #b7d4ee;
}

.org-register__section {
    display: flex;
    flex-direction: column;
    gap: 0.9rem;
    padding: 1rem 1rem 1.05rem;
    margin-bottom: 0.9rem;
    border: 1px solid #e8f2fa;
    border-radius: 1.15rem;
    background: linear-gradient(180deg, #f8fcff 0%, #f4f9fd 100%);
}

.org-register__section:last-child {
    margin-bottom: 0.35rem;
}

.org-register__section-head {
    display: flex;
    align-items: center;
    gap: 0.7rem;
}

.org-register__section-icon {
    display: inline-flex;
    height: 2.15rem;
    width: 2.15rem;
    flex-shrink: 0;
    align-items: center;
    justify-content: center;
    border-radius: 0.7rem;
    background: #e8f3fc;
    color: #1b7ad8;
}

.org-register__section-head h3 {
    font-size: 0.78rem;
    font-weight: 700;
    letter-spacing: 0.05em;
    text-transform: uppercase;
    color: #1b7ad8;
}

.org-register__section-head p {
    margin-top: 0.1rem;
    font-size: 0.75rem;
    color: var(--ss-muted);
}

.org-register__field-block label,
.org-register__legend {
    display: block;
    margin-bottom: 0.4rem;
    padding-left: 0.1rem;
    font-size: 0.8rem;
    font-weight: 600;
    color: var(--ss-ink);
}

.org-register__field-block label span,
.org-register__legend span {
    color: #e11d48;
}

.org-register__pair {
    display: grid;
    gap: 0.85rem;
}

.org-register__field {
    position: relative;
}

.org-register__address-trigger {
    position: relative;
    display: flex;
    min-height: 3rem;
    width: 100%;
    align-items: center;
    padding: 0.7rem 1rem 0.7rem 2.75rem;
    border: 1px solid var(--ss-border);
    border-radius: 0.95rem;
    background: #ffffff;
    text-align: left;
    font-size: 0.875rem;
    color: var(--ss-text);
    box-shadow: inset 0 1px 4px rgba(15, 76, 138, 0.04);
    outline: none;
}

.org-register__address-trigger span {
    min-width: 0;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.org-register__address-trigger.is-empty {
    color: var(--ss-placeholder);
}

.org-register__address-trigger:hover,
.org-register__address-trigger:focus-visible {
    border-color: var(--ss-accent);
    background: #ffffff;
    box-shadow: 0 0 0 4px rgba(27, 122, 216, 0.12);
}

.org-register__icon {
    pointer-events: none;
    position: absolute;
    top: 50%;
    left: 1rem;
    width: 18px;
    height: 18px;
    color: var(--ss-icon);
    transform: translateY(-50%);
}

.org-register__field input[type='text'],
.org-register__field input[type='email'],
.org-register__field input[type='password'] {
    height: 3rem;
    width: 100%;
    padding: 0 2.75rem;
    border: 1px solid var(--ss-border);
    border-radius: 0.95rem;
    background: #ffffff;
    font-size: 0.875rem;
    color: var(--ss-text);
    box-shadow: inset 0 1px 4px rgba(15, 76, 138, 0.04);
    outline: none;
    transition:
        border-color 0.15s ease,
        background-color 0.15s ease,
        box-shadow 0.15s ease;
}

.org-register__field input::placeholder {
    color: var(--ss-placeholder);
}

.org-register__field input:focus {
    border-color: var(--ss-accent);
    background-color: #ffffff;
    box-shadow: 0 0 0 4px rgba(27, 122, 216, 0.12);
}

.org-register__toggle {
    position: absolute;
    top: 50%;
    right: 0.7rem;
    display: flex;
    height: 2rem;
    width: 2rem;
    align-items: center;
    justify-content: center;
    border-radius: 9999px;
    color: var(--ss-icon);
    transform: translateY(-50%);
}

.org-register__toggle:hover {
    color: var(--ss-ink);
}

.org-register__foot {
    flex-shrink: 0;
    padding: 0.95rem 1.45rem 1.2rem;
    border-top: 1px solid var(--ss-line);
    background: #fff;
}

.org-register__terms {
    display: flex;
    cursor: pointer;
    align-items: flex-start;
    gap: 0.55rem;
    margin-bottom: 0.85rem;
    font-size: 0.82rem;
    line-height: 1.4;
    color: var(--ss-meta);
}

.org-register__terms input {
    margin-top: 0.15rem;
    width: 1rem;
    height: 1rem;
    flex-shrink: 0;
    accent-color: var(--ss-accent);
}

.org-register__submit {
    display: inline-flex;
    height: 3rem;
    width: 100%;
    align-items: center;
    justify-content: center;
    gap: 0.5rem;
    border-radius: 0.95rem;
    background: #1b7ad8;
    font-size: 1rem;
    font-weight: 600;
    color: #fff;
    box-shadow: 0 10px 22px rgba(27, 122, 216, 0.28);
}

.org-register__submit:hover:not(:disabled) {
    background: #1567c4;
}

.org-register__submit:disabled {
    cursor: not-allowed;
    opacity: 0.7;
}

.org-register__success {
    position: fixed;
    inset: 0;
    z-index: 40;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 1.25rem;
    background: rgba(8, 28, 52, 0.55);
    backdrop-filter: blur(6px);
}

.org-register__success-card {
    width: min(100%, 26rem);
    border-radius: 1.25rem;
    border: 1px solid var(--ss-border);
    background: #fff;
    padding: 2rem 1.6rem 1.5rem;
    text-align: center;
    box-shadow: 0 24px 48px rgba(15, 76, 138, 0.18);
}

.org-register__success-icon {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 4rem;
    height: 4rem;
    margin-bottom: 0.85rem;
    border-radius: 9999px;
    background: #e8f8ef;
    color: #0d9b5a;
}

.org-register__success-card h2 {
    font-size: 1.35rem;
    font-weight: 700;
    color: var(--ss-ink);
}

.org-register__success-card p {
    margin: 0.55rem 0 1.35rem;
    font-size: 0.9rem;
    line-height: 1.5;
    color: var(--ss-muted);
}

.org-register__secure {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 0.75rem;
    margin-top: 0.95rem;
    font-size: 0.75rem;
    font-weight: 500;
    color: var(--ss-muted);
}

.org-register__secure > span:first-child,
.org-register__secure > span:last-child {
    height: 1px;
    width: 3.2rem;
    background: var(--ss-line);
}

.org-register__secure > span:nth-child(2) {
    display: inline-flex;
    align-items: center;
    gap: 0.4rem;
}

.org-register__secure svg {
    color: var(--ss-accent);
}

.org-register__switch {
    margin-top: 0.7rem;
    text-align: center;
    font-size: 0.875rem;
    color: var(--ss-muted);
}

.org-register__switch a {
    font-weight: 700;
    color: var(--ss-accent);
}

.org-register__switch a:hover {
    text-decoration: underline;
}

@media (min-width: 700px) {
    .org-register__pair {
        grid-template-columns: 1fr 1fr;
    }
}

@media (min-width: 1024px) {
    .org-register__shell {
        flex-direction: row;
        align-items: stretch;
    }

    .org-register__brand {
        width: 44%;
        height: 100svh;
        padding: 0 6% 1rem 16%;
    }

    .org-register__logo {
        height: 13.25rem;
        max-width: 22rem;
    }

    .org-register__name {
        margin-top: 1rem;
        font-size: 2.35rem;
    }

    .org-register__slogan {
        font-size: 0.95rem;
    }

    .org-register__panel {
        width: 56%;
        align-items: center;
        padding: 1.75rem 5% 1.75rem 1.25rem;
    }

    .org-register__card {
        max-width: 540px;
        max-height: 100%;
    }

    .org-register__header {
        flex-direction: row;
        align-items: center;
        gap: 0.95rem;
        padding: 1.25rem 1.6rem;
        text-align: left;
    }

    .org-register__avatar {
        margin-bottom: 0;
    }
}
</style>
