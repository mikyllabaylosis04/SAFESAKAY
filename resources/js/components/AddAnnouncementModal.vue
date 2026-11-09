
<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import {
    Bell,
    CalendarDays,
    Check,
    ChevronLeft,
    ChevronRight,
    Clock3,
    CloudRain,
    MapPin,
    Megaphone,
    Send,
    Ship,
    Sparkles,
    TriangleAlert,
    UserRound,
    Users,
    Waves,
    X,
} from '@lucide/vue';
import { computed, ref, watch } from 'vue';

import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogTitle,
} from '@/components/ui/dialog';

type Category = 'weather' | 'delay' | 'general' | 'emergency';
type AudienceRole = 'operator' | 'passenger';
type Step = 1 | 2 | 3;

type RouteOption = {
    value: string;
    label: string;
    group: string | null;
};

const props = withDefaults(
    defineProps<{
    open: boolean;
        weatherRouteOptions?: RouteOption[];
    }>(),
    {
        weatherRouteOptions: () => [],
    }
);

const emit = defineEmits<{
    'update:open': [value: boolean];
}>();

const step = ref<Step>(1);
const attempted = ref(false);
const confirmWeather = ref(false);

const ALL_ROLES: AudienceRole[] = [
    'operator',
    'passenger',
];

/*
|--------------------------------------------------------------------------
| ANNOUNCEMENT CATEGORIES
|--------------------------------------------------------------------------
*/
const categories = [
    {
        value: 'weather',
        label: 'Weather Advisory',
        description: 'Weather alerts and trip suspensions',
        icon: CloudRain,
        color: 'blue',
    },
    {
        value: 'delay',
        label: 'Trip Delay',
        description: 'Delayed boat departures',
        icon: TriangleAlert,
        color: 'amber',
    },
    {
        value: 'general',
        label: 'General Notice',
        description: 'Reminders and general updates',
        icon: Megaphone,
        color: 'green',
    },
    {
        value: 'emergency',
        label: 'Emergency Advisory',
        description: 'Urgent safety information',
        icon: Bell,
        color: 'red',
    },
] as const;

/*
|--------------------------------------------------------------------------
| ORIGINAL SUGGESTED ANNOUNCEMENTS
|--------------------------------------------------------------------------
*/
const templates: Record<
    Category,
    { title: string; body: string }
> = {
    weather: {
        title: 'No trips today due to bad weather',
        body:
            'All regular trips and private boat bookings are suspended today ' +
            'because of bad weather. Please wait for the next advisory.',
    },

    delay: {
        title: 'Trips are delayed due to rough seas',
        body:
            'Departures are delayed due to rough sea conditions. ' +
            'Please wait for further updates.',
    },

    general: {
        title: 'Safety reminder for today’s trips',
        body:
            'Please wear life jackets while on board ' +
            'and follow port safety instructions.',
    },

    emergency: {
        title: 'Emergency advisory',
        body:
            'Please follow port instructions and wait ' +
            'for the next official update.',
    },
};

/*
|--------------------------------------------------------------------------
| DATE HELPER
|--------------------------------------------------------------------------
*/
function today() {
    const parts = new Intl.DateTimeFormat('en-CA', {
        timeZone: 'Asia/Manila',
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
    }).formatToParts(new Date());

    const part = (type: string) =>
        parts.find(item => item.type === type)?.value ?? '';

    return `${part('year')}-${part('month')}-${part('day')}`;
}

/*
|--------------------------------------------------------------------------
| FORM DATA
|--------------------------------------------------------------------------
| Recipients now only use operator and passenger.
*/
const form = useForm({
    category: 'weather' as Category,
    title: templates.weather.title,
    body: templates.weather.body,

    reason: 'bad_weather' as string | null,
    affected_date: today() as string | null,
    affected_routes: ['all'] as string[],
    duration_type: 'whole_day' as string | null,
    starts_at: '' as string | null,
    ends_at: '' as string | null,

    audience: [...ALL_ROLES] as AudienceRole[],
});

/*
|--------------------------------------------------------------------------
| COMPUTED
|--------------------------------------------------------------------------
*/
const routeGroups = computed(() => {
    const groups = new Map<string, RouteOption[]>();

    for (const option of props.weatherRouteOptions) {
        if (!option.value || option.value === 'all') {
            continue;
        }

        const key = option.group ?? 'Routes';
        groups.set(key, [...(groups.get(key) ?? []), option]);
    }

    return [...groups.entries()].map(([label, options]) => ({ label, options }));
});

const routeLabels = computed(
    () => new Map(props.weatherRouteOptions.map(option => [option.value, option.label])),
);

const selectedRoutesLabel = computed(() =>
    form.affected_routes.includes('all')
        ? 'All Routes'
        : form.affected_routes.map(route => routeLabels.value.get(route) ?? route).join(', '),
);

const selectedCategory = computed(() =>
    categories.find(item => item.value === form.category)
);

const operatorsSelected = computed(() =>
    form.audience.includes('operator')
);

const passengersSelected = computed(() =>
    form.audience.includes('passenger')
);

const allSelected = computed(() =>
    operatorsSelected.value && passengersSelected.value
);

const recipientsSummary = computed(() => {
    if (allSelected.value) {
return 'All Users';
}

    if (operatorsSelected.value) {
return 'Boat Operators';
}

    if (passengersSelected.value) {
return 'Passengers';
}

    return 'No recipients selected';
});

const detailsValid = computed(() =>
    form.title.trim().length > 0 &&
    form.title.length <= 100 &&
    form.body.trim().length > 0 &&
    form.body.length <= 500
);

const weatherValid = computed(() => {
    if (form.category !== 'weather') {
return true;
}

    if (
        !form.affected_date ||
        form.affected_routes.length === 0
    ) {
        return false;
    }

    if (form.duration_type === 'time_range') {
        return Boolean(
            form.starts_at &&
            form.ends_at &&
            form.starts_at < form.ends_at
        );
    }

    return true;
});

const coverageValid = computed(() =>
    form.audience.length > 0 &&
    weatherValid.value
);

/*
|--------------------------------------------------------------------------
| STYLES
|--------------------------------------------------------------------------
*/
const fieldClass =
    'w-full min-h-11 rounded-xl border border-slate-300 ' +
    'bg-white px-4 py-3 text-sm text-slate-900 outline-none  dark:bg-[#102036] dark:text-slate-100' +
    'transition focus:border-blue-500 focus:ring-2 ' +
    'focus:ring-blue-100';

const tones: Record<Category, { tile: string; selected: string; ring: string; edge: string; line: string; solid: string; text: string }> = {
    weather: {
        tile: 'bg-sky-100 text-sky-600',
        selected: 'border-sky-500 bg-sky-50 shadow-[0_8px_24px_rgba(14,165,233,0.15)]',
        ring: 'text-sky-600',
        edge: 'border-l-sky-500',
        line: 'bg-sky-500',
        solid: 'bg-sky-600',
        text: 'text-sky-700',
    },
    delay: {
        tile: 'bg-amber-100 text-amber-600',
        selected: 'border-amber-500 bg-amber-50 shadow-[0_8px_24px_rgba(245,158,11,0.15)]',
        ring: 'text-amber-600',
        edge: 'border-l-amber-500',
        line: 'bg-amber-500',
        solid: 'bg-amber-500',
        text: 'text-amber-700',
    },
    general: {
        tile: 'bg-violet-100 text-violet-600',
        selected: 'border-violet-500 bg-violet-50 shadow-[0_8px_24px_rgba(139,92,246,0.15)]',
        ring: 'text-violet-600',
        edge: 'border-l-violet-500',
        line: 'bg-violet-500',
        solid: 'bg-violet-600',
        text: 'text-violet-700',
    },
    emergency: {
        tile: 'bg-red-100 text-red-600',
        selected: 'border-red-500 bg-red-50 shadow-[0_8px_24px_rgba(239,68,68,0.15)]',
        ring: 'text-red-600',
        edge: 'border-l-red-500',
        line: 'bg-red-500',
        solid: 'bg-red-600',
        text: 'text-red-700',
    },
};

const tone = computed(() => tones[form.category]);

const steps = ['Details', 'Coverage', 'Review'] as const;

const recipientOptions = [
    { key: 'all', label: 'All Users', hint: 'Operators and passengers', icon: Users },
    { key: 'operator', label: 'Boat Operators', hint: 'Registered operators', icon: Ship },
    { key: 'passenger', label: 'Passengers', hint: 'Mobile app users', icon: UserRound },
] as const;

function recipientSelected(key: (typeof recipientOptions)[number]['key']) {
    if (key === 'all') {
        return allSelected.value;
    }

    return key === 'operator'
        ? operatorsSelected.value && !passengersSelected.value
        : passengersSelected.value && !operatorsSelected.value;
}

function selectRecipient(key: (typeof recipientOptions)[number]['key']) {
    if (key === 'all') {
        selectAllUsers();
    } else if (key === 'operator') {
        selectOperators();
    } else {
        selectPassengers();
    }
}

const affectedDateLabel = computed(() => {
    if (!form.affected_date) {
        return '—';
    }

    const parsed = new Date(`${form.affected_date}T00:00:00`);

    return Number.isNaN(parsed.getTime())
        ? form.affected_date
        : parsed.toLocaleDateString('en-PH', { weekday: 'short', month: 'short', day: 'numeric', year: 'numeric' });
});

const durationLabel = computed(() =>
    form.duration_type === 'time_range'
        ? form.starts_at && form.ends_at
            ? `${form.starts_at} – ${form.ends_at}`
            : 'Specific hours'
        : 'Whole day',
);

const reasonLabel = computed(() => (form.reason === 'rough_seas' ? 'Rough Seas' : 'Bad Weather'));

const postingAtLabel = computed(() =>
    new Date().toLocaleString('en-PH', { month: 'short', day: 'numeric', year: 'numeric', hour: 'numeric', minute: '2-digit' }),
);

/*
|--------------------------------------------------------------------------
| ANNOUNCEMENT TEMPLATE
|--------------------------------------------------------------------------
*/
function changeCategory(category: Category) {
    form.category = category;
    form.title = templates[category].title;
    form.body = templates[category].body;

    attempted.value = false;
    confirmWeather.value = false;

    form.clearErrors();
}

function useSuggestedText() {
    form.title = templates[form.category].title;
    form.body = templates[form.category].body;

    form.clearErrors('title', 'body');
}

/*
|--------------------------------------------------------------------------
| RECIPIENT SELECTION
|--------------------------------------------------------------------------
| All Users = operator + passenger
|--------------------------------------------------------------------------
*/
function selectAllUsers() {
    form.audience = [...ALL_ROLES];
}

function selectOperators() {
    form.audience = ['operator'];
}

function selectPassengers() {
    form.audience = ['passenger'];
}

/*
|--------------------------------------------------------------------------
| WEATHER ROUTES
|--------------------------------------------------------------------------
*/
function toggleRoute(route: string) {
    if (route === 'all') {
        form.affected_routes = ['all'];

        return;
    }

    const selected = form.affected_routes.filter(
        item => item !== 'all'
    );

    form.affected_routes = selected.includes(route)
        ? selected.filter(item => item !== route)
        : [...selected, route];
}

/*
|--------------------------------------------------------------------------
| NAVIGATION
|--------------------------------------------------------------------------
*/
function nextStep() {
    attempted.value = true;

    if (step.value === 1 && !detailsValid.value) {
        return;
    }

    if (step.value === 2 && !coverageValid.value) {
        return;
    }

    attempted.value = false;

    if (step.value < 3) {
        step.value = (step.value + 1) as Step;
    }
}

function previousStep() {
    if (step.value > 1) {
        step.value = (step.value - 1) as Step;
    }

    attempted.value = false;
}

/*
|--------------------------------------------------------------------------
| RESET
|--------------------------------------------------------------------------
*/
function resetForm() {
            form.reset();
            form.clearErrors();

            form.category = 'weather';
    form.title = templates.weather.title;
    form.body = templates.weather.body;

    form.reason = 'bad_weather';
    form.affected_date = today();
    form.affected_routes = ['all'];
    form.duration_type = 'whole_day';
    form.starts_at = '';
    form.ends_at = '';

    form.audience = [...ALL_ROLES];

    step.value = 1;
    attempted.value = false;
    confirmWeather.value = false;
}

watch(
    () => props.open,
    value => {
        if (value) {
resetForm();
}
    }
);

function close() {
    if (form.processing) {
return;
}

    emit('update:open', false);
}

/*
|--------------------------------------------------------------------------
| SUBMIT TO EXISTING LARAVEL BACKEND
|--------------------------------------------------------------------------
*/
function submit() {
    if (form.processing || step.value !== 3) {
return;
}

    if (!detailsValid.value || !coverageValid.value) {
        return;
    }

    if (
        form.category === 'weather' &&
        !confirmWeather.value
    ) {
        return;
    }

    form.transform(data => {
        if (data.category === 'weather') {
            return {
                ...data,
                starts_at:
                    data.duration_type === 'time_range'
                        ? data.starts_at
                        : null,
                ends_at:
                    data.duration_type === 'time_range'
                        ? data.ends_at
                        : null,
            };
        }

        return {
            ...data,
            reason: null,
            affected_date: null,
            affected_routes: [],
            duration_type: null,
            starts_at: null,
            ends_at: null,
        };
    }).post('/announcements', {
        preserveScroll: true,

        onSuccess: () => {
            emit('update:open', false);
        },

        onError: errors => {
            const coverageFields = [
                'audience',
                'reason',
                'affected_date',
                'affected_routes',
                'duration_type',
                'starts_at',
                'ends_at',
            ];

            step.value = Object.keys(errors).some(
                field =>
                    coverageFields.some(
                        key => field.startsWith(key)
                    )
            ) ? 2 : 1;
        },
    });
}
</script>

<template>
    <Dialog
        :open="open"
        @update:open="value => {
            if (!value) close();
        }"
    >
        <DialogContent
            :show-close-button="false"
            class="flex h-[min(860px,94dvh)] w-[calc(100vw-24px)] max-w-[880px] flex-col gap-0 overflow-hidden rounded-3xl border-0 bg-white p-0 shadow-2xl sm:max-w-[880px] dark:bg-[#102036] dark:text-slate-100 dark:border-white/10"
        >
            <!-- HEADER -->
            <header class="relative shrink-0 border-b border-l-[7px] border-slate-100 bg-white px-5 pt-5 pb-4 sm:px-8 dark:bg-[#102036] dark:text-slate-100 dark:border-white/10" :class="tone.edge">
                <div class="flex items-start justify-between gap-4">
                    <div class="flex items-center gap-3">
                        <div class="flex size-11 items-center justify-center rounded-2xl" :class="tone.tile">
                            <component :is="selectedCategory?.icon ?? Megaphone" class="size-6" />
                        </div>
                        <div>
                            <DialogTitle class="text-xl font-bold text-slate-900">Create Announcement</DialogTitle>
                            <DialogDescription class="text-sm text-slate-500">
                                {{ selectedCategory?.label }} · sent as a push notification
                            </DialogDescription>
                        </div>
                    </div>

                    <button
                        type="button"
                        aria-label="Close"
                        class="rounded-xl p-2 text-slate-400 hover:bg-slate-100 hover:text-slate-600"
                        @click="close"
                    >
                        <X class="size-5" />
                    </button>
                </div>

                <ol class="mt-6 flex items-center gap-4">
                    <template v-for="(label, index) in steps" :key="label">
                        <li class="flex shrink-0 items-center gap-2.5 whitespace-nowrap">
                            <span class="text-sm font-bold" :class="step >= index + 1 ? tone.text : 'text-slate-400'">
                                {{ index + 1 }}
                            </span>
                            <span class="text-sm font-semibold" :class="step >= index + 1 ? tone.text : 'text-slate-400'">{{ label }}</span>
                        </li>
                        <span
                            v-if="index < steps.length - 1"
                            class="h-0.5 min-w-8 flex-1 rounded-full"
                            :class="step > index ? tone.line : 'bg-slate-200'"
                        />
                    </template>
                </ol>
            </header>

            <form class="flex min-h-0 flex-1 flex-col" @submit.prevent="submit">
                <div class="flex min-h-0 flex-1 flex-col">
                    <div class="min-h-0 overflow-y-auto px-5 py-6 sm:px-8">
                        <!-- STEP 1: DETAILS -->
                        <div v-if="step === 1" class="space-y-6">
                            <section class="space-y-3">
                                <p class="text-sm font-semibold text-slate-800">What kind of announcement? <span class="text-red-500">*</span></p>
                                <div class="grid gap-3 sm:grid-cols-2">
                            <button
                                        v-for="category in categories"
                                        :key="category.value"
                                type="button"
                                        :aria-pressed="form.category === category.value"
                                        class="group relative flex items-center gap-3 rounded-2xl border-2 p-4 text-left transition"
                                        :class="form.category === category.value ? tones[category.value].selected : 'border-slate-200 bg-white hover:-translate-y-0.5 hover:border-slate-300 hover:shadow-md dark:bg-[#102036] dark:text-slate-100 dark:border-white/10'"
                                        @click="changeCategory(category.value)"
                                    >
                                        <span class="flex size-12 shrink-0 items-center justify-center rounded-2xl" :class="tones[category.value].tile">
                                            <component :is="category.icon" class="size-6" />
                                        </span>
                                        <span class="min-w-0 flex-1">
                                            <span class="block text-sm font-bold text-slate-900">{{ category.label }}</span>
                                            <span class="mt-0.5 block text-xs text-slate-500">{{ category.description }}</span>
                                        </span>
                                        <span
                                            v-if="form.category === category.value"
                                            class="absolute top-3 right-3 flex size-5 items-center justify-center rounded-full text-white"
                                            :class="tones[category.value].solid"
                                        >
                                            <Check class="size-3.5" />
                                        </span>
                            </button>
                        </div>
                                <p v-if="form.errors.category" class="text-xs text-red-600">{{ form.errors.category }}</p>
                            </section>

                            <section class="space-y-2">
                                <div class="flex items-center justify-between gap-3">
                                    <label for="announcement-title" class="text-sm font-semibold text-slate-800">Title <span class="text-red-500">*</span></label>
                                    <button
                                        type="button"
                                        class="inline-flex items-center gap-1 rounded-full px-2.5 py-1 text-xs font-semibold hover:bg-slate-100"
                                        :class="tone.text"
                                        @click="useSuggestedText"
                                    >
                                        <Sparkles class="size-3.5" />
                                        Use suggested text
                                    </button>
                    </div>
                                <input
                            id="announcement-title"
                            v-model="form.title"
                                    type="text"
                                    maxlength="100"
                            :class="fieldClass"
                            placeholder="e.g. No trips today due to bad weather"
                        />
                                <div class="flex justify-between text-xs">
                                    <span class="text-red-600">
                                        <template v-if="attempted && !form.title.trim()">Title is required.</template>
                                        <template v-else-if="form.errors.title">{{ form.errors.title }}</template>
                                    </span>
                                    <span class="text-ink-muted">{{ form.title.length }}/100</span>
                    </div>
                            </section>

                            <section class="space-y-2">
                                <label for="announcement-message" class="text-sm font-semibold text-slate-800">Message <span class="text-red-500">*</span></label>
                        <textarea
                                    id="announcement-message"
                            v-model="form.body"
                                    rows="5"
                                    maxlength="500"
                                    :class="fieldClass"
                                    placeholder="Write a short, clear message"
                                />
                                <div class="flex justify-between text-xs">
                                    <span class="text-red-600">
                                        <template v-if="attempted && !form.body.trim()">Message is required.</template>
                                        <template v-else-if="form.errors.body">{{ form.errors.body }}</template>
                                    </span>
                                    <span class="text-ink-muted">{{ form.body.length }}/500</span>
                                </div>
                            </section>
                        </div>

                        <!-- STEP 2: COVERAGE -->
                        <div v-else-if="step === 2" class="space-y-6">
                            <section v-if="form.category === 'weather'" class="space-y-5">
                                <div class="flex items-start gap-3 rounded-2xl border border-amber-200 bg-amber-50 p-4">
                                    <TriangleAlert class="mt-0.5 size-5 shrink-0 text-amber-600" />
                                    <p class="text-sm text-amber-900">
                                        <strong>This starts a weather hold.</strong>
                                        Matching trips stop and rentals move to Weather Hold. Payments are not refunded.
                                    </p>
                                </div>

                                <div class="space-y-2">
                                    <p class="text-sm font-semibold text-slate-800">Condition</p>
                                    <div class="grid grid-cols-2 gap-3">
                                        <button
                                            v-for="option in [{ value: 'bad_weather', label: 'Bad Weather', icon: CloudRain }, { value: 'rough_seas', label: 'Rough Seas', icon: Waves }]"
                                            :key="option.value"
                                            type="button"
                                            class="flex items-center gap-3 rounded-2xl border-2 p-3 text-left text-sm font-semibold transition"
                                            :class="form.reason === option.value ? 'border-sky-500 bg-sky-50 text-sky-800' : 'border-slate-200 text-slate-700 hover:border-slate-300'"
                                            @click="form.reason = option.value"
                                        >
                                            <span class="flex size-9 items-center justify-center rounded-xl bg-sky-100 text-sky-600">
                                                <component :is="option.icon" class="size-5" />
                                            </span>
                                            {{ option.label }}
                                        </button>
                                    </div>
                                    <p v-if="form.errors.reason" class="text-xs text-red-600">{{ form.errors.reason }}</p>
                    </div>

                                <div class="grid gap-4 sm:grid-cols-2">
                                    <div class="space-y-2">
                                        <label for="affected_date" class="text-sm font-semibold text-slate-800">Affected date <span class="text-red-500">*</span></label>
                                        <input id="affected_date" v-model="form.affected_date" type="date" :class="fieldClass" />
                                        <p v-if="form.errors.affected_date" class="text-xs text-red-600">{{ form.errors.affected_date }}</p>
                                    </div>
                                    <div class="space-y-2">
                                        <p class="text-sm font-semibold text-slate-800">Duration</p>
                                        <div class="grid h-11 grid-cols-2 rounded-xl bg-slate-100 p-1">
                            <button
                                                v-for="option in [{ value: 'whole_day', label: 'Whole day' }, { value: 'time_range', label: 'Specific hours' }]"
                                :key="option.value"
                                type="button"
                                                class="rounded-lg text-sm font-semibold transition"
                                                :class="form.duration_type === option.value ? 'bg-white text-slate-900 shadow-sm dark:bg-[#102036] dark:text-slate-100' : 'text-slate-500'"
                                                @click="form.duration_type = option.value"
                            >
                                {{ option.label }}
                            </button>
                        </div>
                                        <p v-if="form.errors.duration_type" class="text-xs text-red-600">{{ form.errors.duration_type }}</p>
                                    </div>
                                    <template v-if="form.duration_type === 'time_range'">
                                        <div class="space-y-2">
                                            <label for="starts_at" class="text-sm font-semibold text-slate-800">Start time <span class="text-red-500">*</span></label>
                                            <input id="starts_at" v-model="form.starts_at" type="time" :class="fieldClass" />
                                            <p v-if="form.errors.starts_at" class="text-xs text-red-600">{{ form.errors.starts_at }}</p>
                                        </div>
                                        <div class="space-y-2">
                                            <label for="ends_at" class="text-sm font-semibold text-slate-800">End time <span class="text-red-500">*</span></label>
                                            <input id="ends_at" v-model="form.ends_at" type="time" :class="fieldClass" />
                                            <p v-if="form.errors.ends_at" class="text-xs text-red-600">{{ form.errors.ends_at }}</p>
                                        </div>
                                    </template>
                    </div>

                                <div class="space-y-3">
                                    <div class="flex items-center justify-between gap-3">
                                        <p class="text-sm font-semibold text-slate-800">Affected routes <span class="text-red-500">*</span></p>
                                        <button
                                            type="button"
                                            class="rounded-full px-3 py-1 text-xs font-semibold transition"
                                            :class="form.affected_routes.includes('all') ? 'bg-sky-600 text-white' : 'bg-slate-100 text-slate-600 hover:bg-slate-200'"
                                            @click="toggleRoute('all')"
                                        >
                                            All routes
                                        </button>
                                    </div>
                                    <div v-for="group in routeGroups" :key="group.label" class="space-y-2">
                                        <p class="text-[11px] font-semibold tracking-wide text-ink-muted uppercase">{{ group.label }}</p>
                                        <div class="flex flex-wrap gap-2">
                                            <button
                                                v-for="route in group.options"
                                                :key="route.value"
                                                type="button"
                                                :aria-pressed="form.affected_routes.includes(route.value)"
                                                class="inline-flex items-center gap-1.5 rounded-full border px-3.5 py-1.5 text-sm font-medium transition"
                                                :class="form.affected_routes.includes(route.value) ? 'border-sky-600 bg-sky-600 text-white' : 'border-slate-200 bg-white text-slate-700 hover:border-sky-400 dark:bg-[#102036] dark:text-slate-100 dark:border-white/10'"
                                                @click="toggleRoute(route.value)"
                                            >
                                                <Check v-if="form.affected_routes.includes(route.value)" class="size-3.5" />
                                                {{ route.label }}
                                            </button>
                                        </div>
                                    </div>
                                    <p v-if="form.errors.affected_routes" class="text-xs text-red-600">{{ form.errors.affected_routes }}</p>
                                </div>

                                <p v-if="attempted && !weatherValid" class="rounded-xl bg-red-50 p-3 text-sm text-red-600">
                                    Complete the weather hold details.
                                </p>
                            </section>

                            <section class="space-y-3">
                                <p class="text-sm font-semibold text-slate-800">Send to <span class="text-red-500">*</span></p>
                                <div class="grid gap-3 sm:grid-cols-3">
                                    <button
                                        v-for="option in recipientOptions"
                                        :key="option.key"
                                        type="button"
                                        :aria-pressed="recipientSelected(option.key)"
                                        class="relative flex flex-col items-start gap-2 rounded-2xl border-2 p-4 text-left transition"
                                        :class="recipientSelected(option.key) ? tone.selected : 'border-slate-200 bg-white hover:border-slate-300 dark:bg-[#102036] dark:text-slate-100 dark:border-white/10'"
                                        @click="selectRecipient(option.key)"
                                    >
                                        <span class="flex size-10 items-center justify-center rounded-xl" :class="tone.tile">
                                            <component :is="option.icon" class="size-5" />
                                        </span>
                                        <span>
                                            <span class="block text-sm font-bold text-slate-900">{{ option.label }}</span>
                                            <span class="block text-xs text-slate-500">{{ option.hint }}</span>
                                        </span>
                                        <span
                                            v-if="recipientSelected(option.key)"
                                            class="absolute top-3 right-3 flex size-5 items-center justify-center rounded-full text-white"
                                            :class="tone.solid"
                                        >
                                            <Check class="size-3.5" />
                                    </span>
                                    </button>
                                </div>
                                <p v-if="attempted && !form.audience.length" class="text-sm text-red-600">Select who receives this announcement.</p>
                                <p v-if="form.errors.audience" class="text-sm text-red-600">{{ form.errors.audience }}</p>
                            </section>
                        </div>

                        <!-- STEP 3: REVIEW -->
                        <div v-else class="space-y-5">
                            <div class="overflow-hidden rounded-2xl border border-slate-200">
                                <div class="flex items-center gap-3 border-b border-slate-100 px-5 py-4">
                                    <span class="flex size-10 items-center justify-center rounded-xl" :class="tone.tile">
                                        <component :is="selectedCategory?.icon ?? Megaphone" class="size-5" />
                                    </span>
                                    <div class="min-w-0">
                                        <p class="text-xs font-bold tracking-wide uppercase" :class="tone.text">{{ selectedCategory?.label }}</p>
                                        <p class="truncate text-base font-bold text-slate-900">{{ form.title }}</p>
                                    </div>
                                </div>
                                <p class="px-5 py-4 text-sm leading-7 whitespace-pre-wrap text-slate-700">{{ form.body }}</p>
                                <dl class="grid gap-x-6 gap-y-3 border-t border-slate-100 bg-slate-50/70 px-5 py-4 text-sm sm:grid-cols-2">
                                    <div class="flex items-center gap-2">
                                        <Users class="size-4 text-ink-muted" />
                                        <dt class="sr-only">Recipients</dt>
                                        <dd class="font-medium text-slate-800">{{ recipientsSummary }}</dd>
                                    </div>
                                    <div class="flex items-center gap-2">
                                        <Send class="size-4 text-ink-muted" />
                                        <dt class="sr-only">Posting</dt>
                                        <dd class="font-medium text-slate-800">Posts now · {{ postingAtLabel }}</dd>
                                    </div>
                                    <template v-if="form.category === 'weather'">
                                        <div class="flex items-center gap-2">
                                            <CalendarDays class="size-4 text-ink-muted" />
                                            <dt class="sr-only">Affected date</dt>
                                            <dd class="font-medium text-slate-800">{{ affectedDateLabel }}</dd>
                                        </div>
                                        <div class="flex items-center gap-2">
                                            <Clock3 class="size-4 text-ink-muted" />
                                            <dt class="sr-only">Duration</dt>
                                            <dd class="font-medium text-slate-800">{{ durationLabel }} · {{ reasonLabel }}</dd>
                                        </div>
                                        <div class="flex items-start gap-2 sm:col-span-2">
                                            <MapPin class="mt-0.5 size-4 text-ink-muted" />
                                            <dt class="sr-only">Routes</dt>
                                            <dd class="font-medium text-slate-800">{{ selectedRoutesLabel }}</dd>
                                        </div>
                                    </template>
                                </dl>
                            </div>

                            <label
                                v-if="form.category === 'weather'"
                                class="flex cursor-pointer items-start gap-3 rounded-2xl border-2 p-4 transition"
                                :class="confirmWeather ? 'border-amber-400 bg-amber-50' : 'border-amber-200 bg-white dark:bg-[#102036] dark:text-slate-100 dark:border-white/10'"
                            >
                                <input v-model="confirmWeather" type="checkbox" class="mt-0.5 size-5 shrink-0 accent-amber-500" />
                                <span class="text-sm text-amber-900">
                                    <strong class="block">Start the weather hold</strong>
                                    Affected trips stop and rentals move to Weather Hold. Payments are not refunded automatically.
                                </span>
                            </label>

                            <div v-if="Object.keys(form.errors).length" class="space-y-1 rounded-2xl border border-red-200 bg-red-50 p-4">
                                <p v-for="(error, key) in form.errors" :key="key" class="text-sm text-red-600">{{ error }}</p>
                            </div>
                        </div>
                    </div>

                </div>

                <!-- FOOTER -->
                <footer class="flex shrink-0 items-center justify-between gap-3 border-t border-slate-100 bg-white px-5 py-4 sm:px-8 dark:bg-[#102036] dark:text-slate-100 dark:border-white/10">
                    <button
                        type="button"
                        class="inline-flex min-h-11 items-center gap-2 rounded-xl border border-slate-200 px-5 text-sm font-semibold text-slate-700 hover:bg-slate-50"
                        @click="step === 1 ? close() : previousStep()"
                    >
                        <ChevronLeft v-if="step > 1" class="size-4" />
                        {{ step === 1 ? 'Cancel' : 'Back' }}
                    </button>

                    <p class="hidden text-xs text-ink-muted sm:block">Step {{ step }} of 3</p>

                    <button
                        v-if="step < 3"
                        type="button"
                        class="inline-flex min-h-11 items-center gap-2 rounded-xl px-6 text-sm font-semibold text-white shadow-sm transition hover:brightness-110"
                        :class="tone.solid"
                        @click="nextStep"
                    >
                        Continue
                        <ChevronRight class="size-4" />
                    </button>

                    <button
                        v-else
                        type="submit"
                        :disabled="form.processing || (form.category === 'weather' && !confirmWeather)"
                        class="inline-flex min-h-11 items-center gap-2 rounded-xl px-6 text-sm font-semibold text-white shadow-sm transition hover:brightness-110 disabled:cursor-not-allowed disabled:opacity-50"
                        :class="tone.solid"
                    >
                        <Send class="size-4" />
                        {{ form.processing ? 'Posting...' : form.category === 'weather' ? 'Post & start hold' : 'Post announcement' }}
                    </button>
                </footer>
            </form>
        </DialogContent>
    </Dialog>
</template>
