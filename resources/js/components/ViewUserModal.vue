
<script setup lang="ts">
import {
    CalendarDays,
    Clock3,
    Mail,
    Pencil,
    RotateCcw,
    Ship,
    UserRound,
    UserX,
    X,
} from '@lucide/vue';

import { computed } from 'vue';

import { Button } from '@/components/ui/button';

import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogTitle,
} from '@/components/ui/dialog';

import type { ManagedUser } from '@/types/user';

// PROPS
const props = defineProps<{
    open: boolean;
    user: ManagedUser | null;
}>();

// EVENTS
const emit = defineEmits<{
    'update:open': [value: boolean];
    edit: [user: ManagedUser];
    status: [user: ManagedUser];
}>();

// DISPLAY ROLE
const roleLabel = computed(() => {
    if (!props.user) {
        return '—';
    }

    if (props.user.role === 'administrator') {
        return 'Administrator';
    }

    if (props.user.role === 'operator') {
        return 'Operator';
    }

    if (props.user.role === 'passenger') {
        return 'Passenger';
    }

    return props.user.role_label === 'Customer'
        ? 'Passenger'
        : props.user.role_label;
});

// ROLE PERMISSIONS
const isPassenger = computed(
    () => props.user?.role === 'passenger',
);

const isOperator = computed(
    () => props.user?.role === 'operator',
);

const canEdit = computed(
    () => Boolean(props.user && !isPassenger.value),
);

const canChangeStatus = computed(
    () =>
        Boolean(
            props.user &&
            isPassenger.value &&
            !props.user.is_current,
        ),
);

// ACCOUNT DATA
const createdDate = computed(() => {
    return props.user?.created_at_label || '—';
});

const lastActivity = computed(() => {
    return props.user?.last_active_label || 'Never signed in';
});

const assignedBoat = computed(() => {
    return props.user?.boat_label || 'Not linked yet';
});

// ACTIONS
function close() {
    emit('update:open', false);
}

function editUser() {
    if (!props.user || !canEdit.value) {
        return;
    }

    emit('edit', props.user);
}

function changeStatus() {
    if (!props.user || !canChangeStatus.value) {
        return;
    }

    emit('status', props.user);
}
</script>

<template>
    <Dialog
        :open="open"
        @update:open="emit('update:open', $event)"
    >
        <DialogContent
            v-if="user"
            :show-close-button="false"
            class="flex max-h-[90vh] w-full max-w-[calc(100%-1.5rem)] flex-col gap-0 overflow-hidden rounded-2xl border-none bg-white p-0 shadow-2xl sm:max-w-[480px] dark:bg-slate-900"
        >
            <!-- HEADER -->
            <div
                class="flex shrink-0 items-center justify-between bg-[#1b7ad8] px-5 py-4 text-white"
            >
                <div class="min-w-0">
                    <DialogTitle
                        class="text-lg font-bold text-white"
                    >
                        Account Details
                    </DialogTitle>

                    <DialogDescription
                        class="mt-1 text-xs text-white/80"
                    >
                        {{
                            isPassenger
                                ? 'Passenger account information'
                                : isOperator
                                  ? 'Boat operator account information'
                                  : 'Administrator account information'
                        }}
                    </DialogDescription>
                </div>

                <button
                    type="button"
                    class="flex size-8 shrink-0 items-center justify-center rounded-lg text-white/90 transition hover:bg-white/15 hover:text-white"
                    aria-label="Close account details"
                    @click="close"
                >
                    <X class="size-5" />
                </button>
            </div>

            <!-- BODY -->
            <div
                class="min-h-0 space-y-5 overflow-y-auto px-5 py-5 sm:px-6"
            >
                <!-- USER PROFILE -->
                <div class="flex items-center gap-3">
                    <div
                        class="flex size-12 shrink-0 items-center justify-center rounded-full bg-[#e8f4fc] text-[#1b7ad8] dark:bg-blue-500/15"
                    >
                        <UserRound class="size-6" />
                    </div>

                    <div class="min-w-0 flex-1">
                        <div
                            class="flex flex-wrap items-center gap-2"
                        >
                            <p
                                class="break-words text-base font-bold text-[#0f4c8a] dark:text-sky-100"
                            >
                                {{ user.name }}
                            </p>

                            <span
                                v-if="user.is_current"
                                class="rounded-full bg-[#e8f4fc] px-2 py-0.5 text-[11px] font-semibold text-[#1b7ad8]"
                            >
                                You
                            </span>
                        </div>

                        <div
                            class="mt-1 flex items-start gap-1.5 text-xs text-ink-muted dark:text-slate-400"
                        >
                            <Mail
                                class="mt-0.5 size-3.5 shrink-0"
                            />

                            <span class="break-all">
                                {{ user.email }}
                            </span>
                        </div>
                    </div>
                </div>

                <!-- DIVIDER -->
                <div
                    class="border-t border-[#e7eef5] dark:border-white/10"
                />

                <!-- ACCOUNT INFORMATION -->
                <dl
                    class="grid grid-cols-1 gap-x-5 gap-y-5 sm:grid-cols-2"
                >
                    <!-- ROLE -->
                    <div class="min-w-0">
                        <dt
                            class="mb-1 text-xs font-medium text-ink-muted dark:text-slate-400"
                        >
                            Role
                        </dt>

                        <dd
                            class="text-sm font-semibold text-[#0f4c8a] dark:text-sky-100"
                        >
                            {{ roleLabel }}
                        </dd>
                    </div>

                    <!-- STATUS -->
                    <div class="min-w-0">
                        <dt
                            class="mb-1 text-xs font-medium text-ink-muted dark:text-slate-400"
                        >
                            Status
                        </dt>

                        <dd>
                            <span
                                class="inline-flex items-center rounded-full px-3 py-1 text-xs font-semibold"
                                :class="
                                    user.is_suspended
                                        ? 'bg-red-100 text-red-700 dark:bg-red-500/20 dark:text-red-200'
                                        : user.is_active
                                          ? 'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-300'
                                          : 'bg-red-50 text-red-700 dark:bg-red-500/15 dark:text-red-300'
                                "
                            >
                                {{ user.status_label }}
                            </span>
                        </dd>
                    </div>

                    <!-- DATE CREATED -->
                    <div class="min-w-0">
                        <dt
                            class="mb-1 flex items-center gap-1.5 text-xs font-medium text-ink-muted dark:text-slate-400"
                        >
                            <CalendarDays class="size-3.5" />
                            Date Created
                        </dt>

                        <dd
                            class="text-sm font-semibold text-[#0f4c8a] dark:text-sky-100"
                        >
                            {{ createdDate }}
                        </dd>
                    </div>

                    <!-- LAST ACTIVITY -->
                    <div class="min-w-0">
                        <dt
                            class="mb-1 flex items-center gap-1.5 text-xs font-medium text-ink-muted dark:text-slate-400"
                        >
                            <Clock3 class="size-3.5" />
                            Last Activity
                        </dt>

                        <dd
                            class="text-sm font-semibold text-[#0f4c8a] dark:text-sky-100"
                        >
                            {{ lastActivity }}
                        </dd>
                    </div>

                    <!-- ASSIGNED BOAT: OPERATOR ONLY -->
                    <div
                        v-if="isOperator"
                        class="rounded-xl border border-[#d7e8f6] bg-[#f7fbff] px-3 py-3 dark:border-white/10 dark:bg-white/5 sm:col-span-2"
                    >
                        <dt
                            class="mb-1 flex items-center gap-1.5 text-xs font-medium text-ink-muted dark:text-slate-400"
                        >
                            <Ship class="size-3.5" />
                            Assigned Boat
                        </dt>

                        <dd
                            class="text-sm font-semibold text-[#0f4c8a] dark:text-sky-100"
                        >
                            {{ assignedBoat }}
                        </dd>
                    </div>

                    <div
                        v-if="isOperator"
                        class="min-w-0"
                    >
                        <dt class="mb-1 text-xs font-medium text-ink-muted dark:text-slate-400">
                            Violations
                        </dt>
                        <dd class="text-sm font-semibold text-[#0f4c8a] dark:text-sky-100">
                            {{ user.violation_count ?? 0 }} / 3
                        </dd>
                    </div>

                    <div
                        v-if="isOperator && user.suspension"
                        class="rounded-xl border border-rose-100 bg-rose-50 px-3 py-3 dark:border-rose-500/20 dark:bg-rose-500/10 sm:col-span-2"
                    >
                        <dt class="text-xs font-semibold text-rose-700 dark:text-rose-200">
                            Suspension
                        </dt>
                        <dd class="mt-1 space-y-1 text-sm text-rose-800 dark:text-rose-100">
                            <p>{{ user.suspension.reason }}</p>
                            <p>{{ user.suspension.started_at_label }} – {{ user.suspension.ends_at_label }}</p>
                            <p class="font-semibold">{{ user.suspension.remaining_label }}</p>
                        </dd>
                    </div>

                    <div
                        v-if="isOperator && (user.violations?.length ?? 0) > 0"
                        class="sm:col-span-2"
                    >
                        <dt class="mb-2 text-xs font-medium text-ink-muted dark:text-slate-400">
                            Violation history
                        </dt>
                        <dd class="space-y-2">
                            <div
                                v-for="item in user.violations"
                                :key="item.id"
                                class="rounded-xl border border-[#d7e8f6] px-3 py-2 dark:border-white/10"
                            >
                                <p class="text-sm font-semibold text-[#0f4c8a] dark:text-sky-100">
                                    {{ item.strike_label }} · {{ item.booking_code }}
                                </p>
                                <p class="text-xs text-ink-muted">
                                    {{ item.reason }} · {{ item.incident_date_label }}
                                </p>
                            </div>
                        </dd>
                    </div>

                    <!-- DEACTIVATION REASON -->
                    <div
                        v-if="user.deactivated_reason"
                        class="rounded-xl border border-red-100 bg-red-50 px-3 py-3 dark:border-red-500/20 dark:bg-red-500/10 sm:col-span-2"
                    >
                        <dt
                            class="text-xs font-semibold text-red-700 dark:text-red-300"
                        >
                            Deactivation Reason
                        </dt>

                        <dd
                            class="mt-1 text-sm leading-relaxed text-red-700 dark:text-red-200"
                        >
                            {{ user.deactivated_reason }}
                        </dd>
                    </div>
                </dl>
            </div>

            <!-- FOOTER -->
            <div
                class="flex shrink-0 flex-wrap items-center justify-end gap-2 border-t border-[#e7eef5] bg-[#fbfdff] px-5 py-4 dark:border-white/10 dark:bg-white/5"
            >
                <!-- PASSENGER STATUS ACTION -->
                <Button
                    v-if="canChangeStatus"
                    type="button"
                    variant="outline"
                    class="mr-auto h-9 rounded-lg px-3 text-xs font-semibold"
                    :class="
                        user.is_active
                            ? 'border-red-200 text-red-600 hover:bg-red-50 dark:border-red-500/30 dark:text-red-300'
                            : 'border-emerald-200 text-emerald-700 hover:bg-emerald-50 dark:border-emerald-500/30 dark:text-emerald-300'
                    "
                    @click="changeStatus"
                >
                    <UserX
                        v-if="user.is_active"
                        class="mr-1.5 size-3.5"
                    />

                    <RotateCcw
                        v-else
                        class="mr-1.5 size-3.5"
                    />

                    {{
                        user.is_active
                            ? 'Deactivate'
                            : 'Restore'
                    }}
                </Button>

                <!-- CLOSE -->
                <Button
                    type="button"
                    variant="outline"
                    class="h-9 rounded-lg border-[#c5def5] px-4 text-xs font-semibold text-[#1b7ad8] hover:bg-[#e8f4fc]"
                    @click="close"
                >
                    Close
                </Button>

                <!-- EDIT -->
                <Button
                    v-if="canEdit"
                    type="button"
                    class="h-9 rounded-lg bg-[#1b7ad8] px-4 text-xs font-semibold text-white hover:bg-[#1567c4]"
                    @click="editUser"
                >
                    <Pencil class="mr-1.5 size-3.5" />
                    Edit User
                </Button>
            </div>
        </DialogContent>
    </Dialog>
</template>
