
<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import {
    ArrowLeft,
    Building2,
    CheckCircle2,
    Eye,
    EyeOff,
    Info,
    LockKeyhole,
    Mail,
    MapPin,
    Phone,
    Save,
    ShieldCheck,
    UserRound,
    Users,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import AddressPicker, {
    type PickedAddress,
} from '@/components/AddressPicker.vue';

const props = defineProps<{
    takenLocations?: Array<{
        name: string;
        address: string;
    }>;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Platform', href: '/platform' },
            {
                title: 'Organizations',
                href: '/platform/organizations',
            },
            {
                title: 'Onboard',
                href: '/platform/organizations/create',
            },
        ],
    },
});

const addressPickerOpen = ref(false);
const selectedAddress = ref<PickedAddress | null>(null);

const showPassword = ref(false);
const showConfirmPassword = ref(false);

const form = useForm({
    organization_name: '',
    organization_address: '',
    address_region: '',
    address_province: '',
    address_city: '',
    address_barangay: '',
    contact_number: '',
    name: '',
    email: '',
    password: '',
    password_confirmation: '',
});

const passwordRules = computed(() => ({
    length: form.password.length >= 8,
    uppercase: /[A-Z]/.test(form.password),
    lowercase: /[a-z]/.test(form.password),
    number: /[0-9]/.test(form.password),
    special: /[^A-Za-z0-9]/.test(form.password),
}));

const passwordValid = computed(() =>
    Object.values(passwordRules.value).every(Boolean),
);

const passwordsMatch = computed(
    () =>
        form.password.length > 0 &&
        form.password === form.password_confirmation,
);

const canSubmit = computed(
    () =>
        !form.processing &&
        form.organization_name.trim() !== '' &&
        form.organization_address.trim() !== '' &&
        form.contact_number.trim() !== '' &&
        form.name.trim() !== '' &&
        form.email.trim() !== '' &&
        passwordValid.value &&
        passwordsMatch.value,
);

function applyAddress(value: PickedAddress) {
    selectedAddress.value = value;

    form.organization_address = value.address;
    form.address_region = value.region;
    form.address_province = value.province;
    form.address_city = value.city;
    form.address_barangay = value.barangay;

    form.clearErrors(
        'organization_address',
        'address_region',
        'address_province',
        'address_city',
        'address_barangay',
    );
}

function unlockField(event: Event) {
    (event.target as HTMLInputElement).removeAttribute(
        'readonly',
    );
}

function submit() {
    if (!canSubmit.value) return;

    form.post('/platform/organizations', {
        preserveScroll: true,
        onError: () => {
            // Inertia displays validation errors below each field.
        },
    });
}
</script>

<template>
    <Head title="Onboard Organization" />

    <div
        class="flex flex-1 flex-col gap-6 bg-[#f7fafd] p-4 dark:bg-transparent sm:p-6 lg:p-8"
    >
        <!-- PAGE HEADER -->
        <div class="mx-auto w-full max-w-6xl">
            <Link
                href="/platform/organizations"
                class="mb-5 inline-flex items-center gap-2 text-sm font-medium text-ink-muted transition hover:text-[#1976d2] dark:text-slate-400"
            >
                <ArrowLeft class="size-4" />
                Back to Organizations
            </Link>

            <div
                class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between"
            >
                <div>
                    <div
                        class="mb-3 inline-flex items-center gap-2 rounded-full border border-[#d4e7ff] bg-[#eaf4ff] px-3 py-1.5 text-xs font-semibold text-[#1976d2]"
                    >
                        <Building2 class="size-3.5" />
                        Platform Administration
                    </div>

                    <h1
                        class="text-2xl font-bold tracking-tight text-ink dark:text-sky-100 sm:text-3xl"
                    >
                        Onboard Organization
                    </h1>

                    <p
                        class="mt-2 max-w-2xl text-sm leading-6 text-ink-muted dark:text-slate-400"
                    >
                        Register a new barangay port and create its
                        administrator account. The organization will
                        have access after successful registration.
                    </p>
                </div>
            </div>
        </div>

        <!-- MAIN FORM -->
        <form
            class="mx-auto w-full max-w-6xl space-y-6"
            autocomplete="off"
            @submit.prevent="submit"
        >
            <!-- AUTOFILL DECOYS -->
            <div
                aria-hidden="true"
                class="pointer-events-none absolute h-0 w-0 overflow-hidden opacity-0"
            >
                <input
                    type="text"
                    name="fake-username"
                    tabindex="-1"
                    autocomplete="username"
                />
                <input
                    type="password"
                    name="fake-password"
                    tabindex="-1"
                    autocomplete="current-password"
                />
            </div>

            <!-- ORGANIZATION INFORMATION -->
            <section
                class="overflow-hidden rounded-2xl border border-[#dce8f4] bg-white shadow-sm dark:border-white/10 dark:bg-slate-900/60"
            >
                <div
                    class="flex items-center gap-3 border-b border-[#e9f0f7] px-5 py-5 dark:border-white/10 sm:px-7"
                >
                    <div
                        class="flex size-11 shrink-0 items-center justify-center rounded-xl bg-[#e9f3ff] text-[#1976d2]"
                    >
                        <Building2 class="size-5" />
                    </div>

                    <div>
                        <h2
                            class="text-base font-bold text-ink dark:text-sky-100"
                        >
                            Organization Information
                        </h2>

                        <p
                            class="mt-0.5 text-xs text-ink-muted dark:text-slate-400"
                        >
                            Basic details of the barangay port
                        </p>
                    </div>
                </div>

                <div class="grid gap-5 p-5 sm:p-7 md:grid-cols-2">
                    <!-- ORGANIZATION NAME -->
                    <div class="space-y-2 md:col-span-2">
                        <label
                            for="organization-name"
                            class="block text-sm font-semibold text-ink dark:text-slate-200"
                        >
                            Organization Name
                            <span class="text-rose-500">*</span>
                        </label>

                        <div class="relative">
                            <Building2
                                class="pointer-events-none absolute top-1/2 left-3.5 size-4 -translate-y-1/2 text-[#91a7bc]"
                            />

                            <input
                                id="organization-name"
                                v-model="form.organization_name"
                                type="text"
                                maxlength="255"
                                required
                                autocomplete="organization"
                                placeholder="Enter organization or barangay port name"
                                :aria-invalid="Boolean(form.errors.organization_name)"
                                class="h-11 w-full rounded-xl border border-[#d4e3f1] bg-[#f9fbfe] pr-4 pl-10 text-sm text-ink outline-none transition placeholder:text-[#a3b3c3] focus:border-[#4095e7] focus:ring-2 focus:ring-blue-100 dark:border-white/10 dark:bg-white/5 dark:text-white"
                            />
                        </div>

                        <p
                            v-if="form.errors.organization_name"
                            class="text-xs text-rose-600"
                        >
                            {{ form.errors.organization_name }}
                        </p>
                    </div>

                    <!-- ADDRESS -->
                    <div class="space-y-2 md:col-span-2">
                        <label
                            class="block text-sm font-semibold text-ink dark:text-slate-200"
                        >
                            Organization Address
                            <span class="text-rose-500">*</span>
                        </label>

                        <button
                            type="button"
                            :aria-invalid="Boolean(form.errors.organization_address)"
                            class="flex min-h-12 w-full items-center justify-between gap-3 rounded-xl border border-[#d4e3f1] bg-[#f9fbfe] px-4 py-3 text-left text-sm transition hover:border-[#4095e7] hover:bg-[#f1f8ff] focus-visible:outline-2 focus-visible:outline-blue-500 dark:border-white/10 dark:bg-white/5"
                            @click="addressPickerOpen = true"
                        >
                            <span class="flex min-w-0 items-center gap-3">
                                <MapPin
                                    class="size-4 shrink-0 text-[#1976d2]"
                                />

                                <span
                                    class="break-words"
                                    :class="
                                        form.organization_address
                                            ? 'text-ink dark:text-white'
                                            : 'text-[#a3b3c3]'
                                    "
                                >
                                    {{
                                        form.organization_address ||
                                        'Select region, province, city, and barangay'
                                    }}
                                </span>
                            </span>

                            <span
                                class="shrink-0 rounded-lg bg-[#e8f3ff] px-3 py-1.5 text-xs font-semibold text-[#1976d2]"
                            >
                                {{ form.organization_address ? 'Change' : 'Select' }}
                            </span>
                        </button>

                        <p class="text-xs text-ink-muted">
                            Select the official barangay location.
                        </p>

                        <p
                            v-if="form.errors.organization_address"
                            class="text-xs text-rose-600"
                        >
                            {{ form.errors.organization_address }}
                        </p>

                        <template
                            v-for="field in [
                                'address_region',
                                'address_province',
                                'address_city',
                                'address_barangay',
                            ] as const"
                            :key="field"
                        >
                            <p
                                v-if="form.errors[field]"
                                class="text-xs text-rose-600"
                            >
                                {{ form.errors[field] }}
                            </p>
                        </template>
                    </div>

                    <!-- CONTACT NUMBER -->
                    <div class="space-y-2 md:col-span-2">
                        <label
                            for="contact-number"
                            class="block text-sm font-semibold text-ink dark:text-slate-200"
                        >
                            Contact Number
                            <span class="text-rose-500">*</span>
                        </label>

                        <div class="relative">
                            <Phone
                                class="pointer-events-none absolute top-1/2 left-3.5 size-4 -translate-y-1/2 text-[#91a7bc]"
                            />

                            <input
                                id="contact-number"
                                v-model="form.contact_number"
                                type="tel"
                                inputmode="tel"
                                maxlength="30"
                                required
                                autocomplete="tel"
                                placeholder="Enter organization contact number"
                                :aria-invalid="Boolean(form.errors.contact_number)"
                                class="h-11 w-full rounded-xl border border-[#d4e3f1] bg-[#f9fbfe] pr-4 pl-10 text-sm text-ink outline-none transition placeholder:text-[#a3b3c3] focus:border-[#4095e7] focus:ring-2 focus:ring-blue-100 dark:border-white/10 dark:bg-white/5 dark:text-white"
                            />
                        </div>

                        <p
                            v-if="form.errors.contact_number"
                            class="text-xs text-rose-600"
                        >
                            {{ form.errors.contact_number }}
                        </p>
                    </div>
                </div>
            </section>

            <!-- ADMIN ACCOUNT -->
            <section
                class="overflow-hidden rounded-2xl border border-[#dce8f4] bg-white shadow-sm dark:border-white/10 dark:bg-slate-900/60"
            >
                <div
                    class="flex items-center gap-3 border-b border-[#e9f0f7] px-5 py-5 dark:border-white/10 sm:px-7"
                >
                    <div
                        class="flex size-11 shrink-0 items-center justify-center rounded-xl bg-[#eaf8f0] text-[#15965a]"
                    >
                        <Users class="size-5" />
                    </div>

                    <div>
                        <h2
                            class="text-base font-bold text-ink dark:text-sky-100"
                        >
                            Barangay Administrator
                        </h2>

                        <p
                            class="mt-0.5 text-xs text-ink-muted dark:text-slate-400"
                        >
                            Create the account responsible for managing this organization
                        </p>
                    </div>
                </div>

                <div class="grid gap-5 p-5 sm:p-7 md:grid-cols-2">
                    <!-- ADMIN NAME -->
                    <div class="space-y-2">
                        <label
                            for="admin-name"
                            class="block text-sm font-semibold text-ink dark:text-slate-200"
                        >
                            Administrator Name
                            <span class="text-rose-500">*</span>
                        </label>

                        <div class="relative">
                            <UserRound
                                class="pointer-events-none absolute top-1/2 left-3.5 size-4 -translate-y-1/2 text-[#91a7bc]"
                            />

                            <input
                                id="admin-name"
                                v-model="form.name"
                                type="text"
                                required
                                maxlength="255"
                                autocomplete="off"
                                placeholder="Enter administrator's full name"
                                :aria-invalid="Boolean(form.errors.name)"
                                class="h-11 w-full rounded-xl border border-[#d4e3f1] bg-[#f9fbfe] pr-4 pl-10 text-sm text-ink outline-none transition placeholder:text-[#a3b3c3] focus:border-[#4095e7] focus:ring-2 focus:ring-blue-100 dark:border-white/10 dark:bg-white/5 dark:text-white"
                            />
                        </div>

                        <p
                            v-if="form.errors.name"
                            class="text-xs text-rose-600"
                        >
                            {{ form.errors.name }}
                        </p>
                    </div>

                    <!-- ADMIN EMAIL -->
                    <div class="space-y-2">
                        <label
                            for="admin-email"
                            class="block text-sm font-semibold text-ink dark:text-slate-200"
                        >
                            Administrator Email
                            <span class="text-rose-500">*</span>
                        </label>

                        <div class="relative">
                            <Mail
                                class="pointer-events-none absolute top-1/2 left-3.5 size-4 -translate-y-1/2 text-[#91a7bc]"
                            />

                            <input
                                id="admin-email"
                                v-model="form.email"
                                type="email"
                                name="admin_email"
                                required
                                autocomplete="off"
                                readonly
                                data-1p-ignore
                                data-lpignore="true"
                                placeholder="Enter administrator's email"
                                :aria-invalid="Boolean(form.errors.email)"
                                class="h-11 w-full rounded-xl border border-[#d4e3f1] bg-[#f9fbfe] pr-4 pl-10 text-sm text-ink outline-none transition placeholder:text-[#a3b3c3] focus:border-[#4095e7] focus:ring-2 focus:ring-blue-100 dark:border-white/10 dark:bg-white/5 dark:text-white"
                                @focus="unlockField"
                            />
                        </div>

                        <p
                            v-if="form.errors.email"
                            class="text-xs text-rose-600"
                        >
                            {{ form.errors.email }}
                        </p>
                    </div>

                    <!-- TEMPORARY PASSWORD -->
                    <div class="space-y-2">
                        <label
                            for="admin-password"
                            class="block text-sm font-semibold text-ink dark:text-slate-200"
                        >
                            Temporary Password
                            <span class="text-rose-500">*</span>
                        </label>

                        <div class="relative">
                            <LockKeyhole
                                class="pointer-events-none absolute top-1/2 left-3.5 size-4 -translate-y-1/2 text-[#91a7bc]"
                            />

                            <input
                                id="admin-password"
                                v-model="form.password"
                                :type="showPassword ? 'text' : 'password'"
                                name="admin_password"
                                required
                                autocomplete="new-password"
                                readonly
                                data-1p-ignore
                                data-lpignore="true"
                                placeholder="Create a secure password"
                                :aria-invalid="Boolean(form.errors.password)"
                                class="h-11 w-full rounded-xl border border-[#d4e3f1] bg-[#f9fbfe] pr-11 pl-10 text-sm text-ink outline-none transition placeholder:text-[#a3b3c3] focus:border-[#4095e7] focus:ring-2 focus:ring-blue-100 dark:border-white/10 dark:bg-white/5 dark:text-white"
                                @focus="unlockField"
                            />

                            <button
                                type="button"
                                class="absolute top-1/2 right-3 flex size-7 -translate-y-1/2 items-center justify-center rounded-md text-ink-muted transition hover:text-[#1976d2]"
                                :aria-label="showPassword ? 'Hide password' : 'Show password'"
                                @click="showPassword = !showPassword"
                            >
                                <EyeOff
                                    v-if="showPassword"
                                    class="size-4"
                                />
                                <Eye
                                    v-else
                                    class="size-4"
                                />
                            </button>
                        </div>

                        <p
                            v-if="form.errors.password"
                            class="text-xs text-rose-600"
                        >
                            {{ form.errors.password }}
                        </p>
                    </div>

                    <!-- CONFIRM PASSWORD -->
                    <div class="space-y-2">
                        <label
                            for="confirm-password"
                            class="block text-sm font-semibold text-ink dark:text-slate-200"
                        >
                            Confirm Password
                            <span class="text-rose-500">*</span>
                        </label>

                        <div class="relative">
                            <LockKeyhole
                                class="pointer-events-none absolute top-1/2 left-3.5 size-4 -translate-y-1/2 text-[#91a7bc]"
                            />

                            <input
                                id="confirm-password"
                                v-model="form.password_confirmation"
                                :type="showConfirmPassword ? 'text' : 'password'"
                                name="admin_password_confirmation"
                                required
                                autocomplete="new-password"
                                readonly
                                data-1p-ignore
                                data-lpignore="true"
                                placeholder="Re-enter your password"
                                :aria-invalid="Boolean(form.errors.password_confirmation)"
                                class="h-11 w-full rounded-xl border border-[#d4e3f1] bg-[#f9fbfe] pr-11 pl-10 text-sm text-ink outline-none transition placeholder:text-[#a3b3c3] focus:border-[#4095e7] focus:ring-2 focus:ring-blue-100 dark:border-white/10 dark:bg-white/5 dark:text-white"
                                @focus="unlockField"
                            />

                            <button
                                type="button"
                                class="absolute top-1/2 right-3 flex size-7 -translate-y-1/2 items-center justify-center rounded-md text-ink-muted transition hover:text-[#1976d2]"
                                :aria-label="showConfirmPassword ? 'Hide password' : 'Show password'"
                                @click="showConfirmPassword = !showConfirmPassword"
                            >
                                <EyeOff
                                    v-if="showConfirmPassword"
                                    class="size-4"
                                />
                                <Eye
                                    v-else
                                    class="size-4"
                                />
                            </button>
                        </div>

                        <p
                            v-if="form.password_confirmation && !passwordsMatch"
                            class="text-xs text-rose-600"
                        >
                            Passwords do not match.
                        </p>

                        <p
                            v-if="form.password_confirmation && passwordsMatch"
                            class="flex items-center gap-1 text-xs text-emerald-600"
                        >
                            <CheckCircle2 class="size-3.5" />
                            Passwords match.
                        </p>

                        <p
                            v-if="form.errors.password_confirmation"
                            class="text-xs text-rose-600"
                        >
                            {{ form.errors.password_confirmation }}
                        </p>
                    </div>

                    <!-- PASSWORD REQUIREMENTS -->
                    <div
                        class="rounded-xl border border-[#dceaf7] bg-[#f5f9fe] p-4 dark:border-white/10 dark:bg-white/5 md:col-span-2"
                    >
                        <div class="flex items-start gap-2">
                            <Info
                                class="mt-0.5 size-4 shrink-0 text-[#1976d2]"
                            />

                            <div class="w-full">
                                <p
                                    class="text-sm font-semibold text-ink dark:text-slate-200"
                                >
                                    Password Requirements
                                </p>

                                <p
                                    class="mt-1 text-xs text-ink-muted dark:text-slate-400"
                                >
                                    Use a strong temporary password for the administrator.
                                </p>

                                <div
                                    class="mt-3 grid gap-2 sm:grid-cols-2"
                                >
                                    <div
                                        v-for="rule in [
                                            {
                                                label: 'At least 8 characters',
                                                valid: passwordRules.length,
                                            },
                                            {
                                                label: 'Uppercase letter',
                                                valid: passwordRules.uppercase,
                                            },
                                            {
                                                label: 'Lowercase letter',
                                                valid: passwordRules.lowercase,
                                            },
                                            {
                                                label: 'At least one number',
                                                valid: passwordRules.number,
                                            },
                                            {
                                                label: 'Special character',
                                                valid: passwordRules.special,
                                            },
                                        ]"
                                        :key="rule.label"
                                        class="flex items-center gap-2 text-xs"
                                        :class="
                                            rule.valid
                                                ? 'text-emerald-600'
                                                : 'text-ink-muted dark:text-slate-400'
                                        "
                                    >
                                        <CheckCircle2 class="size-3.5" />
                                        {{ rule.label }}
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <!-- SUBMISSION FOOTER -->
            <section
                class="flex flex-col gap-4 rounded-2xl border border-[#dce8f4] bg-white p-5 shadow-sm dark:border-white/10 dark:bg-slate-900/60 sm:flex-row sm:items-center sm:justify-between sm:p-6"
            >
                <div class="flex items-start gap-3">
                    <div
                        class="flex size-10 shrink-0 items-center justify-center rounded-xl bg-[#eaf8f0] text-[#15965a]"
                    >
                        <ShieldCheck class="size-5" />
                    </div>

                    <div>
                        <p
                            class="text-sm font-semibold text-ink dark:text-slate-200"
                        >
                            Ready to create this organization?
                        </p>

                        <p
                            class="mt-1 max-w-md text-xs leading-5 text-ink-muted dark:text-slate-400"
                        >
                            Review the organization and administrator
                            details before submitting. This action
                            creates the organization and its admin account.
                        </p>
                    </div>
                </div>

                <div
                    class="flex shrink-0 flex-col gap-2 sm:flex-row"
                >
                    <Link
                        href="/platform/organizations"
                        class="inline-flex h-11 items-center justify-center rounded-xl border border-[#d4e3f1] bg-white px-5 text-sm font-semibold text-ink-soft transition hover:bg-[#f4f8fc] dark:border-white/10 dark:bg-white/5 dark:text-slate-300"
                    >
                        Cancel
                    </Link>

                    <button
                        type="submit"
                        :disabled="!canSubmit"
                        class="inline-flex h-11 items-center justify-center gap-2 rounded-xl bg-[#1976d2] px-6 text-sm font-semibold text-white shadow-sm transition hover:bg-[#1261b2] disabled:cursor-not-allowed disabled:opacity-50"
                    >
                        <Save class="size-4" />

                        {{
                            form.processing
                                ? 'Creating...'
                                : 'Create Organization'
                        }}
                    </button>
                </div>
            </section>
        </form>

        <!-- EXISTING ADDRESS PICKER -->
        <AddressPicker
            :open="addressPickerOpen"
            :taken-locations="props.takenLocations ?? []"
            @update:open="addressPickerOpen = $event"
            @select="applyAddress"
        />
    </div>
</template>
