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
                            className="data-[state=open]:bg-sidebar-accent"
                        >
                            <div className="flex aspect-square size-8 items-center justify-center rounded-md bg-sidebar-primary text-sm font-semibold text-sidebar-primary-foreground">
                                {workspace.current.name.charAt(0).toUpperCase()}
                            </div>
                            <div className="grid flex-1 text-left text-sm leading-tight">
                                <span className="truncate font-semibold">
                                    {workspace.current.name}
                                </span>
                                <span className="truncate text-xs text-muted-foreground">
                                    Workspace
                                </span>
                            </div>
                            <ChevronsUpDown className="ml-auto size-4" />
                        </SidebarMenuButton>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent
                        className="w-(--radix-dropdown-menu-trigger-width) min-w-56"
                        align="start"
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
                        <DropdownMenuItem asChild>
                            <Link href={createWorkspace()}>
                                <Plus className="size-4" />
                                New workspace
                            </Link>
                        </DropdownMenuItem>
                    </DropdownMenuContent>
                </DropdownMenu>
            </SidebarMenuItem>
        </SidebarMenu>
    );
}
