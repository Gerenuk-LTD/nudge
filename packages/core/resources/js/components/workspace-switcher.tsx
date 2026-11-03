import { Link, router } from '@inertiajs/react';
import { Check, ChevronsUpDown, Plus, Settings } from 'lucide-react';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { update as switchWorkspace } from '@/routes/marketing/current-workspace';
import { edit as workspaceSettings } from '@/routes/marketing/workspace';
import { create as createWorkspace } from '@/routes/marketing/workspaces';
import { WorkspaceAvatar } from '@marketing/components/workspace-avatar';
import { useWorkspace } from '@marketing/hooks/use-workspace';

export function WorkspaceSwitcher() {
    const workspace = useWorkspace();

    if (!workspace) {
        return null;
    }

    return (
        <SidebarMenu>
            <SidebarMenuItem>
                <DropdownMenu>
                    <DropdownMenuTrigger asChild>
                        <SidebarMenuButton
                            size="lg"
                            tooltip={{ children: workspace.current.name }}
                            className="h-11 border border-sidebar-border bg-card px-1.5 shadow-xs group-data-[collapsible=icon]:border-0 group-data-[collapsible=icon]:bg-transparent group-data-[collapsible=icon]:p-0.5! group-data-[collapsible=icon]:shadow-none hover:bg-sidebar-accent data-[state=open]:bg-sidebar-accent"
                        >
                            <WorkspaceAvatar workspace={workspace.current} />
                            <div className="grid flex-1 text-left leading-tight">
                                <span className="truncate text-13 font-medium">
                                    {workspace.current.name}
                                </span>
                                {workspace.role && (
                                    <span className="truncate text-xs text-muted-foreground">
                                        {workspace.role}
                                    </span>
                                )}
                            </div>
                            <ChevronsUpDown className="ml-auto size-4 text-muted-foreground" />
                        </SidebarMenuButton>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent
                        className="w-(--radix-dropdown-menu-trigger-width) min-w-60"
                        align="start"
                        sideOffset={6}
                    >
                        <DropdownMenuLabel className="text-xs text-muted-foreground">
                            Workspaces
                        </DropdownMenuLabel>
                        {workspace.all.map((item) => (
                            <DropdownMenuItem
                                key={item.id}
                                onSelect={() =>
                                    router.visit(switchWorkspace(), {
                                        data: { workspace_id: item.id },
                                    })
                                }
                            >
                                <WorkspaceAvatar
                                    workspace={item}
                                    className="size-5 rounded text-[9px]"
                                />
                                <span className="truncate">{item.name}</span>
                                {item.id === workspace.current.id && (
                                    <Check className="ml-auto size-4" />
                                )}
                            </DropdownMenuItem>
                        ))}
                        <DropdownMenuSeparator />
                        <DropdownMenuItem asChild>
                            <Link href={workspaceSettings()}>
                                <Settings className="size-4" />
                                Workspace settings
                            </Link>
                        </DropdownMenuItem>
                        {workspace.canCreateWorkspace && (
                            <DropdownMenuItem asChild>
                                <Link href={createWorkspace()}>
                                    <Plus className="size-4" />
                                    New workspace
                                </Link>
                            </DropdownMenuItem>
                        )}
                    </DropdownMenuContent>
                </DropdownMenu>
            </SidebarMenuItem>
        </SidebarMenu>
    );
}
