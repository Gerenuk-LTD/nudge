import { NavMain } from '@/components/nav-main';
import { NavUser } from '@/components/nav-user';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarRail,
} from '@/components/ui/sidebar';
import type { NavItem } from '@/types';
import { SendingStatus } from '@marketing/components/sending-status';
import { WorkspaceSwitcher } from '@marketing/components/workspace-switcher';
import { useCan } from '@marketing/hooks/use-workspace';
import { marketingNavItems } from '@marketing/navigation';

export function AppSidebar() {
    const can = useCan();

    const mainNavItems: NavItem[] = marketingNavItems.filter((item) =>
        can(item.permission),
    );

    return (
        <Sidebar collapsible="icon">
            <SidebarHeader className="h-16 justify-center border-b border-sidebar-border px-3 group-data-[collapsible=icon]:px-2">
                <WorkspaceSwitcher />
            </SidebarHeader>

            <SidebarContent className="gap-0 py-2">
                <NavMain items={mainNavItems} />
            </SidebarContent>

            <SendingStatus />

            <SidebarFooter className="border-t border-sidebar-border p-3 group-data-[collapsible=icon]:p-2">
                <NavUser />
            </SidebarFooter>

            <SidebarRail />
        </Sidebar>
    );
}
