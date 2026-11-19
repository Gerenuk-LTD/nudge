import {
    CircleAlert,
    CircleCheck,
    Info,
    OctagonAlert,
    TriangleAlert,
    X,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { useState } from 'react';
import type { ReactNode } from 'react';
import { cn } from '@/lib/utils';

export type ShoutType = 'info' | 'success' | 'warning' | 'danger' | 'neutral';

const tones: Record<
    ShoutType,
    { box: string; icon: string; button: string; Icon: LucideIcon }
> = {
    info: {
        box: 'border-sky-300/70 bg-sky-50 text-sky-950 dark:border-sky-400/30 dark:bg-sky-400/10 dark:text-sky-100',
        icon: 'text-sky-600 dark:text-sky-400',
        button: '[&_[data-slot=button]]:border-sky-300 [&_[data-slot=button]]:bg-white/70 [&_[data-slot=button]]:text-sky-900 [&_[data-slot=button]:hover]:bg-sky-100 [&_[data-slot=button]:hover]:text-sky-950 dark:[&_[data-slot=button]]:border-sky-400/30 dark:[&_[data-slot=button]]:bg-sky-400/10 dark:[&_[data-slot=button]]:text-sky-100 dark:[&_[data-slot=button]:hover]:bg-sky-400/20 dark:[&_[data-slot=button]:hover]:text-sky-50',
        Icon: Info,
    },
    success: {
        box: 'border-emerald-300/70 bg-emerald-50 text-emerald-950 dark:border-emerald-400/30 dark:bg-emerald-400/10 dark:text-emerald-100',
        icon: 'text-emerald-600 dark:text-emerald-400',
        button: '[&_[data-slot=button]]:border-emerald-300 [&_[data-slot=button]]:bg-white/70 [&_[data-slot=button]]:text-emerald-900 [&_[data-slot=button]:hover]:bg-emerald-100 [&_[data-slot=button]:hover]:text-emerald-950 dark:[&_[data-slot=button]]:border-emerald-400/30 dark:[&_[data-slot=button]]:bg-emerald-400/10 dark:[&_[data-slot=button]]:text-emerald-100 dark:[&_[data-slot=button]:hover]:bg-emerald-400/20 dark:[&_[data-slot=button]:hover]:text-emerald-50',
        Icon: CircleCheck,
    },
    warning: {
        box: 'border-amber-300/70 bg-amber-50 text-amber-950 dark:border-amber-400/30 dark:bg-amber-400/10 dark:text-amber-100',
        icon: 'text-amber-600 dark:text-amber-400',
        button: '[&_[data-slot=button]]:border-amber-300 [&_[data-slot=button]]:bg-white/70 [&_[data-slot=button]]:text-amber-900 [&_[data-slot=button]:hover]:bg-amber-100 [&_[data-slot=button]:hover]:text-amber-950 dark:[&_[data-slot=button]]:border-amber-400/30 dark:[&_[data-slot=button]]:bg-amber-400/10 dark:[&_[data-slot=button]]:text-amber-100 dark:[&_[data-slot=button]:hover]:bg-amber-400/20 dark:[&_[data-slot=button]:hover]:text-amber-50',
        Icon: TriangleAlert,
    },
    danger: {
        box: 'border-red-300/70 bg-red-50 text-red-950 dark:border-red-400/30 dark:bg-red-400/10 dark:text-red-100',
        icon: 'text-red-600 dark:text-red-400',
        button: '[&_[data-slot=button]]:border-red-300 [&_[data-slot=button]]:bg-white/70 [&_[data-slot=button]]:text-red-900 [&_[data-slot=button]:hover]:bg-red-100 [&_[data-slot=button]:hover]:text-red-950 dark:[&_[data-slot=button]]:border-red-400/30 dark:[&_[data-slot=button]]:bg-red-400/10 dark:[&_[data-slot=button]]:text-red-100 dark:[&_[data-slot=button]:hover]:bg-red-400/20 dark:[&_[data-slot=button]:hover]:text-red-50',
        Icon: OctagonAlert,
    },
    neutral: {
        box: 'border-border bg-muted/50 text-foreground',
        icon: 'text-muted-foreground',
        button: '',
        Icon: CircleAlert,
    },
};

function storedDismissal(key: string): boolean {
    try {
        return window.localStorage.getItem(`shout:${key}`) === '1';
    } catch {
        return false;
    }
}

/**
 * An inline callout that tells people something about where they are: a tinted box with an icon, an optional
 * heading, the message, and actions beside it (or below, for longer messages). Buttons in the actions take the
 * callout's colours. Pass `dismissKey` to let people close
 * it; it then stays closed in this browser until the key changes.
 */
export function Shout({
    type = 'info',
    heading,
    icon: Icon = tones[type].Icon,
    actions,
    actionsBelow = false,
    dismissKey,
    className,
    children,
}: {
    type?: ShoutType;
    heading?: ReactNode;
    icon?: LucideIcon;
    actions?: ReactNode;
    actionsBelow?: boolean;
    dismissKey?: string;
    className?: string;
    children?: ReactNode;
}) {
    const [dismissed, setDismissed] = useState(
        () => dismissKey !== undefined && storedDismissal(dismissKey),
    );

    if (dismissed) {
        return null;
    }

    const dismiss = () => {
        setDismissed(true);

        try {
            window.localStorage.setItem(`shout:${dismissKey}`, '1');
        } catch {
            // Without storage it stays closed until the page reloads.
        }
    };

    return (
        <div
            role={type === 'danger' || type === 'warning' ? 'alert' : 'status'}
            className={cn(
                'flex items-center gap-3 rounded-lg border px-4 py-3',
                tones[type].box,
                className,
            )}
        >
            <div className="flex min-w-0 flex-1 items-start gap-3">
                <Icon
                    aria-hidden
                    className={cn(
                        'size-4 shrink-0',
                        heading ? 'mt-0.5' : 'mt-px',
                        tones[type].icon,
                    )}
                />
                <div className="flex min-w-0 flex-1 flex-col gap-2.5">
                    <div className="space-y-0.5">
                        {heading && (
                            <p className="text-sm font-semibold">{heading}</p>
                        )}
                        {children && (
                            <div
                                className={cn(
                                    'text-13',
                                    heading && 'opacity-80',
                                )}
                            >
                                {children}
                            </div>
                        )}
                    </div>
                    {actions && actionsBelow && (
                        <div
                            className={cn(
                                'flex flex-wrap items-center gap-2',
                                tones[type].button,
                            )}
                        >
                            {actions}
                        </div>
                    )}
                </div>
            </div>
            {actions && !actionsBelow && (
                <div
                    className={cn(
                        'ml-auto flex shrink-0 items-center gap-2',
                        tones[type].button,
                    )}
                >
                    {actions}
                </div>
            )}
            {dismissKey && (
                <button
                    type="button"
                    onClick={dismiss}
                    aria-label="Dismiss"
                    className="-mr-1 shrink-0 self-start rounded-md p-1 opacity-60 transition-opacity hover:opacity-100"
                >
                    <X className="size-4" />
                </button>
            )}
        </div>
    );
}
