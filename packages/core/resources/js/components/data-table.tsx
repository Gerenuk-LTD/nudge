import type { ReactNode } from 'react';
import { cn } from '@/lib/utils';

/**
 * A plain bordered table that scrolls sideways on narrow screens.
 */
export function DataTable({
    headers,
    children,
    empty,
}: {
    headers: ReactNode[];
    children: ReactNode;
    empty?: ReactNode;
}) {
    return (
        <div className="overflow-x-auto rounded-xl border border-sidebar-border/70 dark:border-sidebar-border">
            <table className="w-full text-left text-sm">
                <thead className="border-b bg-muted/40 text-xs text-muted-foreground uppercase">
                    <tr>
                        {headers.map((header, index) => (
                            <th key={index} className="px-4 py-2.5 font-medium">
                                {header}
                            </th>
                        ))}
                    </tr>
                </thead>
                <tbody className="divide-y">{children}</tbody>
            </table>
            {empty}
        </div>
    );
}

export function Cell({
    children,
    className,
}: {
    children?: ReactNode;
    className?: string;
}) {
    return (
        <td className={cn('px-4 py-2.5 align-middle', className)}>
            {children}
        </td>
    );
}

export function EmptyRow({ message }: { message: string }) {
    return (
        <p className="px-4 py-10 text-center text-sm text-muted-foreground">
            {message}
        </p>
    );
}
