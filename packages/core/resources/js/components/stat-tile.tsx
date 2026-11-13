import type { LucideIcon } from 'lucide-react';
import type { ReactNode } from 'react';
import { cn } from '@/lib/utils';

/**
 * One figure in a row of stats: a label, a large value and an optional hint, on the card surface.
 * Render inside a `<dl>`.
 */
export function StatTile({
    label,
    value,
    hint,
    icon: Icon,
    problem = false,
    valueClassName,
}: {
    label: ReactNode;
    value: ReactNode;
    hint?: ReactNode;
    icon?: LucideIcon;
    /** Colours the icon and value red, for counts that need attention. */
    problem?: boolean;
    valueClassName?: string;
}) {
    return (
        <div className="flex h-full flex-col rounded-xl border bg-card p-4 text-card-foreground shadow-xs">
            <dt className="flex items-start justify-between gap-2 text-13 text-muted-foreground">
                <span className="min-w-0">{label}</span>
                {Icon && <StatIcon icon={Icon} problem={problem} />}
            </dt>
            <dd
                className={cn(
                    'mt-2 text-2xl font-semibold tracking-tight tabular-nums',
                    problem && 'text-red-700 dark:text-red-400',
                    valueClassName,
                )}
            >
                {value}
            </dd>
            {hint && (
                <dd className="mt-auto pt-1 text-xs text-muted-foreground">
                    {hint}
                </dd>
            )}
        </div>
    );
}

/** A stat's icon, shown quietly at the top right of its card. */
export function StatIcon({
    icon: Icon,
    problem = false,
}: {
    icon: LucideIcon;
    problem?: boolean;
}) {
    return (
        <Icon
            aria-hidden
            className={cn(
                'size-4 shrink-0 text-muted-foreground/70',
                problem && 'text-red-600 dark:text-red-400',
            )}
        />
    );
}
