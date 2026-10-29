import { Breadcrumbs } from '@/components/breadcrumbs';
import { CommandPalette } from '@/components/command-palette';
import { Separator } from '@/components/ui/separator';
import { SidebarTrigger } from '@/components/ui/sidebar';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { useCurrentUrl } from '@/hooks/use-current-url';
import type { BreadcrumbItem as BreadcrumbItemType } from '@/types';

export function AppSidebarHeader({
    breadcrumbs = [],
}: {
    breadcrumbs?: BreadcrumbItemType[];
}) {
    const { isCurrentUrl } = useCurrentUrl();

    // A trail that is only the page you are on repeats its title; one that leads somewhere else is a way back.
    const trail =
        breadcrumbs.length === 1 && isCurrentUrl(breadcrumbs[0].href)
            ? []
            : breadcrumbs;

    return (
        <header className="sticky top-0 z-10 flex h-16 shrink-0 items-center gap-3 border-b bg-background/80 px-4 backdrop-blur md:px-6">
            <div className="flex min-w-0 items-center gap-3">
                <Tooltip>
                    <TooltipTrigger asChild>
                        <SidebarTrigger className="-ml-1.5 text-muted-foreground" />
                    </TooltipTrigger>
                    <TooltipContent
                        side="bottom"
                        className="flex items-center gap-2"
                    >
                        Toggle sidebar
                        <kbd className="rounded border border-background/20 px-1 font-sans text-xs">
                            ⌘B
                        </kbd>
                    </TooltipContent>
                </Tooltip>
                {trail.length > 0 && (
                    <>
                        <Separator orientation="vertical" className="h-4!" />
                        <Breadcrumbs breadcrumbs={trail} />
                    </>
                )}
            </div>
            <div className="ml-auto">
                <CommandPalette />
            </div>
        </header>
    );
}
