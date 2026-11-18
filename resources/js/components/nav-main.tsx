import { Link } from '@inertiajs/react';
import {
    SidebarGroup,
    SidebarGroupLabel,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
    useSidebar,
} from '@/components/ui/sidebar';
import { useCurrentUrl } from '@/hooks/use-current-url';
import { cn } from '@/lib/utils';
import type { NavItem } from '@/types';

/**
 * Renders items under a heading per group, keeping the order the groups first appear in.
 * An item stays highlighted on its child pages, such as a campaign's report.
 * On phones the sidebar is a drawer, which closes once an item is picked.
 */
export function NavMain({
    items,
    className,
}: {
    items: NavItem[];
    className?: string;
}) {
    const { isCurrentOrParentUrl } = useCurrentUrl();
    const { isMobile, setOpenMobile } = useSidebar();

    const groups = Map.groupBy(items, (item) => item.group ?? '');

    return (
        <>
            {[...groups].map(([group, groupItems]) => (
                <SidebarGroup
                    key={group}
                    className={cn(
                        // Groups sit 24px apart; the first starts close to the top.
                        'px-3 pt-6 pb-0 group-data-[collapsible=icon]:px-2 first:pt-2',
                        className,
                    )}
                >
                    {group && (
                        <SidebarGroupLabel className="mb-1 h-6 px-[9px] text-xs font-medium text-muted-foreground/70 group-data-[collapsible=icon]:-mt-6">
                            {group}
                        </SidebarGroupLabel>
                    )}
                    <SidebarMenu className="gap-0.5">
                        {groupItems.map((item) => (
                            <SidebarMenuItem key={item.title}>
                                <SidebarMenuButton
                                    asChild
                                    isActive={isCurrentOrParentUrl(item.href)}
                                    tooltip={{ children: item.title }}
                                    className="h-[34px] border border-transparent px-2 text-13 text-muted-foreground transition-[color,background-color,border-color] hover:bg-sidebar-accent hover:text-foreground data-[active=true]:border-sidebar-border data-[active=true]:bg-card data-[active=true]:text-foreground data-[active=true]:shadow-xs data-[active=true]:hover:bg-card [&>svg]:text-muted-foreground data-[active=true]:[&>svg]:text-foreground"
                                >
                                    <Link
                                        href={item.href}
                                        prefetch
                                        onClick={() =>
                                            isMobile && setOpenMobile(false)
                                        }
                                    >
                                        {item.icon && <item.icon />}
                                        <span>{item.title}</span>
                                    </Link>
                                </SidebarMenuButton>
                            </SidebarMenuItem>
                        ))}
                    </SidebarMenu>
                </SidebarGroup>
            ))}
        </>
    );
}
