import type { LucideIcon } from 'lucide-react';
import type { ReactNode } from 'react';
import { cn } from '@/lib/utils';

/**
 * What a screen shows before it has anything in it: an icon, a title, a sentence on what to do, and actions.
 */
export function EmptyState({
    icon: Icon,
    title,
    description,
    actions,
    className,
}: {
    icon?: LucideIcon;
    title?: ReactNode;
    description?: ReactNode;
    actions?: ReactNode;
    className?: string;
}) {
    return (
        <div
            className={cn(
                'flex flex-col items-center gap-3 rounded-xl border border-dashed bg-muted/40 px-6 py-16 text-center',
                className,
            )}
        >
            {Icon && (
                <span className="flex size-10 items-center justify-center rounded-full border bg-card text-muted-foreground shadow-xs">
                    <Icon aria-hidden className="size-5" />
                </span>
            )}
            {(title || description) && (
                <div className="space-y-1">
                    {title && <p className="font-medium">{title}</p>}
                    {description && (
                        <p className="max-w-sm text-sm text-muted-foreground">
                            {description}
                        </p>
                    )}
                </div>
            )}
            {actions && (
                <div className="flex flex-wrap justify-center gap-2 pt-1">
                    {actions}
                </div>
            )}
        </div>
    );
}
