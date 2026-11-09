<script setup lang="ts">
import { Form, Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { Building2, Save, UserRound } from '@lucide/vue';
import { computed } from 'vue';
import ProfileController from '@/actions/App/Http/Controllers/Settings/ProfileController';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { edit } from '@/routes/profile';
import { send } from '@/routes/verification';

type OrganizationSettings = {
    name: string;
    status_label: string;
    can_request_closure: boolean;
    closure_status: string;
    closure_reason: string | null;
    closure_details: string | null;
    closure_requested_at_label: string | null;
    closure_rejection_reason: string | null;
    closure_rejected_at_label: string | null;
    closure_reasons: string[];
};

const props = defineProps<{
    mustVerifyEmail?: boolean;
    status?: string;
    account?: {
        name: string | null;
        email: string | null;
        role_label: string | null;
        organization_name: string | null;
    };
    organization_settings?: OrganizationSettings | null;
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Settings', href: edit() }],
    },
});

const page = usePage();
const user = computed(() => page.props.auth.user);
const organizationName = computed(() => props.organization_settings?.name || props.account?.organization_name || '—');
const organizationStatus = computed(() => props.organization_settings?.status_label || null);

const fieldClass =
    'h-10 rounded-xl border-[#c5def5] bg-[#f7fbfe] text-ink focus-visible:border-[#1b7ad8] dark:border-white/10 dark:bg-white/5 dark:text-sky-50';

const organization = computed(() => props.organization_settings ?? null);
const closurePending = computed(() => organization.value?.closure_status === 'Pending Super Admin review');

const closureForm = useForm({
    reason: '',
    specify_reason: '',
    details: '',
});

function requestClosure() {
    closureForm.post('/settings/organization/closure', {
        preserveScroll: true,
        onSuccess: () => closureForm.reset(),
    });
}
</script>

<template>
    <Head title="Profile settings" />

    <section class="rounded-2xl border border-[#d7e8f6] bg-white shadow-sm dark:border-white/10 dark:bg-white/5">
        <header class="flex items-center gap-3 border-b border-[#eef4f9] px-5 py-4 dark:border-white/10">
            <span class="flex size-9 items-center justify-center rounded-xl bg-[#1b7ad8] text-white">
                <UserRound class="size-4" />
            </span>
            <div>
                <h2 class="font-semibold text-[#0f4c8a] dark:text-sky-100">Profile</h2>
                <p class="text-xs text-ink-muted dark:text-slate-400">{{ account?.role_label || 'Administrator' }} account details</p>
            </div>
        </header>

        <Form v-bind="ProfileController.update.form()" class="space-y-5 p-5" v-slot="{ errors, processing }">
            <div class="grid gap-4 sm:grid-cols-2">
                <div class="grid gap-1.5">
                    <Label for="name" class="text-ink dark:text-sky-100">Admin Name</Label>
                    <Input id="name" name="name" :default-value="user.name" required autocomplete="name" placeholder="Full name" :class="fieldClass" />
                    <InputError :message="errors.name" />
                </div>
                <div class="grid gap-1.5">
                    <Label for="email" class="text-ink dark:text-sky-100">Email Address</Label>
                    <Input
                        id="email"
                        type="email"
                        name="email"
                        :default-value="user.email"
                        required
                        autocomplete="username"
                        placeholder="Email address"
                        :class="fieldClass"
                    />
                    <InputError :message="errors.email" />
                </div>
            </div>

            <div v-if="mustVerifyEmail && !user.email_verified_at" class="rounded-xl bg-amber-50 px-4 py-3 text-sm text-amber-900 dark:bg-amber-500/10 dark:text-amber-200">
                Your email address is not verified.
                <Link :href="send()" as="button" class="font-semibold underline underline-offset-4">Resend the verification email.</Link>
                <p v-if="status === 'verification-link-sent'" class="mt-1 font-medium text-emerald-700 dark:text-emerald-300">
                    A new verification link was sent to your email address.
                </p>
            </div>

            <div v-if="!page.props.auth?.is_super_admin" class="grid gap-3 rounded-xl border border-[#eef4f9] bg-[#f8fbfe] p-4 sm:grid-cols-2 dark:border-white/10 dark:bg-white/5">
                <div class="flex items-start gap-3">
                    <Building2 class="mt-0.5 size-4 shrink-0 text-[#1b7ad8]" />
                    <div>
                        <p class="text-xs text-ink-muted">Organization Name</p>
                        <p class="font-semibold text-ink dark:text-sky-50">{{ organizationName }}</p>
                    </div>
                </div>
                <div>
                    <p class="text-xs text-ink-muted">Organization Status</p>
                    <span
                        class="mt-1 inline-flex rounded-full px-2.5 py-0.5 text-xs font-semibold"
                        :class="organizationStatus === 'Active' ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-300' : 'bg-slate-100 text-slate-700 dark:bg-white/10 dark:text-slate-300'"
                    >
                        {{ organizationStatus || '—' }}
                    </span>
                </div>
                <div v-if="organization && organization.closure_status !== 'No request'">
                    <p class="text-xs text-ink-muted">Account Closure</p>
                    <p
                        class="mt-1 text-sm font-semibold"
                        :class="organization.closure_status === 'Rejected' ? 'text-rose-600' : closurePending ? 'text-amber-700' : 'text-ink dark:text-sky-50'"
                    >
                        {{ organization.closure_status }}
                    </p>
                </div>
                <p
                    v-if="organization?.closure_rejected_at_label"
                    class="text-sm text-rose-600 sm:col-span-2"
                >
                    Rejected {{ organization.closure_rejected_at_label }} · {{ organization.closure_rejection_reason || 'Closure request was rejected.' }}
                </p>
            </div>

            <div class="flex justify-end">
                <Button type="submit" :disabled="processing" class="h-10 rounded-xl bg-[#1b7ad8] px-4 hover:bg-[#1567c4]" data-test="update-profile-button">
                    <Spinner v-if="processing" class="size-4" />
                    <Save v-else class="size-4" />
                    Save Changes
                </Button>
            </div>
        </Form>
    </section>

    <section
        v-if="organization && !page.props.auth?.is_super_admin"
        class="mt-5 rounded-2xl border border-rose-200 bg-white p-5 shadow-sm dark:border-rose-500/30 dark:bg-white/5"
    >
        <h2 class="font-semibold text-[#0f4c8a] dark:text-sky-100">Close Organization Account</h2>
        <p class="mt-1 max-w-2xl text-sm text-ink-muted dark:text-slate-400">
            If your barangay no longer wishes to use SafeSakay, you may request account closure. The account stays active until the SafeSakay Super Admin approves it.
        </p>

        <div v-if="closurePending" class="mt-4 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-100">
            <p class="font-semibold">Waiting for Super Admin</p>
            <p class="mt-1">Requested {{ organization.closure_requested_at_label || 'recently' }}.</p>
            <p class="mt-1">Reason: {{ organization.closure_reason || '—' }}</p>
            <p v-if="organization.closure_details" class="mt-1">{{ organization.closure_details }}</p>
        </div>

        <form v-else-if="organization.can_request_closure" class="mt-4 grid max-w-xl gap-3" @submit.prevent="requestClosure">
            <div class="grid gap-1.5">
                <Label for="closure-reason" class="text-ink dark:text-sky-100">Reason</Label>
                <select
                    id="closure-reason"
                    v-model="closureForm.reason"
                    required
                    class="h-10 rounded-xl border border-[#c5def5] bg-[#f7fbfe] px-3 text-sm text-ink dark:border-white/10 dark:bg-white/5 dark:text-sky-50"
                >
                    <option value="" disabled>Select a reason</option>
                    <option v-for="reason in organization.closure_reasons" :key="reason" :value="reason">{{ reason }}</option>
                </select>
                <InputError :message="closureForm.errors.reason" />
            </div>

            <div v-if="closureForm.reason === 'Other reason'" class="grid gap-1.5">
                <Label for="closure-specify" class="text-ink dark:text-sky-100">Specify the reason</Label>
                <Input id="closure-specify" v-model="closureForm.specify_reason" required maxlength="160" placeholder="Tell Super Admin why" :class="fieldClass" />
                <InputError :message="closureForm.errors.specify_reason" />
            </div>

            <div class="grid gap-1.5">
                <Label for="closure-details" class="text-ink dark:text-sky-100">Details</Label>
                <textarea
                    id="closure-details"
                    v-model="closureForm.details"
                    rows="3"
                    maxlength="1000"
                    placeholder="Optional details for Super Admin"
                    class="rounded-xl border border-[#c5def5] bg-[#f7fbfe] px-3 py-2 text-sm text-ink dark:border-white/10 dark:bg-white/5 dark:text-sky-50"
                />
                <InputError :message="closureForm.errors.details" />
            </div>

            <div>
                <Button type="submit" variant="outline" :disabled="closureForm.processing || !closureForm.reason" class="h-10 rounded-xl border-rose-300 bg-white px-4 text-rose-700 hover:bg-rose-50 dark:bg-[#102036] dark:text-slate-100 dark:border-white/10">
                    <Spinner v-if="closureForm.processing" class="size-4" />
                    Request Account Closure
                </Button>
            </div>
        </form>
    </section>
</template>
