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
        <div className="rounded-xl border bg-card p-4 text-card-foreground shadow-xs">
            <dt className="flex items-center gap-2.5 text-sm text-muted-foreground">
                {Icon && <StatIcon icon={Icon} problem={problem} />}
                {label}
            </dt>
            <dd
                className={cn(
                    'mt-1 text-2xl font-semibold tabular-nums',
                    problem && 'text-red-700 dark:text-red-400',
                    valueClassName,
                )}
            >
                {value}
            </dd>
            {hint && <dd className="text-xs text-muted-foreground">{hint}</dd>}
        </div>
    );
}

/** The small square an icon sits in beside a stat's label. */
export function StatIcon({
    icon: Icon,
    problem = false,
}: {
    icon: LucideIcon;
    problem?: boolean;
}) {
    return (
        <span
            aria-hidden
            className={cn(
                'flex size-7 shrink-0 items-center justify-center rounded-md border bg-muted/40 text-muted-foreground',
                problem &&
                    'border-red-200 bg-red-50 text-red-600 dark:border-red-400/20 dark:bg-red-400/10 dark:text-red-400',
            )}
        >
            <Icon className="size-3.5" />
        </span>
    );
}
