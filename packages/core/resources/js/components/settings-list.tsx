import type { ReactNode } from 'react';
import { cn } from '@/lib/utils';

/**
 * A titled card of rows, for short collections such as members or tokens that read better as a list than a table.
 * Rows run edge to edge, split by hairlines, and the card shows `empty` when there are none.
 */
export function SettingsList({
    title,
    description,
    actions,
    empty,
    className,
    children,
}: {
    title: string;
    description?: ReactNode;
    actions?: ReactNode;
    empty?: ReactNode;
    className?: string;
    children?: ReactNode;
}) {
    const hasRows = Array.isArray(children)
        ? children.some(Boolean)
        : Boolean(children);

    return (
        <section
            className={cn(
                'overflow-hidden rounded-xl border bg-card text-card-foreground shadow-xs',
                className,
            )}
        >
            <header className="flex flex-wrap items-start justify-between gap-3 border-b px-5 py-4 sm:px-6">
                <div className="space-y-1">
                    <h2 className="text-base font-semibold">{title}</h2>
                    {description && (
                        <p className="text-sm text-muted-foreground">
                            {description}
                        </p>
                    )}
                </div>
                {actions}
            </header>

            {hasRows ? (
                <ul className="divide-y">{children}</ul>
            ) : (
                <p className="px-5 py-10 text-center text-sm text-muted-foreground sm:px-6">
                    {empty}
                </p>
            )}
        </section>
    );
}

/**
 * One row of a settings list: an optional leading mark, a title with a line of detail, and trailing controls.
 */
export function SettingsListItem({
    leading,
    title,
    detail,
    children,
}: {
    leading?: ReactNode;
    title: ReactNode;
    detail?: ReactNode;
    children?: ReactNode;
}) {
    return (
        <li className="flex flex-wrap items-center gap-x-4 gap-y-2 px-5 py-3.5 sm:px-6">
            {leading}
            <div className="min-w-0 flex-1 basis-40">
                <div className="flex flex-wrap items-center gap-2 text-sm font-medium">
                    {title}
                </div>
                {detail && (
                    <div className="truncate text-13 text-muted-foreground">
                        {detail}
                    </div>
                )}
            </div>
            {children && (
                <div className="flex shrink-0 items-center gap-1">
                    {children}
                </div>
            )}
        </li>
    );
}
