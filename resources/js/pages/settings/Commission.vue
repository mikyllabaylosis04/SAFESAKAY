<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { Info, Percent, Save } from '@lucide/vue';
import { computed, ref } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';

const props = defineProps<{
    can_manage: boolean;
    commission: { enabled: boolean; percentage: number; effective_date_label: string | null };
}>();

defineOptions({
    layout: { breadcrumbs: [{ title: 'Settings', href: '/settings/profile' }, { title: 'Commission', href: '/settings/commission' }] },
});

const form = useForm({
    commission_enabled: props.commission.enabled,
    commission_percentage: Number(props.commission.percentage ?? 0),
});

const currentRate = computed(() => (props.commission.enabled ? `${Number.parseFloat(Number(props.commission.percentage).toFixed(2))}%` : 'Off'));

const samplePrice = ref(2000);
const preview = computed(() => {
    const rate = form.commission_enabled ? Math.min(Math.max(Number(form.commission_percentage) || 0, 0), 100) : 0;
    const price = Math.max(Number(samplePrice.value) || 0, 0);
    const commission = Math.round(price * rate) / 100;

    return { rate, price, commission, operator: Math.max(price - commission, 0) };
});

const peso = (value: number) => `₱${value.toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;

function save() {
    const percent = Number(form.commission_percentage);

    if (Number.isNaN(percent) || percent < 0 || percent > 100) {
        form.setError('commission_percentage', 'Enter a percentage between 0 and 100.');

        return;
    }

    form.clearErrors();
    form.post('/settings/payments/commission', { preserveScroll: true });
}
</script>

<template>
    <Head title="Commission settings" />

    <section class="rounded-2xl border border-[#d7e8f6] bg-white shadow-sm dark:border-white/10 dark:bg-white/5">
        <header class="flex items-center gap-3 border-b border-[#eef4f9] px-5 py-4 dark:border-white/10">
            <span class="flex size-9 items-center justify-center rounded-xl bg-[#1b7ad8] text-white">
                <Percent class="size-4" />
            </span>
            <div>
                <h2 class="font-semibold text-[#0f4c8a] dark:text-sky-100">Commission</h2>
                <p class="text-xs text-ink-muted dark:text-slate-400">Barangay share of each private rental</p>
            </div>
        </header>

        <form class="grid gap-5 p-5 lg:grid-cols-[minmax(0,1fr)_minmax(0,0.9fr)]" @submit.prevent="save">
            <div class="space-y-4">
                <div class="flex items-center justify-between rounded-xl bg-[#eef7fd] px-4 py-3 dark:bg-sky-500/10">
                    <div>
                        <p class="text-xs font-medium text-ink-soft dark:text-slate-400">Current barangay commission rate</p>
                        <p class="text-2xl font-bold text-[#0f4c8a] dark:text-sky-100">{{ currentRate }}</p>
                    </div>
                    <p class="text-right text-xs text-ink-soft dark:text-slate-400">
                        Effective since
                        <span class="block font-semibold text-ink dark:text-sky-100">{{ commission.effective_date_label || '—' }}</span>
                    </p>
                </div>

                <label class="flex cursor-pointer items-center gap-3 text-sm font-medium text-ink dark:text-sky-100">
                    <input v-model="form.commission_enabled" type="checkbox" class="size-4 accent-[#1b7ad8]" :disabled="!can_manage" />
                    Collect commission on private rentals
                </label>

                <div class="grid gap-1.5">
                    <Label for="commission_percentage" class="text-ink dark:text-sky-100">Commission percentage</Label>
                    <div class="relative max-w-[12rem]">
                        <Input
                            id="commission_percentage"
                            v-model="form.commission_percentage"
                            type="number"
                            min="0"
                            max="100"
                            step="0.01"
                            placeholder="2"
                            class="h-10 rounded-xl border-[#c5def5] bg-[#f7fbfe] pr-9 dark:border-white/10 dark:bg-white/5"
                            :disabled="!can_manage || !form.commission_enabled"
                        />
                        <span class="pointer-events-none absolute top-1/2 right-3 -translate-y-1/2 text-sm font-semibold text-ink-soft">%</span>
                    </div>
                    <InputError :message="form.errors.commission_percentage" />
                </div>

                <p class="flex items-start gap-2 rounded-xl border border-[#eef4f9] bg-[#f8fbfe] px-3 py-2.5 text-xs text-ink-soft dark:border-white/10 dark:bg-white/5 dark:text-slate-400">
                    <Info class="mt-0.5 size-3.5 shrink-0 text-[#1b7ad8]" />
                    A new rate applies only to rentals booked after you save. Existing payments and payout records keep the rate they were booked with.
                </p>

                <div class="flex items-center justify-end gap-3">
                    <span v-if="!can_manage" class="text-xs text-ink-muted">Administrator only</span>
                    <span v-else-if="form.recentlySuccessful" class="text-xs font-medium text-emerald-600">Saved</span>
                    <Button type="submit" class="h-10 rounded-xl bg-[#1b7ad8] px-4 hover:bg-[#1567c4]" :disabled="!can_manage || form.processing">
                        <Spinner v-if="form.processing" class="size-4" />
                        <Save v-else class="size-4" />
                        Save Changes
                    </Button>
                </div>
            </div>

            <aside class="rounded-xl border border-[#eef4f9] p-4 dark:border-white/10">
                <h3 class="text-sm font-semibold text-[#0f4c8a] dark:text-sky-100">How commission is calculated</h3>
                <p class="mt-1 text-xs text-ink-muted dark:text-slate-400">
                    Commission is a percentage of the full rental price. It is taken from the passenger’s first payment to SafeSakay. SafeSakay releases the rest to the operator.
                </p>

                <div class="mt-3 grid gap-1.5">
                    <Label for="sample_price" class="text-xs text-ink-soft">Example rental price</Label>
                    <div class="relative">
                        <span class="pointer-events-none absolute top-1/2 left-3 -translate-y-1/2 text-sm font-semibold text-ink-soft">₱</span>
                        <Input id="sample_price" v-model="samplePrice" type="number" min="0" step="1" class="h-9 rounded-xl border-[#c5def5] pl-7 dark:border-white/10" />
                    </div>
                </div>

                <dl class="mt-3 space-y-1.5 text-sm">
                    <div class="flex justify-between rounded-lg bg-[#eef7fd] px-3 py-2 dark:bg-sky-500/10">
                        <dt class="text-ink dark:text-sky-100">Barangay ({{ preview.rate }}%)</dt>
                        <dd class="font-bold text-[#0f4c8a] dark:text-sky-100">{{ peso(preview.commission) }}</dd>
                    </div>
                    <div class="flex justify-between rounded-lg bg-[#eefaf3] px-3 py-2 dark:bg-emerald-500/10">
                        <dt class="text-ink dark:text-sky-100">Operator</dt>
                        <dd class="font-bold text-emerald-700 dark:text-emerald-300">{{ peso(preview.operator) }}</dd>
                    </div>
                </dl>
            </aside>
        </form>
    </section>
</template>
