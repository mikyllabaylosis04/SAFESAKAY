
<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3';

import {
    BarChart3,
    Building2,
    CalendarRange,
    ClipboardList,
    Clock,
    HandCoins,
    History,
    LayoutDashboard,
    LogOut,
    MapPin,
    Megaphone,
    Receipt,
    RefreshCw,
    Settings,
    Shield,
    Ship,
    Ticket,
    Users,
    Wallet,
} from '@lucide/vue';

import { computed } from 'vue';
import { useCurrentUrl } from '@/composables/useCurrentUrl';

import AppLogo from '@/components/AppLogo.vue';
import NavMain from '@/components/NavMain.vue';

import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarGroup,
    SidebarGroupContent,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';

import type { NavItem } from '@/types';

// ==========================================
// PAGE PROPS
// ==========================================

const page = usePage();
const { currentUrl } = useCurrentUrl();

const isSuperAdmin = computed(() =>
    Boolean(page.props.auth?.is_super_admin),
);

const isArchived = computed(() =>
    Boolean(page.props.auth?.organization?.archived),
);

const pendingCount = computed(() =>
    Number(page.props.pendingBookingRequests ?? 0),
);

const pendingPaymentCount = computed(() =>
    Number(page.props.pendingPaymentActions ?? 0),
);

const pendingOrgCount = computed(() =>
    Number(page.props.pendingOrganizationApprovals ?? 0),
);

const pendingOrganizationRequestCount = computed(() =>
    Number(page.props.pendingClosureRequests ?? 0) +
    Number(page.props.pendingRestoreRequests ?? 0) +
    Number(page.props.pendingReactivationRequests ?? 0),
);

// ==========================================
// HOME URL
// ==========================================

const homeHref = computed(() => {
    if (isSuperAdmin.value) {
        return '/platform';
    }

    if (isArchived.value) {
        return '/organization/archived';
    }

    return '/dashboard';
});

// ==========================================
// MAIN NAVIGATION - DASHBOARD
// ==========================================

const mainNavItems = computed<NavItem[]>(() => [
    {
        title: isArchived.value
            ? 'Archived Organization'
            : 'Dashboard',
        href: homeHref.value,
        icon: LayoutDashboard,
    },
]);

// ==========================================
// SUPER ADMIN - PLATFORM
// KEEP EXISTING FUNCTIONALITY
// ==========================================

const platformItems = computed<NavItem[]>(() => [
    {
        title: 'Organizations',
        href: '/platform/organizations',
        icon: Building2,
    },
    {
        title: 'Organization Request',
        href: '/platform/organization-requests',
        icon: ClipboardList,
        badge: pendingOrganizationRequestCount.value,
    },
    {
        title: 'Pending Approval',
        href: '/platform/pending',
        icon: Clock,
        badge: pendingOrgCount.value,
    },
    {
        title: 'Admin Management',
        href: '/platform/admins',
        icon: Shield,
    },
    {
        title: 'User Management',
        href: '/platform/users',
        icon: Users,
    },
]);

// ==========================================
// SUPER ADMIN - REPORTS
// ==========================================

const platformReportItems = computed<NavItem[]>(() => [
    {
        title: 'System Reports',
        href: '/platform/reports',
        icon: BarChart3,
    },
    {
        title: 'Audit Logs',
        href: '/platform/audit-logs',
        icon: History,
    },
]);

// ==========================================
// SUPER ADMIN - SYSTEM
// ==========================================

const platformSystemItems = computed<NavItem[]>(() => [
    {
        title: 'System Settings',
        href: '/platform/system-settings',
        icon: Settings,
    },
]);

// ==========================================
// ORGANIZATION ADMIN - MAIN MANAGEMENT
//
// 1. Route Management
// 2. Boat Management
// 3. User Management
// ==========================================

const organizationManagementItems: NavItem[] = [
    {
        title: 'Route Management',
        href: '/routes',
        icon: MapPin,
    },
    {
        title: 'Boat Management',
        href: '/boats',
        icon: Ship,
    },
    {
        title: 'User Management',
        href: '/users',
        icon: Users,
    },
];

// ==========================================
// ORGANIZATION ADMIN - SCHEDULING
//
// NEW ORDER:
// 1. Regular Trip Scheduling
// 2. Rental Scheduling
// 3. Rental Requests
// ==========================================

const schedulingItems = computed<NavItem[]>(() => [
    {
        title: 'Regular Trip Scheduling',
        href: '/schedules/regular-trip-rotation',
        icon: RefreshCw,
        isActive:
            currentUrl.value === '/schedules/regular-trip-rotation' ||
            /^\/regular-trips\/\d+/.test(currentUrl.value),
    },
    {
        title: 'Rental Scheduling',
        href: '/schedules/boat-booking-schedule',
        icon: CalendarRange,
    },
    {
        title: 'Rental Requests',
        href: '/bookings/requests',
        icon: Ticket,
        badge: pendingCount.value,
    },
]);

// ==========================================
// ORGANIZATION ADMIN - OTHER MANAGEMENT
//
// NEW ORDER:
// 1. Announcements
// 2. Payments
// 3. My Commission
// 4. Reports
// ==========================================

const organizationOtherItems = computed<NavItem[]>(() => [
    {
        title: 'Announcements',
        href: '/announcements',
        icon: Megaphone,
    },
    {
        title: 'Payments',
        href: '/payments',
        icon: Wallet,
        badge: pendingPaymentCount.value,
    },
    {
        title: 'My Commission',
        href: '/my-commission',
        icon: Receipt,
    },
    {
        title: 'Operator Payouts',
        href: '/operator-payouts',
        icon: HandCoins,
    },
    {
        title: 'Reports',
        href: '/reports',
        icon: BarChart3,
    },
]);

// ==========================================
// ORGANIZATION ADMIN - SETTINGS
//
// Uses the conventional Laravel starter-kit
// profile settings URL.
//
// Verify that this route exists in your
// application before using the link.
// ==========================================

const organizationSettingsHref = '/settings/profile';

// ==========================================
// LOGOUT
// ==========================================

const logoutHref = computed(() =>
    isSuperAdmin.value
        ? '/superadmin/logout'
        : '/logout',
);

const handleLogout = () => {
    router.flushAll();
};
</script>

<template>
    <Sidebar
        collapsible="icon"
        variant="sidebar"
    >
        <!-- ================================= -->
        <!-- SIDEBAR HEADER -->
        <!-- KEEP ORIGINAL BRANDING -->
        <!-- ================================= -->

        <SidebarHeader class="pt-4">
            <SidebarMenu>
                <SidebarMenuItem>
                    <SidebarMenuButton
                        size="lg"
                        as-child
                        class="hover:bg-white/10 data-[active=true]:bg-transparent"
                    >
                        <Link :href="homeHref">
                            <AppLogo />
                        </Link>
                    </SidebarMenuButton>
                </SidebarMenuItem>
            </SidebarMenu>

            <!-- ORIGINAL WAVE DIVIDER -->
            <div
                class="mx-4 mt-1 hidden h-px items-center gap-2 group-data-[collapsible=icon]:hidden lg:flex"
                aria-hidden="true"
            >
                <span class="h-px flex-1 bg-white/35" />

                <svg
                    class="size-4 text-[#9fe0ff]"
                    viewBox="0 0 24 24"
                    fill="none"
                    aria-hidden="true"
                >
                    <path
                        d="M2 12c2.5-3 5-3 7.5 0s5 3 7.5 0 5-3 7 0"
                        stroke="currentColor"
                        stroke-width="1.8"
                        stroke-linecap="round"
                    />
                </svg>

                <span class="h-px flex-1 bg-white/35" />
            </div>
        </SidebarHeader>

        <!-- ================================= -->
        <!-- SIDEBAR CONTENT -->
        <!-- ================================= -->

        <SidebarContent class="gap-3 pt-2">

            <!-- DASHBOARD -->
            <NavMain :items="mainNavItems" />

            <!-- ================================= -->
            <!-- SUPER ADMIN -->
            <!-- ORIGINAL NAVIGATION PRESERVED -->
            <!-- ================================= -->

            <template v-if="isSuperAdmin">

                <NavMain
                    label="PLATFORM"
                    :items="platformItems"
                />

                <NavMain
                    label="REPORTS"
                    :items="platformReportItems"
                />

                <NavMain
                    label="SYSTEM"
                    :items="platformSystemItems"
                />

            </template>

            <!-- ================================= -->
            <!-- ORGANIZATION ADMIN -->
            <!-- NEW SIDEBAR SEQUENCE -->
            <!-- ================================= -->

            <template v-else-if="!isArchived">

                <!-- USER / BOAT / ROUTE MANAGEMENT -->
                <NavMain
                    :items="organizationManagementItems"
                />

                <!-- SECTION DIVIDER -->
                <div
                    class="mx-4 h-px bg-white/20 group-data-[collapsible=icon]:hidden"
                    aria-hidden="true"
                />

                <!-- SCHEDULING MANAGEMENT -->
                <NavMain
                    label="SCHEDULING MANAGEMENT"
                    :items="schedulingItems"
                />

                <!-- SECTION DIVIDER -->
                <div
                    class="mx-4 h-px bg-white/20 group-data-[collapsible=icon]:hidden"
                    aria-hidden="true"
                />

                <!-- ANNOUNCEMENTS / PAYMENTS -->
                <!-- MY COMMISSION / REPORTS -->
                <NavMain
                    :items="organizationOtherItems"
                />

            </template>

        </SidebarContent>

        <!-- ================================= -->
        <!-- SIDEBAR FOOTER -->
        <!-- SETTINGS AND LOGOUT -->
        <!-- ================================= -->

        <SidebarFooter
            class="border-t border-white/15 pb-4 pt-3"
        >
            <SidebarGroup class="px-2">
                <SidebarGroupContent>
                    <SidebarMenu>

                        <!-- ORGANIZATION SETTINGS -->
                        <SidebarMenuItem
                            v-if="!isSuperAdmin && !isArchived"
                        >
                            <SidebarMenuButton
                                as-child
                                tooltip="Settings"
                                class="rounded-xl text-white hover:bg-white/15"
                            >
                                <Link
                                    :href="organizationSettingsHref"
                                >
                                    <Settings />
                                    <span>Settings</span>
                                </Link>
                            </SidebarMenuButton>
                        </SidebarMenuItem>

                        <!-- LOGOUT -->
                        <SidebarMenuItem>
                            <SidebarMenuButton
                                as-child
                                tooltip="Logout"
                                class="rounded-xl text-white hover:bg-white/15"
                            >
                                <Link
                                    :href="logoutHref"
                                    method="post"
                                    as="button"
                                    @click="handleLogout"
                                >
                                    <LogOut />
                                    <span>Logout</span>
                                </Link>
                            </SidebarMenuButton>
                        </SidebarMenuItem>

                    </SidebarMenu>
                </SidebarGroupContent>
            </SidebarGroup>
        </SidebarFooter>

    </Sidebar>

    <slot />
</template>
