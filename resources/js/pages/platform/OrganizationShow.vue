
<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import {
  ArrowLeft,
  Building2,
  MapPin,
  Ship,
  Users,
  CalendarDays,
  Info,
  UserRound,
  Mail,
  Phone,
  Tag,
  LayoutDashboard,
  History,
  ShieldCheck,
  Power,
  X,
  AlertTriangle,
  CheckCircle2,
  Clock3,
  FileText,
} from 'lucide-vue-next';

/*
|--------------------------------------------------------------------------
| Types - aligned with PlatformController organization payload
|--------------------------------------------------------------------------
*/

type AccessHistory = {
  id?: number;
  action?: string;
  action_label?: string;
  actor_name?: string | null;
  actor?: string | null;
  note?: string | null;
  reason?: string | null;
  created_at_label?: string | null;
  created_at?: string | null;
};

type Admin = {
  id: number;
  name: string;
  email: string;
  is_active?: boolean;
  status?: string;
  deactivated_reason?: string | null;
  can_deactivate?: boolean;
};

type Boat = {
  id: number;
  name?: string;
  boat_name?: string;
  code?: string | null;
  registration_number?: string | null;
  status_label?: string | null;
  status?: string | null;
  archived?: boolean;
};

type Organization = {
  id: number;
  name: string;
  address?: string | null;
  location?: string | null;
  contact_number?: string | null;
  tenant_id?: string | null;

  is_active?: boolean;
  access_state?: string | null;
  status_label?: string | null;

  admin_name?: string | null;
  admin_email?: string | null;
  created_at_label?: string | null;
  created_at?: string | null;

  boats_count?: number;
  users_count?: number;
  trips_count?: number;
  rentals_count?: number;

  access_history?: AccessHistory[];
  admins?: Admin[];
  boats?: Boat[];
};

const props = defineProps<{
  organization: Organization;
}>();

/*
|--------------------------------------------------------------------------
| Page state
|--------------------------------------------------------------------------
*/

type Tab = 'overview' | 'history' | 'admins' | 'boats';

const activeTab = ref<Tab>('overview');

const showDeactivateModal = ref(false);
const deactivateReason = ref('');
const processing = ref(false);
const formError = ref('');

const tabs = [
  {
    id: 'overview',
    label: 'Overview',
    icon: LayoutDashboard,
  },
  {
    id: 'history',
    label: 'Access History',
    icon: History,
  },
  {
    id: 'admins',
    label: 'Barangay Admins',
    icon: Users,
  },
  {
    id: 'boats',
    label: 'Boats',
    icon: Ship,
  },
] as const;

/*
|--------------------------------------------------------------------------
| Data mapping
|--------------------------------------------------------------------------
*/

const orgName = computed(() =>
  props.organization.name || 'Organization'
);

const accessState = computed(() =>
  String(props.organization.access_state ?? '')
    .trim()
    .toLowerCase()
);

const statusText = computed(() => {
  if (props.organization.status_label) {
    return props.organization.status_label;
  }

  if (accessState.value) {
    return accessState.value
      .replaceAll('_', ' ')
      .replace(/\b\w/g, (char) => char.toUpperCase());
  }

  if (typeof props.organization.is_active === 'boolean') {
    return props.organization.is_active ? 'Active' : 'Inactive';
  }

  return 'Status unavailable';
});

const isActive = computed(() => {
  if (typeof props.organization.is_active === 'boolean') {
    return props.organization.is_active;
  }

  return ['active', 'approved', 'restored'].includes(
    accessState.value
  );
});

const accessHistory = computed(() =>
  props.organization.access_history ?? []
);

const admins = computed(() =>
  props.organization.admins ?? []
);

const boats = computed(() =>
  props.organization.boats ?? []
);

const organizationLocation = computed(() =>
  props.organization.address ||
  props.organization.location ||
  'Location not provided'
);

const adminName = computed(() =>
  props.organization.admin_name ||
  admins.value[0]?.name ||
  'Not assigned'
);

const adminEmail = computed(() =>
  props.organization.admin_email ||
  admins.value[0]?.email ||
  'Not available'
);

const registeredDate = computed(() => {
  if (props.organization.created_at_label) {
    return props.organization.created_at_label;
  }

  return formatDate(props.organization.created_at);
});

const boatCount = computed(() =>
  props.organization.boats_count ?? boats.value.length
);

const userCount = computed(() =>
  props.organization.users_count ?? 0
);

const tripCount = computed(() =>
  props.organization.trips_count ?? 0
);

const rentalCount = computed(() =>
  props.organization.rentals_count ?? 0
);

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/

function formatDate(value?: string | null): string {
  if (!value) return '—';

  const date = new Date(value);

  if (Number.isNaN(date.getTime())) {
    return value;
  }

  return date.toLocaleDateString('en-PH', {
    month: 'short',
    day: 'numeric',
    year: 'numeric',
  });
}

function formatDateTime(value?: string | null): string {
  if (!value) return '—';

  const date = new Date(value);

  if (Number.isNaN(date.getTime())) {
    return value;
  }

  return date.toLocaleString('en-PH', {
    month: 'short',
    day: 'numeric',
    year: 'numeric',
    hour: 'numeric',
    minute: '2-digit',
  });
}

function statusClass(status?: string | null): string {
  const value = String(status ?? '')
    .toLowerCase()
    .trim();

  if (
    ['active', 'approved', 'restored', 'completed'].some(
      (item) => value.includes(item)
    )
  ) {
    return 'bg-emerald-50 text-emerald-700 ring-emerald-200';
  }

  if (
    ['pending', 'requested', 'review'].some(
      (item) => value.includes(item)
    )
  ) {
    return 'bg-amber-50 text-amber-700 ring-amber-200';
  }

  if (
    ['inactive', 'suspended', 'deactivated', 'archived', 'rejected']
      .some((item) => value.includes(item))
  ) {
    return 'bg-rose-50 text-rose-700 ring-rose-200';
  }

  return 'bg-slate-100 text-slate-600 ring-slate-200';
}

function historyAction(entry: AccessHistory): string {
  return (
    entry.action_label ||
    entry.action?.replaceAll('_', ' ') ||
    'Unknown action'
  );
}

function historyDate(entry: AccessHistory): string {
  return (
    entry.created_at_label ||
    formatDateTime(entry.created_at)
  );
}

function historyActor(entry: AccessHistory): string {
  return (
    entry.actor_name ||
    entry.actor ||
    'System'
  );
}

function historyReason(entry: AccessHistory): string {
  return (
    entry.note ||
    entry.reason ||
    '—'
  );
}

function adminStatus(admin: Admin): string {
  if (typeof admin.is_active === 'boolean') {
    return admin.is_active ? 'Active' : 'Inactive';
  }

  return admin.status || 'Not specified';
}

function boatName(boat: Boat): string {
  return (
    boat.name ||
    boat.boat_name ||
    `Boat #${boat.id}`
  );
}

function boatStatus(boat: Boat): string {
  if (boat.archived) return 'Archived';

  return (
    boat.status_label ||
    boat.status ||
    'Not specified'
  );
}

/*
|--------------------------------------------------------------------------
| Organization deactivation
|--------------------------------------------------------------------------
*/

function openDeactivateModal(): void {
  deactivateReason.value = '';
  formError.value = '';
  showDeactivateModal.value = true;
}

function closeDeactivateModal(): void {
  if (processing.value) return;

  showDeactivateModal.value = false;
  deactivateReason.value = '';
  formError.value = '';
}

function submitDeactivation(): void {
  const reason = deactivateReason.value.trim();

  if (reason.length < 3) {
    formError.value =
      'Please provide a reason with at least 3 characters.';
    return;
  }

  formError.value = '';
  processing.value = true;

  router.patch(
    `/platform/organizations/${props.organization.id}/deactivate`,
    { reason },
    {
      preserveScroll: true,

      onSuccess: () => {
        showDeactivateModal.value = false;
        deactivateReason.value = '';
      },

      onError: (errors) => {
        formError.value =
          errors.reason ||
          'Unable to deactivate the organization.';
      },

      onFinish: () => {
        processing.value = false;
      },
    }
  );
}
</script>

<template>
  <Head :title="`${orgName} | Organization Details`" />

  <!--
    No AppLayout wrapper here.
    This version assumes your Inertia application already applies
    AppLayout globally. Do not nest a second AppLayout.
  -->

  <main class="min-h-screen bg-[#f6f9fc] text-[#112b55] dark:bg-[#0b1b30] dark:text-slate-100">
    <div class="mx-auto w-full max-w-[1600px] px-4 py-7 sm:px-6 lg:px-8">

      <!-- Breadcrumb -->
      <nav
        aria-label="Breadcrumb"
        class="mb-7 flex flex-wrap items-center gap-3 text-sm"
      >
        <Link
          href="/platform/organizations"
          class="inline-flex items-center gap-2 font-semibold text-blue-700 transition hover:text-blue-900"
        >
          <ArrowLeft class="h-4 w-4" />
          Organizations
        </Link>

        <span class="text-ink-muted">/</span>

        <span class="text-slate-500">
          Organization Details
        </span>
      </nav>

      <!-- Header -->
      <header class="mb-7 flex flex-wrap items-center justify-between gap-5">
        <div class="flex min-w-0 items-center gap-4">
          <div
            class="flex h-16 w-16 shrink-0 items-center justify-center rounded-2xl bg-blue-100 text-blue-700 sm:h-20 sm:w-20"
          >
            <Building2 class="h-9 w-9 sm:h-10 sm:w-10" />
          </div>

          <div class="min-w-0">
            <div class="flex flex-wrap items-center gap-3">
              <h1
                class="break-words text-2xl font-bold tracking-tight text-[#10234b] sm:text-3xl"
              >
                {{ orgName }}
              </h1>

              <span
                class="inline-flex rounded-full px-3 py-1 text-xs font-semibold capitalize ring-1"
                :class="statusClass(statusText)"
              >
                {{ statusText }}
              </span>
            </div>

            <p
              class="mt-2 flex items-start gap-2 text-sm leading-5 text-slate-500"
            >
              <MapPin class="mt-0.5 h-4 w-4 shrink-0" />
              {{ organizationLocation }}
            </p>
          </div>
        </div>
      </header>

      <!-- Statistics -->
      <section
        aria-label="Organization statistics"
        class="mb-6 grid grid-cols-1 gap-4 md:grid-cols-3"
      >
        <div
          class="flex items-center gap-4 rounded-2xl border border-blue-200 bg-blue-50 p-5"
        >
          <div
            class="flex h-14 w-14 shrink-0 items-center justify-center rounded-full bg-blue-100 text-blue-700"
          >
            <Ship class="h-7 w-7" />
          </div>

          <div>
            <p class="text-sm font-medium text-slate-600">
              Registered Boats
            </p>

            <p class="mt-1 text-3xl font-bold">
              {{ boatCount }}
            </p>
          </div>
        </div>

        <div
          class="flex items-center gap-4 rounded-2xl border border-emerald-200 bg-emerald-50 p-5"
        >
          <div
            class="flex h-14 w-14 shrink-0 items-center justify-center rounded-full bg-emerald-100 text-emerald-700"
          >
            <Users class="h-7 w-7" />
          </div>

          <div>
            <p class="text-sm font-medium text-slate-600">
              Registered Users
            </p>

            <p class="mt-1 text-3xl font-bold">
              {{ userCount }}
            </p>
          </div>
        </div>

        <div
          class="flex items-center gap-4 rounded-2xl border border-violet-200 bg-violet-50 p-5"
        >
          <div
            class="flex h-14 w-14 shrink-0 items-center justify-center rounded-full bg-violet-100 text-violet-700"
          >
            <CalendarDays class="h-7 w-7" />
          </div>

          <div>
            <p class="text-sm font-medium text-slate-600">
              Trips / Rentals
            </p>

            <p class="mt-1 text-3xl font-bold">
              {{ tripCount }} / {{ rentalCount }}
            </p>
          </div>
        </div>
      </section>

      <!-- Organization Information -->
      <section
        class="mb-6 overflow-hidden rounded-2xl border border-blue-200 bg-white shadow-sm dark:bg-[#102036] dark:text-slate-100 dark:border-white/10"
      >
        <div
          class="flex items-center gap-3 border-b border-blue-100 bg-blue-50/50 px-6 py-4"
        >
          <Info class="h-5 w-5 text-blue-700" />

          <div>
            <h2 class="text-lg font-bold">
              Organization Information
            </h2>

            <p class="mt-0.5 text-xs text-slate-500">
              Basic information and registration details
            </p>
          </div>
        </div>

        <div
          class="grid grid-cols-1 gap-x-8 gap-y-6 p-6 sm:grid-cols-2 xl:grid-cols-3"
        >
          <!-- Administrator -->
          <div class="flex min-w-0 items-start gap-3">
            <UserRound class="mt-1 h-5 w-5 shrink-0 text-ink-muted" />

            <div class="min-w-0">
              <p class="text-xs text-slate-500">
                Administrator
              </p>

              <p class="mt-1 break-words font-semibold">
                {{ adminName }}
              </p>
            </div>
          </div>

          <!-- Contact -->
          <div class="flex min-w-0 items-start gap-3">
            <Phone class="mt-1 h-5 w-5 shrink-0 text-ink-muted" />

            <div class="min-w-0">
              <p class="text-xs text-slate-500">
                Contact Number
              </p>

              <p class="mt-1 font-semibold">
                {{ organization.contact_number || 'Not provided' }}
              </p>
            </div>
          </div>

          <!-- Registered -->
          <div class="flex min-w-0 items-start gap-3">
            <CalendarDays class="mt-1 h-5 w-5 shrink-0 text-ink-muted" />

            <div class="min-w-0">
              <p class="text-xs text-slate-500">
                Registration Date
              </p>

              <p class="mt-1 font-semibold">
                {{ registeredDate }}
              </p>
            </div>
          </div>

          <!-- Email -->
          <div class="flex min-w-0 items-start gap-3">
            <Mail class="mt-1 h-5 w-5 shrink-0 text-ink-muted" />

            <div class="min-w-0">
              <p class="text-xs text-slate-500">
                Administrator Email
              </p>

              <p class="mt-1 break-all font-semibold">
                {{ adminEmail }}
              </p>
            </div>
          </div>

          <!-- Tenant ID -->
          <div class="flex min-w-0 items-start gap-3">
            <Tag class="mt-1 h-5 w-5 shrink-0 text-ink-muted" />

            <div class="min-w-0">
              <p class="text-xs text-slate-500">
                Tenant ID
              </p>

              <p class="mt-1 break-all font-semibold">
                {{ organization.tenant_id || '—' }}
              </p>
            </div>
          </div>

          <!-- Access -->
          <div class="flex min-w-0 items-start gap-3">
            <ShieldCheck class="mt-1 h-5 w-5 shrink-0 text-ink-muted" />

            <div class="min-w-0">
              <p class="text-xs text-slate-500">
                Access Status
              </p>

              <span
                class="mt-1 inline-flex rounded-full px-3 py-1 text-xs font-semibold ring-1"
                :class="statusClass(statusText)"
              >
                {{ statusText }}
              </span>
            </div>
          </div>
        </div>
      </section>

      <!-- Navigation Tabs -->
      <div
        class="mb-6 flex flex-wrap gap-2 border-b border-blue-100 pb-4"
        role="tablist"
        aria-label="Organization sections"
      >
        <button
          v-for="tab in tabs"
          :key="tab.id"
          type="button"
          role="tab"
          :aria-selected="activeTab === tab.id"
          class="inline-flex items-center gap-2 rounded-xl border px-4 py-3 text-sm font-semibold transition sm:px-5"
          :class="
            activeTab === tab.id
              ? 'border-blue-700 bg-blue-700 text-white shadow-sm'
              : 'border-blue-100 bg-white text-ink hover:bg-blue-50 dark:bg-[#102036] dark:text-slate-100 dark:border-white/10'
          "
          @click="activeTab = tab.id"
        >
          <component
            :is="tab.icon"
            class="h-4 w-4"
          />

          {{ tab.label }}
        </button>
      </div>

      <!-- Overview -->
      <div
        v-if="activeTab === 'overview'"
        class="grid grid-cols-1 gap-5 xl:grid-cols-3"
      >
        <!-- Access Control -->
        <section
          class="rounded-2xl border border-blue-200 bg-white p-6 shadow-sm xl:col-span-2 dark:bg-[#102036] dark:text-slate-100 dark:border-white/10"
        >
          <div class="flex items-start gap-4">
            <div
              class="rounded-xl bg-blue-50 p-3 text-blue-700"
            >
              <ShieldCheck class="h-6 w-6" />
            </div>

            <div class="min-w-0 flex-1">
              <h2 class="text-lg font-bold">
                Organization Access Control
              </h2>

              <p class="mt-2 text-sm leading-6 text-slate-500">
                Manage this organization's access to SafeSakay.
                Deactivation restricts organization access
                according to the existing backend rules.
              </p>
            </div>
          </div>

          <div
            v-if="isActive"
            class="mt-6 rounded-xl border border-emerald-100 bg-emerald-50 p-4"
          >
            <div class="flex items-start gap-3">
              <CheckCircle2
                class="mt-0.5 h-5 w-5 shrink-0 text-emerald-600"
              />

              <div>
                <p class="font-semibold text-emerald-800">
                  Organization is Active
                </p>

                <p class="mt-1 text-sm text-emerald-700">
                  This organization currently has active access.
                </p>
              </div>
            </div>
          </div>

          <div
            v-else
            class="mt-6 rounded-xl border border-amber-200 bg-amber-50 p-4"
          >
            <div class="flex items-start gap-3">
              <AlertTriangle
                class="mt-0.5 h-5 w-5 shrink-0 text-amber-600"
              />

              <div>
                <p class="font-semibold text-amber-800">
                  Organization is {{ statusText }}
                </p>

                <p class="mt-1 text-sm text-amber-700">
                  Review the access history and existing restoration
                  workflow before changing this organization's access.
                </p>
              </div>
            </div>
          </div>

          <div class="mt-6">
            <button
              v-if="isActive"
              type="button"
              class="inline-flex items-center gap-2 rounded-xl bg-rose-600 px-5 py-3 text-sm font-semibold text-white transition hover:bg-rose-700"
              @click="openDeactivateModal"
            >
              <Power class="h-4 w-4" />
              Deactivate Organization
            </button>

            <p
              v-else
              class="text-sm text-slate-500"
            >
              No deactivation action is available while the
              organization is inactive.
            </p>
          </div>
        </section>

        <!-- Summary -->
        <section
          class="rounded-2xl border border-blue-200 bg-white p-6 shadow-sm dark:bg-[#102036] dark:text-slate-100 dark:border-white/10"
        >
          <h2 class="text-lg font-bold">
            Quick Summary
          </h2>

          <p class="mt-1 text-sm text-slate-500">
            Overview of this organization's records
          </p>

          <div class="mt-6 space-y-4">
            <div class="flex items-center justify-between gap-3">
              <span class="text-sm text-slate-500">
                Registered Boats
              </span>

              <span class="font-bold">
                {{ boatCount }}
              </span>
            </div>

            <div class="border-t border-slate-100"></div>

            <div class="flex items-center justify-between gap-3">
              <span class="text-sm text-slate-500">
                Registered Users
              </span>

              <span class="font-bold">
                {{ userCount }}
              </span>
            </div>

            <div class="border-t border-slate-100"></div>

            <div class="flex items-center justify-between gap-3">
              <span class="text-sm text-slate-500">
                Trips
              </span>

              <span class="font-bold">
                {{ tripCount }}
              </span>
            </div>

            <div class="border-t border-slate-100"></div>

            <div class="flex items-center justify-between gap-3">
              <span class="text-sm text-slate-500">
                Rentals
              </span>

              <span class="font-bold">
                {{ rentalCount }}
              </span>
            </div>
          </div>

          <button
            type="button"
            class="mt-6 inline-flex items-center gap-2 text-sm font-semibold text-blue-700 hover:text-blue-900"
            @click="activeTab = 'history'"
          >
            <History class="h-4 w-4" />
            View Access History
          </button>
        </section>
      </div>

      <!-- Access History -->
      <section
        v-if="activeTab === 'history'"
        class="overflow-hidden rounded-2xl border border-blue-200 bg-white shadow-sm dark:bg-[#102036] dark:text-slate-100 dark:border-white/10"
      >
        <div class="flex items-start gap-3 border-b border-blue-100 px-6 py-5">
          <History class="mt-0.5 h-5 w-5 text-blue-700" />

          <div>
            <h2 class="text-lg font-bold">
              Access History
            </h2>

            <p class="mt-1 text-sm text-slate-500">
              Record of access changes, requests, and
              administrative actions for this organization.
            </p>
          </div>
        </div>

        <div
          v-if="accessHistory.length"
          class="overflow-x-auto"
        >
          <table class="w-full min-w-[700px] text-left text-sm">
            <thead
              class="border-b border-slate-200 bg-slate-50 text-xs uppercase tracking-wide text-slate-500"
            >
              <tr>
                <th class="px-6 py-4 font-semibold">
                  Date & Time
                </th>

                <th class="px-6 py-4 font-semibold">
                  Action
                </th>

                <th class="px-6 py-4 font-semibold">
                  Performed By
                </th>

                <th class="px-6 py-4 font-semibold">
                  Reason / Details
                </th>
              </tr>
            </thead>

            <tbody class="divide-y divide-slate-100">
              <tr
                v-for="(entry, index) in accessHistory"
                :key="entry.id ?? index"
                class="transition hover:bg-blue-50/40"
              >
                <td class="whitespace-nowrap px-6 py-4 text-slate-500">
                  {{ historyDate(entry) }}
                </td>

                <td class="px-6 py-4">
                  <span
                    class="inline-flex rounded-full px-3 py-1 text-xs font-semibold capitalize ring-1"
                    :class="statusClass(historyAction(entry))"
                  >
                    {{ historyAction(entry) }}
                  </span>
                </td>

                <td class="px-6 py-4 font-medium">
                  {{ historyActor(entry) }}
                </td>

                <td class="px-6 py-4 text-slate-500">
                  {{ historyReason(entry) }}
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <div
          v-else
          class="flex flex-col items-center px-6 py-14 text-center"
        >
          <Clock3 class="h-10 w-10 text-slate-300" />

          <p class="mt-3 font-semibold">
            No Access History
          </p>

          <p class="mt-1 text-sm text-slate-500">
            No access changes have been recorded for this organization.
          </p>
        </div>
      </section>

      <!-- Admins -->
      <section
        v-if="activeTab === 'admins'"
        class="overflow-hidden rounded-2xl border border-blue-200 bg-white shadow-sm dark:bg-[#102036] dark:text-slate-100 dark:border-white/10"
      >
        <div class="flex items-start gap-3 border-b border-blue-100 px-6 py-5">
          <Users class="mt-0.5 h-5 w-5 text-blue-700" />

          <div>
            <h2 class="text-lg font-bold">
              Barangay Administrators
            </h2>

            <p class="mt-1 text-sm text-slate-500">
              Accounts assigned to manage this organization.
            </p>
          </div>
        </div>

        <div
          v-if="admins.length"
          class="divide-y divide-slate-100"
        >
          <div
            v-for="admin in admins"
            :key="admin.id"
            class="flex flex-wrap items-center justify-between gap-4 px-6 py-5"
          >
            <div class="flex min-w-0 items-center gap-4">
              <div
                class="flex h-12 w-12 shrink-0 items-center justify-center rounded-full bg-blue-100 text-blue-700"
              >
                <UserRound class="h-6 w-6" />
              </div>

              <div class="min-w-0">
                <p class="font-semibold">
                  {{ admin.name }}
                </p>

                <p class="mt-1 break-all text-sm text-slate-500">
                  {{ admin.email }}
                </p>

                <p
                  v-if="admin.deactivated_reason"
                  class="mt-2 text-xs text-rose-600"
                >
                  Reason: {{ admin.deactivated_reason }}
                </p>
              </div>
            </div>

            <span
              class="rounded-full px-3 py-1 text-xs font-semibold ring-1"
              :class="statusClass(adminStatus(admin))"
            >
              {{ adminStatus(admin) }}
            </span>
          </div>
        </div>

        <div
          v-else
          class="flex flex-col items-center px-6 py-14 text-center"
        >
          <Users class="h-10 w-10 text-slate-300" />

          <p class="mt-3 font-semibold">
            No Administrators Found
          </p>

          <p class="mt-1 text-sm text-slate-500">
            No administrator records were provided for this organization.
          </p>
        </div>
      </section>

      <!-- Boats -->
      <section
        v-if="activeTab === 'boats'"
        class="overflow-hidden rounded-2xl border border-blue-200 bg-white shadow-sm dark:bg-[#102036] dark:text-slate-100 dark:border-white/10"
      >
        <div
          class="flex flex-wrap items-center justify-between gap-3 border-b border-blue-100 px-6 py-5"
        >
          <div class="flex items-start gap-3">
            <Ship class="mt-0.5 h-5 w-5 text-blue-700" />

            <div>
              <h2 class="text-lg font-bold">
                Registered Boats
              </h2>

              <p class="mt-1 text-sm text-slate-500">
                Read-only boat records belonging to this organization.
              </p>
            </div>
          </div>

          <span
            class="rounded-full bg-blue-50 px-3 py-1 text-xs font-semibold text-blue-700"
          >
            {{ boats.length }} Listed
          </span>
        </div>

        <div
          v-if="boats.length"
          class="divide-y divide-slate-100"
        >
          <div
            v-for="boat in boats"
            :key="boat.id"
            class="flex flex-wrap items-center justify-between gap-4 px-6 py-5"
          >
            <div class="flex min-w-0 items-center gap-4">
              <div
                class="flex h-12 w-12 shrink-0 items-center justify-center rounded-xl bg-blue-50 text-blue-700"
              >
                <Ship class="h-6 w-6" />
              </div>

              <div class="min-w-0">
                <p class="font-semibold">
                  {{ boatName(boat) }}
                </p>

                <p class="mt-1 text-sm text-slate-500">
                  {{ boat.code || boat.registration_number || 'No boat code provided' }}
                </p>
              </div>
            </div>

            <span
              class="rounded-full px-3 py-1 text-xs font-semibold ring-1"
              :class="statusClass(boatStatus(boat))"
            >
              {{ boatStatus(boat) }}
            </span>
          </div>
        </div>

        <div
          v-else
          class="flex flex-col items-center px-6 py-14 text-center"
        >
          <Ship class="h-11 w-11 text-slate-300" />

          <p class="mt-3 font-semibold">
            No Boats Registered
          </p>

          <p class="mt-1 text-sm text-slate-500">
            There are no boat records to display.
          </p>
        </div>
      </section>
    </div>

    <!-- Deactivation Confirmation Modal -->
    <div
      v-if="showDeactivateModal"
      class="fixed inset-0 z-[100] flex items-center justify-center bg-slate-950/50 p-4"
      role="presentation"
      @click.self="closeDeactivateModal"
    >
      <div
        role="dialog"
        aria-modal="true"
        aria-labelledby="deactivate-title"
        class="w-full max-w-lg overflow-hidden rounded-2xl bg-white shadow-2xl dark:bg-[#102036] dark:text-slate-100"
      >
        <div
          class="flex items-start justify-between gap-4 border-b border-slate-100 px-6 py-5"
        >
          <div class="flex items-start gap-3">
            <div
              class="rounded-xl bg-rose-100 p-3 text-rose-600"
            >
              <AlertTriangle class="h-6 w-6" />
            </div>

            <div>
              <h2
                id="deactivate-title"
                class="text-lg font-bold text-slate-900"
              >
                Deactivate Organization
              </h2>

              <p class="mt-1 text-sm text-slate-500">
                Please confirm this administrative action.
              </p>
            </div>
          </div>

          <button
            type="button"
            aria-label="Close dialog"
            :disabled="processing"
            class="rounded-lg p-2 text-ink-muted hover:bg-slate-100"
            @click="closeDeactivateModal"
          >
            <X class="h-5 w-5" />
          </button>
        </div>

        <form @submit.prevent="submitDeactivation">
          <div class="space-y-5 px-6 py-5">
            <p class="text-sm leading-6 text-slate-600">
              You are about to deactivate
              <strong class="text-slate-900">
                {{ orgName }}
              </strong>.
              This may restrict access for the organization's
              administrators and operators.
            </p>

            <div>
              <label
                for="deactivation-reason"
                class="mb-2 block text-sm font-semibold text-slate-800"
              >
                Reason for Deactivation
                <span class="text-rose-600">*</span>
              </label>

              <textarea
                id="deactivation-reason"
                v-model="deactivateReason"
                rows="4"
                required
                minlength="3"
                :disabled="processing"
                placeholder="Explain why this organization is being deactivated..."
                class="w-full resize-none rounded-xl border border-slate-200 bg-white px-4 py-3 text-sm text-slate-800 outline-none transition focus:border-blue-500 focus:ring-2 focus:ring-blue-100 dark:bg-[#102036] dark:text-slate-100 dark:border-white/10"
              ></textarea>

              <p class="mt-2 text-xs text-slate-500">
                This reason will be submitted to the existing
                organization access-control endpoint.
              </p>

              <p
                v-if="formError"
                role="alert"
                class="mt-2 text-sm text-rose-600"
              >
                {{ formError }}
              </p>
            </div>
          </div>

          <div
            class="flex flex-wrap justify-end gap-3 border-t border-slate-100 bg-slate-50 px-6 py-4"
          >
            <button
              type="button"
              :disabled="processing"
              class="rounded-xl border border-slate-200 bg-white px-5 py-3 text-sm font-semibold text-slate-700 hover:bg-slate-100 disabled:opacity-50 dark:bg-[#102036] dark:text-slate-100 dark:border-white/10"
              @click="closeDeactivateModal"
            >
              Cancel
            </button>

            <button
              type="submit"
              :disabled="processing"
              class="inline-flex items-center gap-2 rounded-xl bg-rose-600 px-5 py-3 text-sm font-semibold text-white hover:bg-rose-700 disabled:cursor-not-allowed disabled:opacity-50"
            >
              <Power class="h-4 w-4" />

              {{
                processing
                  ? 'Deactivating...'
                  : 'Confirm Deactivation'
              }}
            </button>
          </div>
        </form>
      </div>
    </div>
  </main>
</template>
