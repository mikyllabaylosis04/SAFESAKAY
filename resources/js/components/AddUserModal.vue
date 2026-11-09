
<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import {
    Save,
    X,
    UserRound,
    Ship,
    LockKeyhole,
} from '@lucide/vue';
import { computed, watch } from 'vue';

import InputError from '@/components/InputError.vue';
import PasswordInput from '@/components/PasswordInput.vue';
import { Button } from '@/components/ui/button';

import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogTitle,
} from '@/components/ui/dialog';

import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';

import { Spinner } from '@/components/ui/spinner';
import { cn } from '@/lib/utils';

import type {
    ManagedUser,
    OperatorBoatOption,
    UserOrganizationOption,
    UserRole,
    UserRoleOption,
} from '@/types/user';

const props = defineProps<{
    open: boolean;
    roles: UserRoleOption[];
    organization?: UserOrganizationOption | null;
    boats?: OperatorBoatOption[];
    user?: ManagedUser | null;
    defaultRole?: UserRole;
}>();

const emit = defineEmits<{
    'update:open': [value: boolean];
}>();

// Shared input design
const fieldClass =
    'h-10 w-full rounded-lg border border-[#c5def5] bg-[#f8fbfe] text-sm text-ink shadow-none focus-visible:border-[#1b7ad8] focus-visible:ring-[#1b7ad8]/20 dark:border-white/10 dark:bg-white/5 dark:text-sky-50';

// Existing form fields
const form = useForm({
    name: '',
    email: '',
    role: 'user' as UserRole,
    boat_id: '',
    is_active: '1',
    password: '',
    password_confirmation: '',
});

// Form state
const isEditing = computed(() => Boolean(props.user));

const isOperator = computed(
    () => form.role === 'operator',
);

const boats = computed(() => props.boats ?? []);

const subtitle = computed(() => {
    if (isEditing.value) {
        return 'Update user account information.';
    }

    return 'Create a new SafeSakay account.';
});

// Keep role values unchanged; display Passenger instead of Customer
function displayRole(label: string, value: UserRole): string {
    if (value === 'passenger' || label.toLowerCase() === 'customer') {
        return 'Passenger';
    }

    return label;
}

// Populate edit form or reset add form
watch(
    () => [props.open, props.user] as const,
    ([open, user]) => {
        if (!open) {
            form.reset();
            form.clearErrors();
            return;
        }

        if (user) {
            form.name = user.name;
            form.email = user.email;
            form.role = user.role;
            form.boat_id = user.boat_id
                ? String(user.boat_id)
                : '';
            form.is_active = user.is_active ? '1' : '0';
            form.password = '';
            form.password_confirmation = '';
            form.clearErrors();
            return;
        }

        form.name = '';
        form.email = '';
        form.role = props.defaultRole ?? 'user';
        form.boat_id = '';
        form.is_active = '1';
        form.password = '';
        form.password_confirmation = '';
        form.clearErrors();
    },
);

// Clear stale boat assignment when changing away from Operator
watch(
    () => form.role,
    (role) => {
        if (role !== 'operator') {
            form.boat_id = '';
        }
    },
);

function close() {
    if (form.processing) {
        return;
    }

    emit('update:open', false);
}

// Preserve original backend submission behavior
function submit() {
    if (form.processing) {
        return;
    }

    const options = {
        preserveScroll: true,
        onSuccess: () => close(),
    };

    if (props.user) {
        form.post(`/users/${props.user.id}`, options);
        return;
    }

    form.post('/users', options);
}
</script>

<template>
    <Dialog
        :open="open"
        @update:open="
            (value) => {
                if (!form.processing) {
                    emit('update:open', value);
                }
            }
        "
    >
        <DialogContent
            :show-close-button="false"
            class="flex max-h-[90vh] w-full max-w-[calc(100%-1.5rem)] flex-col gap-0 overflow-hidden rounded-2xl border-none bg-white p-0 shadow-2xl sm:max-w-[520px] dark:bg-slate-900"
        >
            <!-- HEADER -->
            <div
                class="flex shrink-0 items-center justify-between bg-[#1b7ad8] px-5 py-4 text-white"
            >
                <div class="flex min-w-0 items-center gap-3">
                    <div
                        class="flex size-10 shrink-0 items-center justify-center rounded-xl bg-white/15"
                    >
                        <UserRound class="size-5 text-white" />
                    </div>

                    <div class="min-w-0">
                        <DialogTitle
                            class="text-lg font-bold text-white"
                        >
                            {{ isEditing ? 'Edit User' : 'Add User' }}
                        </DialogTitle>

                        <DialogDescription
                            class="mt-0.5 text-xs text-white/80"
                        >
                            {{ subtitle }}
                        </DialogDescription>
                    </div>
                </div>

                <button
                    type="button"
                    class="flex size-8 shrink-0 items-center justify-center rounded-lg text-white/90 transition hover:bg-white/15"
                    aria-label="Close user form"
                    :disabled="form.processing"
                    @click="close"
                >
                    <X class="size-5" />
                </button>
            </div>

            <!-- FORM -->
            <form
                class="flex min-h-0 flex-1 flex-col"
                @submit.prevent="submit"
            >
                <!-- SCROLLABLE CONTENT -->
                <div
                    class="min-h-0 flex-1 space-y-5 overflow-y-auto px-5 py-5"
                >
                    <!-- BASIC INFORMATION -->
                    <section class="space-y-3">
                        <div class="flex items-center gap-2">
                            <UserRound
                                class="size-4 text-[#1b7ad8]"
                            />
                            <h3
                                class="text-sm font-bold text-[#0f4c8a] dark:text-sky-100"
                            >
                                Basic Information
                            </h3>
                        </div>

                        <!-- FULL NAME -->
                        <div class="grid gap-1.5">
                            <Label
                                for="user-name"
                                class="text-xs font-semibold text-ink dark:text-slate-200"
                            >
                                Full Name
                            </Label>

                            <Input
                                id="user-name"
                                v-model="form.name"
                                :class="fieldClass"
                                placeholder="Enter full name"
                                autocomplete="name"
                            />

                            <InputError
                                :message="form.errors.name"
                            />
                        </div>

                        <!-- EMAIL -->
                        <div class="grid gap-1.5">
                            <Label
                                for="user-email"
                                class="text-xs font-semibold text-ink dark:text-slate-200"
                            >
                                Email Address
                            </Label>

                            <Input
                                id="user-email"
                                v-model="form.email"
                                type="email"
                                :class="fieldClass"
                                placeholder="Enter email address"
                                autocomplete="email"
                            />

                            <InputError
                                :message="form.errors.email"
                            />
                        </div>
                    </section>

                    <div
                        class="border-t border-[#e7eef5] dark:border-white/10"
                    />

                    <!-- ACCOUNT ACCESS -->
                    <section class="space-y-3">
                        <h3
                            class="text-sm font-bold text-[#0f4c8a] dark:text-sky-100"
                        >
                            Account Access
                        </h3>

                        <div class="grid gap-3 sm:grid-cols-2">
                            <!-- ROLE -->
                            <div class="grid min-w-0 gap-1.5">
                                <Label
                                    class="text-xs font-semibold text-ink dark:text-slate-200"
                                >
                                    Role
                                </Label>

                                <Select v-model="form.role">
                                    <SelectTrigger
                                        :class="cn(fieldClass, 'w-full')"
                                    >
                                        <SelectValue
                                            placeholder="Select role"
                                        />
                                    </SelectTrigger>

                                    <SelectContent>
                                        <SelectItem
                                            v-for="role in roles"
                                            :key="role.value"
                                            :value="role.value"
                                        >
                                            {{
                                                displayRole(
                                                    role.label,
                                                    role.value,
                                                )
                                            }}
                                        </SelectItem>
                                    </SelectContent>
                                </Select>

                                <InputError
                                    :message="form.errors.role"
                                />
                            </div>

                            <!-- STATUS -->
                            <div class="grid min-w-0 gap-1.5">
                                <Label
                                    class="text-xs font-semibold text-ink dark:text-slate-200"
                                >
                                    Status
                                </Label>

                                <Select v-model="form.is_active">
                                    <SelectTrigger
                                        :class="cn(fieldClass, 'w-full')"
                                    >
                                        <SelectValue
                                            placeholder="Select status"
                                        />
                                    </SelectTrigger>

                                    <SelectContent>
                                        <SelectItem value="1">
                                            Active
                                        </SelectItem>

                                        <SelectItem value="0">
                                            Inactive
                                        </SelectItem>
                                    </SelectContent>
                                </Select>

                                <InputError
                                    :message="form.errors.is_active"
                                />
                            </div>
                        </div>
                    </section>

                    <!-- OPERATOR INFORMATION -->
                    <section
                        v-if="isOperator"
                        class="space-y-3"
                    >
                        <div
                            class="border-t border-[#e7eef5] dark:border-white/10"
                        />

                        <div class="flex items-center gap-2">
                            <Ship
                                class="size-4 text-[#1b7ad8]"
                            />

                            <h3
                                class="text-sm font-bold text-[#0f4c8a] dark:text-sky-100"
                            >
                                Operator Assignment
                            </h3>
                        </div>

                        <!-- ORGANIZATION -->
                        <div
                            v-if="organization"
                            class="grid gap-1.5"
                        >
                            <Label
                                for="user-organization"
                                class="text-xs font-semibold text-ink dark:text-slate-200"
                            >
                                Organization / Barangay
                            </Label>

                            <Input
                                id="user-organization"
                                :model-value="organization.name"
                                type="text"
                                readonly
                                tabindex="-1"
                                :class="
                                    cn(
                                        fieldClass,
                                        'bg-[#eef6fc] text-ink-soft dark:bg-white/5',
                                    )
                                "
                            />
                        </div>

                        <!-- ASSIGNED BOAT -->
                        <div class="grid gap-1.5">
                            <Label
                                class="text-xs font-semibold text-ink dark:text-slate-200"
                            >
                                Assigned Boat
                            </Label>

                            <Select
                                :model-value="form.boat_id || 'none'"
                                @update:model-value="
                                    form.boat_id =
                                        String($event ?? '') === 'none'
                                            ? ''
                                            : String($event ?? '')
                                "
                            >
                                <SelectTrigger
                                    :class="cn(fieldClass, 'w-full')"
                                >
                                    <SelectValue
                                        placeholder="Select boat"
                                    />
                                </SelectTrigger>

                                <SelectContent>
                                    <SelectItem value="none">
                                        No boat yet
                                    </SelectItem>

                                    <SelectItem
                                        v-for="boat in boats"
                                        :key="boat.id"
                                        :value="String(boat.id)"
                                    >
                                        {{
                                            boat.label +
                                            (
                                                boat.assigned_user_id &&
                                                boat.assigned_user_id !== props.user?.id
                                                    ? ' (already linked)'
                                                    : ''
                                            )
                                        }}
                                    </SelectItem>
                                </SelectContent>
                            </Select>

                            <p
                                v-if="boats.length === 0"
                                class="text-xs text-red-600"
                            >
                                Register a boat in Boat Management first.
                            </p>

                            <InputError
                                :message="form.errors.boat_id"
                            />
                        </div>
                    </section>

                    <!-- PASSWORD SECTION -->
                    <section class="space-y-3">
                        <div
                            class="border-t border-[#e7eef5] dark:border-white/10"
                        />

                        <div class="flex items-center gap-2">
                            <LockKeyhole
                                class="size-4 text-[#1b7ad8]"
                            />

                            <h3
                                class="text-sm font-bold text-[#0f4c8a] dark:text-sky-100"
                            >
                                {{
                                    isEditing
                                        ? 'Change Password'
                                        : 'Set Password'
                                }}
                            </h3>
                        </div>

                        <p
                            v-if="isEditing"
                            class="text-xs text-ink-muted dark:text-slate-400"
                        >
                            Leave both password fields blank to keep the current password.
                        </p>

                        <div class="grid gap-3 sm:grid-cols-2">
                            <!-- PASSWORD -->
                            <div class="grid min-w-0 gap-1.5">
                                <Label
                                    for="user-password"
                                    class="text-xs font-semibold text-ink dark:text-slate-200"
                                >
                                    {{
                                        isEditing
                                            ? 'New Password (Optional)'
                                            : 'Password'
                                    }}
                                </Label>

                                <PasswordInput
                                    id="user-password"
                                    v-model="form.password"
                                    :class="fieldClass"
                                    autocomplete="new-password"
                                />

                                <InputError
                                    :message="form.errors.password"
                                />
                            </div>

                            <!-- CONFIRM PASSWORD -->
                            <div class="grid min-w-0 gap-1.5">
                                <Label
                                    for="user-password-confirmation"
                                    class="text-xs font-semibold text-ink dark:text-slate-200"
                                >
                                    Confirm Password
                                </Label>

                                <PasswordInput
                                    id="user-password-confirmation"
                                    v-model="form.password_confirmation"
                                    :class="fieldClass"
                                    autocomplete="new-password"
                                />

                                <InputError
                                    :message="form.errors.password_confirmation"
                                />
                            </div>
                        </div>

                        <p
                            class="text-xs leading-relaxed text-ink-muted dark:text-slate-400"
                        >
                            Minimum 8 characters with uppercase, lowercase,
                            number, and special character.
                        </p>
                    </section>
                </div>

                <!-- FOOTER -->
                <div
                    class="flex shrink-0 items-center justify-end gap-2 border-t border-[#e7eef5] bg-white px-5 py-4 dark:border-white/10 dark:bg-slate-900"
                >
                    <Button
                        type="button"
                        variant="outline"
                        class="h-10 rounded-lg border-[#c5def5] px-5 text-sm font-semibold text-[#1b7ad8] hover:bg-[#e8f4fc]"
                        :disabled="form.processing"
                        @click="close"
                    >
                        Cancel
                    </Button>

                    <Button
                        type="submit"
                        class="h-10 rounded-lg bg-[#1b7ad8] px-5 text-sm font-semibold text-white hover:bg-[#1567c4]"
                        :disabled="form.processing"
                    >
                        <Spinner
                            v-if="form.processing"
                            class="size-4"
                        />

                        <Save
                            v-else
                            class="size-4"
                        />

                        {{
                            form.processing
                                ? 'Saving...'
                                : isEditing
                                  ? 'Save Changes'
                                  : 'Create Account'
                        }}
                    </Button>
                </div>
            </form>
        </DialogContent>
    </Dialog>
</template>
