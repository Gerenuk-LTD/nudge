import type { ReactNode } from 'react';
import { cn } from '@/lib/utils';

export type StatusTone = 'neutral' | 'info' | 'success' | 'warning' | 'danger';

/** Tinted background, text and dot per tone, in the flat-with-dot style of Tailwind's own badges. */
const tones: Record<StatusTone, { badge: string; dot: string }> = {
    neutral: {
        badge: 'bg-zinc-100 text-zinc-600 dark:bg-zinc-400/10 dark:text-zinc-400',
        dot: 'bg-zinc-400',
    },
    info: {
        badge: 'bg-sky-100 text-sky-700 dark:bg-sky-400/10 dark:text-sky-400',
        dot: 'bg-sky-500 dark:bg-sky-400',
    },
    success: {
        badge: 'bg-emerald-100 text-emerald-700 dark:bg-emerald-400/10 dark:text-emerald-400',
        dot: 'bg-emerald-500 dark:bg-emerald-400',
    },
    warning: {
        badge: 'bg-amber-100 text-amber-800 dark:bg-amber-400/10 dark:text-amber-400',
        dot: 'bg-amber-500 dark:bg-amber-400',
    },
    danger: {
        badge: 'bg-red-100 text-red-700 dark:bg-red-400/10 dark:text-red-400',
        dot: 'bg-red-500 dark:bg-red-400',
    },
};

/**
 * A small tinted status label led by a coloured dot, so a status reads at a glance.
 * `live` pulses the dot for work in progress, such as a campaign that is sending.
 */
export function StatusBadge({
    tone,
    live = false,
    children,
}: {
    tone: StatusTone;
    live?: boolean;
    children: ReactNode;
}) {
    return (
        <span
            className={cn(
                'inline-flex w-fit shrink-0 items-center gap-x-1.5 rounded-md px-2 py-0.5 text-xs font-medium whitespace-nowrap',
                tones[tone].badge,
            )}
        >
            <span className="relative flex size-1.5" aria-hidden="true">
                {live && (
                    <span
                        className={cn(
                            'absolute inline-flex size-full animate-ping rounded-full opacity-60 motion-reduce:animate-none',
                            tones[tone].dot,
                        )}
                    />
                )}
                <span
                    className={cn(
                        'relative inline-flex size-1.5 rounded-full',
                        tones[tone].dot,
                    )}
                />
            </span>
            {children}
        </span>
    );
}
