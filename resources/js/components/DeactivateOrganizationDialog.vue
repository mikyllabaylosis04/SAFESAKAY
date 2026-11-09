<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { PowerOff } from '@lucide/vue';
import { ref, watch } from 'vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogTitle,
} from '@/components/ui/dialog';

const props = defineProps<{
    open: boolean;
    organization: { id: number; name: string } | null;
}>();

const emit = defineEmits<{
    'update:open': [value: boolean];
}>();

const reason = ref('');
const error = ref('');
const processing = ref(false);

watch(
    () => props.open,
    (open) => {
        if (open) {
            reason.value = '';
            error.value = '';
        }
    },
);

function close() {
    if (processing.value) {
        return;
    }

    emit('update:open', false);
}

function submit() {
    if (!props.organization || processing.value) {
        return;
    }

    if (reason.value.trim().length < 3) {
        error.value = 'Please enter a reason (at least 3 characters).';

        return;
    }

    processing.value = true;
    router.patch(
        `/platform/organizations/${props.organization.id}/deactivate`,
        {
            reason: reason.value.trim(),
        },
        {
            preserveScroll: true,
            onFinish: () => {
                processing.value = false;
            },
            onSuccess: () => {
                emit('update:open', false);
            },
            onError: (errors) => {
                error.value = errors.reason || 'Could not deactivate this organization.';
            },
        },
    );
}
</script>

<template>
    <Dialog :open="open" @update:open="(value) => emit('update:open', value)">
        <DialogContent class="flex max-h-[90vh] w-full max-w-[calc(100%-1.5rem)] flex-col gap-0 overflow-hidden rounded-2xl border-none p-0 shadow-2xl sm:max-w-md">
            <div class="shrink-0 bg-[#1b7ad8] px-5 py-4 text-white">
                <DialogTitle class="flex items-center gap-2 text-lg font-semibold text-white">
                    <PowerOff class="size-5 text-rose-200" />
                    Deactivate Organization
                </DialogTitle>
                <DialogDescription class="mt-1 text-sm text-white/80">
                    {{ organization?.name }}
                </DialogDescription>
            </div>
            <div class="min-h-0 space-y-4 overflow-y-auto bg-white p-5 dark:bg-background">
                <p class="text-sm text-ink dark:text-sky-100/80">
                    The barangay admin and operators will not be able to sign in. Boats, trips, and payment records stay saved.
                    The admin can send a restore request from the login page, which appears under Organization Request.
                </p>
                <label class="block">
                    <span class="text-xs font-semibold uppercase tracking-wide text-[#0f4c8a] dark:text-sky-200">Reason for deactivation</span>
                    <textarea
                        v-model="reason"
                        rows="3"
                        maxlength="500"
                        placeholder="e.g. Unpaid fees, policy violation, temporarily unused"
                        class="mt-1 w-full rounded-xl border border-[#d5e5f4] bg-[#f8fbff] px-3 py-2 text-sm text-ink outline-none focus:border-[#1976d2] focus:ring-2 focus:ring-[#1976d2]/10 dark:border-white/10 dark:bg-white/5 dark:text-sky-50"
                    />
                </label>
                <p v-if="error" class="text-xs text-[#d45b5b]">{{ error }}</p>
                <div class="flex justify-end gap-3">
                    <Button type="button" variant="outline" :disabled="processing" @click="close">Cancel</Button>
                    <Button
                        type="button"
                        class="bg-rose-600 hover:bg-rose-700"
                        :disabled="processing"
                        @click="submit"
                    >
                        Deactivate
                    </Button>
                </div>
            </div>
        </DialogContent>
    </Dialog>
</template>
