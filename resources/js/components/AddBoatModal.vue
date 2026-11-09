<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import {
    CalendarDays,
    Check,
    LockKeyhole,
    MapPin,
    Maximize2,
    Minimize2,
    Minus,
    Save,
    Ship,
    Upload,
    UserRound,
    X,
} from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import AddressPicker from '@/components/AddressPicker.vue';
import InputError from '@/components/InputError.vue';
import OperatorPhotoAdjuster from '@/components/OperatorPhotoAdjuster.vue';
import PasswordInput from '@/components/PasswordInput.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
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
    BoatPictureItem,
    BoatRecord,
    RentalDestinationOption,
    StatusOption,
} from '@/types/boat';

const MAX_BOAT_PICTURES = 5;
const MIN_OPERATOR_AGE = 18;
const MAX_OPERATOR_AGE = 80;
const LIFE_JACKETS = 'life_jackets';

const safetyEquipmentOptions = [
    { value: LIFE_JACKETS, label: 'Life Jackets' },
    { value: 'fire_extinguisher', label: 'Fire Extinguisher' },
    { value: 'first_aid_kit', label: 'First-Aid Kit' },
    {
        value: 'marine_radio',
        label: 'Marine Radio / Communication Device',
    },
    { value: 'navigation_lights', label: 'Navigation Lights' },
    { value: 'anchor_and_rope', label: 'Anchor & Anchor Rope' },
    { value: 'flashlight', label: 'Flashlight' },
    {
        value: 'emergency_signaling_device',
        label: 'Emergency Signaling Device',
    },
] as const;

type PicturePreview = {
    key: string;
    id?: number;
    url: string;
    file?: File;
};

const props = defineProps<{
    open: boolean;
    statusOptions: StatusOption[];
    serviceOptions: StatusOption[];
    rentalDestinations?: RentalDestinationOption[];
    existingCodes?: string[];
    boat?: BoatRecord | null;
    resumeOperatorAccount?: BoatRecord | null;
}>();

const emit = defineEmits<{
    'update:open': [value: boolean];
}>();

const fieldClass =
    'h-10 w-full rounded-xl border border-[#c5def5] bg-[#f7fbfe] text-ink shadow-none focus-visible:border-[#1b7ad8] focus-visible:ring-[#1b7ad8]/20 dark:border-white/10 dark:bg-white/5 dark:text-sky-50';

const form = useForm({
    name: '',
    code: '',
    capacity: '',
    license_no: '',
    registration_date: '',
    status: 'unavailable',
    service_availability: [] as string[],
    rental_destination_ids: [] as number[],
    safety_equipment: [] as string[],
    life_jacket_quantity: '',
    boat_pictures: [] as File[],
    removed_picture_ids: [] as number[],
    admin_use_only: false,
    operator_first_name: '',
    operator_middle_name: '',
    operator_last_name: '',
    operator_age: '',
    operator_gender: '',
    operator_contact_number: '',
    operator_address: '',
    operator_experience_years: '',
    operator_emergency_contact_name: '',
    operator_emergency_contact_number: '',
    operator_picture: null as File | null,
});

const accountForm = useForm({
    email: '',
    password: '',
    password_confirmation: '',
});

const step = ref<'details' | 'account'>('details');
const createdBoatId = ref<number | null>(null);
const isAccountStep = computed(() => step.value === 'account');
const createdOperatorName = computed(() =>
    [form.operator_first_name, form.operator_middle_name, form.operator_last_name]
        .map((part) => part.trim())
        .filter(Boolean)
        .join(' '),
);
const createdBoatLabel = computed(() =>
    [form.name.trim(), form.code.trim()].filter(Boolean).join(' · ') || assignedBoatLabel.value,
);

const boatInput = ref<HTMLInputElement | null>(null);
const operatorInput = ref<HTMLInputElement | null>(null);
const picturePreviews = ref<PicturePreview[]>([]);
const operatorPreview = ref<string | null>(null);
const operatorSourceUrl = ref<string | null>(null);
const operatorAdjustOpen = ref(false);
const addressPickerOpen = ref(false);
const maximized = ref(false);

const assignedBoatLabel = computed(() => form.name.trim() || 'This new boat');
const isEditing = computed(() => Boolean(props.boat));
const availableCodes = computed(() => {
    const used = new Set(
        (props.existingCodes ?? []).map((code) => code.toUpperCase()),
    );
    const current = props.boat?.code?.trim();

    if (current) {
        used.delete(current.toUpperCase());
    }

    const codes: string[] = [];
    let number = 1;

    while (codes.length < 12 && number <= 999) {
        const code = `PB-${String(number).padStart(3, '0')}`;

        if (!used.has(code)) {
            codes.push(code);
        }

        number += 1;
    }

    if (current && !codes.includes(current)) {
        codes.unshift(current);
    }

    return codes;
});
const nextAvailableCode = computed(
    () => availableCodes.value[0] ?? 'PB-001',
);
const canAddPictures = computed(
    () => picturePreviews.value.length < MAX_BOAT_PICTURES,
);
const operatorAgeNumber = computed(() => {
    const raw = form.operator_age.trim();

    if (!raw) {
        return null;
    }

    const age = Number(raw);

    return Number.isInteger(age) ? age : null;
});
const operatorAgeError = computed(() => {
    const age = operatorAgeNumber.value;

    if (age === null) {
        return form.errors.operator_age;
    }

    if (age < MIN_OPERATOR_AGE) {
        return `Minors cannot be registered as operators. The skipper must be at least ${MIN_OPERATOR_AGE} years old.`;
    }

    if (age > MAX_OPERATOR_AGE) {
        return `Enter a realistic age of ${MAX_OPERATOR_AGE} or below.`;
    }

    return form.errors.operator_age;
});
const operatorAgeInvalid = computed(() => Boolean(operatorAgeError.value));
const hasLifeJackets = computed(() =>
    form.safety_equipment.includes(LIFE_JACKETS),
);
const boatPicturesError = computed(() => {
    const errors = form.errors as Record<string, string>;

    return (
        errors.boat_pictures ||
        errors.boat_picture ||
        Object.entries(errors).find(([key]) =>
            key.startsWith('boat_pictures.'),
        )?.[1]
    );
});

function resetPictures() {
    picturePreviews.value.forEach((preview) => {
        if (preview.url.startsWith('blob:')) {
            URL.revokeObjectURL(preview.url);
        }
    });
    picturePreviews.value = [];
}

function syncPictureFiles() {
    form.boat_pictures = picturePreviews.value
        .map((preview) => preview.file)
        .filter((file): file is File => Boolean(file));
}

function savedPictures(boat: BoatRecord): PicturePreview[] {
    const pictures: BoatPictureItem[] = boat.pictures?.length
        ? boat.pictures
        : boat.picture_url
          ? [{ id: 0, url: boat.picture_url }]
          : [];

    return pictures.map((picture) => ({
        key: `saved-${picture.id}-${picture.url}`,
        id: picture.id || undefined,
        url: picture.url,
    }));
}

function savedServices(boat: BoatRecord): string[] {
    if (boat.service_availability?.length) {
        return [...boat.service_availability];
    }

    if (boat.service_type === 'both') {
        return props.serviceOptions.map((option) => option.value);
    }

    if (
        boat.service_type === 'regular_trip' ||
        boat.service_type === 'private_booking'
    ) {
        return [boat.service_type];
    }

    return [];
}

function operatorNameParts(operator: BoatRecord['operator'] | null | undefined) {
    if (operator?.first_name || operator?.last_name) {
        return {
            first: operator.first_name ?? '',
            middle: operator.middle_name ?? '',
            last: operator.last_name ?? '',
        };
    }

    const parts = (operator?.full_name ?? '')
        .trim()
        .split(/\s+/)
        .filter(Boolean);
    const first = parts.shift() ?? '';
    const last = parts.length ? (parts.pop() ?? '') : '';

    return {
        first,
        middle: parts.join(' '),
        last,
    };
}

function digitsOnly(value: string) {
    return String(value).replace(/\D/g, '');
}

function sanitizeNameInput(value: string) {
    return String(value)
        .replace(/[\u2018\u2019\u201A\uFF07]/g, "'")
        .replace(/[^\p{L} '\-]/gu, '')
        .replace(/\s+/g, ' ')
        .replace(/^\s+/u, '');
}

function trimmedName(value: string) {
    return sanitizeNameInput(value).trim();
}

function startOperatorAccountStep(saved: BoatRecord) {
    createdBoatId.value = saved.id;
    form.name = saved.name;
    form.code = saved.code;

    const names = operatorNameParts(saved.operator);

    form.operator_first_name = names.first;
    form.operator_middle_name = names.middle;
    form.operator_last_name = names.last;
    accountForm.reset();
    accountForm.clearErrors();
    step.value = 'account';
}

watch(
    () => [props.open, props.boat, props.resumeOperatorAccount] as const,
    ([open, boat, resume]) => {
        if (!open) {
            maximized.value = false;
            operatorAdjustOpen.value = false;
            addressPickerOpen.value = false;

            return;
        }

        if (!boat && resume && !resume.operator?.user_id) {
            startOperatorAccountStep(resume);
            return;
        }

        if (boat) {
            form.name = boat.name;
            form.code = boat.code;
            form.capacity = String(boat.capacity);
            form.license_no = boat.license_no ?? '';
            form.registration_date = boat.registration_date ?? '';
            form.status =
                boat.status === 'on_duty' || boat.status === 'on_trip'
                    ? 'available'
                    : boat.status;
            form.service_availability = savedServices(boat);
            form.rental_destination_ids = assignedDestinationIds(boat);
            form.safety_equipment = [...(boat.safety_equipment ?? [])];
            form.life_jacket_quantity = boat.life_jacket_quantity
                ? String(boat.life_jacket_quantity)
                : '';
            form.boat_pictures = [];
            form.removed_picture_ids = [];
            form.admin_use_only = boat.operator?.admin_use_only ?? false;

            const names = operatorNameParts(boat.operator);
            form.operator_first_name = names.first;
            form.operator_middle_name = names.middle;
            form.operator_last_name = names.last;
            form.operator_age = boat.operator?.age ? String(boat.operator.age) : '';
            form.operator_gender = boat.operator?.gender ?? '';
            form.operator_contact_number = boat.operator?.contact_number ?? '';
            form.operator_address = boat.operator?.address ?? '';
            form.operator_experience_years = boat.operator?.experience_years != null
                ? String(boat.operator.experience_years)
                : '';
            form.operator_emergency_contact_name =
                boat.operator?.emergency_contact_name ?? '';
            form.operator_emergency_contact_number =
                boat.operator?.emergency_contact_number ?? '';
            form.operator_picture = null;
            resetPictures();
            picturePreviews.value = savedPictures(boat);
            operatorPreview.value = boat.operator?.picture_url ?? null;
            operatorSourceUrl.value = boat.operator?.picture_url ?? null;
            form.clearErrors();
            step.value = 'details';
            createdBoatId.value = null;
        } else {
            form.reset();
            form.clearErrors();
            form.code = nextAvailableCode.value;
            resetPictures();
            operatorPreview.value = null;
            operatorSourceUrl.value = null;
            accountForm.reset();
            accountForm.clearErrors();
            step.value = 'details';
            createdBoatId.value = null;
        }
    },
    { immediate: true },
);

function closeNestedLayers() {
    addressPickerOpen.value = false;
    operatorAdjustOpen.value = false;
}

function close() {
    closeNestedLayers();
    emit('update:open', false);
}

function onDialogOpenChange(value: boolean) {
    if (value) {
        emit('update:open', true);
        return;
    }

    if (addressPickerOpen.value) {
        addressPickerOpen.value = false;
        return;
    }

    if (operatorAdjustOpen.value) {
        operatorAdjustOpen.value = false;
        return;
    }

    emit('update:open', false);
}

function preventOutsideDismiss(event: Event) {
    event.preventDefault();
}

function onEscapeKeyDown(event: KeyboardEvent) {
    if (addressPickerOpen.value) {
        event.preventDefault();
        addressPickerOpen.value = false;
        return;
    }

    if (operatorAdjustOpen.value) {
        event.preventDefault();
        operatorAdjustOpen.value = false;
        return;
    }

    close();
}

function openAddressPicker() {
    operatorAdjustOpen.value = false;
    addressPickerOpen.value = true;
}

function toggleMaximize() {
    maximized.value = !maximized.value;
}

function setService(value: string, checked: boolean | 'indeterminate') {
    const selected = new Set(form.service_availability);

    if (checked === true) {
        selected.add(value);
    } else {
        selected.delete(value);
    }

    form.service_availability = [...selected];

    if (value !== 'private_booking') {
        return;
    }

    if (checked === true) {
        if (form.rental_destination_ids.length === 0 && props.boat) {
            form.rental_destination_ids = assignedDestinationIds(props.boat);
        }

        return;
    }

    form.rental_destination_ids = [];
}

function isServiceSelected(value: string) {
    return form.service_availability.includes(value);
}

function serviceLabel(item: StatusOption) {
    return item.value === 'private_booking'
        ? 'Available for Private Rental'
        : item.label;
}

const PRIVATE_RENTAL = 'private_booking';
const isPrivateRental = computed(() =>
    form.service_availability.includes(PRIVATE_RENTAL),
);
const rentalDestinations = computed(() => props.rentalDestinations ?? []);
const selectedRentalCount = computed(
    () => form.rental_destination_ids.length,
);
const selectAllDestinationsState = computed<boolean | 'indeterminate'>(() => {
    const total = rentalDestinations.value.length;

    if (total === 0 || selectedRentalCount.value === 0) {
        return false;
    }

    if (selectedRentalCount.value === total) {
        return true;
    }

    return 'indeterminate';
});

function assignedDestinationIds(boat: BoatRecord): number[] {
    const eligible = new Set(rentalDestinations.value.map((item) => item.id));
    const saved = boat.rental_destination_ids?.length
        ? boat.rental_destination_ids
        : (boat.rental_destinations ?? []).map((item) => item.id);

    return saved.filter((id) => eligible.has(id));
}

function isDestinationSelected(id: number) {
    return form.rental_destination_ids.includes(id);
}

function setDestination(id: number, checked: boolean | 'indeterminate') {
    const selected = new Set(form.rental_destination_ids);

    if (checked === true) {
        selected.add(id);
    } else {
        selected.delete(id);
    }

    form.rental_destination_ids = [...selected];
}

function setSelectAllDestinations(checked: boolean | 'indeterminate') {
    form.rental_destination_ids =
        checked === true ? rentalDestinations.value.map((item) => item.id) : [];
}

function isEquipmentSelected(value: string) {
    return form.safety_equipment.includes(value);
}

function setEquipment(value: string, checked: boolean | 'indeterminate') {
    const selected = new Set(form.safety_equipment);

    if (checked === true) {
        selected.add(value);
    } else {
        selected.delete(value);
    }

    form.safety_equipment = [...selected];

    if (value === LIFE_JACKETS && checked !== true) {
        form.life_jacket_quantity = '';
    }
}

function onQuantityKeydown(event: KeyboardEvent) {
    if (
        event.ctrlKey ||
        event.metaKey ||
        [
            'Backspace',
            'Delete',
            'Tab',
            'ArrowLeft',
            'ArrowRight',
            'Home',
            'End',
        ].includes(event.key)
    ) {
        return;
    }

    if (!/^\d$/.test(event.key)) {
        event.preventDefault();
    }
}

function onBoatFile(event: Event) {
    const input = event.target as HTMLInputElement;
    const remaining = MAX_BOAT_PICTURES - picturePreviews.value.length;
    const files = Array.from(input.files ?? []).slice(0, remaining);

    picturePreviews.value.push(
        ...files.map((file) => ({
            key: `${file.name}-${file.size}-${file.lastModified}-${Math.random()}`,
            url: URL.createObjectURL(file),
            file,
        })),
    );
    syncPictureFiles();
    input.value = '';
}

function removePicture(key: string) {
    const preview = picturePreviews.value.find((item) => item.key === key);

    if (!preview) {
        return;
    }

    if (preview.id) {
        form.removed_picture_ids = [...form.removed_picture_ids, preview.id];
    }

    if (preview.url.startsWith('blob:')) {
        URL.revokeObjectURL(preview.url);
    }

    picturePreviews.value = picturePreviews.value.filter(
        (item) => item.key !== key,
    );
    syncPictureFiles();
}

function onOperatorFile(event: Event) {
    const input = event.target as HTMLInputElement;
    const file = input.files?.[0] ?? null;
    input.value = '';

    if (!file) {
        return;
    }

    if (operatorSourceUrl.value?.startsWith('blob:')) {
        URL.revokeObjectURL(operatorSourceUrl.value);
    }

    operatorSourceUrl.value = URL.createObjectURL(file);
    operatorAdjustOpen.value = true;
}

function applyOperatorPicture(file: File) {
    if (operatorPreview.value?.startsWith('blob:')) {
        URL.revokeObjectURL(operatorPreview.value);
    }

    form.operator_picture = file;
    operatorPreview.value = URL.createObjectURL(file);
}

function openOperatorAdjuster() {
    if (operatorSourceUrl.value || operatorPreview.value) {
        operatorSourceUrl.value =
            operatorSourceUrl.value ?? operatorPreview.value;
        operatorAdjustOpen.value = true;
        return;
    }

    operatorInput.value?.click();
}

function submitAccount() {
    if (!createdBoatId.value || accountForm.processing) {
        return;
    }

    accountForm.post(`/boats/${createdBoatId.value}/operator-account`, {
        onSuccess: () => close(),
    });
}

function submit() {
    if (isAccountStep.value) {
        submitAccount();
        return;
    }

    if (operatorAgeInvalid.value) {
        return;
    }

    form.operator_first_name = trimmedName(form.operator_first_name);
    form.operator_middle_name = trimmedName(form.operator_middle_name);
    form.operator_last_name = trimmedName(form.operator_last_name);
    form.operator_emergency_contact_name = trimmedName(
        form.operator_emergency_contact_name,
    );
    form.operator_address = form.operator_address.trim();

    if (!isPrivateRental.value) {
        form.rental_destination_ids = [];
    }

    const options = {
        forceFormData: true,
        preserveScroll: true,
        preserveState: !isEditing.value,
        onSuccess: (page: { props: { boats?: BoatRecord[]; createOperatorBoatId?: number | null } }) => {
            if (isEditing.value) {
                close();
                return;
            }

            const boats = page.props.boats ?? [];
            const code = form.code.trim().toUpperCase();
            const created =
                boats.find((boat) => boat.id === page.props.createOperatorBoatId) ??
                boats.find((boat) => boat.code?.toUpperCase() === code);

            if (!created?.id || created.operator?.user_id) {
                close();
                return;
            }

            startOperatorAccountStep(created);
        },
    };

    if (props.boat) {
        form.post(`/boats/${props.boat.id}`, options);

        return;
    }

    form.post('/boats', options);
}
</script>

<template>
    <Dialog :open="open" @update:open="onDialogOpenChange">
        <DialogContent
            :show-close-button="false"
            :class="
                cn(
                    'pointer-events-auto fixed top-1/2 left-1/2 z-[60] flex h-[min(92vh,920px)] max-h-[92vh] w-full max-w-[calc(100%-1.5rem)] -translate-x-1/2 -translate-y-1/2 flex-col gap-0 overflow-hidden rounded-2xl border-none p-0 shadow-2xl sm:max-w-6xl',
                    maximized ? 'sm:max-w-[calc(100%-1.5rem)]' : '',
                )
            "
            @pointer-down-outside="preventOutsideDismiss"
            @interact-outside="preventOutsideDismiss"
            @focus-outside="preventOutsideDismiss"
            @escape-key-down="onEscapeKeyDown"
        >
            <div
                class="relative z-30 flex shrink-0 items-start justify-between bg-[#1b7ad8] px-5 py-4 text-white"
            >
                <div class="flex items-center gap-3">
                    <div
                        class="flex size-11 items-center justify-center overflow-hidden rounded-full bg-white/15"
                    >
                        <img
                            src="/images/safesakay-logo.png?v=2"
                            alt="SafeSakay"
                            class="size-9 object-contain"
                        />
                    </div>
                    <div>
                        <DialogTitle class="text-lg font-semibold text-white">
                            {{
                                isAccountStep
                                    ? 'Create Operator App Login'
                                    : isEditing
                                      ? 'Edit Boat'
                                      : 'Add New Boat'
                            }}
                        </DialogTitle>
                        <DialogDescription class="text-sm text-white/80">
                            {{
                                isAccountStep
                                    ? 'Boat is saved. Set the skipper’s email and password for the mobile app.'
                                    : isEditing
                                      ? 'Update boat and operator information.'
                                      : 'Register a pump boat in the fleet.'
                            }}
                        </DialogDescription>
                    </div>
                </div>
                <div class="flex items-center gap-1">
                    <button
                        type="button"
                        class="relative z-40 rounded-full p-1.5 text-white/80 transition hover:bg-white/15 hover:text-white"
                        :aria-label="maximized ? 'Restore' : 'Maximize'"
                        @pointerdown.stop
                        @click.stop="toggleMaximize"
                    >
                        <Minimize2 v-if="maximized" class="size-5" />
                        <Maximize2 v-else class="size-5" />
                    </button>
                    <button
                        type="button"
                        class="relative z-40 rounded-full p-1.5 text-white/80 transition hover:bg-white/15 hover:text-white"
                        aria-label="Close"
                        @pointerdown.stop
                        @mousedown.stop
                        @click.stop="close"
                    >
                        <X class="size-5" />
                    </button>
                </div>
            </div>

            <form
                class="flex min-h-0 flex-1 flex-col overflow-hidden"
                @submit.prevent="submit"
            >
                <div
                    v-show="!isAccountStep"
                    class="grid min-h-0 flex-1 gap-4 overflow-y-auto p-5 lg:grid-cols-2"
                >
                    <section
                        class="rounded-2xl border border-[#d7e8f6] bg-[#f4f9fd] p-4 dark:border-white/10 dark:bg-white/5"
                    >
                        <div
                            class="mb-4 flex items-center gap-2 text-sm font-semibold text-[#0f4c8a] dark:text-sky-100"
                        >
                            <Ship class="size-4" />
                            Boat Information
                        </div>

                        <div class="mb-4">
                            <div class="mb-2 flex items-center justify-between gap-2">
                                <p class="text-xs font-medium text-ink-soft">
                                    Boat pictures
                                </p>
                                <p class="text-xs text-ink-muted">
                                    {{ picturePreviews.length }} /
                                    {{ MAX_BOAT_PICTURES }}
                                </p>
                            </div>
                            <div class="flex flex-wrap gap-2">
                                <div
                                    v-for="preview in picturePreviews"
                                    :key="preview.key"
                                    class="relative size-24 overflow-hidden rounded-xl bg-[#d7ebfa] dark:bg-sky-500/10"
                                >
                                    <img
                                        :src="preview.url"
                                        alt="Boat preview"
                                        class="h-full w-full object-cover"
                                    />
                                    <button
                                        type="button"
                                        class="absolute top-1 right-1 flex size-6 items-center justify-center rounded-full bg-black/60 text-white transition hover:bg-black/80"
                                        aria-label="Remove picture"
                                        @click="removePicture(preview.key)"
                                    >
                                        <X class="size-3.5" />
                                    </button>
                                </div>
                                <button
                                    v-if="canAddPictures"
                                    type="button"
                                    class="flex size-24 flex-col items-center justify-center rounded-xl border border-dashed border-[#8bbcE8] bg-white px-2 text-center text-xs text-ink-soft transition hover:border-[#1b7ad8] hover:text-[#1b7ad8] dark:border-white/20 dark:bg-white/5"
                                    @click="boatInput?.click()"
                                >
                                    <Upload class="mb-1 size-5" />
                                    Add photo
                                </button>
                            </div>
                            <p class="mt-2 text-xs text-ink-muted">
                                JPG or PNG, max 5MB each. Up to
                                {{ MAX_BOAT_PICTURES }} pictures.
                            </p>
                            <input
                                ref="boatInput"
                                type="file"
                                accept="image/png,image/jpeg"
                                multiple
                                class="hidden"
                                @change="onBoatFile"
                            />
                        </div>
                        <InputError
                            class="mb-3"
                            :message="boatPicturesError"
                        />

                        <div class="grid items-start gap-3 sm:grid-cols-2">
                            <div class="grid gap-1.5">
                                <Label for="boat-name">Boat Name</Label>
                                <Input
                                    id="boat-name"
                                    v-model="form.name"
                                    :class="fieldClass"
                                    placeholder="e.g. Lakandula 1"
                                />
                                <InputError :message="form.errors.name" />
                            </div>
                            <div class="grid gap-1.5">
                                <Label>Boat Code</Label>
                                <Select v-model="form.code">
                                    <SelectTrigger
                                        :class="cn(fieldClass, 'h-10 w-full py-0')"
                                    >
                                        <SelectValue placeholder="Select available code" />
                                    </SelectTrigger>
                                    <SelectContent class="z-[130]">
                                        <SelectItem
                                            v-for="code in availableCodes"
                                            :key="code"
                                            :value="code"
                                        >
                                            {{ code }}
                                        </SelectItem>
                                    </SelectContent>
                                </Select>
                                <InputError :message="form.errors.code" />
                            </div>
                            <div class="grid gap-1.5">
                                <Label for="boat-capacity"
                                    >Capacity (Passengers)</Label
                                >
                                <Input
                                    id="boat-capacity"
                                    v-model="form.capacity"
                                    type="number"
                                    min="1"
                                    :class="fieldClass"
                                    placeholder="20"
                                />
                                <InputError :message="form.errors.capacity" />
                            </div>
                            <div class="grid gap-1.5">
                                <Label for="license-no">License No.</Label>
                                <Input
                                    id="license-no"
                                    v-model="form.license_no"
                                    :class="fieldClass"
                                    placeholder="MARINA license no."
                                />
                                <InputError :message="form.errors.license_no" />
                            </div>
                            <div class="grid gap-1.5">
                                <Label for="registration-date"
                                    >Registration Date</Label
                                >
                                <div class="relative">
                                    <Input
                                        id="registration-date"
                                        v-model="form.registration_date"
                                        type="date"
                                        :class="cn(fieldClass, 'pr-10')"
                                    />
                                    <CalendarDays
                                        class="pointer-events-none absolute top-1/2 right-3 size-4 -translate-y-1/2 text-[#7da7cc]"
                                    />
                                </div>
                                <InputError
                                    :message="form.errors.registration_date"
                                />
                            </div>
                            <div class="grid gap-1.5 sm:col-span-2">
                                <Label>Boat readiness</Label>
                                <Select v-model="form.status">
                                    <SelectTrigger :class="cn(fieldClass, 'w-full')">
                                        <SelectValue placeholder="Select status" />
                                    </SelectTrigger>
                                    <SelectContent class="z-[130]">
                                        <SelectItem
                                            v-for="option in statusOptions"
                                            :key="option.value"
                                            :value="option.value"
                                        >
                                            {{ option.label }}
                                        </SelectItem>
                                    </SelectContent>
                                </Select>
                                <InputError :message="form.errors.status" />
                            </div>
                            <div class="grid gap-2 sm:col-span-2">
                                <div>
                                    <Label>Service Availability</Label>
                                </div>
                                <div class="grid gap-2 sm:grid-cols-2">
                                    <div
                                        v-for="item in serviceOptions"
                                        :key="item.value"
                                        class="flex items-start gap-3 rounded-xl border bg-white px-3 py-2.5 transition dark:bg-white/5"
                                        :class="
                                            isServiceSelected(item.value)
                                                ? 'border-[#1b7ad8] bg-[#f3f8fe] dark:border-[#1b7ad8]'
                                                : 'border-[#d7e8f6] dark:border-white/10'
                                        "
                                    >
                                        <Checkbox
                                            :id="`service-${item.value}`"
                                            :model-value="
                                                isServiceSelected(item.value)
                                            "
                                            class="mt-0.5 size-5 rounded-[5px] border-[#8bbcE8] data-[state=checked]:border-[#1b7ad8] data-[state=checked]:bg-[#1b7ad8] data-[state=checked]:text-white"
                                            @update:model-value="
                                                setService(item.value, $event)
                                            "
                                        />
                                        <label
                                            :for="`service-${item.value}`"
                                            class="block cursor-pointer text-sm font-medium text-ink dark:text-sky-50"
                                        >
                                            {{ serviceLabel(item) }}
                                        </label>
                                    </div>
                                </div>
                                <InputError
                                    :message="form.errors.service_availability"
                                />
                            </div>
                            <div
                                v-if="isPrivateRental"
                                class="grid gap-2 sm:col-span-2"
                            >
                                <div class="flex items-start justify-between gap-3">
                                    <div>
                                        <Label>Assign Rental Destinations</Label>
                                    </div>
                                    <p class="shrink-0 text-xs font-medium text-ink-soft">
                                        {{ selectedRentalCount }}
                                        of {{ rentalDestinations.length }} selected
                                    </p>
                                </div>

                                <div
                                    v-if="rentalDestinations.length === 0"
                                    class="rounded-xl border border-dashed border-[#c5def5] bg-[#f7fbfe] px-3 py-4 text-sm text-ink-muted dark:border-white/10 dark:bg-white/5"
                                >
                                    No active exclusive boat rental destinations yet. Add them in Route Management first.
                                </div>

                                <div
                                    v-else
                                    class="overflow-hidden rounded-xl border border-[#d7e8f6] dark:border-white/10"
                                >
                                    <div
                                        class="flex items-start gap-3 border-b border-[#e8f1f8] bg-[#f7fbfe] px-3 py-2.5 dark:border-white/10 dark:bg-white/5"
                                    >
                                        <Checkbox
                                            id="rental-destination-select-all"
                                            :model-value="selectAllDestinationsState"
                                            class="mt-0.5 size-5 rounded-[5px] border-[#8bbcE8] data-[state=checked]:border-[#1b7ad8] data-[state=checked]:bg-[#1b7ad8] data-[state=checked]:text-white data-[state=indeterminate]:border-[#1b7ad8] data-[state=indeterminate]:bg-[#1b7ad8] data-[state=indeterminate]:text-white"
                                            @update:model-value="setSelectAllDestinations"
                                        >
                                            <Minus
                                                v-if="
                                                    selectAllDestinationsState ===
                                                    'indeterminate'
                                                "
                                                class="size-3.5"
                                            />
                                            <Check
                                                v-else
                                                class="size-3.5"
                                            />
                                        </Checkbox>
                                        <label
                                            for="rental-destination-select-all"
                                            class="block cursor-pointer text-sm font-medium text-ink dark:text-sky-50"
                                        >
                                            Select All Destinations
                                        </label>
                                    </div>
                                    <div class="max-h-48 overflow-y-auto">
                                        <div
                                            v-for="item in rentalDestinations"
                                            :key="item.id"
                                            class="flex items-start gap-3 border-t border-[#eef4f9] px-3 py-2.5 first:border-t-0 dark:border-white/10"
                                        >
                                            <Checkbox
                                                :id="`rental-destination-${item.id}`"
                                                :model-value="
                                                    isDestinationSelected(item.id)
                                                "
                                                class="mt-0.5 size-5 rounded-[5px] border-[#8bbcE8] data-[state=checked]:border-[#1b7ad8] data-[state=checked]:bg-[#1b7ad8] data-[state=checked]:text-white"
                                                @update:model-value="
                                                    setDestination(item.id, $event)
                                                "
                                            />
                                            <label
                                                :for="`rental-destination-${item.id}`"
                                                class="block cursor-pointer text-sm font-medium text-ink dark:text-sky-50"
                                            >
                                                {{ item.destination }}
                                                <span
                                                    v-if="item.rental_price_label"
                                                    class="font-normal text-ink-muted"
                                                >
                                                    · {{ item.rental_price_label }}
                                                </span>
                                            </label>
                                        </div>
                                    </div>
                                </div>
                                <InputError
                                    :message="form.errors.rental_destination_ids"
                                />
                            </div>
                            <div class="grid gap-2 sm:col-span-2">
                                <div>
                                    <Label>Safety Equipment</Label>
                                </div>
                                <div class="grid gap-2 sm:grid-cols-2">
                                    <div
                                        v-for="item in safetyEquipmentOptions"
                                        :key="item.value"
                                        class="flex items-start gap-3 rounded-xl border bg-white px-3 py-2.5 transition dark:bg-white/5"
                                        :class="
                                            isEquipmentSelected(item.value)
                                                ? 'border-[#1b7ad8] bg-[#f3f8fe] dark:border-[#1b7ad8]'
                                                : 'border-[#d7e8f6] dark:border-white/10'
                                        "
                                    >
                                        <Checkbox
                                            :id="`safety-${item.value}`"
                                            :model-value="
                                                isEquipmentSelected(item.value)
                                            "
                                            class="mt-0.5 size-5 rounded-[5px] border-[#8bbcE8] data-[state=checked]:border-[#1b7ad8] data-[state=checked]:bg-[#1b7ad8] data-[state=checked]:text-white"
                                            @update:model-value="
                                                setEquipment(item.value, $event)
                                            "
                                        />
                                        <span class="min-w-0 flex-1">
                                            <label
                                                :for="`safety-${item.value}`"
                                                class="block cursor-pointer text-sm font-medium text-ink dark:text-sky-50"
                                            >
                                                {{ item.label }}
                                            </label>
                                            <span
                                                v-if="
                                                    item.value ===
                                                        LIFE_JACKETS &&
                                                    hasLifeJackets
                                                "
                                                class="mt-2 flex items-center gap-2"
                                            >
                                                <label
                                                    for="life-jacket-quantity"
                                                    class="text-xs text-ink-muted"
                                                >
                                                    Quantity
                                                </label>
                                                <Input
                                                    id="life-jacket-quantity"
                                                    :model-value="
                                                        form.life_jacket_quantity
                                                    "
                                                    type="text"
                                                    inputmode="numeric"
                                                    pattern="[0-9]*"
                                                    placeholder="0"
                                                    :class="
                                                        cn(
                                                            fieldClass,
                                                            'h-8 w-20 px-2 text-sm',
                                                        )
                                                    "
                                                    @update:model-value="
                                                        form.life_jacket_quantity =
                                                            String(
                                                                $event,
                                                            ).replace(
                                                                /\D/g,
                                                                '',
                                                            )
                                                    "
                                                    @keydown="
                                                        onQuantityKeydown
                                                    "
                                                />
                                            </span>
                                        </span>
                                    </div>
                                </div>
                                <InputError
                                    :message="form.errors.safety_equipment"
                                />
                                <InputError
                                    :message="form.errors.life_jacket_quantity"
                                />
                            </div>
                        </div>
                    </section>

                    <section
                        class="rounded-2xl border border-[#d7e8f6] bg-[#f4f9fd] p-4 dark:border-white/10 dark:bg-white/5"
                    >
                        <div class="mb-4 flex items-center justify-between">
                            <div
                                class="flex items-center gap-2 text-sm font-semibold text-[#0f4c8a] dark:text-sky-100"
                            >
                                <UserRound class="size-4" />
                                Operator Information
                            </div>
                            <label
                                class="flex items-center gap-2 text-xs font-medium text-ink-soft dark:text-slate-300"
                            >
                                For Admin Use Only
                                <button
                                    type="button"
                                    role="switch"
                                    :aria-checked="form.admin_use_only"
                                    class="relative h-5 w-9 rounded-full transition"
                                    :class="
                                        form.admin_use_only
                                            ? 'bg-[#1b7ad8]'
                                            : 'bg-[#c5def5]'
                                    "
                                    @click="
                                        form.admin_use_only = !form.admin_use_only
                                    "
                                >
                                    <span
                                        class="absolute top-0.5 left-0.5 size-4 rounded-full bg-white transition"
                                        :class="
                                            form.admin_use_only
                                                ? 'translate-x-4'
                                                : ''
                                        "
                                    />
                                </button>
                            </label>
                        </div>

                        <div class="mb-4 flex items-center gap-3">
                            <button
                                type="button"
                                class="flex size-20 shrink-0 items-center justify-center overflow-hidden rounded-full border border-dashed border-[#8bbcE8] bg-white dark:bg-white/5"
                                @click="openOperatorAdjuster"
                            >
                                <img
                                    v-if="operatorPreview"
                                    :src="operatorPreview"
                                    alt="Operator preview"
                                    class="h-full w-full object-cover"
                                />
                                <UserRound v-else class="size-8 text-[#1b7ad8]" />
                            </button>
                            <div class="text-sm text-ink-soft">
                                <button
                                    type="button"
                                    class="text-left hover:text-[#1b7ad8]"
                                    @click="operatorInput?.click()"
                                >
                                    Operator picture
                                </button>
                                <button
                                    v-if="operatorPreview"
                                    type="button"
                                    class="mt-1 text-xs font-medium text-[#1b7ad8] hover:underline"
                                    @click="openOperatorAdjuster"
                                >
                                    Adjust photo
                                </button>
                            </div>
                            <input
                                ref="operatorInput"
                                type="file"
                                accept="image/png,image/jpeg"
                                class="hidden"
                                @change="onOperatorFile"
                            />
                        </div>
                        <InputError
                            class="mb-3"
                            :message="form.errors.operator_picture"
                        />

                        <div class="grid gap-3 sm:grid-cols-2">
                            <div class="grid gap-1.5">
                                <Label for="operator-first-name">First Name *</Label>
                                <Input
                                    id="operator-first-name"
                                    :model-value="form.operator_first_name"
                                    :class="fieldClass"
                                    placeholder="First name"
                                    @update:model-value="
                                        form.operator_first_name =
                                            sanitizeNameInput(String($event))
                                    "
                                />
                                <InputError
                                    :message="form.errors.operator_first_name"
                                />
                            </div>
                            <div class="grid gap-1.5">
                                <Label for="operator-middle-name">Middle Name</Label>
                                <Input
                                    id="operator-middle-name"
                                    :model-value="form.operator_middle_name"
                                    :class="fieldClass"
                                    placeholder="Middle name"
                                    @update:model-value="
                                        form.operator_middle_name =
                                            sanitizeNameInput(String($event))
                                    "
                                />
                                <InputError
                                    :message="form.errors.operator_middle_name"
                                />
                            </div>
                            <div class="grid gap-1.5 sm:col-span-2">
                                <Label for="operator-last-name">Last Name *</Label>
                                <Input
                                    id="operator-last-name"
                                    :model-value="form.operator_last_name"
                                    :class="fieldClass"
                                    placeholder="Last name"
                                    @update:model-value="
                                        form.operator_last_name =
                                            sanitizeNameInput(String($event))
                                    "
                                />
                                <InputError
                                    :message="form.errors.operator_last_name"
                                />
                            </div>
                            <div class="grid gap-1.5">
                                <Label for="operator-age">Age *</Label>
                                <Input
                                    id="operator-age"
                                    :model-value="form.operator_age"
                                    type="text"
                                    inputmode="numeric"
                                    pattern="[0-9]*"
                                    :aria-invalid="operatorAgeInvalid"
                                    :class="
                                        cn(
                                            fieldClass,
                                            operatorAgeInvalid &&
                                                'border-red-500 bg-red-50 text-red-700 focus-visible:border-red-500 focus-visible:ring-red-500/20 dark:border-red-400 dark:bg-red-500/10 dark:text-red-200',
                                        )
                                    "
                                    placeholder="18"
                                    @update:model-value="
                                        form.operator_age = digitsOnly(
                                            String($event),
                                        )
                                    "
                                    @keydown="onQuantityKeydown"
                                />
                                <InputError :message="operatorAgeError" />
                            </div>
                            <div class="grid gap-1.5">
                                <Label>Gender *</Label>
                                <Select v-model="form.operator_gender">
                                    <SelectTrigger :class="cn(fieldClass, 'w-full')">
                                        <SelectValue placeholder="Select gender" />
                                    </SelectTrigger>
                                    <SelectContent class="z-[130]">
                                        <SelectItem value="Male">Male</SelectItem>
                                        <SelectItem value="Female"
                                            >Female</SelectItem
                                        >
                                        <SelectItem value="Prefer not to say"
                                            >Prefer not to say</SelectItem
                                        >
                                    </SelectContent>
                                </Select>
                                <InputError
                                    :message="form.errors.operator_gender"
                                />
                            </div>
                            <div class="grid gap-1.5 sm:col-span-2">
                                <Label for="operator-contact"
                                    >Contact Number *</Label
                                >
                                <Input
                                    id="operator-contact"
                                    :model-value="form.operator_contact_number"
                                    type="text"
                                    inputmode="numeric"
                                    maxlength="11"
                                    :class="fieldClass"
                                    placeholder="09XXXXXXXXX"
                                    @update:model-value="
                                        form.operator_contact_number =
                                            digitsOnly(String($event)).slice(
                                                0,
                                                11,
                                            )
                                    "
                                    @keydown="onQuantityKeydown"
                                />
                                <InputError
                                    :message="
                                        form.errors.operator_contact_number
                                    "
                                />
                            </div>
                            <div class="grid gap-1.5 sm:col-span-2">
                                <Label for="operator-address"
                                    >Complete Address *</Label
                                >
                                <button
                                    id="operator-address"
                                    type="button"
                                    :class="
                                        cn(
                                            fieldClass,
                                            'relative z-10 flex items-center justify-between px-3 text-left',
                                            form.operator_address
                                                ? ''
                                                : 'text-[#7da7cc]',
                                        )
                                    "
                                    @pointerdown.stop
                                    @click.stop="openAddressPicker"
                                >
                                    <span class="truncate">
                                        {{
                                            form.operator_address ||
                                            'Select region, province, city, and barangay'
                                        }}
                                    </span>
                                    <MapPin class="size-4 shrink-0 text-[#7da7cc]" />
                                </button>
                                <InputError
                                    :message="form.errors.operator_address"
                                />
                            </div>
                            <div class="grid gap-1.5">
                                <Label for="operator-experience">Years of Experience *</Label>
                                <Input
                                    id="operator-experience"
                                    :model-value="form.operator_experience_years"
                                    type="text"
                                    inputmode="numeric"
                                    pattern="[0-9]*"
                                    :class="fieldClass"
                                    placeholder="0"
                                    @update:model-value="
                                        form.operator_experience_years =
                                            digitsOnly(String($event))
                                    "
                                    @keydown="onQuantityKeydown"
                                />
                                <InputError
                                    :message="
                                        form.errors.operator_experience_years
                                    "
                                />
                            </div>
                            <div class="grid gap-1.5">
                                <Label>Assigned Boat</Label>
                                <Input
                                    :model-value="assignedBoatLabel"
                                    :class="fieldClass"
                                    readonly
                                />
                            </div>
                            <div class="grid gap-1.5 sm:col-span-2">
                                <Label for="operator-emergency-name">Emergency Contact Name *</Label>
                                <Input
                                    id="operator-emergency-name"
                                    :model-value="
                                        form.operator_emergency_contact_name
                                    "
                                    :class="fieldClass"
                                    placeholder="Emergency contact name"
                                    @update:model-value="
                                        form.operator_emergency_contact_name =
                                            sanitizeNameInput(String($event))
                                    "
                                />
                                <InputError
                                    :message="
                                        form.errors
                                            .operator_emergency_contact_name
                                    "
                                />
                            </div>
                            <div class="grid gap-1.5 sm:col-span-2">
                                <Label for="operator-emergency-number">Emergency Contact Number *</Label>
                                <Input
                                    id="operator-emergency-number"
                                    :model-value="
                                        form.operator_emergency_contact_number
                                    "
                                    type="text"
                                    inputmode="numeric"
                                    maxlength="11"
                                    :class="fieldClass"
                                    placeholder="09XXXXXXXXX"
                                    @update:model-value="
                                        form.operator_emergency_contact_number =
                                            digitsOnly(String($event)).slice(
                                                0,
                                                11,
                                            )
                                    "
                                    @keydown="onQuantityKeydown"
                                />
                                <InputError
                                    :message="
                                        form.errors
                                            .operator_emergency_contact_number
                                    "
                                />
                            </div>
                        </div>
                    </section>
                </div>

                <div
                    v-if="isAccountStep"
                    class="min-h-0 flex-1 overflow-y-auto p-5"
                >
                    <section class="mx-auto max-w-lg space-y-4 rounded-2xl border border-[#d7e8f6] bg-[#f4f9fd] p-5 dark:border-white/10 dark:bg-white/5">
                        <div class="flex items-center gap-2 text-sm font-semibold text-[#0f4c8a] dark:text-sky-100">
                            <LockKeyhole class="size-4" />
                            Operator app account
                        </div>
                        <dl class="grid gap-3 text-sm sm:grid-cols-2">
                            <div>
                                <dt class="text-xs text-ink-muted">Skipper</dt>
                                <dd class="font-semibold text-ink dark:text-sky-100">{{ createdOperatorName || '—' }}</dd>
                            </div>
                            <div>
                                <dt class="text-xs text-ink-muted">Boat</dt>
                                <dd class="font-semibold text-ink dark:text-sky-100">{{ createdBoatLabel }}</dd>
                            </div>
                        </dl>
                        <div class="grid gap-1.5">
                            <Label for="operator-account-email">Email *</Label>
                            <Input
                                id="operator-account-email"
                                v-model="accountForm.email"
                                type="email"
                                autocomplete="off"
                                :class="fieldClass"
                                placeholder="operator@email.com"
                            />
                            <InputError :message="accountForm.errors.email" />
                        </div>
                        <div class="grid gap-3 sm:grid-cols-2">
                            <div class="grid gap-1.5">
                                <Label for="operator-account-password">Password *</Label>
                                <PasswordInput
                                    id="operator-account-password"
                                    v-model="accountForm.password"
                                    :class="fieldClass"
                                    autocomplete="new-password"
                                />
                                <InputError :message="accountForm.errors.password" />
                            </div>
                            <div class="grid gap-1.5">
                                <Label for="operator-account-password-confirmation">Confirm Password *</Label>
                                <PasswordInput
                                    id="operator-account-password-confirmation"
                                    v-model="accountForm.password_confirmation"
                                    :class="fieldClass"
                                    autocomplete="new-password"
                                />
                                <InputError :message="accountForm.errors.password_confirmation" />
                            </div>
                        </div>
                        <p class="text-xs text-ink-muted">
                            Minimum 8 characters with uppercase, lowercase, number, and special character. This is the skipper’s login on the SafeSakay mobile app.
                        </p>
                    </section>
                </div>

                <div
                    class="relative z-20 flex shrink-0 items-center justify-end gap-3 border-t border-[#d7e8f6] bg-white px-5 py-4 dark:border-white/10 dark:bg-background"
                >
                    <Button
                        type="button"
                        variant="outline"
                        class="h-10 rounded-xl border-[#1b7ad8] px-5 text-[#1b7ad8] hover:bg-[#e8f4fc]"
                        :disabled="form.processing || accountForm.processing"
                        @pointerdown.stop
                        @click.stop="close"
                    >
                        {{ isAccountStep ? 'Skip for now' : 'Cancel' }}
                    </Button>
                    <Button
                        type="submit"
                        class="h-10 rounded-xl bg-[#1b7ad8] px-5 text-white hover:bg-[#1567c4]"
                        :disabled="form.processing || accountForm.processing"
                    >
                        <Spinner v-if="form.processing || accountForm.processing" class="size-4" />
                        <LockKeyhole v-else-if="isAccountStep" class="size-4" />
                        <Save v-else class="size-4" />
                        {{
                            isAccountStep
                                ? 'Create App Login'
                                : isEditing
                                  ? 'Save Changes'
                                  : 'Save Boat & Continue'
                        }}
                    </Button>
                </div>
            </form>

            <OperatorPhotoAdjuster
                :open="operatorAdjustOpen"
                :source-url="operatorSourceUrl"
                :teleport="false"
                @update:open="operatorAdjustOpen = $event"
                @apply="applyOperatorPicture"
            />

            <AddressPicker
                :open="addressPickerOpen"
                :teleport="false"
                :model-value="form.operator_address"
                @update:open="addressPickerOpen = $event"
                @select="form.operator_address = $event.address"
            />
        </DialogContent>
    </Dialog>
</template>
