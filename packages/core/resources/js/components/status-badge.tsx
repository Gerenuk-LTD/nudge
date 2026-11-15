import type { ReactNode } from 'react';
import { cn } from '@/lib/utils';

export type StatusTone = 'neutral' | 'info' | 'success' | 'warning' | 'danger';

/** The dot's colour per tone. The badge itself is a neutral outline, so only the dot carries the status. */
const tones: Record<StatusTone, string> = {
    neutral: 'bg-zinc-400',
    info: 'bg-sky-500 dark:bg-sky-400',
    success: 'bg-emerald-500 dark:bg-emerald-400',
    warning: 'bg-amber-500 dark:bg-amber-400',
    danger: 'bg-red-500 dark:bg-red-400',
};

/**
 * A small outlined status label led by a coloured dot, so a status reads at a glance.
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
        <span className="inline-flex w-fit shrink-0 items-center gap-x-1.5 rounded-md px-2 py-0.5 text-xs font-medium whitespace-nowrap text-foreground ring-1 ring-border ring-inset">
            <span className="relative flex size-1.5" aria-hidden="true">
                {live && (
                    <span
                        className={cn(
                            'absolute inline-flex size-full animate-ping rounded-full opacity-60 motion-reduce:animate-none',
                            tones[tone],
                        )}
                    />
                )}
                <span
                    className={cn(
                        'relative inline-flex size-1.5 rounded-full',
                        tones[tone],
                    )}
                />
            </span>
            {children}
        </span>
    );
}
