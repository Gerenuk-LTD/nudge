import { Link } from '@inertiajs/react';
import AppLogo from '@/components/app-logo';
import { NavMain } from '@/components/nav-main';
import { NavUser } from '@/components/nav-user';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
    SidebarRail,
    useSidebar,
} from '@/components/ui/sidebar';
import { home } from '@/routes/nudge';
import type { NavItem } from '@/types';
import { SendingStatus } from '@nudge/components/sending-status';
import { WorkspaceSwitcher } from '@nudge/components/workspace-switcher';
import { useCan } from '@nudge/hooks/use-workspace';
import { nudgeNavItems } from '@nudge/navigation';

export function AppSidebar() {
    const can = useCan();
    const { isMobile, setOpenMobile } = useSidebar();

    const mainNavItems: NavItem[] = nudgeNavItems.filter((item) =>
        can(item.permission),
    );

    return (
        <Sidebar collapsible="icon">
            <SidebarHeader className="h-16 justify-center border-b border-sidebar-border px-3 group-data-[collapsible=icon]:px-2">
                <SidebarMenu>
                    <SidebarMenuItem>
                        <SidebarMenuButton
                            size="lg"
                            asChild
                            className="h-10 px-1 group-data-[collapsible=icon]:p-0! hover:bg-transparent active:bg-transparent"
                        >
                            <Link
                                href={home()}
                                prefetch
                                onClick={() => isMobile && setOpenMobile(false)}
                            >
                                <AppLogo />
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                </SidebarMenu>
            </SidebarHeader>

            <SidebarContent className="gap-0 py-2">
                <div className="px-3 pt-2 group-data-[collapsible=icon]:px-2">
                    <WorkspaceSwitcher />
                </div>
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
