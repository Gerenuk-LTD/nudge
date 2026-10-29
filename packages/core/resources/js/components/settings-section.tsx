import type { ReactNode } from 'react';
import { cn } from '@/lib/utils';

/**
 * One settings section as a bordered card: a title, its fields, and a footer bar holding a hint and the section's actions.
 * Wrap it in a form when the footer submits. The `danger` tone marks actions that cannot be undone.
 */
export function SettingsSection({
    title,
    description,
    badge,
    hint,
    actions,
    tone = 'default',
    className,
    children,
}: {
    title: string;
    description?: ReactNode;
    badge?: ReactNode;
    hint?: ReactNode;
    actions?: ReactNode;
    tone?: 'default' | 'danger';
    className?: string;
    children?: ReactNode;
}) {
    const danger = tone === 'danger';

    return (
        <section
            className={cn(
                'overflow-hidden rounded-xl border bg-card text-card-foreground shadow-xs',
                danger && 'border-destructive/30',
                className,
            )}
        >
            <div className="space-y-4 p-5 sm:p-6">
                <header className="space-y-1">
                    <div className="flex flex-wrap items-center gap-2">
                        <h2 className="text-base font-semibold">{title}</h2>
                        {badge}
                    </div>
                    {description && (
                        <p className="text-sm text-muted-foreground">
                            {description}
                        </p>
                    )}
                </header>
                {children}
            </div>

            {(hint || actions) && (
                <footer
                    className={cn(
                        'flex flex-wrap items-center justify-between gap-3 border-t px-5 py-3 sm:px-6',
                        danger
                            ? 'border-destructive/30 bg-destructive/5'
                            : 'bg-muted/40',
                    )}
                >
                    <p className="text-sm text-muted-foreground">{hint}</p>
                    {actions && (
                        <div className="ml-auto flex items-center gap-2">
                            {actions}
                        </div>
                    )}
                </footer>
            )}
        </section>
    );
}
