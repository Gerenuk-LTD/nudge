import { Link } from '@inertiajs/react';
import { ArrowRight, CircleAlert, OctagonPause } from 'lucide-react';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { cn } from '@/lib/utils';
import { index as providersIndex } from '@/routes/nudge/providers';
import { edit as sendingEdit } from '@/routes/nudge/sending';
import { useCan, useWorkspace } from '@nudge/hooks/use-workspace';
import type { WorkspaceSharedProps } from '@nudge/types';

const issues: Record<
    NonNullable<WorkspaceSharedProps['sendingIssue']>,
    {
        title: string;
        description: string;
        action: string;
        href: ReturnType<typeof sendingEdit>;
    }
> = {
    paused: {
        title: 'Sending is paused',
        description:
            'Bounces or complaints passed the safe limit. Campaigns are held.',
        action: 'Review and resume',
        href: sendingEdit(),
    },
    no_provider: {
        title: 'Connect a provider',
        description:
            'Campaigns need a sending provider before they can go out.',
        action: 'Add a provider',
        href: providersIndex(),
    },
    no_postal_address: {
        title: 'Add a postal address',
        description: 'Anti-spam law requires one in every campaign.',
        action: 'Add it',
        href: sendingEdit(),
    },
};

/**
 * A callout at the foot of the sidebar while campaigns can't go out, linking to the fix for people who can make it.
 * When the sidebar is collapsed to icons it shrinks to its icon, with the title as a tooltip.
 */
export function SendingStatus() {
    const workspace = useWorkspace();
    const canFix = useCan()('providers.manage');

    if (!workspace?.sendingIssue) {
        return null;
    }

    const issue = issues[workspace.sendingIssue];
    const paused = workspace.sendingIssue === 'paused';
    const Icon = paused ? OctagonPause : CircleAlert;
    // Red when sending was stopped for safety, amber when setup is unfinished.
    const tone = paused
        ? 'border-red-200 bg-red-50 text-red-950 dark:border-red-500/30 dark:bg-red-500/10 dark:text-red-100'
        : 'border-amber-200 bg-amber-50 text-amber-950 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-100';
    const iconTone = paused
        ? 'text-red-600 dark:text-red-400'
        : 'text-amber-600 dark:text-amber-400';
    const mutedTone = paused
        ? 'text-red-900/75 dark:text-red-200/75'
        : 'text-amber-900/75 dark:text-amber-200/75';

    return (
        <>
            <div
                role="status"
                className={cn(
                    'mx-3 mb-2 rounded-md border p-3 text-xs group-data-[collapsible=icon]:hidden',
                    tone,
                )}
            >
                <p className="flex items-center gap-1.5 font-medium">
                    <Icon className={cn('size-3.5 shrink-0', iconTone)} />
                    {issue.title}
                </p>
                <p className={cn('mt-1', mutedTone)}>{issue.description}</p>
                {canFix && (
                    <Link
                        href={issue.href}
                        className="mt-2 inline-flex items-center gap-1 font-medium underline-offset-2 hover:underline"
                    >
                        {issue.action}
                        <ArrowRight className="size-3" />
                    </Link>
                )}
            </div>

            <Tooltip>
                <TooltipTrigger asChild>
                    <Link
                        href={canFix ? issue.href : '#'}
                        aria-label={issue.title}
                        aria-disabled={!canFix}
                        onClick={(event) => !canFix && event.preventDefault()}
                        className={cn(
                            'mx-auto mb-2 hidden size-8 items-center justify-center rounded-md border group-data-[collapsible=icon]:flex',
                            tone,
                        )}
                    >
                        <Icon className={cn('size-4', iconTone)} />
                    </Link>
                </TooltipTrigger>
                <TooltipContent side="right">{issue.title}</TooltipContent>
            </Tooltip>
        </>
    );
}
