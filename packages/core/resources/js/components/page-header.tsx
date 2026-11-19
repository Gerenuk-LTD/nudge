import type { ReactNode } from 'react';

export function PageHeader({
    title,
    description,
    meta,
    actions,
}: {
    title: string;
    description?: string;
    /** A status shown on the description line, such as a badge, so it stays out of the row of buttons. */
    meta?: ReactNode;
    actions?: ReactNode;
}) {
    return (
        <div className="flex flex-wrap items-end justify-between gap-4">
            <div className="min-w-0 space-y-1">
                <h1 className="text-2xl font-semibold break-words">{title}</h1>
                {(meta || description) && (
                    <div className="flex flex-wrap items-center gap-x-2 gap-y-1 text-sm text-muted-foreground">
                        {meta}
                        {description && <p>{description}</p>}
                    </div>
                )}
            </div>
            {actions && (
                <div className="flex flex-wrap items-center gap-2">
                    {actions}
                </div>
            )}
        </div>
    );
}

export function PageBody({ children }: { children: ReactNode }) {
    return (
        <div className="mx-auto flex w-full max-w-7xl flex-1 flex-col gap-6 px-4 py-6 md:px-8 md:py-8">
            {children}
        </div>
    );
}
