import { NavMain } from '@/components/nav-main';
import { NavUser } from '@/components/nav-user';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
} from '@/components/ui/sidebar';
import type { NavItem } from '@/types';
import { WorkspaceSwitcher } from '@marketing/components/workspace-switcher';
import { useCan } from '@marketing/hooks/use-workspace';
import { marketingNavItems } from '@marketing/navigation';

export function AppSidebar() {
    const can = useCan();

    const mainNavItems: NavItem[] = marketingNavItems.filter((item) =>
        can(item.permission),
    );

    return (
        <Sidebar collapsible="icon" variant="inset">
            <SidebarHeader>
                <WorkspaceSwitcher />
            </SidebarHeader>

            <SidebarContent>
                <NavMain items={mainNavItems} />
            </SidebarContent>

            <SidebarFooter>
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
