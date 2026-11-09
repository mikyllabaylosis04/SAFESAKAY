<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { ChevronRight } from '@lucide/vue';
import { computed } from 'vue';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    SidebarGroup,
    SidebarGroupLabel,
    SidebarMenu,
    SidebarMenuBadge,
    SidebarMenuButton,
    SidebarMenuItem,
    SidebarMenuSub,
    SidebarMenuSubButton,
    SidebarMenuSubItem,
    useSidebar,
} from '@/components/ui/sidebar';
import { useCurrentUrl } from '@/composables/useCurrentUrl';
import { toUrl } from '@/lib/utils';
import type { NavItem } from '@/types';

const props = defineProps<{
    items: NavItem[];
    label?: string;
}>();

const { isCurrentUrl } = useCurrentUrl();
const { state, isMobile } = useSidebar();

const isIconCollapsed = computed(
    () => state.value === 'collapsed' && !isMobile.value,
);

function isGroupActive(item: NavItem) {
    if (item.items?.length) {
        return item.items.some((child) => isCurrentUrl(child.href));
    }

    return isCurrentUrl(item.href);
}
</script>

<template>
    <SidebarGroup class="px-2 py-1">
        <SidebarGroupLabel
            v-if="label"
            class="px-3 text-[11px] font-semibold tracking-[0.16em] text-white/70"
        >
            {{ label }}
        </SidebarGroupLabel>
        <SidebarMenu>
            <template v-for="item in props.items" :key="item.title">
                <SidebarMenuItem v-if="item.items?.length">
                    <DropdownMenu v-if="isIconCollapsed">
                        <DropdownMenuTrigger as-child>
                            <SidebarMenuButton
                                :is-active="isGroupActive(item)"
                                class="rounded-xl text-white hover:bg-white/15 data-[active=true]:bg-[#4aa0ee] data-[active=true]:text-white"
                            >
                                <component :is="item.icon" />
                                <span>{{ item.title }}</span>
                            </SidebarMenuButton>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent
                            side="right"
                            align="start"
                            class="min-w-56 rounded-xl border-[#d7e8f6] bg-white text-[#0f4c8a] shadow-lg dark:bg-[#102036] dark:text-slate-100 dark:border-white/10"
                        >
                            <DropdownMenuLabel class="text-ink-muted">
                                {{ item.title }}
                            </DropdownMenuLabel>
                            <DropdownMenuItem
                                v-for="child in item.items"
                                :key="child.title"
                                as-child
                                class="cursor-pointer rounded-lg text-[#0f4c8a] focus:bg-[#e8f4fc] focus:text-[#1b7ad8]"
                            >
                                <Link :href="child.href">
                                    <component :is="child.icon" />
                                    <span>{{ child.title }}</span>
                                </Link>
                            </DropdownMenuItem>
                        </DropdownMenuContent>
                    </DropdownMenu>

                    <Collapsible
                        v-else
                        :default-open="isGroupActive(item)"
                        class="group/collapsible"
                    >
                        <CollapsibleTrigger as-child>
                            <SidebarMenuButton
                                :is-active="isGroupActive(item)"
                                :tooltip="item.title"
                                class="rounded-xl text-white hover:bg-white/15 data-[active=true]:bg-[#4aa0ee] data-[active=true]:text-white"
                            >
                                <component :is="item.icon" />
                                <span>{{ item.title }}</span>
                                <ChevronRight
                                    class="ml-auto size-4 transition-transform group-data-[state=open]/collapsible:rotate-90"
                                />
                            </SidebarMenuButton>
                        </CollapsibleTrigger>
                        <CollapsibleContent>
                            <SidebarMenuSub class="border-white/20">
                                <SidebarMenuSubItem
                                    v-for="child in item.items"
                                    :key="child.title"
                                >
                                    <SidebarMenuSubButton
                                        as-child
                                        :is-active="isCurrentUrl(child.href)"
                                        class="rounded-lg text-white/90 hover:bg-white/15 hover:text-white data-[active=true]:bg-[#4aa0ee] data-[active=true]:text-white"
                                    >
                                        <Link :href="child.href">
                                            <component :is="child.icon" />
                                            <span>{{ child.title }}</span>
                                        </Link>
                                    </SidebarMenuSubButton>
                                </SidebarMenuSubItem>
                            </SidebarMenuSub>
                        </CollapsibleContent>
                    </Collapsible>
                </SidebarMenuItem>

                <SidebarMenuItem v-else>
                    <SidebarMenuButton
                        as-child
                        :is-active="item.isActive || isCurrentUrl(item.href)"
                        :tooltip="item.title"
                        class="rounded-xl text-white hover:bg-white/15 data-[active=true]:bg-[#4aa0ee] data-[active=true]:text-white"
                    >
                        <Link :href="toUrl(item.href)">
                            <component :is="item.icon" />
                            <span>{{ item.title }}</span>
                        </Link>
                    </SidebarMenuButton>
                    <SidebarMenuBadge
                        v-if="item.badge"
                        class="bg-red-500 text-white"
                    >
                        {{ item.badge }}
                    </SidebarMenuBadge>
                </SidebarMenuItem>
            </template>
        </SidebarMenu>
    </SidebarGroup>
</template>
