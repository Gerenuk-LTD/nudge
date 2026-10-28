import type { SelectHTMLAttributes } from 'react';
import { cn } from '@/lib/utils';

/**
 * A styled native select, used where values must submit with a plain form or be compact inside a builder.
 */
export function NativeSelect({
    className,
    ...props
}: SelectHTMLAttributes<HTMLSelectElement>) {
    return (
        <select
            className={cn(
                'h-9 rounded-md border border-input bg-transparent px-3 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 disabled:opacity-50 dark:bg-input/30',
                className,
            )}
            {...props}
        />
    );
}
